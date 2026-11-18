<?php
declare(strict_types=1);

require_once PROJECT . '/private/app/Services/PersonnelRecords/files.php';

function profilePrepareTab(array $user,array $staff,string $tab): callable
{
    profileDemand($user,$tab);
    $personnel = $staff['id'];
    $mode = choice($_GET,'view',['','edit']);
    $id = field($_GET,'record',32) === '' ? null : key32($_GET,'record');
    $source = choice($_GET,'source',['record','training'],'record');
    if ($source==='training' && ($tab!=='certificates' || !$id)) {
        fail(404,'Catatan tidak ditemukan.');
    }
    if ($mode==='edit') {
        profileDemand($user,$tab,'write');
        $row = $id ? ($source==='training' ? profileTrainingRow($user,$personnel,$id) : profileRow($user,$personnel,$tab,$id)) : [];
        profileRequireEditable($staff,$row);
        if (($row['case_status'] ?? null)==='closed' || ($row['document_status'] ?? 'current')!=='current') {
            fail(409,'Catatan final ini tidak dapat diedit.');
        }
        return static fn () => profileForm($user,$staff,$tab,$row,$source);
    }
    if ($id) {
        $row = $source==='training' ? profileTrainingRow($user,$personnel,$id) : profileRow($user,$personnel,$tab,$id);
        return static fn () => profileDetailRecord($user,$staff,$tab,$row,$source);
    }
    [$from,$where,$args] = profileListFilter($user,$personnel,$tab);
    $order = 'v.event_on IS NULL,v.event_on DESC,v.record_year DESC,v.title,v.id';
    if (isset($_GET['format'])) {
        profileDemand($user,$tab,'export');
        choice($_GET,'format',['csv']);
        $rows = qu("SELECT v.* $from WHERE $where ORDER BY $order LIMIT 1001",$args)->fetchAll();
        require_once PROJECT . '/private/app/Services/exports.php';
        profileHistory($user,$personnel,$tab,$personnel,0,'export_csv');
        sendExport('csv',PROFILE_TABS[$tab],profileListHeaders($tab),
            array_map(static fn (array $row): array => profileCells($row,$tab),$rows));
    }
    [$rows,$total,$current,$per] = limitedRows($from,$where,$args,'v.*',$order);
    return static fn () => profileListPage($user,$staff,$tab,$rows,$total,$current,$per);
}

function profileListHeaders(string $tab): array
{
    return ['Catatan','Nomor atau angkatan','Jenis atau jenjang','Tanggal atau tahun','Verifikasi',
        $tab==='certificates' ? 'Masa berlaku' : 'Status'];
}

function profileCells(array $row,string $tab): array
{
    $state = $tab==='certificates' ? profileCertificateStatus($row) : $row['process_state'];
    $status = $row['archived_at'] ? 'Arsip' : (profileStates($tab)[$state] ?? 'Aktif');
    return [$row['title'],$row['reference_no'],$row['category'] ?? ($row['source']==='training' ? 'Diklat' : null),
        $row['event_on'] ?? $row['record_year'],PROFILE_VERIFY[$row['verification_status']],$status];
}

