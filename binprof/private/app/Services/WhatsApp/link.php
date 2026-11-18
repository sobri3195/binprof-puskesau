<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

function waCreateChallenge(int $user): string
{
    return tx(static function () use ($user): string {
        $version = qu('SELECT session_version FROM users WHERE id=? AND active=1 FOR UPDATE',[$user])->fetchColumn();
        if (!$version) {
            fail(403,'Akun tidak aktif.');
        }
        if ((int) qu('SELECT COUNT(*) FROM whatsapp_link_challenges
            WHERE user_id=? AND created_at>UTC_TIMESTAMP()-INTERVAL 10 MINUTE',[$user])->fetchColumn() >= 4) {
            fail(429,'Pembuatan kode terlalu sering. Coba lagi dalam 10 menit.');
        }
        qu('UPDATE whatsapp_link_challenges SET consumed_at=UTC_TIMESTAMP(),claim_ciphertext=NULL
            WHERE user_id=? AND consumed_at IS NULL',[$user]);
        $code = strtoupper(bin2hex(random_bytes(6)));
        qu('INSERT INTO whatsapp_link_challenges (id,code_hash,user_id,auth_version,expires_at)
            VALUES (?,?,?,?,UTC_TIMESTAMP()+INTERVAL 10 MINUTE)',
            [bin2hex(random_bytes(16)),waHash('code:' . waChannel(),$code),$user,(int) $version]);
        return $code;
    });
}

function waClaimChallenge(array $sender, string $code, string $occurredAt): void
{
    $hash = waHash('code:' . waChannel(),$code);
    $challenge = qu('SELECT id FROM whatsapp_link_challenges WHERE code_hash=? AND consumed_at IS NULL
        AND claim_hash IS NULL AND expires_at>UTC_TIMESTAMP() AND created_at<=TIMESTAMPADD(SECOND,5,?)
        LIMIT 1 FOR UPDATE',[$hash,$occurredAt])->fetchColumn();
    if ($challenge) {
        qu('UPDATE whatsapp_link_challenges SET claim_hash=?,claim_ciphertext=?,identity_hint=? WHERE id=?',
            [waSenderHash($sender),waSeal($sender,"claim:$challenge"),$sender['hint'],$challenge]);
    }
}

function waCancelLink(string $id): void
{
    qu("UPDATE whatsapp_links SET active_slot=NULL,revoked_at=UTC_TIMESTAMP(),recipient_ciphertext=NULL,
        opted_in_at=NULL,map_specialty_id=NULL,context_until=NULL WHERE id=? AND active_slot=1",[$id]);
    qu("UPDATE whatsapp_outbox SET state='skipped',error_kind='link_revoked',payload_ciphertext=NULL
        WHERE link_id=? AND state='queued'",[$id]);
}

function waConfirmChallenge(int $user, string $id): void
{
    tx(static function () use ($user,$id): void {
        qu('SELECT id FROM application_state WHERE id=1 FOR UPDATE');
        $version = qu('SELECT session_version FROM users WHERE id=? AND active=1 FOR UPDATE',[$user])->fetchColumn();
        $challenge = qu('SELECT * FROM whatsapp_link_challenges WHERE id=? AND user_id=? AND auth_version=?
            AND consumed_at IS NULL AND claim_ciphertext IS NOT NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE',
            [$id,$user,(int) $version])->fetch();
        if (!$version || !$challenge) {
            fail(409,'Permintaan penautan tidak aktif. Buat kode baru.');
        }
        $current = qu('SELECT * FROM whatsapp_links WHERE sender_hash=? AND active_slot=1 FOR UPDATE',
            [$challenge['claim_hash']])->fetch();
        if ($current && (int) $current['user_id'] !== $user) {
            if (waActor($current)) {
                fail(409,'Identitas tersebut sudah tertaut. Batalkan dari akun asal terlebih dahulu.');
            }
            waCancelLink($current['id']);
        }
        $old = waLinkForUser($user);
        if ($old) {
            waCancelLink($old['id']);
        }
        $sender = waOpen($challenge['claim_ciphertext'],"claim:$id");
        if (!hash_equals($challenge['claim_hash'],waSenderHash($sender))) {
            fail(409,'Saluran WhatsApp berubah. Buat kode baru.');
        }
        $newId = bin2hex(random_bytes(16));
        qu('INSERT INTO whatsapp_links (id,user_id,sender_hash,channel_ref,identity_type,identity_hint,recipient_ciphertext,auth_version)
            VALUES (?,?,?,?,?,?,?,?)',[$newId,$user,$challenge['claim_hash'],waChannel(),$sender['type'],$sender['hint'],
                waSeal($sender,"link:$newId"),(int) $version]);
        qu('UPDATE whatsapp_link_challenges SET consumed_at=UTC_TIMESTAMP(),claim_ciphertext=NULL WHERE id=?',[$id]);
        audit($user,'whatsapp_link_confirm','whatsapp');
    });
}

function waUnlink(int $user): void
{
    tx(static function () use ($user): void {
        qu('SELECT id FROM application_state WHERE id=1 FOR UPDATE');
        qu('SELECT id FROM users WHERE id=? FOR UPDATE',[$user]);
        $link = waLinkForUser($user);
        if ($link) {
            waCancelLink($link['id']);
        }
        qu('UPDATE whatsapp_link_challenges SET consumed_at=UTC_TIMESTAMP(),claim_ciphertext=NULL
            WHERE user_id=? AND consumed_at IS NULL',[$user]);
        audit($user,'whatsapp_unlink','whatsapp');
    });
}
