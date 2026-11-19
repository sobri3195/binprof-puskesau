<?php
declare(strict_types=1);

require_once __DIR__ . '/policy.php';
require_once PROJECT . '/private/app/Services/documents.php';
require_once PROJECT . '/private/app/Services/jobs.php';

function executiveDirective(array $user,string $id,bool $lock=false): array
{
    [$scope,$args] = scope($user,'f.unit_id','f.id');
    $private = can($user,'executive.review') || can($user,'executive.issue') ? '' : ' AND d.pic_id=? AND d.state<>\'draft\'';
    if ($private!=='') {
        $args[] = $user['id'];
    }
    $row = qu("SELECT d.*,f.unit_id,f.name AS facility,u.name AS pic,rr.read_at FROM executive_directives d
        JOIN facilities f ON f.id=d.facility_id JOIN users u ON d.pic_id=u.id
        LEFT JOIN executive_directive_reads rr ON rr.directive_id=d.id AND rr.user_id=?
        WHERE d.id=? AND f.active=1 AND $scope$private" . ($lock ? ' FOR UPDATE' : ''),[$user['id'],$id,...$args])->fetch();
    return $row ?: fail(404,'Arahan tidak tersedia.');
}

function executiveLogDirective(array $user,array $row,string $action,?string $from,?string $note=null): void
{
    qu('INSERT INTO executive_directive_updates (directive_id,actor_id,row_version,action,from_state,to_state,note) VALUES (?,?,?,?,?,?,?)',
        [$row['id'],$user['id'],$row['row_version'],$action,$from,$row['state'],$note]);
    audit($user['id'],'executive_' . $action,'executive_directives',$row['id'],(int) $row['unit_id']);
}

function executiveVersion(array $row): int
{
    if ((int) $row['row_version'] !== number($_POST,'version')) {
        fail(409,'Arahan telah berubah. Muat ulang terlebih dahulu.');
    }
    return (int) $row['row_version']+1;
}

function executiveSaveDirective(array $user): string
{
    executiveNeed($user,'issue');
    $id = field($_POST,'id',32)==='' ? null : key32($_POST);
    $facilityId = number($_POST,'facility') ?? fail(422,'Fasilitas diperlukan.');
    $pic = number($_POST,'pic') ?? fail(422,'PIC diperlukan.');
    $title = field($_POST,'title',160,true);
    $instruction = field($_POST,'instruction',4000,true);
    $kind = choice($_POST,'kind',array_keys(EXEC_ISSUES),'general');
    if ($kind==='directive') {
        fail(422,'Pilih kategori masalah sumber.');
    }
    $severity = choice($_POST,'severity',array_keys(MAP_SEVERITIES));
    $due = calendar($_POST,'due_on');
    if (!$due || $due<day()) {
        fail(422,'Tenggat hari ini atau tanggal mendatang diperlukan.');
    }
    $reference = $kind==='retirement' ? field($_POST,'plan_reference',250) : '';
    return tx(static function () use ($user,$id,$facilityId,$pic,$title,$instruction,$kind,$severity,$due,$reference): string {
        qu('SELECT id FROM application_state WHERE id=1 FOR UPDATE');
        $facility = executiveFacility($user,$facilityId,true);
        if (!executiveOperator($pic,$facility)) {
            fail(422,'PIC aktif dengan kewenangan dan cakupan fasilitas diperlukan.');
        }
        $values = [$title,$instruction,$kind,$severity,$due,$pic,$reference ?: null];
        if ($id) {
            $row = executiveDirective($user,$id,true);
            if ($row['state']!=='draft' || (int) $row['created_by']!==$user['id'] || (int) $row['facility_id']!==$facilityId) {
                fail(409,'Perubahan isi hanya tersedia untuk draf milik pembuat.');
            }
            $version = executiveVersion($row);
            qu('UPDATE executive_directives SET title=?,instruction=?,kind=?,severity=?,due_on=?,pic_id=?,plan_reference=?,
                row_version=?,updated_at=UTC_TIMESTAMP() WHERE id=?',[...$values,$version,$id]);
        } else {
            $id = bin2hex(random_bytes(16));
            $version = 1;
            qu('INSERT INTO executive_directives (title,instruction,kind,severity,due_on,pic_id,plan_reference,id,facility_id,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)',[...$values,$id,$facilityId,$user['id']]);
        }
        executiveLogDirective($user,['id'=>$id,'unit_id'=>$facility['unit_id'],'state'=>'draft','row_version'=>$version],
            $version===1 ? 'draft' : 'edit',$version===1 ? null : 'draft');
        return $id;
    });
}

function executiveDirectiveActions(array $user,array $row): array
{
    $id = $user['id'];
    $isPic = (int) $row['pic_id']===$id && can($user,'executive.work');
    $issuer = can($user,'executive.issue') && (int) $row['created_by']===$id;
    $reviewer = can($user,'executive.review') && !$isPic;
    return match($row['state']) {
        'draft'=>$issuer ? ['issue','cancel'] : [],
        'issued'=>[...($isPic ? ['accept'] : []),...($issuer || $reviewer ? ['cancel'] : [])],
        'accepted'=>[...($isPic ? ['work'] : []),...($reviewer ? ['cancel'] : [])],
        'working'=>[...($isPic ? ['submit'] : []),...($reviewer ? ['cancel'] : [])],
        'review'=>$reviewer ? ['finish','return','cancel'] : [],
        default=>[],
    };
}

function executiveTransition(array $user,string $id): void
{
    $action = choice($_POST,'action',['read','issue','accept','work','submit','finish','return','cancel','reassign']);
    $notes = field($_POST,'note',1000,in_array($action,['return','cancel','reassign'],true));
    tx(static function () use ($user,$id,$action,$notes): void {
        qu('SELECT id FROM application_state WHERE id=1 FOR UPDATE');
        $row = executiveDirective($user,$id,true);
        if ($action==='read') {
            if (qu('INSERT IGNORE INTO executive_directive_reads (directive_id,user_id) VALUES (?,?)',[$id,$user['id']])->rowCount()) {
                audit($user['id'],'executive_read','executive_directives',$id,(int) $row['unit_id']);
            }
            return;
        }
        $version = executiveVersion($row);
        $facility = ['id'=>$row['facility_id']];
        if ($action==='reassign') {
            if (!can($user,'executive.review') || in_array($row['state'],['draft','done','cancelled'],true)) {
                fail(403,'Perubahan PIC tidak tersedia.');
            }
            $pic = number($_POST,'pic') ?? fail(422,'PIC diperlukan.');
            if (!executiveOperator($pic,$facility) || $pic===(int) $row['pic_id']) {
                fail(422,'Pilih PIC lain yang berwenang.');
            }
            $state = 'issued';
        } else {
            if (!in_array($action,executiveDirectiveActions($user,$row),true)) {
                fail(403,'Tahap atau kewenangan arahan tidak sesuai.');
            }
            $pic = (int) $row['pic_id'];
            if ($action==='issue' && (!executiveOperator($pic,$facility) || $row['due_on']<day())) {
                fail(409,'Periksa tenggat dan mandat PIC sebelum menerbitkan.');
            }
            $state = match($action) {
                'issue'=>'issued','accept'=>'accepted','work','return'=>'working','submit'=>'review','finish'=>'done','cancel'=>'cancelled',
            };
        }
        qu('UPDATE executive_directives SET state=?,pic_id=?,row_version=?,updated_at=UTC_TIMESTAMP() WHERE id=?',[$state,$pic,$version,$id]);
        if ($action==='reassign' || $action==='cancel') {
            qu('UPDATE notifications SET cancelled_at=UTC_TIMESTAMP() WHERE user_id=? AND event_key LIKE ? AND cancelled_at IS NULL',
                [(int) $row['pic_id'],"executive:$id:%"]);
        }
        $recipient = in_array($action,['submit','finish'],true) ? (int) $row['created_by'] : $pic;
        notify($recipient,"executive:$id:$version",'Ada pembaruan tindak lanjut pada portal BINPROFKES.');
        executiveLogDirective($user,array_replace($row,['state'=>$state,'row_version'=>$version]),$action,$row['state'],$notes ?: null);
    });
}

function executiveUpload(array $user,string $id): void
{
    executiveDirective($user,$id);
    $upload = $_FILES['attachment'] ?? [];
    if (!is_array($upload)) {
        fail(422,'Lampiran diperlukan.');
    }
    storePrivateDocument($upload,"directive:$id",static function (string $file) use ($user,$id): void {
        $row = executiveDirective($user,$id,true);
        $author = $row['state']==='draft' && (int) $row['created_by']===$user['id'] && can($user,'executive.issue');
        $worker = in_array($row['state'],['accepted','working'],true) && (int) $row['pic_id']===$user['id'] && can($user,'executive.work');
        if (!$author && !$worker) {
            fail(403,'Unggah hanya tersedia bagi pembuat draf atau PIC pada tahap kerja.');
        }
        $version = executiveVersion($row);
        qu('INSERT INTO executive_directive_files VALUES (?,?,?)',[$id,$file,$user['id']]);
        qu('UPDATE executive_directives SET row_version=?,updated_at=UTC_TIMESTAMP() WHERE id=?',[$version,$id]);
        executiveLogDirective($user,array_replace($row,['row_version'=>$version]),'attach',$row['state']);
    });
}
