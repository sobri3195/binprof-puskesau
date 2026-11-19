<?php
declare(strict_types=1);

require_once PROJECT . '/private/app/Services/Executive/governance.php';
require_once PROJECT . '/private/app/Services/Executive/workflow.php';
require_once PROJECT . '/private/app/Views/executive.php';

function executivePage(array $user): void
{
    executiveNeed($user,'view');
    $filters = executiveFilters($_GET);
    $facilities = executiveFacilities($user);
    $pickers = ['command_id'=>[], 'unit_id'=>[], 'type_id'=>[], 'facility_id'=>[]];
    foreach ($facilities as $row) {
        if ($row['command_id']) {
            $pickers['command_id'][$row['command_id']] = $row['command_name'];
        }
        $pickers['unit_id'][$row['unit_id']] = $row['unit'];
        $pickers['type_id'][$row['type_id']] = $row['kind'];
        $pickers['facility_id'][$row['id']] = $row['name'];
    }
    foreach ($pickers as &$pairs) {
        natcasesort($pairs);
    }
    unset($pairs);
    $pickers['profession_id'] = optionsFor('professions',activeOnly:false);
    $pickers['specialty_id'] = optionsFor('specialties',activeOnly:false);
    $presets = qu('SELECT id,name,settings_json FROM executive_dashboard_preferences WHERE user_id=? ORDER BY name',[$user['id']])->fetchAll();
    $scope = $user['role_code']==='super_admin' ? 'Seluruh cakupan administratif' : count($pickers['unit_id']) . ' satuan berizin';
    $model = tx(static fn () => executiveModel($user,$filters));
    $model['access'] = executiveAccessToken($user);
    $model['facets'] = array_map(static fn(array $row): array => array_intersect_key($row,
        array_fill_keys(['id','unit_id','command_id','type_id'],true)),$facilities);
    $model['session_seconds'] = max(0,$user['expires_at']-time());
    page('Peta Kendali Pimpinan',static fn () => executiveView($user,$model,$pickers,$presets,$scope),$user,EXECUTIVE);
}

function executiveData(array $user): never
{
    executiveNeed($user,'view');
    $filters = executiveFilters($_GET);
    $after = field($_GET,'after',64);
    $data = tx(static function () use ($user,$filters,$after): array {
        $access = executiveAccessToken($user);
        $revision = executiveRevision($access,$filters,$user['id']);
        $session = ['access'=>$access,'revision'=>$revision,'checked_at'=>gmdate('c'),'session_seconds'=>max(0,$user['expires_at']-time())];
        if ($after!=='' && hash_equals($revision,$after)) {
            return $session + ['changed'=>false];
        }
        return $session + ['changed'=>true] + executiveModel($user,$filters);
    });
    jsonResponse($data);
}

function executivePreset(array $user): never
{
    executiveNeed($user,'view');
    $action = choice($_POST,'action',['save','delete']);
    if ($action==='delete') {
        if (!qu('DELETE FROM executive_dashboard_preferences WHERE user_id=? AND id=?',[$user['id'],key32($_POST)])->rowCount()) {
            fail(404,'Preset tidak tersedia.');
        }
        jsonResponse(['deleted'=>true]);
    }
    $name = field($_POST,'name',80,true);
    $prefs = executiveFilters($_POST) + ['display'=>choice($_POST,'display',['map','globe'],'map')];
    $result = tx(static function () use ($user,$name,$prefs): array {
        qu('SELECT id FROM users WHERE id=? FOR UPDATE',[$user['id']]);
        $id = qu('SELECT id FROM executive_dashboard_preferences WHERE user_id=? AND name=?',[$user['id'],$name])->fetchColumn();
        if ($id===false) {
            if ((int) qu('SELECT COUNT(*) FROM executive_dashboard_preferences WHERE user_id=?',[$user['id']])->fetchColumn()>=20) {
                fail(422,'Maksimal 20 preset tersimpan.');
            }
            $id = bin2hex(random_bytes(16));
        }
        $settings = json_encode($prefs,JSON_THROW_ON_ERROR);
        qu('INSERT INTO executive_dashboard_preferences VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json)',
            [$id,$user['id'],$name,$settings]);
        return ['id'=>$id,'name'=>$name,'settings'=>$prefs];
    });
    jsonResponse($result);
}

