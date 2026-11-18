<?php
declare(strict_types=1);

require_once __DIR__ . '/intents.php';
require_once __DIR__ . '/link.php';
require_once __DIR__ . '/meta.php';

function waEnqueue(string $key, string $kind, array $payload, string $expires, ?string $link = null, ?string $event = null): void
{
    $id = bin2hex(random_bytes(16));
    qu('INSERT INTO whatsapp_outbox (id,dedupe_key,link_id,event_id,kind,payload_ciphertext,expires_at)
        VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE dedupe_key=VALUES(dedupe_key)',
        [$id,$key,$link,$event,$kind,waSeal($payload,"outbox:$id"),$expires]);
}

function waProcessInbox(): void
{
    $ids = qu('SELECT id FROM whatsapp_inbox WHERE processed_at IS NULL AND next_attempt_at<=UTC_TIMESTAMP()
        ORDER BY occurred_at,sequence_no LIMIT 40')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        try {
            tx(static function () use ($id): void {
                $row = qu('SELECT * FROM whatsapp_inbox WHERE id=? AND processed_at IS NULL FOR UPDATE',[$id])->fetch();
                if (!$row) {
                    return;
                }
                $deadline = (new DateTimeImmutable($row['occurred_at'],new DateTimeZone('UTC')))
                    ->modify('+24 hours')->format('Y-m-d H:i:s');
                if ($deadline > gmdate('Y-m-d H:i:s')) {
                    waHandleInbox($row,$deadline);
                }
                qu('UPDATE whatsapp_inbox SET processed_at=UTC_TIMESTAMP(),payload_ciphertext=NULL WHERE id=?',[$id]);
            });
        } catch (Throwable $error) {
            reportFailure($error);
            qu('UPDATE whatsapp_inbox SET processed_at=IF(attempts>=4,UTC_TIMESTAMP(),NULL),
                payload_ciphertext=IF(attempts>=4,NULL,payload_ciphertext),attempts=attempts+1,
                next_attempt_at=TIMESTAMPADD(MINUTE,LEAST(30,POW(2,attempts)),UTC_TIMESTAMP()) WHERE id=?',[$id]);
        }
    }
}