function profileListPage(array $user,array $staff,string $tab,array $rows,int $total,int $current,int $per): void
{
    $personnel = $staff['id'];
    $query = profileQuery($personnel,$tab);
    echo '<div class="section-heading"><h2>' . e(PROFILE_TABS[$tab]) . '</h2><div class="actions">';
    if (profileAllowed($user,$tab,'write') && !$staff['archived_at']) {
        linkButton(PROFILE_PATH,'Tambah catatan',$query + ['view'=>'edit'],'primary');
    }
    if (profileAllowed($user,$tab,'export')) {
        linkButton(PROFILE_PATH,'Ekspor CSV',$query + ['format'=>'csv'] + array_diff_key($_GET,array_flip(['r','page'])));
    }
    echo '</div></div>';
    if ($tab==='general') {
        $latest = profileLatestEducation($personnel);
        echo '<h3>Pendidikan umum terakhir yang terverifikasi</h3>';
        if ($latest) {
            definition(['Jenjang'=>$latest['level'],'Institusi'=>$latest['institution'],
                'Program studi'=>$latest['study_program'],'Tahun selesai'=>$latest['end_year']]);
        } else {
            echo '<p class="empty">Belum ada pendidikan lulus dengan verifikasi dan urutan jenjang yang disetujui.</p>';
        }
    }
    getStart(PROFILE_PATH);
    echo '<input type="hidden" name="id" value="' . e($personnel) . '"><input type="hidden" name="tab" value="' . e($tab) . '">';
    input('q','Cari nama, nomor atau perihal',$_GET['q'] ?? '',max:100);
    selectField('category','Jenis atau jenjang',profileCategories($tab,false),$_GET['category'] ?? '','Semua');
    input('year','Tahun',$_GET['year'] ?? '','number');
    selectField('verification','Verifikasi',PROFILE_VERIFY,$_GET['verification'] ?? '','Semua');
    if (profileStates($tab)) {
        selectField('state','Status',profileStates($tab),$_GET['state'] ?? '','Semua');
    }
    selectField('archive','Arsip',['active'=>'Aktif','archived'=>'Arsip'],$_GET['archive'] ?? 'active');
    selectField('per','Per halaman',[25=>25,50=>50,100=>100],$per);
    echo '<div class="actions"><button>Filter</button>';
    linkButton(PROFILE_PATH,'Reset',$query);
    echo '</div></form>';
    table([...profileListHeaders($tab),'Aksi'],$rows,static function (array $row) use ($tab,$query): void {
        td(...profileCells($row,$tab));
        echo '<td>';
        linkButton(PROFILE_PATH,'Detail',$query + ['record'=>$row['id'],'source'=>$row['source']]);
        echo '</td>';
    }, 'Belum ada catatan yang sesuai.');
    paginate(PROFILE_PATH,$total,$current,$per);
}