function executiveSnapshot(array $user): never
{
    executiveNeed($user,'snapshot');
    $count = executiveCapture($user,executiveFilters($_POST));
    done("$count snapshot baru disimpan.",EXECUTIVE);
}

function executiveSourcePage(array $user): void
{
    executiveNeed($user,can($user,'executive.configure') ? 'configure' : 'verify');
    $facilities = executiveFacilitiesSelect($user);
    $id = number($_GET,'facility');
    if ($id) {
        executiveFacility($user,$id);
    }
    page('Sumber penilaian',static fn () => executiveSourceView($user,$facilities,$id),$user,EXECUTIVE);
}

function executiveSourceAction(array $user): never
{
    $id = number($_POST,'facility') ?? fail(422,'Pilih fasilitas.');
    $action = choice($_POST,'action',['rule','target','roster','verify_rule','verify_target','verify_roster','verify_event']);
    executiveConfigAction($user,$id,$action);
    done('Sumber penilaian diperbarui.',EXECUTIVE . '/sumber',['facility'=>$id]);
}

function executiveWorkPage(array $user): void
{
    executiveNeed($user,'work');
    $id = field($_GET,'id',32)==='' ? null : key32($_GET);
    if ($id) {
        $row = executiveDirective($user,$id);
        page('Detail arahan',static fn () => executiveWorkDetail($user,$row),$user,EXECUTIVE . '/arahan');
        return;
    }
    [$scope,$args] = scope($user,'f.unit_id','f.id');
    $private = can($user,'executive.issue') || can($user,'executive.review') ? '' : " AND d.pic_id=? AND d.state<>'draft'";
    if ($private!=='') {
        $args[] = $user['id'];
    }
    $facility = number($_GET,'facility');
    $filter = $facility ? ' AND d.facility_id=?' : '';
    if ($facility) {
        executiveFacility($user,$facility);
        $args[] = $facility;
    }
    [$rows,$total,$current,$per] = limitedRows('FROM executive_directives d JOIN facilities f ON f.id=d.facility_id JOIN users u ON u.id=d.pic_id',
        "f.active=1 AND $scope$private$filter",$args,'d.id,d.title,d.state,d.due_on,d.severity,f.name AS facility,u.name AS pic',
        "FIELD(d.state,'review','issued','accepted','working','draft','done','cancelled'),d.due_on,d.id");
    page('Arahan dan tindak lanjut',static fn () => executiveWorkList($user,$facility,$rows,$total,$current,$per),$user,EXECUTIVE . '/arahan');
}

function executiveWorkSave(array $user): never
{
    $id = executiveSaveDirective($user);
    done('Draf arahan disimpan.',EXECUTIVE . '/arahan',['id'=>$id]);
}

function executiveWorkAction(array $user): never
{
    executiveNeed($user,'work');
    $id = key32($_POST);
    executiveTransition($user,$id);
    done('Arahan diperbarui.',EXECUTIVE . '/arahan',['id'=>$id]);
}

function executiveWorkUpload(array $user): never
{
    executiveNeed($user,'work');
    $id = key32($_POST);
    executiveUpload($user,$id);
    done('Lampiran disimpan.',EXECUTIVE . '/arahan',['id'=>$id]);
}

function executiveWorkDownload(array $user): never
{
    executiveNeed($user,'work');
    $id = key32($_GET);
    $row = executiveDirective($user,$id);
    $file = key32($_GET,'file');
    $doc = qu('SELECT f.* FROM executive_directive_files a JOIN file_objects f ON f.id=a.file_id
        WHERE a.directive_id=? AND a.file_id=?',[$id,$file])->fetch() ?: fail(404,'Lampiran tidak ditemukan.');
    $bytes = readPrivateDocument($doc,"directive:$id");
    audit($user['id'],'download','executive_directives',$id,(int) $row['unit_id']);
    sendPrivateDocument($doc,$bytes);
}

function executivePdf(array $user): never
{
    executiveNeed($user,'export');
    $data = tx(static fn () => executiveModel($user,executiveFilters($_GET)));
    require_once PROJECT . '/private/app/Services/Executive/pdf.php';
    executiveReport($user,$data);
}
