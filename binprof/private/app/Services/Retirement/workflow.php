<?php
declare(strict_types=1);

require_once __DIR__ . '/policy.php';
require_once PROJECT . '/private/app/Controllers/Personnel.php';
require_once PROJECT . '/private/app/Services/PersonnelRecords/catalog.php';

function retirementScopedActor(int $id,array $person,string $operation): ?array
{
    $actor = qu('SELECT id,role_code FROM users WHERE id=? AND active=1',[$id])->fetch();
    if (!$actor) {
        return null;
    }
    $actor = withPermissions($actor);
    [$where,$args] = scope($actor,'p.unit_id','p.facility_id');
    return can($actor,'retirement.' . $operation) && qu("SELECT 1 FROM personnel p WHERE p.id=? AND $where",
        [$person['id'],...$args])->fetchColumn() ? $actor : null;
}

function retirementCheckPerson(array $staff): void
{
    if ($staff['archived_at'] || $staff['retired_on']) {
        fail(409,'Personel berada dalam arsip atau sudah resmi pensiun.');
    }
}

function retirementDecree(string $personnel,?string $id,bool $verified = false,bool $lock = false): ?array
{
    if ($id === null) {
        if ($verified) {
            fail(422,'Tautkan Petikan keputusan pensiun yang terverifikasi.');
        }
        return null;
    }
    $row = qu('SELECT * FROM personnel_decree_extracts WHERE id=? AND personnel_id=?' . ($lock ? ' FOR UPDATE' : ''),
        [$id,$personnel])->fetch();
    if (!$row) {
        fail(404,'Petikan tidak ditemukan.');
    }
    if ($verified && ($row['archived_at'] || $row['document_status']!=='current'
        || $row['verification_status']!=='verified' || !$row['effective_on'])) {
        fail(409,'Petikan harus aktif, terverifikasi, dan mencantumkan TMT resmi.');
    }
    return $row;
}

