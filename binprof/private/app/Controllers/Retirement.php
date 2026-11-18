<?php
declare(strict_types=1);

require_once PROJECT . '/private/app/Services/Retirement/jobs.php';
require_once PROJECT . '/private/app/Views/retirement.php';

function retirementAction(array $user,string $operation): array
{
    retirementRequire($user,'read');
    retirementRequire($user,$operation);
    $personnel = key32($_POST,'personnel');
    person($user,$personnel);
    $id = field($_POST,'retirement',32) === '' ? null : key32($_POST,'retirement');
    return [$personnel,$id];
}

function retirementDone(string $personnel,string $message): never
{
    done($message,'/app/personel/detail',['id'=>$personnel,'tab'=>RETIREMENT_TAB]);
}

function retirementSave(array $user): never
{
    [$personnel,$id] = retirementAction($user,'write');
    retirementSaveRow($user,$personnel,$id);
    retirementDone($personnel,'Data pensiun disimpan.');
}

function retirementDecision(array $user): never
{
    $operations = ['submit'=>'write','verify'=>'verify','approve'=>'approve','return'=>'verify','cancel'=>'write'];
    $action = choice($_POST,'action',array_keys($operations));
    [$personnel,$id] = retirementAction($user,$operations[$action]);
    retirementTransition($user,$personnel,$id ?? fail(422,'Catatan diperlukan.'),$action);
    retirementDone($personnel,'Tahap pensiun diperbarui.');
}

function retirementBirth(array $user): never
{
    [$personnel,$id] = retirementAction($user,'verify');
    retirementVerifyBirth($user,$personnel,$id ?? fail(422,'Catatan diperlukan.'));
    retirementDone($personnel,'Tanggal lahir diverifikasi.');
}

function retirementChecklist(array $user): never
{
    [$personnel,$id] = retirementAction($user,'write');
    retirementChecklistSave($user,$personnel,$id ?? fail(422,'Catatan diperlukan.'));
    retirementDone($personnel,'Checklist diperbarui.');
}

function retirementApply(array $user): never
{
    [$personnel,$id] = retirementAction($user,'apply');
    if (!$id || !retirementRow($personnel,$id)) {
        fail(404,'Catatan pensiun tidak ditemukan.');
    }
    $applied = retirementApplyOne($user,$id,number($_POST,'version') ?? fail(422,'Versi catatan diperlukan.'));
    retirementDone($personnel,$applied ? 'Pensiun efektif diterapkan.' : 'Catatan belum jatuh tempo atau sudah diproses.');
}

function retirementAccount(array $user): never
{
    [$personnel] = retirementAction($user,'account');
    $action = choice($_POST,'action',['link','grant','revoke']);
    match($action) {
        'link' => retirementLinkAccount($user,$personnel),
        'grant' => retirementGrantAssignment($user,$personnel),
        'revoke' => retirementRevokeAssignment($user,$personnel),
    };
    retirementDone($personnel,'Akses akun diperbarui.');
}