function waHandleInbox(array $row, string $deadline): void
{
    $id = $row['id'];
    $data = waOpen($row['payload_ciphertext'],"inbox:$id");
    $text = trim($data['text']);
    $sender = $data['sender'];
    $link = qu('SELECT * FROM whatsapp_links WHERE sender_hash=? AND active_slot=1 FOR UPDATE',[$row['sender_hash']])->fetch();
    $actor = $link ? waActor($link) : null;
    // STOP bypasses the chat rate limit so consent can always be withdrawn.
    if ($actor && preg_match('/^stop[.!]?$/iD',$text)) {
        qu('UPDATE whatsapp_links SET opted_in_at=NULL WHERE id=?',[$link['id']]);
        qu("UPDATE whatsapp_outbox SET state='skipped',error_kind='opted_out',payload_ciphertext=NULL
            WHERE link_id=? AND kind='map_event' AND state='queued'",[$link['id']]);
        audit($actor['id'],'whatsapp_stop','whatsapp');
        waEnqueue("reply:$id",'reply',['intent'=>'stopped'],$deadline,$link['id']);
        return;
    }
    $limit = (int) qu('SELECT COUNT(*) FROM whatsapp_inbox WHERE sender_hash=? AND processed_at>=UTC_TIMESTAMP()-INTERVAL 5 MINUTE',
        [$row['sender_hash']])->fetchColumn();
    if ($limit >= 12) {
        return;
    }
    if (preg_match('/^TAUT\s+([a-f0-9]{12})$/iD',$text,$parts)) {
        waClaimChallenge($sender,strtoupper($parts[1]),$row['occurred_at']);
        waEnqueue("reply:$id",'link_help',['sender'=>$sender,'claim'=>true,'channel'=>waChannel()],$deadline);
    } elseif (!$actor) {
        waEnqueue("reply:$id",'link_help',['sender'=>$sender,'claim'=>false,'channel'=>waChannel()],$deadline);
    } else {
        waEnqueue("reply:$id",'reply',waIntent($text),$deadline,$link['id']);
    }
}

function waQueueMapEvents(): void
{
    tx(static function (): void {
        $cursor = (int) qu('SELECT revision FROM whatsapp_map_cursor WHERE id=1 FOR UPDATE')->fetchColumn();
        $end = mapRevision();
        if ($end <= $cursor) {
            return;
        }
        $events = qu('SELECT e.id,e.revision,e.category,e.created_at,f.id AS facility_id,f.unit_id FROM map_events e
            JOIN facilities f ON f.id=e.facility_id WHERE e.revision>? AND e.revision<=?
            ORDER BY e.revision,e.id LIMIT 80',[$cursor,$end])->fetchAll();
        foreach ($events as $event) {
            $cursor = (int) $event['revision'];
            if ($event['created_at'] < gmdate('Y-m-d H:i:s',time()-86400)) {
                continue;
            }
            $expiration = (new DateTimeImmutable($event['created_at'],new DateTimeZone('UTC')))
                ->modify('+24 hours')->format('Y-m-d H:i:s');
            $links = qu("SELECT l.id FROM whatsapp_links l JOIN users u ON u.id=l.user_id
                JOIN role_permissions rp ON rp.role_code=u.role_code AND rp.permission_code='map'
                WHERE l.active_slot=1 AND l.opted_in_at IS NOT NULL AND l.opted_in_at<=?
                AND u.active=1 AND u.session_version=l.auth_version
                AND (u.role_code='super_admin' OR EXISTS (SELECT 1 FROM user_unit_scopes s WHERE s.user_id=u.id AND s.unit_id=?)
                OR EXISTS (SELECT 1 FROM user_facility_scopes s WHERE s.user_id=u.id AND s.facility_id=?))",
                [$event['created_at'],(int) $event['unit_id'],(int) $event['facility_id']])->fetchAll(PDO::FETCH_COLUMN);
            foreach ($links as $link) {
                waEnqueue("map:$link:{$event['id']}",'map_event',[],$expiration,$link,$event['id']);
            }
        }
        qu('UPDATE whatsapp_map_cursor SET revision=? WHERE id=1',[count($events) < 80 ? $end : $cursor]);
    });
}

function waApplyReceipts(): void
{
    $receipts = qu("SELECT r.* FROM whatsapp_receipts r WHERE r.applied_at IS NULL AND
        (EXISTS (SELECT 1 FROM whatsapp_outbox o WHERE o.provider_id=r.provider_id)
            OR EXISTS (SELECT 1 FROM whatsapp_outbox o WHERE o.id=r.callback_id AND o.provider_id IS NULL
                AND o.state IN ('sending','unknown'))
            OR r.recorded_at<UTC_TIMESTAMP()-INTERVAL 2 DAY)
        ORDER BY r.recorded_at,r.fingerprint LIMIT 200")->fetchAll();
    $rank = ['sending'=>0,'unknown'=>0,'accepted'=>1,'sent'=>2,'failed'=>3,'delivered'=>4,'read'=>5];
    foreach ($receipts as $receipt) {
        tx(static function () use ($receipt,$rank): void {
            $row = qu('SELECT id,provider_id,state FROM whatsapp_outbox WHERE provider_id=? FOR UPDATE',
                [$receipt['provider_id']])->fetch();
            if (!$row && $receipt['callback_id']) {
                $row = qu("SELECT id,provider_id,state FROM whatsapp_outbox WHERE id=? AND provider_id IS NULL
                    AND state IN ('sending','unknown') FOR UPDATE",[$receipt['callback_id']])->fetch();
            }
            if (!$row) {
                if ($receipt['recorded_at'] < gmdate('Y-m-d H:i:s',time()-172800)) {
                    qu('UPDATE whatsapp_receipts SET applied_at=UTC_TIMESTAMP() WHERE fingerprint=?',[$receipt['fingerprint']]);
                }
                return;
            }
            $state = $receipt['status'];
            if (isset($rank[$row['state']]) && $rank[$state] > $rank[$row['state']]) {
                qu('UPDATE whatsapp_outbox SET provider_id=?,state=?,last_provider_at=?,error_kind=? WHERE id=?',
                    [$receipt['provider_id'],$state,$receipt['occurred_at'],$state === 'failed' ? 'provider_failed' : null,$row['id']]);
            }
            qu('UPDATE whatsapp_receipts SET applied_at=UTC_TIMESTAMP() WHERE fingerprint=?',[$receipt['fingerprint']]);
        });
    }
}

function waOutgoingMessage(array $row): ?array
{
    $id = $row['id'];
    $data = waOpen($row['payload_ciphertext'],"outbox:$id");
    if ($row['kind'] === 'link_help') {
        if ($data['channel'] !== waChannel()) {
            waFinish($id,'skipped','channel_changed');
            return null;
        }
        $sender = $data['sender'];
        $text = $data['claim']
            ? 'Jika kode masih aktif, periksa identitas dan konfirmasi penautan pada portal.'
            : 'Tautkan akun untuk memakai chatbot BINPROFKES. Masuk ke portal, buat kode, lalu balas TAUT diikuti kode.';
        return waTextMessage($sender['recipient'],$id,"$text\n" . waPortal(WA_SETTINGS));
    }
    $link = qu('SELECT * FROM whatsapp_links WHERE id=? AND active_slot=1',[$row['link_id']])->fetch();
    $actor = $link ? waActor($link) : null;
    if (!$actor || !$link['recipient_ciphertext']) {
        waFinish($id,'skipped','permission_revoked');
        return null;
    }
    $sender = waOpen($link['recipient_ciphertext'],"link:{$link['id']}");
    if ($row['kind'] === 'reply') {
        return waTextMessage($sender['recipient'],$id,waAnswer($actor,$link,$data));
    }
    return waMapMessage($row,$sender,$actor,$link);
}

function waTextMessage(string $recipient, string $id, string $text): array
{
    return ['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$recipient,
        'biz_opaque_callback_data'=>$id,'type'=>'text','text'=>['preview_url'=>false,'body'=>mb_substr($text,0,3900)]];
}

function waMapMessage(array $row,array $sender,array $actor,array $link): ?array
{
    $id = $row['id'];
    if (!$link['opted_in_at'] || !can($actor,'map')) {
        waFinish($id,'skipped','opted_out_or_forbidden');
        return null;
    }
    [$where,$params] = scope($actor,'f.unit_id','f.id');
    $event = qu("SELECT e.category FROM map_events e JOIN facilities f ON f.id=e.facility_id
        WHERE e.id=? AND e.active_slot=1 AND f.active=1 AND e.created_at>=? AND $where",
        [$row['event_id'],$link['opted_in_at'],...$params])->fetch();
    $pref = mapPreferences($actor['id']);
    if (!$event || $pref['muted'] || !$pref[$event['category']]) {
        waFinish($id,'skipped','event_out_of_scope_or_muted');
        return null;
    }
    if ($pref['quiet'] || !waTemplateReady()) {
        qu('UPDATE whatsapp_outbox SET next_attempt_at=UTC_TIMESTAMP()+INTERVAL 20 MINUTE,error_kind=? WHERE id=?',
            [$pref['quiet'] ? 'quiet_hours' : 'template_unavailable',$id]);
        return null;
    }
    return ['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$sender['recipient'],
        'biz_opaque_callback_data'=>$id,'type'=>'template','template'=>['name'=>waConfig('map_template'),
            'language'=>['code'=>waConfig('template_language')]]];
}

function waFinish(string $id,string $state,?string $error = null,?string $providerId = null): void
{
    qu('UPDATE whatsapp_outbox SET state=?,error_kind=?,provider_id=COALESCE(?,provider_id),
        payload_ciphertext=NULL WHERE id=?',[$state,$error,$providerId,$id]);
}

function waRetry(string $id,int $attempt,string $error,int $after = 0): void
{
    if ($attempt >= 5) {
        waFinish($id,'failed','retry_exhausted');
        return;
    }
    $delay = max($after,min(1800,60 * (2 ** max(0,$attempt-1))));
    qu("UPDATE whatsapp_outbox SET state='queued',next_attempt_at=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP()),
        error_kind=? WHERE id=?",[$delay,$error,$id]);
}

function waSendDue(callable $transport): void
{
    $ids = qu("SELECT id FROM whatsapp_outbox WHERE state='queued' AND next_attempt_at<=UTC_TIMESTAMP()
        AND expires_at>UTC_TIMESTAMP() ORDER BY next_attempt_at,sequence_no LIMIT 6")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        try {
            $claim = tx(static function () use ($id): ?array {
                $row = qu("SELECT * FROM whatsapp_outbox WHERE id=? AND state='queued'
                    AND expires_at>UTC_TIMESTAMP() FOR UPDATE",[$id])->fetch();
                if (!$row) {
                    return null;
                }
                $message = waOutgoingMessage($row);
                if ($message === null) {
                    return null;
                }
                $attempt = (int) $row['attempts']+1;
                qu("UPDATE whatsapp_outbox SET attempts=?,state='sending',started_at=UTC_TIMESTAMP(),error_kind=NULL WHERE id=?",
                    [$attempt,$id]);
                return [$message,$attempt];
            });
        } catch (Throwable $error) {
            reportFailure($error);
            qu("UPDATE whatsapp_outbox SET next_attempt_at=UTC_TIMESTAMP()+INTERVAL 5 MINUTE,error_kind='prepare_failure'
                WHERE id=? AND state='queued'",[$id]);
            continue;
        }
        if (!$claim) {
            continue;
        }
        [$message,$attempt] = $claim;
        try {
            $result = $transport($message);
            $state = $result['state'] ?? 'unknown';
            if ($state === 'retry') {
                waRetry($id,$attempt,$result['error'],(int) ($result['after'] ?? 0));
            } elseif ($state === 'accepted' && ($providerId = waProviderId($result['provider_id'] ?? null))) {
                waFinish($id,'accepted',providerId:$providerId);
            } elseif ($state === 'failed') {
                waFinish($id,'failed',$result['error'] ?? 'provider_rejected');
            } else {
                waFinish($id,'unknown',$result['error'] ?? 'transport_uncertain');
            }
        } catch (Throwable $error) {
            reportFailure($error);
            waFinish($id,'unknown','transport_uncertain');
        }
    }
}

function waRunWorker(?callable $transport = null): bool
{
    if (!waMigrated() || !waWebhookReady()) {
        return false;
    }
    $lock = 'wa:' . hash('sha1',(string) qu('SELECT DATABASE()')->fetchColumn());
    if ((int) qu('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn() !== 1) {
        return false;
    }
    try {
        qu("UPDATE whatsapp_outbox SET state='unknown',error_kind='worker_interrupted',payload_ciphertext=NULL WHERE state='sending'");
        waApplyReceipts();
        waProcessInbox();
        waQueueMapEvents();
        qu("UPDATE whatsapp_outbox SET state='skipped',error_kind='expired',payload_ciphertext=NULL
            WHERE state='queued' AND expires_at<=UTC_TIMESTAMP()");
        if ($transport || waTransportReady()) {
            waSendDue($transport ?? 'waMetaSend');
        }
        qu("DELETE FROM whatsapp_link_challenges WHERE expires_at < UTC_TIMESTAMP()-INTERVAL 1 DAY LIMIT 500");
        qu("DELETE FROM whatsapp_receipts WHERE recorded_at < UTC_TIMESTAMP()-INTERVAL 30 DAY LIMIT 500");
        qu("DELETE FROM whatsapp_inbox WHERE received_at < UTC_TIMESTAMP()-INTERVAL 30 DAY LIMIT 500");
        qu("DELETE FROM whatsapp_outbox WHERE created_at < UTC_TIMESTAMP()-INTERVAL 30 DAY LIMIT 500");
        return true;
    } finally {
        qu('SELECT RELEASE_LOCK(?)',[$lock]);
    }
}
