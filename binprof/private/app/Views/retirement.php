<?php
declare(strict_types=1);

require_once PROJECT . '/private/app/Services/Retirement/accounts.php';
require_once PROJECT . '/private/app/Views/personnel_records.php';

function retirementStateLabel(array $row): string
{
    if ($row['state']==='approved' && $row['effective_on'] <= day()) {
        return $row['last_apply_error'] ? 'Perlu peninjauan sebelum penerapan' : 'Jatuh tempo, belum diterapkan';
    }
    return RETIREMENT_STATES[$row['state']] ?? 'Belum ada pengurusan';
}

function retirementPrepareProfile(array $user,array $staff): callable
{
    retirementRequire($user);
    $id = field($_GET,'retirement',32)==='' ? null : key32($_GET,'retirement');
    $row = retirementRow($staff['id'],$id);
    if ($id && !$row) {
        fail(404,'Catatan pensiun tidak ditemukan.');
    }
    return static fn () => retirementProfileView($user,$staff,$row);
}

function retirementPersonStatus(array $staff): string
{
    return !empty($staff['retired_on']) ? 'Purnawirawan' : ($staff['active'] ? 'Aktif' : 'Nonaktif');
}

function retirementProfileView(array $user,array $staff,?array $row): void
{
    $pid = $staff['id'];
    $writable = can($user,'retirement.write') && !$staff['archived_at'] && !$staff['retired_on'];
    $editable = $writable && (!$row || in_array($row['state'],['draft','returned'],true));
    echo '<div class="section-heading"><h2>Pensiun/Purnawirawan</h2>';
    linkButton(RETIREMENT,'Daftar pensiun');
    echo '</div>';
    $effective = ($row['state'] ?? null)==='effective';
    $ranks = $effective ? [] : optionsFor('ranks',activeOnly:false);
    $units = $effective ? [] : optionsFor('units',$user,false);
    $facilities = $effective ? [] : optionsFor('facilities',$user,false);
    definition([
        'NRP'=>$staff['nrp'],'Status dinas'=>retirementPersonStatus($staff),
        'Pangkat terakhir'=>$effective ? $row['last_rank_name'] : ($ranks[$staff['rank_id']] ?? null),
        'Jabatan terakhir'=>$effective ? $row['last_position'] : $staff['position_title'],
        'Satuan terakhir'=>$effective ? $row['last_unit_name'] : ($units[$staff['unit_id']] ?? null),
        'Fasilitas dinas terakhir'=>$effective ? $row['last_facility_name'] : ($facilities[$staff['facility_id']] ?? null),
    ]);
    if ($row) {
        if ($row['rule_id']) {
            $rule = qu("SELECT CONCAT(code,' / ',version_label) FROM retirement_rules WHERE id=?",[$row['rule_id']])->fetchColumn();
        }
        $decree = can($user,'personnel.decrees.read') ? retirementDecree($pid,$row['decree_id']) : null;
        definition([
            'Kategori'=>$row['category'],'Aturan'=>$rule ?? null,'Perkiraan tanggal pensiun'=>$row['planned_on'] ?? 'Belum tersedia',
            'Sumber perkiraan'=>$row['projection_source'],'Perkiraan diperbarui (UTC)'=>$row['projected_at'],
            'Tanggal lahir'=>$row['birth_on'],'Sumber tanggal lahir'=>$row['birth_source'],
            'Verifikasi tanggal lahir'=>$row['birth_verified_at'] ? 'Terverifikasi' : 'Belum terverifikasi',
            'Status proses'=>retirementStateLabel($row),'PIC'=>profileActorName((int) $row['pic_id']),
            'Pemeriksa'=>$row['verified_by'] ? profileActorName((int) $row['verified_by']) : null,
            'Approver'=>$row['approved_by'] ? profileActorName((int) $row['approved_by']) : null,
            'Nomor SK'=>$decree['document_no'] ?? null,'Tanggal SK'=>$decree['decreed_on'] ?? null,
            'Penerbit keputusan'=>$decree['issuer'] ?? null,'Tanggal input (UTC)'=>$row['created_at'],
            'TMT resmi terverifikasi'=>$row['effective_on'],'Penerapan (UTC)'=>$row['effective_at'],
            'Serah terima terakhir'=>$row['handover_on'],'Catatan'=>$row['notes'],
        ]);
        if ($decree && in_array($row['state'],['verified','approved'],true)
            && ((int) $decree['row_version']!==(int) $row['decree_version'] || $decree['archived_at']
            || $decree['document_status']!=='current' || $decree['verification_status']!=='verified')) {
            echo '<p class="empty" role="status">Petikan sumber berubah. Kembalikan pengajuan dan verifikasi ulang sebelum menerapkan.</p>';
        }
        if ($row['last_apply_error']) {
            echo '<p class="empty" role="status">' . e($row['last_apply_error']) . '</p>';
        }
        if ($decree) {
            linkButton(PROFILE_PATH,'Buka Petikan dan kelola berkas sumber',profileQuery($pid,'decrees',['record'=>$decree['id']]));
            profileFileList($user,$pid,'decrees',$decree);
        }
        retirementWorkflowControls($user,$staff,$row,$writable);
        retirementChecklistView($staff,$row,$writable);
        retirementHistoryView($row);
    } else {
        echo '<p class="empty">Belum ada pengurusan atau tanggal pensiun tercatat.</p>';
    }
    if ($editable) {
        retirementProfileForm($user,$staff,$row);
    }
    if (can($user,'retirement.account') && !$staff['archived_at']) {
        retirementAccountView($user,$staff);
    }
    $cancelled = qu("SELECT id,created_at,updated_at FROM personnel_retirements WHERE personnel_id=? AND state='cancelled'
        ORDER BY updated_at DESC LIMIT 30",[$pid])->fetchAll();
    if ($cancelled) {
        echo '<h3>Pengajuan dibatalkan</h3>';
        table(['Dibuat (UTC)','Dibatalkan (UTC)','Aksi'],$cancelled,static function (array $item) use ($pid): void {
            td($item['created_at'],$item['updated_at']);
            echo '<td>';
            linkButton(PROFILE_PATH,'Buka histori',['id'=>$pid,'tab'=>RETIREMENT_TAB,'retirement'=>$item['id']]);
            echo '</td>';
        });
    }
}

function retirementProfileForm(array $user,array $staff,?array $row): void
{
    $pid = $staff['id'];
    $hidden = ['personnel'=>$pid] + ($row ? ['retirement'=>$row['id'],'version'=>$row['row_version']] : []);
    $rules = qu("SELECT id,CONCAT(category,' / ',code,' / ',version_label) FROM retirement_rules
        WHERE verified_at IS NOT NULL ORDER BY category,valid_from DESC,id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $pics = qu("SELECT u.id,u.name FROM users u JOIN role_permissions r ON r.role_code=u.role_code
        AND r.permission_code='retirement.write' WHERE u.active=1 AND (u.role_code='super_admin'
        OR EXISTS (SELECT 1 FROM user_unit_scopes s WHERE s.user_id=u.id AND s.unit_id=?)
        OR EXISTS (SELECT 1 FROM user_facility_scopes s WHERE s.user_id=u.id AND s.facility_id=?))
        ORDER BY u.name,u.id",[(int) $staff['unit_id'],$staff['facility_id'] ? (int) $staff['facility_id'] : null])
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    echo '<details class="entry"' . ($row ? '' : ' open') . '><summary>' . ($row ? 'Ubah data pengurusan' : 'Tambah draf pensiun') . '</summary>';
    echo '<p class="subtle">Hitung perkiraan dari aturan setelah pengelola lain memeriksa tanggal lahir dan sumbernya.</p>';
    postStart(RETIREMENT . '/save',$hidden);
    echo '<div class="form-grid">';
    input('category','Kategori bila tanpa versi aturan',$row['category'] ?? '',max:100);
    selectField('rule_id','Aturan terverifikasi',$rules,$row['rule_id'] ?? null,'Tanpa aturan');
    input('birth_on','Tanggal lahir',$row['birth_on'] ?? '','date');
    input('birth_source','Sumber verifikasi tanggal lahir',$row['birth_source'] ?? '',max:250);
    selectField('projection_method','Dasar perkiraan',['unknown'=>'Belum tersedia','manual'=>'Tanggal dengan sumber','rule'=>'Hitung dari aturan'],
        $row['projection_method'] ?? 'unknown');
    input('planned_on','Tanggal perkiraan manual',$row['planned_on'] ?? '','date');
    input('projection_source','Sumber perkiraan manual',$row['projection_source'] ?? '',max:250);
    selectField('pic_id','PIC',$pics,$row['pic_id'] ?? $user['id']);
    if (can($user,'personnel.decrees.read')) {
        $decrees = qu("SELECT id,CONCAT(document_no,' / ',title) FROM personnel_decree_extracts WHERE personnel_id=?
            AND (archived_at IS NULL OR id=?) ORDER BY decreed_on DESC,id",[$pid,$row['decree_id'] ?? null])
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        selectField('decree_id','Petikan SK',$decrees,$row['decree_id'] ?? null,'Belum ditautkan');
        linkButton(PROFILE_PATH,'Lihat tab Petikan',profileQuery($pid,'decrees'));
    }
    echo '</div>';
    textArea('notes','Catatan',$row['notes'] ?? '');
    echo '<button>Simpan</button></form></details>';
}

function retirementWorkflowControls(array $user,array $staff,array $row,bool $writable): void
{
    if ($staff['archived_at'] || $staff['retired_on']) {
        return;
    }
    $state = $row['state'];
    $editable = in_array($state,['draft','returned'],true);
    $hid = ['personnel'=>$staff['id'],'retirement'=>$row['id'],'version'=>$row['row_version']];
    $verify = can($user,'retirement.verify');
    $approve = can($user,'retirement.approve');
    echo '<div class="actions">';
    if ($editable && $writable) {
        postButton(RETIREMENT . '/decision','Ajukan',$hid + ['action'=>'submit']);
    }
    if ($editable && $verify && $row['birth_on'] && !$row['birth_verified_at']
        && $user['id']!==(int) $row['birth_entered_by']) {
        postButton(RETIREMENT . '/birth','Verifikasi tanggal lahir',$hid);
    }
    if ($state==='submitted' && $verify) {
        postButton(RETIREMENT . '/decision','Verifikasi Petikan sebagai SK pensiun',$hid + ['action'=>'verify']);
    }
    if ($state==='verified' && $approve) {
        postButton(RETIREMENT . '/decision','Setujui dan jadwalkan',$hid + ['action'=>'approve']);
    }
    if ($state==='approved' && can($user,'retirement.apply')) {
        postButton(RETIREMENT . '/process','Terapkan jika jatuh tempo',$hid);
    }
    echo '</div>';
    if (in_array($state,['submitted','verified','approved'],true) && retirementCanDecide($user,'return',$state)) {
        retirementReasonForm($hid,'return','Kembalikan untuk perbaikan');
    }
    if (!in_array($state,['effective','cancelled'],true) && retirementCanDecide($user,'cancel',$state)) {
        retirementReasonForm($hid,'cancel','Batalkan pengajuan');
    }
}

function retirementReasonForm(array $hidden,string $action,string $label): void
{
    echo '<details class="entry"><summary>' . e($label) . '</summary>';
    postStart(RETIREMENT . '/decision',$hidden + ['action'=>$action]);
    input('reason','Alasan',required:true,max:250);
    echo '<button>Konfirmasi</button></form></details>';
}

function retirementChecklistView(array $staff,array $row,bool $writable): void
{
    $items = qu('SELECT c.*,u.name AS actor FROM personnel_retirement_checklists c LEFT JOIN users u ON u.id=c.completed_by
        WHERE c.retirement_id=? ORDER BY c.item_type,c.required DESC,c.id',[$row['id']])->fetchAll();
    echo '<h3>Checklist dokumen dan serah terima</h3>';
    table(['Butir','Jenis','Syarat','Selesai','Petugas','Catatan'],$items,static function (array $item): void {
        td($item['label'],$item['item_type']==='document' ? 'Dokumen' : 'Serah terima',$item['required'] ? 'Wajib' : 'Opsional',
            $item['completed_on'],$item['actor'],$item['note']);
    });
    if (!$writable || in_array($row['state'],['cancelled','effective'],true)) {
        return;
    }
    foreach ($items as $item) {
        if ($item['item_type']==='document' && !in_array($row['state'],['draft','returned','submitted'],true)) {
            continue;
        }
        echo '<details class="entry"><summary>' . e('Ubah checklist · ' . $item['label']) . '</summary>';
        postStart(RETIREMENT . '/checklist',['personnel'=>$staff['id'],'retirement'=>$row['id'],
            'version'=>$row['row_version'],'item'=>$item['id']]);
        input('completed_on','Tanggal selesai',$item['completed_on'] ?? '','date');
        input('item_note','Catatan',$item['note'] ?? '',max:250);
        echo '<button>Simpan checklist</button></form></details>';
    }
}

function retirementHistoryView(array $row): void
{
    echo '<h3>Riwayat perubahan</h3>';
    $rows = qu('SELECT v.*,u.name AS actor FROM personnel_retirement_versions v LEFT JOIN users u ON u.id=v.actor_id
        WHERE v.retirement_id=? ORDER BY v.id DESC LIMIT 100',[$row['id']])->fetchAll();
    $actions = ['create'=>'Draf dibuat','edit'=>'Data disimpan','birth_verified'=>'Tanggal lahir diperiksa',
        'submit'=>'Diajukan','verify'=>'Keputusan diverifikasi','approve'=>'Disetujui','return'=>'Dikembalikan',
        'cancel'=>'Dibatalkan','checklist'=>'Checklist diubah','apply'=>'Diterapkan'];
    table(['Tindakan','Status','Aktor','Waktu (UTC)','Perkiraan','TMT','Alasan'],$rows,
        static fn (array $item) => td($actions[$item['action']],RETIREMENT_STATES[$item['state']],
            $item['actor'] ?? 'Proses terjadwal',$item['created_at'],$item['planned_on'],$item['effective_on'],$item['reason']));
}

function retirementAccountView(array $user,array $staff): void
{
    $pid = $staff['id'];
    $link = retirementExistingAccount($pid);
    echo '<h3>Akun terkait</h3>';
    if (!$link && !$staff['retired_on']) {
        echo '<p>Tautkan akun dengan bukti verifikasi identitas melalui email akun yang telah diperiksa.</p>';
        postStart(RETIREMENT . '/account',['action'=>'link','personnel'=>$pid]);
        input('email','Email akun terverifikasi',type:'email',required:true,max:254);
        input('reference','Referensi pemeriksaan hubungan akun',required:true,max:250);
        echo '<button>Catat tautan terverifikasi</button></form>';
    } elseif ($link) {
        definition(['Akun'=>$link['email'],'Verifikator'=>profileActorName((int) $link['verified_by']),
            'Referensi'=>$link['verification_reference'],'Waktu verifikasi (UTC)'=>$link['verified_at'],
            'Akun aktif'=>$link['active'] ? 'Ya' : 'Tidak']);
        if ($staff['retired_on']) {
            $assignment = qu('SELECT a.expires_on,a.authorization_reference,u.name AS unit,f.name AS facility
                FROM retirement_assignments a LEFT JOIN units u ON u.id=a.unit_id LEFT JOIN facilities f ON f.id=a.facility_id
                WHERE a.user_id=? AND a.active_slot=1',[(int) $link['user_id']])->fetch();
            if ($assignment) {
                definition(['Dasar penugasan'=>$assignment['authorization_reference'],'Batas berlaku'=>$assignment['expires_on'],
                    'Scope penugasan'=>$assignment['unit'] ?? $assignment['facility']]);
                postButton(RETIREMENT . '/account','Cabut penugasan',['action'=>'revoke','personnel'=>$pid]);
            }
            echo '<details class="entry"><summary>Tetapkan penugasan pascapensiun</summary>';
            postStart(RETIREMENT . '/account',['action'=>'grant','personnel'=>$pid]);
            echo '<p>Penugasan berlaku sebagai Viewer pada satu cakupan dan tanggal berakhir yang ditetapkan.</p>';
            selectField('unit_id','Satuan',optionsFor('units',$user),blank:'Pilih bila scope satuan');
            selectField('facility_id','Fasilitas',optionsFor('facilities',$user),blank:'Pilih bila scope fasilitas');
            input('reference','Dasar penugasan',required:true,max:250);
            input('expires_on','Berakhir',type:'date',required:true);
            echo '<button>Tetapkan penugasan</button></form></details>';
        }
    }
}
