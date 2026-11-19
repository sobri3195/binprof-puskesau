<?php
declare(strict_types=1);

require_once PROJECT . '/private/app/Services/maps.php';

const EXECUTIVE = '/app/peta-kendali';
const EXEC_API = '/api/executive';
const EXEC_STATES = ['draft'=>'Draf','issued'=>'Diterbitkan','accepted'=>'Diterima','working'=>'Dikerjakan',
    'review'=>'Menunggu verifikasi','done'=>'Selesai','cancelled'=>'Dibatalkan'];
const EXEC_LAYERS = ['situation'=>'Situasi','staff'=>'Distribusi SDM','staffing'=>'Target dan gap tenaga',
    'certificate'=>'Sertifikat wajib','retirement'=>'Pensiun','event'=>'Kejadian','quality'=>'Mutu data','directive'=>'Tindak lanjut'];
const EXEC_ISSUES = ['staffing'=>'Gap SDM','certificate'=>'Sertifikat wajib','retirement'=>'Pensiun terjadwal',
    'directive'=>'Arahan melewati tenggat','event'=>'Gangguan fasilitas','quality'=>'Mutu data','general'=>'Umum'];
const EXEC_KPI = ['facilities'=>'Fasilitas aktif','active'=>'Personel aktif','doctors'=>'Dokter aktif','nurses'=>'Perawat aktif',
    'shortage'=>'Fasilitas dengan kekurangan tenaga','near'=>'Personel menjelang pensiun dalam 12 bulan'];

function executiveReady(): bool
{
    static $ready;
    return $ready ??= (bool) qu("SELECT 1 FROM schema_migrations WHERE version='007_executive_dashboard'")->fetchColumn();
}

function executiveNeed(array $user,string $permission): void
{
    if (!executiveReady() || !can($user,'executive.' . $permission)) {
        fail(403,'Akses Peta Kendali tidak tersedia.');
    }
}

function executiveFacility(array $user,int $id,bool $lock=false): array
{
    [$scoped,$args] = scope($user,'f.unit_id','f.id');
    $facility = qu("SELECT f.id,f.unit_id,f.name FROM facilities f WHERE f.id=? AND f.active=1 AND $scoped"
        . ($lock ? ' FOR UPDATE' : ''),[$id,...$args])->fetch();
    return $facility ?: fail(404,'Fasilitas tidak tersedia.');
}

function executiveOperator(int $id,array $facility): ?array
{
    $user = qu('SELECT id,name,role_code FROM users WHERE id=? AND active=1',[$id])->fetch();
    if (!$user || !retirementAccountEligible($id,$user['role_code']) || !can(withPermissions($user),'executive.work')) {
        return null;
    }
    [$scoped,$args] = scope($user,'f.unit_id','f.id');
    return qu("SELECT id FROM facilities f WHERE f.id=? AND $scoped",[$facility['id'],...$args])->fetchColumn() ? $user : null;
}

function executiveFilters(array $input): array
{
    $f = ['q'=>field($input,'q',100)];
    foreach (['command_id','unit_id','type_id','profession_id','specialty_id','facility_id'] as $key) {
        $f[$key] = number($input,$key);
    }
    $f['mode'] = choice($input,'mode',['now','history','projection'],'now');
    $f['months'] = (int) choice($input,'months',['3','6','12','24'],'12');
    $f['date'] = $f['mode']==='history' ? calendar($input,'date') : null;
    $f['metric'] = choice($input,'metric',['all','facilities','active','doctors','nurses','shortage','near'],'all');
    $f['layer'] = choice($input,'layer',array_keys(EXEC_LAYERS),'situation');
    return $f;
}

function executiveFacilities(array $user,array $filters=[]): array
{
    [$scoped,$args] = scope($user,'f.unit_id','f.id');
    $where = [$scoped,'f.active=1'];
    foreach (['command_id'=>'u.command_id','unit_id'=>'f.unit_id','type_id'=>'f.type_id','facility_id'=>'f.id'] as $key=>$col) {
        if (($filters[$key] ?? null) !== null) {
            $where[] = "$col=?";
            $args[] = $filters[$key];
        }
    }
    if (($filters['q'] ?? '') !== '') {
        $where[] = '(LOCATE(?,f.name)>0 OR LOCATE(?,f.code)>0)';
        array_push($args,$filters['q'],$filters['q']);
    }
    return qu('SELECT f.id,f.name,f.unit_id,f.type_id,f.latitude,f.longitude,f.updated_at,u.name AS unit,
        u.command_id,c.name AS command_name,t.name AS kind FROM facilities f JOIN units u ON u.id=f.unit_id
        JOIN facility_types t ON t.id=f.type_id LEFT JOIN commands c ON c.id=u.command_id WHERE '
        . implode(' AND ',$where) . ' ORDER BY f.name,f.id',$args)->fetchAll();
}

function executiveFacilitiesSelect(array $user): array
{
    return array_column(executiveFacilities($user),'name','id');
}

function executiveIn(array $ids): string
{
    return implode(',',array_fill(0,count($ids),'?'));
}

function executiveRevision(string $access,array $filters,int $actor): string
{
    // High-water marks for existing sources avoid recalculating the roster in an empty delta.
    $source = qu("SELECT (SELECT MAX(id) FROM activity_logs WHERE result='success'
        AND entity NOT IN ('session','whatsapp') AND action NOT LIKE 'export_%' AND action NOT IN ('view','read','download')) AS audit,
        (SELECT MAX(id) FROM personnel_record_history) AS documents,
        (SELECT revision FROM map_revision WHERE id=1) AS maps")->fetch();
    $read = qu("SELECT (SELECT MAX(id) FROM activity_logs WHERE actor_id=? AND action='executive_read') AS directive_read,
        (SELECT MAX(read_at) FROM map_event_reads WHERE user_id=?) AS map_read",[$actor,$actor])->fetch();
    return hash_hmac('sha256',json_encode([$access,day(),$filters,$source,$read],JSON_THROW_ON_ERROR),secretBytes(cfg('lookup_pepper')));
}

function executiveAccessToken(array $user): string
{
    $id = $user['id'];
    $units = qu('SELECT unit_id FROM user_unit_scopes WHERE user_id=? ORDER BY unit_id',[$id])->fetchAll(PDO::FETCH_COLUMN);
    $places = qu('SELECT facility_id FROM user_facility_scopes WHERE user_id=? ORDER BY facility_id',[$id])->fetchAll(PDO::FETCH_COLUMN);
    $permissions = $user['permissions'];
    sort($permissions);
    return hash_hmac('sha256',json_encode([$id,$user['role_code'],$permissions,$units,$places],
        JSON_THROW_ON_ERROR),secretBytes(cfg('lookup_pepper')));
}

function executiveCardLabels(array $filters): array
{
    if ($filters['mode'] !== 'projection') {
        return EXEC_KPI;
    }
    return array_replace(EXEC_KPI,['active'=>'Proyeksi personel aktif','doctors'=>'Proyeksi dokter aktif','nurses'=>'Proyeksi perawat aktif',
        'shortage'=>'Fasilitas dengan proyeksi kekurangan tenaga']);
}
