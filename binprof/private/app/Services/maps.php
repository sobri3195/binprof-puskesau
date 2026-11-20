<?php
declare(strict_types=1);

require_once PROJECT . '/private/app/Services/admin.php';
require_once PROJECT . '/private/app/Services/Retirement/metrics.php';

const MAP_CATEGORIES = ['operations'=>'Operasional','data'=>'Pembaruan data','training'=>'Diklat'];
const MAP_SEVERITIES = ['info'=>'Info','warning'=>'Perhatian','critical'=>'Kritis'];
const MAP_FILTERS = [
    'unit_id'=>['units','Satuan'], 'command_id'=>['commands','Komando'], 'type_id'=>['facility_types','Kategori fasilitas'],
    'profession_id'=>['professions','Profesi'], 'specialty_id'=>['specialties','Spesialisasi'],
];

function mapFilters(array $input): array
{
    $filters = ['q'=>field($input,'q',100),'retirement_months'=>retirementMapMonths($input)];
    foreach (array_keys(MAP_FILTERS) as $key) {
        $filters[$key] = number($input,$key);
    }
    return $filters;
}

function mapFacility(array $user, int $id): array
{
    [$where,$params] = scope($user,'f.unit_id','f.id');
    $row = qu("SELECT f.id,f.name,f.address,f.unit_id,f.updated_at,f.latitude,f.longitude,u.name AS unit,
        c.name AS command_name,ft.name AS kind FROM facilities f JOIN units u ON u.id=f.unit_id
        JOIN facility_types ft ON ft.id=f.type_id LEFT JOIN commands c ON c.id=u.command_id
        WHERE f.id=? AND f.active=1 AND $where",[$id,...$params])->fetch();
    if (!$row) {
        fail(404,'Fasilitas tidak ditemukan.');
    }
    return $row;
}

function mapRows(array $user, ?array $filters = null): array
{
    $filters ??= mapFilters($_GET);
    [$scoped,$args] = scope($user,'f.unit_id','f.id');
    $conditions = [$scoped,'f.active=1'];
    if ($filters['q'] !== '') {
        $conditions[] = '(LOCATE(?,f.name)>0 OR LOCATE(?,f.code)>0)';
        array_push($args,$filters['q'],$filters['q']);
    }
    foreach (['unit_id'=>'f.unit_id','command_id'=>'u.command_id','type_id'=>'f.type_id'] as $key=>$column) {
        if ($filters[$key] !== null) {
            $conditions[] = "$column=?";
            $args[] = $filters[$key];
        }
    }
    if ($filters['profession_id'] !== null || $filters['specialty_id'] !== null) {
        $criteria = ['p.facility_id=f.id','p.active=1','p.archived_at IS NULL'];
        if ($filters['profession_id'] !== null) {
            $criteria[] = 'p.profession_id=?';
            $args[] = $filters['profession_id'];
        }
        if ($filters['specialty_id'] !== null) {
            $criteria[] = 'EXISTS (SELECT 1 FROM personnel_specialties ps WHERE ps.personnel_id=p.id AND ps.specialty_id=?)';
            $args[] = $filters['specialty_id'];
        }
        $conditions[] = 'EXISTS (SELECT 1 FROM personnel p WHERE ' . implode(' AND ',$criteria) . ')';
    }
    $where = implode(' AND ',$conditions);
    return qu("WITH candidate AS (
        SELECT f.id,f.name,f.latitude,f.longitude,f.updated_at,ft.name AS kind,u.name AS unit,c.name AS command_name
        FROM facilities f JOIN units u ON u.id=f.unit_id JOIN facility_types ft ON ft.id=f.type_id
        LEFT JOIN commands c ON c.id=u.command_id WHERE $where ORDER BY f.name,f.id LIMIT 1000
    ), stats AS (
        SELECT p.facility_id, COUNT(*) AS total, SUM(pr.code='doctor') AS doctors,
            SUM(pr.code='dentist') AS dentists, SUM(pr.code='nurse') AS nurses
        FROM candidate f JOIN personnel p ON p.facility_id=f.id JOIN professions pr ON pr.id=p.profession_id
        WHERE p.active=1 AND p.archived_at IS NULL GROUP BY p.facility_id
    )
    SELECT f.*,stats.total,stats.doctors,stats.dentists,stats.nurses FROM candidate f
    LEFT JOIN stats ON stats.facility_id=f.id ORDER BY f.name,f.id",$args)->fetchAll();
}

function eventRows(array $user, int $revision, ?int $after = null, ?int $facility = null): array
{
    [$scoped,$params] = scope($user,'f.unit_id','f.id');
    $conditions = [$scoped,'f.active=1','e.revision<=?'];
    $params = [$user['id'],...$params,$revision];
    if ($after === null) {
        $conditions[] = "(e.active_slot=1 OR e.resolved_at>=UTC_TIMESTAMP()-INTERVAL 30 DAY)";
    } else {
        $conditions[] = 'e.revision>?';
        $params[] = $after;
    }
    if ($facility !== null) {
        $conditions[] = 'f.id=?';
        $params[] = $facility;
    }
    $where = implode(' AND ',$conditions);
    $order = $after === null ? '(e.resolved_at IS NULL) DESC,e.created_at DESC,e.id DESC' : 'e.revision,e.id';
    return qu("SELECT e.id,e.facility_id,f.name AS facility,e.category,e.severity,e.created_at,e.acknowledged_at,
        e.resolved_at,rd.read_at,e.revision
        FROM map_events e JOIN facilities f ON f.id=e.facility_id
        LEFT JOIN map_event_reads rd ON rd.event_id=e.id AND rd.user_id=?
        WHERE $where ORDER BY $order LIMIT 201",$params)->fetchAll();
}

function mapBadges(array $user): array
{
    [$where,$params] = scope($user,'f.unit_id','f.id');
    return qu("SELECT f.id AS facility_id,COUNT(*) AS active,SUM(rd.event_id IS NULL) AS unread,
        MAX(FIELD(e.severity,'info','warning','critical')) AS severity
        FROM map_events e JOIN facilities f ON f.id=e.facility_id
        LEFT JOIN map_event_reads rd ON rd.event_id=e.id AND rd.user_id=?
        WHERE $where AND f.active=1 AND e.active_slot=1 GROUP BY f.id",[$user['id'],...$params])->fetchAll();
}

function mapPreferences(int $userId): array
{
    $row = qu('SELECT * FROM map_preferences WHERE user_id=?',[$userId])->fetch();
    $zone = new DateTimeZone(cfg('timezone') ?? 'Asia/Jakarta');
    $now = (new DateTimeImmutable('now',$zone))->format('H:i:s');
    $start = $row['quiet_start'] ?? null;
    $end = $row['quiet_end'] ?? null;
    $quiet = $start !== null && ($start < $end ? $now >= $start && $now < $end : $now >= $start || $now < $end);
    return [
        'operations'=>(bool) ($row['operations_enabled'] ?? true),
        'data'=>(bool) ($row['data_enabled'] ?? true),
        'training'=>(bool) ($row['training_enabled'] ?? true),
        'muted'=>(bool) ($row['muted'] ?? false), 'quiet'=>$quiet,
        'quiet_start'=>$start === null ? '' : substr($start,0,5), 'quiet_end'=>$end === null ? '' : substr($end,0,5),
        'zone'=>$zone->getName(), 'revision'=>(int) ($row['revision'] ?? 0),
    ];
}

function mapRevision(): int
{
    return (int) qu('SELECT revision FROM map_revision WHERE id=1')->fetchColumn();
}

function nextMapRevision(): int
{
    qu('UPDATE map_revision SET revision=revision+1 WHERE id=1');
    return mapRevision();
}

function touchMapPreferences(int $userId): void
{
    qu('INSERT INTO map_preferences (user_id) VALUES (?) ON DUPLICATE KEY UPDATE revision=revision+1',[$userId]);
}

function mapCursor(string $key): ?int
{
    $value = field($_GET,$key,18);
    if ($value === '') {
        return null;
    }
    if (!ctype_digit($value)) {
        fail(422,'Posisi sinkronisasi tidak valid.');
    }
    return (int) $value;
}

function mapEventsPoll(array $user): never
{
    session_write_close();
    $after = mapCursor('after');
    $userAfter = mapCursor('personal');
    $payload = tx(static function () use ($user,$after,$userAfter): array {
        $revisions = qu('SELECT revision,refresh_revision FROM map_revision WHERE id=1')->fetch();
        $revision = (int) $revisions['revision'];
        $invalidate = $after !== null && $after < (int) $revisions['refresh_revision'];
        $preferences = mapPreferences($user['id']);
        $reset = $after === null || $after > $revision || $userAfter !== $preferences['revision'] || $invalidate;
        $changed = $reset || $after < $revision;
        $events = $changed ? eventRows($user,$revision,$reset ? null : $after) : [];
        if (!$reset && count($events) > 200) {
            $reset = true;
            $events = eventRows($user,$revision);
        }
        return [
            'revision'=>(string) $revision, 'personal'=>(string) $preferences['revision'],
            'refresh_revision'=>(string) $revisions['refresh_revision'],
            'reset'=>$reset, 'refresh_facilities'=>$invalidate, 'events'=>array_slice($events,0,200), 'changed'=>$changed,
            'badges'=>$changed ? mapBadges($user) : null, 'preferences'=>$preferences,
            'synced_at'=>gmdate(DATE_ATOM),
        ];
    });
    jsonResponse($payload);
}

function mapCanReport(array $user): bool
{
    return can($user,'map.manage') || can($user,'map.report');
}

function mapEventAction(array $user): never
{
    $action = choice($_POST,'action',['create','read','acknowledge','resolve']);
    $facility = number($_POST,'facility') ?? fail(422,'Fasilitas diperlukan.');
    if (($action === 'create' && !mapCanReport($user)) || ($action === 'resolve' && !can($user,'map.manage'))) {
        fail(403,'Pengelolaan kejadian memerlukan izin.');
    }
    if ($action === 'acknowledge' && !can($user,'map.respond')) {
        fail(403,'Pengakuan kejadian memerlukan izin.');
    }
    $result = tx(static function () use ($user,$action,$facility): array {
        $f = mapFacility($user,$facility);
        if ($action === 'create') {
            // All state mutations acquire the global revision row before the event row.
            qu('SELECT revision FROM map_revision WHERE id=1 FOR UPDATE');
            $category = choice($_POST,'category',array_keys(MAP_CATEGORIES));
            $severity = choice($_POST,'severity',array_keys(MAP_SEVERITIES));
            $existing = qu('SELECT id FROM map_events WHERE facility_id=? AND category=? AND active_slot=1 FOR UPDATE',
                [$facility,$category])->fetchColumn();
            if ($existing !== false) {
                return ['id'=>$existing,'changed'=>false];
            }
            $rev = nextMapRevision();
            $id = bin2hex(random_bytes(16));
            qu('INSERT INTO map_events (id,facility_id,category,severity,revision,created_by) VALUES (?,?,?,?,?,?)',
                [$id,$facility,$category,$severity,$rev,$user['id']]);
        } else {
            $id = key32($_POST,'event');
            if ($action !== 'read') {
                qu('SELECT revision FROM map_revision WHERE id=1 FOR UPDATE');
            }
            $event = qu('SELECT * FROM map_events WHERE id=? AND facility_id=? FOR UPDATE',[$id,$facility])->fetch();
            if (!$event) {
                fail(404,'Kejadian tidak ditemukan.');
            }
            if ($action === 'read') {
                $alreadyRead = qu('SELECT 1 FROM map_event_reads WHERE user_id=? AND event_id=? FOR UPDATE',[$user['id'],$id])->fetchColumn();
                if (!$alreadyRead) {
                    qu('INSERT INTO map_event_reads (user_id,event_id) VALUES (?,?)',[$user['id'],$id]);
                    touchMapPreferences($user['id']);
                }
                return ['id'=>$id,'changed'=>!$alreadyRead];
            }
            if ($event['resolved_at'] || ($action === 'acknowledge' && $event['acknowledged_at'])) {
                return ['id'=>$id,'changed'=>false];
            }
            $rev = nextMapRevision();
            if ($action === 'acknowledge') {
                qu('UPDATE map_events SET revision=?,acknowledged_at=UTC_TIMESTAMP(),acknowledged_by=? WHERE id=?',
                    [$rev,$user['id'],$id]);
            } else {
                qu('UPDATE map_events SET revision=?,resolved_at=UTC_TIMESTAMP(),resolved_by=?,active_slot=NULL WHERE id=?',
                    [$rev,$user['id'],$id]);
            }
        }
        audit($user['id'],'map_event_' . $action,'map_events',$id,(int) $f['unit_id']);
        return ['id'=>$id,'changed'=>true];
    });
    jsonResponse($result);
}

function storeMapPreferences(int $userId, array $data): void
{
    $values = [];
    foreach ([...array_keys(MAP_CATEGORIES),'muted'] as $flag) {
        $values[] = (int) choice($data,$flag,['0','1'],'0');
    }
    $start = field($data,'quiet_start',5);
    $end = field($data,'quiet_end',5);
    if (($start === '') !== ($end === '') || ($start !== '' && ($start === $end
        || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$start)
        || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$end)))) {
        fail(422,'Isi dua waktu yang berbeda atau kosongkan keduanya.');
    }
    qu('INSERT INTO map_preferences (user_id,operations_enabled,data_enabled,training_enabled,muted,quiet_start,quiet_end)
        VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE operations_enabled=VALUES(operations_enabled),
        data_enabled=VALUES(data_enabled),training_enabled=VALUES(training_enabled),muted=VALUES(muted),
        quiet_start=VALUES(quiet_start),quiet_end=VALUES(quiet_end),revision=revision+1',
        [$userId,...$values,$start ?: null,$end ?: null]);
}

function mapSavePreferences(array $user): never
{
    storeMapPreferences($user['id'],$_POST);
    jsonResponse(mapPreferences($user['id']));
}

function mapSavedViews(array $user): never
{
    $views = qu('SELECT id,name,filters_json FROM map_saved_views WHERE user_id=? ORDER BY name,id',[$user['id']])->fetchAll();
    foreach ($views as &$view) {
        $view['view'] = json_decode($view['filters_json'],true,flags:JSON_THROW_ON_ERROR);
        unset($view['filters_json']);
    }
    unset($view);
    jsonResponse($views);
}

function mapChangeView(array $user): never
{
    $action = choice($_POST,'action',['save','delete']);
    if ($action === 'delete') {
        $id = key32($_POST);
        if (qu('DELETE FROM map_saved_views WHERE user_id=? AND id=?',[$user['id'],$id])->rowCount() === 0) {
            fail(404,'Tampilan tidak ditemukan.');
        }
        jsonResponse(['deleted'=>true]);
    }
    $name = field($_POST,'name',80,true);
    $themes = ['category','personnel','events'];
    if (retirementMetricsAllowed($user)) {
        $themes[] = 'retirement';
    }
    $view = ['filters'=>mapFilters($_POST),'theme'=>choice($_POST,'theme',$themes,'category')];
    $result = tx(static function () use ($user,$name,$view): array {
        qu('SELECT id FROM users WHERE id=? FOR UPDATE',[$user['id']]);
        $id = qu('SELECT id FROM map_saved_views WHERE user_id=? AND name=?',[$user['id'],$name])->fetchColumn();
        $json = json_encode($view,JSON_THROW_ON_ERROR);
        if ($id !== false) {
            qu('UPDATE map_saved_views SET filters_json=? WHERE id=?',[$json,$id]);
        } else {
            if ((int) qu('SELECT COUNT(*) FROM map_saved_views WHERE user_id=?',[$user['id']])->fetchColumn() >= 20) {
                fail(422,'Maksimal 20 tampilan per akun.');
            }
            $id = bin2hex(random_bytes(16));
            qu('INSERT INTO map_saved_views (id,user_id,name,filters_json) VALUES (?,?,?,?)',[$id,$user['id'],$name,$json]);
        }
        return ['id'=>$id,'name'=>$name,'view'=>$view];
    });
    jsonResponse($result);
}