function retirementSaveRow(array $user,string $personnel,?string $id): void
{
    tx(static function () use ($user,$personnel,$id): void {
        $staff = person($user,$personnel,true);
        retirementCheckPerson($staff);
        $prior = retirementRow($personnel,$id,true);
        if ($id && !$prior) {
            fail(404,'Catatan pensiun tidak ditemukan.');
        }
        if ((!$id && $prior) || ($prior && !in_array($prior['state'],['draft','returned'],true))) {
            fail(409,'Perubahan hanya tersedia pada draf atau catatan yang dikembalikan.');
        }
        $version = $prior ? retirementVersion($prior) : 1;
        $method = choice($_POST,'projection_method',['unknown','manual','rule']);
        $ruleId = number($_POST,'rule_id');
        $rule = $ruleId ? qu('SELECT * FROM retirement_rules WHERE id=? AND verified_at IS NOT NULL',[$ruleId])->fetch() : null;
        if ($ruleId && !$rule) {
            fail(422,'Pilih aturan pensiun terverifikasi.');
        }
        $birth = calendar($_POST,'birth_on');
        $birthSource = field($_POST,'birth_source',250);
        if ($birth && ($birth > day() || $birthSource==='')) {
            fail(422,'Tanggal lahir membutuhkan sumber dan tidak boleh di masa depan.');
        }
        $birthChanged = $birth !== ($prior['birth_on'] ?? null) || $birthSource !== ($prior['birth_source'] ?? '');
        $planned = calendar($_POST,'planned_on');
        $source = field($_POST,'projection_source',250);
        $category = $rule ? $rule['category'] : field($_POST,'category',100,true);
        if ($method==='rule') {
            if (!$rule || !$birth || $birthChanged || !$prior['birth_verified_at'] || $rule['valid_from']>day()
                || ($rule['valid_until'] && $rule['valid_until']<day())) {
                fail(422,'Proyeksi memerlukan tanggal lahir terverifikasi dan aturan yang berlaku saat perhitungan.');
            }
            $planned = retirementMonths($birth,(int) $rule['age_months']);
            $source = $rule['code'] . ' / ' . $rule['version_label'] . ' · ' . $rule['source_reference'];
            $source = mb_substr($source,0,250);
        } elseif ($method==='manual' && (!$planned || $source==='')) {
            fail(422,'Perkiraan manual memerlukan tanggal dan sumber.');
        } elseif ($method==='unknown') {
            $planned = null;
            $source = '';
        }
        $pic = number($_POST,'pic_id') ?? fail(422,'Pilih PIC pengurusan.');
        if (!retirementScopedActor($pic,$staff,'write')) {
            fail(422,'PIC harus memiliki kewenangan aktif dalam cakupan personel.');
        }
        $decree = ($prior['decree_id'] ?? null);
        if (can($user,'personnel.decrees.read')) {
            $decree = field($_POST,'decree_id',32) === '' ? null : key32($_POST,'decree_id');
        } elseif (field($_POST,'decree_id',32) !== '') {
            fail(403,'Izin membaca Petikan diperlukan.');
        }
        retirementDecree($personnel,$decree);
        $notes = field($_POST,'notes',4000);
        $now = gmdate('Y-m-d H:i:s');
        $data = [$category,$ruleId,$method,$planned,$source ?: null,$planned ? $now : null,$birth,$birthSource ?: null,
            $birthChanged && $birth ? $user['id'] : ($birth ? ($prior['birth_entered_by'] ?? null) : null),
            $birthChanged ? null : ($prior['birth_verified_by'] ?? null),
            $birthChanged ? null : ($prior['birth_verified_at'] ?? null),$decree,$pic,$notes];
        if ($prior) {
            $scheduleChanged = $planned !== $prior['planned_on'] || $pic !== (int) $prior['pic_id'];
            qu('UPDATE personnel_retirements SET category=?,rule_id=?,projection_method=?,planned_on=?,projection_source=?,
                projected_at=?,birth_on=?,birth_source=?,birth_entered_by=?,birth_verified_by=?,birth_verified_at=?,decree_id=?,
                pic_id=?,notes=?,updated_by=?,updated_at=UTC_TIMESTAMP(),row_version=?,reminder_version=reminder_version+? WHERE id=?',
                [...$data,$user['id'],$version,(int) $scheduleChanged,$id]);
            if ($scheduleChanged) {
                retirementInvalidateNotifications($id);
            }
        } else {
            $id = bin2hex(random_bytes(16));
            qu('INSERT INTO personnel_retirements (category,rule_id,projection_method,planned_on,projection_source,projected_at,
                birth_on,birth_source,birth_entered_by,birth_verified_by,birth_verified_at,decree_id,pic_id,notes,id,personnel_id,
                created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [...$data,$id,$personnel,$user['id'],$user['id']]);
            qu('INSERT INTO personnel_retirement_checklists (retirement_id,template_id,label,item_type,required)
                SELECT ?,id,label,item_type,required FROM retirement_checklist_templates WHERE active=1',[$id]);
        }
        if ($decree && $decree!==($prior['decree_id'] ?? null)) {
            qu('INSERT INTO retirement_document_links (retirement_id,decree_id,linked_by) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE linked_by=VALUES(linked_by),linked_at=UTC_TIMESTAMP()',[$id,$decree,$user['id']]);
        }
        retirementHistory($id,$user['id'],$prior ? 'edit' : 'create');
    });
}

function retirementVerifyBirth(array $user,string $personnel,string $id): void
{
    tx(static function () use ($user,$personnel,$id): void {
        retirementCheckPerson(person($user,$personnel,true));
        $row = retirementRow($personnel,$id,true) ?? fail(404,'Catatan pensiun tidak ditemukan.');
        $version = retirementVersion($row);
        if (!in_array($row['state'],['draft','returned'],true) || !$row['birth_on'] || !$row['birth_source']
            || $row['birth_verified_at'] || $user['id']===(int) $row['birth_entered_by']) {
            fail(409,'Pemeriksaan tanggal lahir membutuhkan draf, sumber, dan pemeriksa lain.');
        }
        qu('UPDATE personnel_retirements SET birth_verified_by=?,birth_verified_at=UTC_TIMESTAMP(),row_version=?,
            updated_at=UTC_TIMESTAMP() WHERE id=?',[$user['id'],$version,$id]);
        retirementHistory($id,$user['id'],'birth_verified');
    });
}

function retirementChecklistDone(string $id,string $type): bool
{
    return !qu('SELECT 1 FROM personnel_retirement_checklists WHERE retirement_id=? AND item_type=?
        AND required=1 AND completed_on IS NULL LIMIT 1',[$id,$type])->fetchColumn();
}

function retirementChecklistSave(array $user,string $personnel,string $id): void
{
    tx(static function () use ($user,$personnel,$id): void {
        retirementCheckPerson(person($user,$personnel,true));
        $row = retirementRow($personnel,$id,true) ?? fail(404,'Catatan pensiun tidak ditemukan.');
        $version = retirementVersion($row);
        $itemId = number($_POST,'item') ?? fail(422,'Butir checklist diperlukan.');
        $item = qu('SELECT * FROM personnel_retirement_checklists WHERE id=? AND retirement_id=? FOR UPDATE',[$itemId,$id])->fetch();
        if (!$item || in_array($row['state'],['effective','cancelled'],true)
            || ($item['item_type']==='document' && !in_array($row['state'],['draft','returned','submitted'],true))) {
            fail(409,'Butir ini tidak dapat diubah pada tahap sekarang.');
        }
        $complete = calendar($_POST,'completed_on');
        $note = field($_POST,'item_note',250);
        if ($complete && $complete>day()) {
            fail(422,'Tanggal penyelesaian tidak boleh di masa depan.');
        }
        qu('UPDATE personnel_retirement_checklists SET completed_on=?,completed_by=?,note=? WHERE id=?',
            [$complete,$complete ? $user['id'] : null,$note,$itemId]);
        $handover = $item['item_type']==='handover'
            ? qu("SELECT MAX(completed_on) FROM personnel_retirement_checklists WHERE retirement_id=? AND item_type='handover'",[$id])->fetchColumn()
            : $row['handover_on'];
        qu('UPDATE personnel_retirements SET handover_on=?,row_version=?,updated_at=UTC_TIMESTAMP() WHERE id=?',[$handover,$version,$id]);
        retirementHistory($id,$user['id'],'checklist',$item['label']);
    });
}

function retirementCheckDecreeVersion(array $row,array $decree): void
{
    if ((int) $row['decree_version'] !== (int) $decree['row_version'] || $row['effective_on'] !== $decree['effective_on']) {
        fail(409,'Petikan berubah. Kembalikan dan verifikasi ulang keputusan.');
    }
}

function retirementTransition(array $user,string $personnel,string $id,string $action): void
{
    tx(static function () use ($user,$personnel,$id,$action): void {
        $staff = person($user,$personnel,true);
        retirementCheckPerson($staff);
        $row = retirementRow($personnel,$id,true) ?? fail(404,'Catatan pensiun tidak ditemukan.');
        $version = retirementVersion($row);
        $state = $row['state'];
        $reason = field($_POST,'reason',250,in_array($action,['return','cancel'],true));
        $actor = $user['id'];
        $extra = '';
        $args = [];
        if ($action==='submit' && in_array($state,['draft','returned'],true)) {
            $next = 'submitted';
            $extra = ',submitted_by=?,verified_by=NULL,verified_at=NULL,approved_by=NULL,approved_at=NULL,effective_on=NULL,decree_version=NULL';
            $args = [$actor];
        } elseif ($action==='verify' && $state==='submitted') {
            if ($actor===(int) $row['submitted_by'] || $actor===(int) $row['updated_by']
                || !retirementChecklistDone($id,'document')) {
                fail(409,'Verifikasi memerlukan pemeriksa lain dan dokumen wajib lengkap.');
            }
            $decree = retirementDecree($personnel,$row['decree_id'],true,true);
            $next = 'verified';
            $extra = ',verified_by=?,verified_at=UTC_TIMESTAMP(),effective_on=?,decree_version=?';
            $args = [$actor,$decree['effective_on'],(int) $decree['row_version']];
        } elseif ($action==='approve' && $state==='verified') {
            if (in_array($actor,[(int) $row['submitted_by'],(int) $row['updated_by'],(int) $row['verified_by']],true)) {
                fail(409,'Approver harus berbeda dari pengaju, pengubah, dan pemeriksa.');
            }
            retirementCheckDecreeVersion($row,retirementDecree($personnel,$row['decree_id'],true,true));
            $next = 'approved';
            $extra = ',approved_by=?,approved_at=UTC_TIMESTAMP()';
            $args = [$actor];
        } elseif ($action==='return' && in_array($state,['submitted','verified','approved'],true)) {
            if ($state==='approved' && !can($user,'retirement.approve')) {
                fail(403,'Pengembalian keputusan disetujui memerlukan izin persetujuan.');
            }
            $next = 'returned';
            $extra = ',verified_by=NULL,verified_at=NULL,approved_by=NULL,approved_at=NULL,effective_on=NULL,decree_version=NULL';
        } elseif ($action==='cancel' && !in_array($state,['effective','cancelled'],true)) {
            if (!in_array($state,['draft','returned'],true) && !can($user,'retirement.approve')) {
                fail(403,'Pembatalan setelah diajukan memerlukan izin persetujuan.');
            }
            $next = 'cancelled';
            $extra = ',active_slot=NULL';
        } else {
            fail(409,'Perpindahan tahap pensiun tidak berlaku.');
        }
        $reminderChanged = $state==='approved' || in_array($next,['approved','cancelled'],true);
        qu("UPDATE personnel_retirements SET state=?,row_version=?,reminder_version=reminder_version+?,
            updated_at=UTC_TIMESTAMP(),last_apply_checked_at=NULL,last_apply_error=NULL$extra WHERE id=?",[$next,$version,(int) $reminderChanged,...$args,$id]);
        if ($reminderChanged) {
            retirementInvalidateNotifications($id);
        }
        retirementHistory($id,$actor,$action,$reason ?: null);
    });
}