function retirementPreference(array $user): never
{
    retirementRequire($user);
    $enabled = (int) choice($_POST,'enabled',['0','1']);
    $months = (int) choice($_POST,'months',array_map('strval',array_keys(RETIREMENT_PERIODS)));
    tx(static function () use ($user,$enabled,$months): void {
        qu('INSERT INTO retirement_reminder_preferences (user_id,enabled,months) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),months=VALUES(months)',[$user['id'],$enabled,$months]);
        qu("UPDATE notifications SET cancelled_at=UTC_TIMESTAMP() WHERE user_id=? AND event_key LIKE 'retirement:%'
            AND cancelled_at IS NULL AND (?=0 OR SUBSTRING_INDEX(event_key,':',-1)<>?)",[$user['id'],$enabled,(string) $months]);
    });
    done('Preferensi pengingat disimpan.',RETIREMENT);
}

function retirementList(array $user): void
{
    retirementRequire($user);
    [$scope,$args] = scope($user,'p.unit_id','p.facility_id');
    $filters = [$scope,'p.archived_at IS NULL'];
    $group = choice($_GET,'group',['all','near','processing','approved','effective','unknown','followup'],'all');
    $months = (int) choice($_GET,'months',array_map('strval',array_keys(RETIREMENT_PERIODS)),'12');
    $to = retirementMonths(day(),$months);
    $filter = match($group) {
        'near'=>"p.active=1 AND CASE WHEN r.state='approved' THEN d.effective_on ELSE r.planned_on END BETWEEN ? AND ?",
        'processing'=>"r.state IN ('draft','submitted','verified','returned')",
        'approved'=>"r.state='approved'",
        'effective'=>"r.state='effective'",
        'unknown'=>"p.retired_on IS NULL AND COALESCE(r.effective_on,r.planned_on) IS NULL",
        'followup'=>"r.state IN ('submitted','verified','returned') OR (r.state='approved' AND (r.effective_on<=? OR d.id IS NULL OR r.last_apply_error IS NOT NULL))",
        default=>'1=1',
    };
    $filters[] = '(' . $filter . ')';
    if ($group==='near') {
        array_push($args,day(),$to);
    } elseif ($group==='followup') {
        $args[] = day();
    }
    if (($q = field($_GET,'q',100)) !== '') {
        $filters[] = '(LOCATE(?,p.name)>0 OR LOCATE(?,p.nrp)>0)';
        array_push($args,$q,$q);
    }
    if (($facility = number($_GET,'facility'))!==null) {
        $filters[] = 'p.facility_id=?';
        $args[] = $facility;
    }
    if (($profession = number($_GET,'profession_id'))!==null) {
        $filters[] = 'p.profession_id=?';
        $args[] = $profession;
    }
    if (($specialty = number($_GET,'specialty_id'))!==null) {
        $filters[] = 'EXISTS (SELECT 1 FROM personnel_specialties s WHERE s.personnel_id=p.id AND s.specialty_id=?)';
        $args[] = $specialty;
    }
    [$rows,$total,$current,$per] = limitedRows('FROM personnel p JOIN units u ON u.id=p.unit_id
        LEFT JOIN personnel_retirements r ON r.personnel_id=p.id AND r.active_slot=1
        LEFT JOIN personnel_decree_extracts d ON d.id=r.decree_id AND d.personnel_id=p.id AND d.archived_at IS NULL
          AND d.document_status=\'current\' AND d.verification_status=\'verified\'
          AND d.row_version=r.decree_version AND d.effective_on=r.effective_on',implode(' AND ',$filters),$args,
        'p.id,p.name,p.active,p.retired_on,u.name AS unit,r.state,r.planned_on,r.effective_on,r.last_apply_error',
        'COALESCE(r.effective_on,r.planned_on) IS NULL,COALESCE(r.effective_on,r.planned_on),p.name,p.id');
    $pref = qu('SELECT enabled,months FROM retirement_reminder_preferences WHERE user_id=?',[$user['id']])->fetch();
    page('Pensiun/Purnawirawan',static function () use ($user,$group,$months,$rows,$total,$current,$per,$facility,$pref): void {
        echo '<section class="surface"><div class="section-heading"><h2>Daftar pensiun</h2>';
        if (can($user,'retirement.manage')) {
            linkButton(RETIREMENT . '/master','Aturan dan checklist');
        }
        echo '</div>';
        getStart(RETIREMENT);
        input('q','Cari nama atau NRP',$_GET['q'] ?? '',max:100);
        selectField('group','Kelompok',['all'=>'Semua','near'=>'Menjelang pensiun','processing'=>'Dalam proses pengurusan',
            'approved'=>'Disetujui / menunggu penerapan','effective'=>'Resmi pensiun','unknown'=>'Tanggal belum tersedia',
            'followup'=>'Perlu tindak lanjut'],$group);
        selectField('months','Periode',RETIREMENT_PERIODS,$months);
        selectField('facility','Fasilitas',optionsFor('facilities',$user,false),$facility,'Semua');
        selectField('profession_id','Profesi',optionsFor('professions',activeOnly:false),$_GET['profession_id'] ?? null,'Semua');
        selectField('specialty_id','Spesialisasi',optionsFor('specialties',activeOnly:false),$_GET['specialty_id'] ?? null,'Semua');
        echo '<button>Terapkan</button></form>';
        table(['Personel','Satuan','Status dinas','Perkiraan','TMT terverifikasi','Proses','Aksi'],$rows,
            static function (array $row): void {
                td($row['name'],$row['unit'],$row['retired_on'] ? 'Purnawirawan' : ($row['active'] ? 'Aktif' : 'Nonaktif'),
                    $row['planned_on'] ?? 'Belum tersedia',$row['effective_on'],retirementStateLabel($row));
                echo '<td>';
                linkButton('/app/personel/detail','Buka',['id'=>$row['id'],'tab'=>RETIREMENT_TAB]);
                echo '</td>';
            });
        paginate(RETIREMENT,$total,$current,$per);
        echo '</section><section class="surface"><h2>Pengingat saya</h2>';
        postStart(RETIREMENT . '/preferences');
        selectField('enabled','Pengingat portal',[0=>'Nonaktif',1=>'Aktif'],$pref['enabled'] ?? 0);
        selectField('months','Periode pengingat',RETIREMENT_PERIODS,$pref['months'] ?? 6);
        echo '<button>Simpan preferensi</button></form></section>';
    },$user,RETIREMENT,'Perkiraan, keputusan resmi, dan status dinas ditampilkan terpisah.');
}

function retirementMaster(array $user): void
{
    retirementRequire($user,'manage');
    $ruleRows = qu('SELECT * FROM retirement_rules ORDER BY code,valid_from DESC,id DESC')->fetchAll();
    $templates = qu('SELECT * FROM retirement_checklist_templates ORDER BY item_type,label,id')->fetchAll();
    $edit = number($_GET,'template');
    $item = $edit ? qu('SELECT * FROM retirement_checklist_templates WHERE id=?',[$edit])->fetch() : [];
    if ($edit && !$item) {
        fail(404,'Template tidak ditemukan.');
    }
    page('Aturan pensiun',static function () use ($user,$ruleRows,$templates,$item,$edit): void {
        echo '<section class="surface"><h2>Aturan proyeksi berversi</h2>';
        table(['Kode','Versi','Kategori','Usia (bulan)','Sumber','Mulai','Berakhir','Verifikasi'],$ruleRows,
            static function (array $row) use ($user): void {
                td($row['code'],$row['version_label'],$row['category'],$row['age_months'],$row['source_reference'],$row['valid_from'],$row['valid_until']);
                echo '<td>';
                if ($row['verified_at']) {
                    echo e($row['verified_at'] . ' UTC');
                } elseif ((int) $row['created_by']!==$user['id']) {
                    postButton(RETIREMENT . '/master/verify','Verifikasi',['id'=>$row['id']]);
                } else {
                    echo 'Menunggu pengelola lain';
                }
                echo '</td>';
            });
        echo '<h3>Tambah versi aturan</h3>';
        postStart(RETIREMENT . '/master/rule');
        echo '<div class="form-grid">';
        input('code','Kode aturan',required:true,max:32);
        input('version_label','Versi',required:true,max:32);
        input('category','Kategori',required:true,max:100);
        input('source_reference','Sumber resmi',required:true,max:250);
        input('age_months','Usia pada aturan (bulan)',type:'number',required:true);
        input('valid_from','Mulai berlaku',type:'date',required:true);
        input('valid_until','Berakhir',type:'date');
        echo '</div><button>Simpan aturan</button></form></section>';
        echo '<section class="surface"><h2>Template dokumen dan serah terima</h2>';
        table(['Kode','Butir','Jenis','Persyaratan','Status','Aksi'],$templates,static function (array $row): void {
            td($row['code'],$row['label'],$row['item_type']==='document' ? 'Dokumen' : 'Serah terima',
                $row['required'] ? 'Wajib' : 'Opsional',$row['active'] ? 'Aktif' : 'Nonaktif');
            echo '<td>';
            linkButton(RETIREMENT . '/master','Ubah',['template'=>$row['id']]);
            echo '</td>';
        });
        postStart(RETIREMENT . '/master/template',$edit ? ['id'=>$edit] : []);
        echo '<div class="form-grid">';
        input('code','Kode butir',$item['code'] ?? '',required:true,max:32);
        input('label','Nama butir',$item['label'] ?? '',required:true);
        selectField('item_type','Jenis',['document'=>'Dokumen','handover'=>'Serah terima'],$item['item_type'] ?? 'document');
        selectField('required','Persyaratan',[1=>'Wajib',0=>'Opsional'],$item['required'] ?? 1);
        selectField('active','Status',[1=>'Aktif',0=>'Nonaktif'],$item['active'] ?? 1);
        echo '</div><button>Simpan template</button></form></section>';
    },$user,RETIREMENT,'Masukkan aturan dan checklist yang disahkan oleh organisasi.');
}

function retirementRuleSave(array $user): never
{
    retirementRequire($user,'manage');
    $code = field($_POST,'code',32,true);
    $version = field($_POST,'version_label',32,true);
    $category = field($_POST,'category',100,true);
    $source = field($_POST,'source_reference',250,true);
    $age = number($_POST,'age_months');
    $from = calendar($_POST,'valid_from');
    $until = calendar($_POST,'valid_until');
    if (!preg_match('/^[a-zA-Z0-9_-]+$/D',$code) || !$age || $age>1800 || !$from || ($until && $until<$from)) {
        fail(422,'Periksa kode, usia dalam bulan, dan periode aturan.');
    }
    saveUnique(static function () use ($user,$code,$version,$category,$source,$age,$from,$until): void {
        tx(static function () use ($user,$code,$version,$category,$source,$age,$from,$until): void {
            qu('INSERT INTO retirement_rules (code,version_label,category,source_reference,age_months,valid_from,valid_until,created_by)
                VALUES (?,?,?,?,?,?,?,?)',[$code,$version,$category,$source,$age,$from,$until,$user['id']]);
            audit($user['id'],'retirement_rule_create','retirement_rules',db()->lastInsertId());
        });
    });
    done('Versi aturan menunggu verifikasi.',RETIREMENT . '/master');
}

function retirementRuleVerify(array $user): never
{
    retirementRequire($user,'manage');
    $id = number($_POST,'id') ?? fail(422,'Aturan diperlukan.');
    tx(static function () use ($id,$user): void {
        if (!qu('UPDATE retirement_rules SET verified_by=?,verified_at=UTC_TIMESTAMP()
            WHERE id=? AND verified_at IS NULL AND created_by<>?',[$user['id'],$id,$user['id']])->rowCount()) {
            fail(409,'Versi ini memerlukan pengelola lain atau sudah diperiksa.');
        }
        audit($user['id'],'retirement_rule_verify','retirement_rules',(string) $id);
    });
    done('Versi aturan terverifikasi.',RETIREMENT . '/master');
}

function retirementTemplateSave(array $user): never
{
    retirementRequire($user,'manage');
    $id = number($_POST,'id');
    $code = field($_POST,'code',32,true);
    if (!preg_match('/^[a-zA-Z0-9_-]+$/D',$code)) {
        fail(422,'Kode butir tidak valid.');
    }
    $data = [$code,field($_POST,'label',160,true),choice($_POST,'item_type',['document','handover']),
        (int) choice($_POST,'required',['0','1']),(int) choice($_POST,'active',['0','1'])];
    saveUnique(static function () use ($user,$id,$data): void {
        tx(static function () use ($user,$id,$data): void {
            if ($id) {
                if (!qu('SELECT 1 FROM retirement_checklist_templates WHERE id=? FOR UPDATE',[$id])->fetchColumn()) {
                    fail(404,'Template tidak ditemukan.');
                }
                qu('UPDATE retirement_checklist_templates SET code=?,label=?,item_type=?,required=?,active=? WHERE id=?',[...$data,$id]);
            } else {
                qu('INSERT INTO retirement_checklist_templates (code,label,item_type,required,active) VALUES (?,?,?,?,?)',$data);
                $id = (int) db()->lastInsertId();
            }
            audit($user['id'],'retirement_template','retirement_checklist_templates',(string) $id);
        });
    });
    done('Template diperbarui untuk catatan baru.',RETIREMENT . '/master');
}