function profileOptions(array $user,array $staff,string $type,string $tab,mixed $selected = null): ?array
{
    $personnel = $staff['id'];
    return match($type) {
        'category'=>profileCategories($tab,retain:$selected ? (int) $selected : null),
        'validity'=>PROFILE_VALIDITY,
        'education'=>PROFILE_EDUCATION_STATES,
        'unit'=>profileHandlingUnits($user),
        'handler'=>profileCaseHandlers($user,$selected ? (int) $selected : null),
        'service'=>qu("SELECT h.id,CONCAT(u.name,' / ',h.started_on) FROM service_history h JOIN units u ON u.id=h.unit_id
            WHERE h.personnel_id=? ORDER BY h.started_on DESC,h.id",[$personnel])->fetchAll(PDO::FETCH_KEY_PAIR),
        'transfer'=>can($user,'transfers.request') ? qu("SELECT t.id,CONCAT(a.name,' → ',b.name,' / ',t.effective_on)
            FROM unit_transfers t JOIN units a ON a.id=t.from_unit_id JOIN units b ON b.id=t.to_unit_id
            WHERE t.personnel_id=? AND (t.requester_id=? OR ?=1) ORDER BY t.effective_on DESC,t.id",
            [$personnel,$user['id'],(int) can($user,'transfers.approve')])->fetchAll(PDO::FETCH_KEY_PAIR) : null,
        'diploma'=>profileAllowed($user,'diplomas','read') ? qu("SELECT id,CONCAT(institution,' / ',document_no)
            FROM personnel_diplomas WHERE personnel_id=? AND (archived_at IS NULL OR id=?) ORDER BY graduation_year DESC,id",
            [$personnel,$selected])->fetchAll(PDO::FETCH_KEY_PAIR) : null,
        'certificate'=>profileAllowed($user,'certificates','read') ? profileCertificateOptions($user,$personnel,$selected) : null,
        default=>null,
    };
}

function profileHandlingUnits(array $user): array
{
    [$where,$args] = scope($user,'u.id');
    return qu("SELECT u.id,u.name FROM units u WHERE u.active=1 AND $where ORDER BY u.name,u.id",$args)
        ->fetchAll(PDO::FETCH_KEY_PAIR);
}

function profileCertificateOptions(array $user,string $personnel,?string $selected): array
{
    [$where,$args] = profileObjectScope($user,'certificates');
    return qu("SELECT r.id,r.title FROM personnel_certificates r LEFT JOIN training_records t ON t.id=r.training_id
        WHERE r.personnel_id=? AND (r.archived_at IS NULL OR r.id=?) AND $where ORDER BY r.title,r.id",
        [$personnel,$selected,...$args])->fetchAll(PDO::FETCH_KEY_PAIR);
}

function profileForm(array $user,array $staff,string $tab,array $row,string $source): void
{
    $virtual = $source==='training';
    $id = $virtual ? null : ($row['id'] ?? null);
    $trainingId = $virtual ? $row['id'] : ($row['training_id'] ?? null);
    $trainingExpiry = $virtual ? $row['expires_on'] : ($row['training_expiry'] ?? null);
    $hidden = ['personnel'=>$staff['id'],'tab'=>$tab];
    if ($id) {
        $hidden += ['record'=>$id,'version'=>$row['row_version']];
    }
    if ($trainingId) {
        $hidden += ['training_id'=>$trainingId,'training_version'=>$virtual ? $row['row_version'] : $row['training_version']];
    }
    echo '<h2>' . e($id ? 'Ubah ' . PROFILE_TABS[$tab] : 'Tambah ' . PROFILE_TABS[$tab]) . '</h2>';
    linkButton(PROFILE_PATH,'Kembali',profileQuery($staff['id'],$tab,$row ? ['record'=>$row['id'],'source'=>$source] : []));
    if (profileCategories($tab,retain:isset($row['category_id']) ? (int) $row['category_id'] : null) === []) {
        echo '<p class="empty">Siapkan kategori aktif melalui Master Data sebelum menyimpan.</p>';
    }
    if ($trainingId) {
        echo '<p class="subtle">Tanggal akhir dan lampiran dikelola melalui catatan Diklat sumber.</p>';
        linkButton('/app/pelatihan/detail','Buka catatan Diklat',['id'=>$trainingId]);
        if ($virtual) {
            $row['reminder_days'] = 90;
        }
    }
    postStart(PROFILE_ACTIONS . '/save',$hidden);
    echo '<div class="form-grid">';
    foreach (profileFields($tab) as $name=>$definition) {
        [$type,$label,$required,$max] = $definition + [2=>false,3=>160];
        if ($trainingId && $name==='expires_on') {
            echo '<p>' . e('Tanggal akhir dari Diklat: ' . ($trainingExpiry ?? 'Belum diketahui')) . '</p>';
            continue;
        }
        $value = $row[$name] ?? '';
        if ($type==='transfer' && $value && !profileTransferVisible($user,$staff['id'],$value)) {
            continue;
        }
        if ($name==='validity_kind' && $trainingId) {
            if ($trainingExpiry) {
                echo '<p>Tipe masa berlaku dari Diklat: Berbatas tanggal</p><input type="hidden" name="validity_kind" value="dated">';
                continue;
            }
            if ($value==='dated') {
                $value = 'unknown';
            }
        }
        if ($type==='note') {
            textArea($name,$label,(string) $value);
        } elseif (in_array($type,['text','date','year','number'],true)) {
            input($name,$label,$value,($type==='year' || $type==='number') ? 'number' : $type,$required,$max);
        } else {
            $choices = profileOptions($user,$staff,$type,$tab,$value === '' ? null : $value);
            if ($choices !== null) {
                selectField($name,$label,$choices,$value,$required ? '' : 'Belum ditetapkan');
            }
        }
    }
    if ($tab==='certificates' && !$id && !$trainingId && can($user,'training.read')) {
        $choices = qu("SELECT t.id,CONCAT(tt.name,' / ',COALESCE(t.started_on,'—')) FROM training_records t
            JOIN training_types tt ON tt.id=t.type_id WHERE t.personnel_id=? AND t.archived_at IS NULL
            AND NOT EXISTS (SELECT 1 FROM personnel_certificates c WHERE c.training_id=t.id)
            ORDER BY t.started_on DESC,t.id",[$staff['id']])->fetchAll(PDO::FETCH_KEY_PAIR);
        selectField('training_id','Relasi opsional Diklat',$choices,null,'Sertifikat tersendiri');
        echo '<p class="subtle">Kosongkan tanggal akhir bila mengaitkan catatan Diklat.</p>';
    }
    echo '</div><button>Simpan</button></form>';
}

function profileDetailRecord(array $user,array $staff,string $tab,array $row,string $source): void
{
    $virtual = $source==='training';
    $id = $row['id'];
    $personnel = $staff['id'];
    $canWrite = !$staff['archived_at'] && profileAllowed($user,$tab,'write');
    $canReview = !$staff['archived_at'] && profileAllowed($user,$tab,'verify');
    $editable = !$row['archived_at'] && ($row['case_status'] ?? '')!=='closed' && ($row['document_status'] ?? 'current')==='current';
    $query = profileQuery($personnel,$tab,['record'=>$id,'source'=>$source]);
    $hidden = ['personnel'=>$personnel,'tab'=>$tab,'record'=>$id,'version'=>$row['row_version']];
    if (!empty($row['training_id'])) {
        $hidden['training_version'] = $row['training_version'];
    }
    echo '<div class="section-heading"><h2>' . e('Detail ' . PROFILE_TABS[$tab]) . '</h2><div class="actions">';
    linkButton(PROFILE_PATH,'Kembali ke daftar',profileQuery($personnel,$tab));
    if ($canWrite && $editable) {
        linkButton(PROFILE_PATH,$virtual ? 'Lengkapi metadata' : 'Ubah catatan',$query + ['view'=>'edit'],'primary');
    }
    echo '</div></div>';
    if ($virtual) {
        definition(['Nama dari Diklat'=>$row['title'],'Tanggal akhir'=>$row['expires_on'],
            'Verifikasi'=>'Metadata belum tercatat','Status masa berlaku'=>profileStates('certificates')[
                profileCertificateStatus(['expiry'=>$row['expires_on'],'validity'=>$row['expires_on'] ? 'dated' : 'unknown',
                    'valid_from'=>null,'reminder_days'=>90])]]);
        echo '<p>Lengkapi metadata melalui tab Sertifikat. Lampiran dan tanggal akhir tetap terhubung ke sumber Diklat.</p>';
        linkButton('/app/pelatihan/detail','Buka catatan Diklat',['id'=>$id]);
        profileFileList($user,$personnel,$tab,$row + ['training_id'=>$id],$source);
        return;
    }
    $certificateValidity = null;
    if ($tab==='certificates') {
        $certificateValidity = $row['training_expiry'] ? 'dated' :
            ($row['training_id'] && $row['validity_kind']==='dated' ? 'unknown' : $row['validity_kind']);
    }
    $details = [];
    foreach (profileFields($tab) as $key=>$definition) {
        [$type,$label] = $definition;
        $value = $row[$key] ?? null;
        if ($type==='category') {
            $value = profileCategories($tab,false)[$value] ?? null;
        } elseif ($type==='validity') {
            $value = PROFILE_VALIDITY[$certificateValidity];
        } elseif ($type==='education') {
            $value = PROFILE_EDUCATION_STATES[$value];
        } elseif (in_array($type,['diploma','certificate','transfer','service','unit','handler'],true)) {
            $value = profileFieldReference($user,$staff,$tab,$type,$value);
        }
        if ($tab==='certificates' && $key==='expires_on' && $row['training_id']) {
            $value = $row['training_expiry'];
        }
        $details[$label] = $value;
    }
    if ($tab==='certificates') {
        $details['Status masa berlaku'] = profileStates($tab)[profileCertificateStatus([
            'expiry'=>$row['training_expiry'] ?? $row['expires_on'],
            'validity'=>$certificateValidity,
            'valid_from'=>$row['valid_from'],'reminder_days'=>$row['reminder_days'],
        ])];
    } elseif ($tab==='cases') {
        $details['Tahap kasus'] = PROFILE_CASE_STATES[$row['case_status']];
        $details['Tanggal penutupan'] = $row['closed_on'];
    } elseif ($tab==='decrees') {
        $details['Status dokumen'] = PROFILE_DECREE_STATES[$row['document_status']];
    }
    definition($details + ['Arsip'=>$row['archived_at'],'Verifikasi'=>PROFILE_VERIFY[$row['verification_status']],
        'Pemeriksa'=>$row['verified_by'] ? profileActorName((int) $row['verified_by']) : null,
        'Waktu verifikasi (UTC)'=>$row['verified_at'],'Catatan verifikasi'=>$row['verification_notes']]);
    if ($tab==='certificates' && $row['training_id']) {
        linkButton('/app/pelatihan/detail','Kelola sumber Diklat',['id'=>$row['training_id']]);
    }
    if ($tab==='decrees' && $row['replacement_id']) {
        linkButton(PROFILE_PATH,'Lihat petikan pengganti',profileQuery($personnel,$tab,['record'=>$row['replacement_id']]));
    }
    if ($tab==='cases') {
        echo '<p class="subtle">Kasus ditutup menandai akhir penanganan. Hasil resmi mengikuti dokumen keputusan, bila ada.</p>';
        profileCaseTimeline($user,$row,$query,$hidden,$canWrite && !$row['archived_at']);
    }
    profileFileList($user,$personnel,$tab,$row);
    if ($canWrite && $editable && empty($row['training_id'])) {
        profileFileForm($tab,$hidden);
    }
    profileRelatedDocuments($user,$staff,$tab,$row);
    if ($canReview && !$row['archived_at'] && $row['verification_status']==='pending'
        && (int) $row['updated_by']!==$user['id']) {
        echo '<details class="entry"><summary>Verifikasi catatan</summary>';
        postStart(PROFILE_ACTIONS . '/verify',$hidden);
        selectField('decision','Hasil',['verified'=>'Terverifikasi','rejected'=>'Ditolak']);
        textArea('review_note','Catatan (wajib untuk penolakan)');
        echo '<button>Simpan verifikasi</button></form></details>';
    }
    if ($canReview && $editable && $tab==='decrees') {
        profileDecreeForms($personnel,$id,$hidden);
    }
    if ($canWrite) {
        echo '<details class="entry"><summary>' . ($row['archived_at'] ? 'Pulihkan catatan' : 'Arsipkan catatan') . '</summary>';
        postButton(PROFILE_ACTIONS . '/archive','Konfirmasi',$hidden + ['action'=>$row['archived_at'] ? 'restore' : 'archive']);
        echo '</details>';
    }
    profileChangeHistory($personnel,$tab,$id,$query);
}

function profileActorName(int $id): ?string
{
    return qu('SELECT name FROM users WHERE id=?',[$id])->fetchColumn() ?: null;
}

function profileFieldReference(array $user,array $staff,string $tab,string $type,mixed $value): mixed
{
    if (!$value) {
        return null;
    }
    if ($type==='unit') {
        return qu('SELECT name FROM units WHERE id=?',[(int) $value])->fetchColumn() ?: null;
    }
    if ($type==='handler') {
        return profileActorName((int) $value);
    }
    return profileOptions($user,$staff,$type,$tab,$value)[$value] ?? null;
}

function profileFileList(array $user,string $personnel,string $tab,array $row,string $source='record'): void
{
    if (!profileAllowed($user,$tab,'download') || (!empty($row['training_id']) && !can($user,'training.download'))) {
        return;
    }
    echo '<h3>Lampiran</h3>';
    table(['Nama','Jenis','Pemindaian','Aksi'],profileDocumentSet($user,$tab,$row),
        static function (array $file) use ($personnel,$tab,$row,$source): void {
            td($file['original_name'],PROFILE_FILE_PURPOSES[$file['purpose']],
                $file['scan_status']==='clean' ? 'Lulus' : 'Belum dipindai');
            $query = ['personnel'=>$personnel,'tab'=>$tab,'record'=>$row['id'],'file'=>$file['id'],'source'=>$source];
            echo '<td><div class="actions">';
            linkButton(PROFILE_ACTIONS . '/file','Unduh',$query);
            if ($file['scan_status']==='clean') {
                linkButton(PROFILE_ACTIONS . '/file','Pratinjau',$query + ['mode'=>'preview']);
            }
            echo '</div></td>';
        },'Belum ada lampiran.');
}

function profileFileForm(string $tab,array $hidden): void
{
    echo '<details class="entry"><summary>Tambah lampiran</summary>';
    postStart(PROFILE_ACTIONS . '/upload',$hidden,true);
    selectField('purpose','Jenis berkas',$tab==='diplomas' ? PROFILE_FILE_PURPOSES
        : array_intersect_key(PROFILE_FILE_PURPOSES,['document'=>true,'support'=>true]));
    echo '<label>PDF, JPG, atau PNG hingga 10 MB<input name="attachment" type="file" accept=".pdf,.jpg,.jpeg,.png" required></label>';
    echo '<button>Unggah</button></form></details>';
}

function profileRelatedDocuments(array $user,array $staff,string $tab,array $row): void
{
    $personnel = $staff['id'];
    if (profileEducation($tab)) {
        foreach (['diploma_id'=>'diplomas','certificate_id'=>'certificates'] as $field=>$target) {
            if (!empty($row[$field]) && profileAllowed($user,$target,'read')) {
                try {
                    $related = profileRow($user,$personnel,$target,$row[$field]);
                } catch (HttpError $error) {
                    if ($error->status !== 404) {
                        throw $error;
                    }
                    continue;
                }
                echo '<h3>' . e(PROFILE_TABS[$target] . ' terkait') . '</h3>';
                linkButton(PROFILE_PATH,'Buka dokumen sumber',profileQuery($personnel,$target,['record'=>$related['id']]));
                profileFileList($user,$personnel,$target,$related);
            }
        }
    } elseif ($tab==='diplomas' || $tab==='certificates') {
        $column = $tab==='diplomas' ? 'diploma_id' : 'certificate_id';
        foreach (['military','general'] as $target) {
            if (!profileAllowed($user,$target,'read')) {
                continue;
            }
            $education = qu("SELECT id,institution FROM personnel_education WHERE personnel_id=? AND $column=?
                AND education_type=? ORDER BY start_year DESC,id",[$personnel,$row['id'],$target])->fetchAll();
            foreach ($education as $item) {
                linkButton(PROFILE_PATH,PROFILE_TABS[$target] . ' · ' . $item['institution'],
                    profileQuery($personnel,$target,['record'=>$item['id']]));
            }
        }
    }
}

function profileCaseTimeline(array $user,array $row,array $query,array $hidden,bool $canWrite): void
{
    echo '<h3>Tindak lanjut</h3>';
    $page = min(number($_GET,'timeline_page') ?? 1,10000);
    $events = qu('SELECT t.occurred_on,t.prior_status,t.next_status,t.note,u.name,t.created_at FROM personnel_case_updates t
        JOIN users u ON u.id=t.actor_id WHERE t.case_id=? ORDER BY t.occurred_on DESC,t.id DESC LIMIT 26 OFFSET ?',
        [$row['id'],($page-1)*25])->fetchAll();
    table(['Tanggal','Tahap sebelumnya','Tahap sesudah','Catatan','Petugas','Waktu catat (UTC)'],array_slice($events,0,25),
        static fn (array $event) => td($event['occurred_on'],PROFILE_CASE_STATES[$event['prior_status']],
            PROFILE_CASE_STATES[$event['next_status']],$event['note'],$event['name'],$event['created_at']));
    profileSubpage($query,'timeline_page',$page,count($events)>25);
    if ($canWrite) {
        $choices = array_intersect_key(PROFILE_CASE_STATES,array_flip(profileCaseNext($user,$row)));
        if (!$choices) {
            return;
        }
        echo '<details class="entry"><summary>Catat tindak lanjut</summary>';
        postStart(PROFILE_ACTIONS . '/progress',$hidden);
        input('occurred_on','Tanggal tindak lanjut',day(),'date',true);
        selectField('state','Tahap sesudah tindak lanjut',$choices,$row['case_status']);
        textArea('progress_note','Catatan tindak lanjut');
        echo '<button>Simpan tindak lanjut</button></form></details>';
    }
}

function profileDecreeForms(string $personnel,string $id,array $hidden): void
{
    $replacements = qu("SELECT id,CONCAT(document_no,' / ',title) FROM personnel_decree_extracts
        WHERE personnel_id=? AND id<>? AND archived_at IS NULL AND document_status='current'
        AND verification_status='verified' ORDER BY decreed_on DESC,id",[$personnel,$id])->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($replacements) {
        echo '<details class="entry"><summary>Catat penggantian</summary>';
        postStart(PROFILE_ACTIONS . '/decree',$hidden + ['document_status'=>'replaced']);
        selectField('replacement','Petikan pengganti terverifikasi',$replacements);
        input('reason','Alasan penggantian',required:true,max:250);
        echo '<button>Catat penggantian</button></form></details>';
    }
    echo '<details class="entry"><summary>Catat pencabutan</summary>';
    postStart(PROFILE_ACTIONS . '/decree',$hidden + ['document_status'=>'revoked']);
    input('reason','Alasan pencabutan',required:true,max:250);
    echo '<button>Catat pencabutan</button></form></details>';
}

function profileSubpage(array $query,string $key,int $page,bool $next): void
{
    if ($page>1 || $next) {
        echo '<div class="actions">';
        if ($page>1) {
            linkButton(PROFILE_PATH,'Sebelumnya',$query + [$key=>$page-1]);
        }
        if ($next) {
            linkButton(PROFILE_PATH,'Berikutnya',$query + [$key=>$page+1]);
        }
        echo '</div>';
    }
}

function profileChangeHistory(string $personnel,string $tab,string $id,array $query): void
{
    echo '<h3>Riwayat perubahan</h3>';
    $page = min(number($_GET,'history_page') ?? 1,10000);
    $rows = qu('SELECT h.action,h.change_summary,h.row_version,h.created_at,u.name FROM personnel_record_history h
        JOIN users u ON u.id=h.actor_id WHERE h.personnel_id=? AND h.record_kind=? AND h.record_id=?
        ORDER BY h.id DESC LIMIT 26 OFFSET ?',[$personnel,$tab,$id,($page-1)*25])->fetchAll();
    $actions = ['create'=>'Tambah','update'=>'Ubah','review_verified'=>'Verifikasi lulus','review_rejected'=>'Verifikasi ditolak',
        'archive'=>'Arsip','restore'=>'Pulihkan','upload'=>'Unggah lampiran','download'=>'Unduh lampiran',
        'preview'=>'Pratinjau lampiran','case_progress'=>'Tindak lanjut','decree_replaced'=>'Penggantian','decree_revoked'=>'Pencabutan'];
    table(['Tindakan','Rincian','Versi','Pengguna','Waktu (UTC)'],array_slice($rows,0,25),
        static fn (array $event) => td($actions[$event['action']] ?? $event['action'],$event['change_summary'],
            $event['row_version'],$event['name'],$event['created_at']));
    profileSubpage($query,'history_page',$page,count($rows)>25);
}
