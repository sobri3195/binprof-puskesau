<?php
declare(strict_types=1);

require_once __DIR__ . '/read.php';

function profileHistory(array $user,string $personnel,string $tab,string $id,int $version,string $action,?string $change = null): void
{
    qu('INSERT INTO personnel_record_history (personnel_id,record_kind,record_id,actor_id,row_version,action,request_id,change_summary)
        VALUES (?,?,?,?,?,?,?,?)',[$personnel,$tab,$id,$user['id'],$version,$action,REQUEST_ID,$change]);
    if ($tab==='decrees' && retirementReady()) {
        $retirement = qu("SELECT id FROM personnel_retirements WHERE personnel_id=? AND decree_id=?
            AND state IN ('verified','approved')",[$personnel,$id])->fetchColumn();
        if ($retirement !== false) {
            retirementInvalidateNotifications($retirement);
            retirementRefreshMap();
        }
    }
}

function profileRequireEditable(array $staff,array $row = []): void
{
    if ($staff['archived_at'] || ($row['archived_at'] ?? null)) {
        fail(409,'Pulihkan arsip sebelum mengubah catatan.');
    }
}

function profileVersion(array $row): int
{
    if ((int) $row['row_version'] !== number($_POST,'version')) {
        fail(409,'Catatan berubah. Muat ulang sebelum melanjutkan.');
    }
    return (int) $row['row_version']+1;
}

function profileUnit(array $user,int $unit): void
{
    [$scope,$args] = scope($user,'u.id');
    if (!qu("SELECT 1 FROM units u WHERE u.id=? AND u.active=1 AND $scope",[$unit,...$args])->fetchColumn()) {
        fail(422,'Unit penanganan tidak tersedia pada cakupan akun.');
    }
}

function profileHandler(int $actor,int $unit,?int $prior): void
{
    if ($actor === $prior) {
        return;
    }
    if (!qu("SELECT 1 FROM users u
        JOIN personnel_case_permissions cp ON cp.user_id=u.id AND cp.permission_code='personnel.cases.write'
        WHERE u.id=? AND u.active=1 AND (u.role_code='super_admin' OR
            EXISTS (SELECT 1 FROM user_unit_scopes s WHERE s.user_id=u.id AND s.unit_id=?))",
        [$actor,$unit])->fetchColumn()) {
        fail(422,'Petugas aktif dengan kewenangan kasus pada unit penanganan diperlukan.');
    }
}

function profileNumericRelation(string $key): ?string
{
    $value = field($_POST,$key,20);
    if ($value === '') {
        return null;
    }
    if (!ctype_digit($value) || $value === '0') {
        fail(422,'Referensi riwayat kedinasan tidak valid.');
    }
    return $value;
}

function profileFieldsFromPost(array $user,string $tab,array $previous): array
{
    $out = [];
    foreach (profileFields($tab) as $name=>$definition) {
        [$type,$label,$required,$max] = $definition + [2=>false,3=>160];
        // A relation that is hidden by permission remains unchanged on an unrelated edit.
        $related = match($type) {'diploma'=>'diplomas','certificate'=>'certificates',default=>null};
        if ($related && !profileAllowed($user,$related,'read')) {
            if (field($_POST,$name,32) !== '') {
                fail(403,'Perubahan relasi dokumen tidak diizinkan.');
            }
            $out[$name] = $previous[$name] ?? null;
            continue;
        }
        if ($type==='transfer' && !isset($_POST[$name]) && !empty($previous[$name])) {
            $out[$name] = $previous[$name];
            continue;
        }
        $value = match($type) {
            'date'=>calendar($_POST,$name), 'year','number','category','unit','handler'=>number($_POST,$name),
            'validity'=>choice($_POST,$name,array_keys(PROFILE_VALIDITY),'unknown'),
            'education'=>choice($_POST,$name,array_keys(PROFILE_EDUCATION_STATES),'planned'),
            'service'=>profileNumericRelation($name),
            'diploma','certificate','transfer'=>field($_POST,$name,32) === '' ? null : key32($_POST,$name),
            'note'=>field($_POST,$name,4000,$required ?? false),
            default=>field($_POST,$name,$max ?? 160,$required ?? false),
        };
        if (($required ?? false) && ($value === null || $value === '')) {
            fail(422,"Isian $label diperlukan.");
        }
        if ($type === 'year' && $value !== null && ($value < 1000 || $value > 9999)) {
            fail(422,"Periksa rentang tahun untuk $label.");
        }
        $out[$name] = $value === '' && !in_array($name,['notes','summary'],true) ? null : $value;
    }
    return $out;
}

function profileValidateCategory(string $tab,?int $category,?int $previous,bool $linked): void
{
    if ($category === null && $tab === 'certificates' && $linked) {
        return;
    }
    if ($category === null || !array_key_exists($category,profileCategories($tab,retain:$previous))) {
        fail(422,'Pilih kategori aktif yang sesuai.');
    }
}

function profileTransferVisible(array $user,string $personnel,string $transfer): bool
{
    return can($user,'transfers.request') && (bool) qu(
        'SELECT 1 FROM unit_transfers WHERE id=? AND personnel_id=? AND (requester_id=? OR ?=1)',
        [$transfer,$personnel,$user['id'],(int) can($user,'transfers.approve')]
    )->fetchColumn();
}

function profileValidate(array $user,string $personnel,string $tab,array &$data,array $previous,?array $training): void
{
    profileValidateCategory($tab,$data['category_id'],isset($previous['category_id']) ? (int) $previous['category_id'] : null,$training !== null);
    if ($tab === 'cases') {
        profileUnit($user,$data['handling_unit_id']);
        $sameUnit = (int) ($previous['handling_unit_id'] ?? 0) === $data['handling_unit_id'];
        profileHandler($data['handler_id'],$data['handling_unit_id'],$sameUnit ? (int) $previous['handler_id'] : null);
        if ($data['reported_on'] > day() || ($data['occurred_on'] && $data['occurred_on'] > $data['reported_on'])
            || ($data['follow_up_on'] && $data['follow_up_on'] < $data['reported_on'])
            || ($data['decision_on'] && ($data['decision_on'] < $data['reported_on'] || $data['decision_on'] > day()))
            || (($data['decision_on'] || $data['decision_no']) && !$data['decision'])) {
            fail(422,'Periksa kronologi kasus dan kelengkapan keputusan resmi.');
        }
        if (empty($previous['id']) && $data['decision']) {
            fail(422,'Catat laporan terlebih dahulu sebelum mengisi keputusan resmi.');
        }
        if ($data['decision'] && $data['decision'] !== ($previous['decision'] ?? null) && $previous['case_status'] !== 'handling') {
            fail(422,'Keputusan resmi dicatat pada tahap penanganan.');
        }
        if (isset($previous['reported_on']) && $data['reported_on'] !== $previous['reported_on']
            && qu('SELECT 1 FROM personnel_case_updates WHERE case_id=? AND occurred_on<? LIMIT 1',[$previous['id'],$data['reported_on']])->fetchColumn()) {
            fail(422,'Tanggal laporan melampaui tanggal tindak lanjut tercatat.');
        }
        return;
    }
    if ($tab === 'certificates') {
        $expiry = $training ? $training['expires_on'] : $data['expires_on'];
        if ($training && $data['expires_on']) {
            fail(422,'Ubah tanggal akhir sertifikat ini pada catatan Diklat.');
        }
        $data['validity_kind'] = $training && $expiry ? 'dated' : $data['validity_kind'];
        if (($data['validity_kind'] === 'dated') !== ($expiry !== null)
            || ($data['reminder_days'] && $data['reminder_days'] > 365)
            || ($data['issued_on'] && $data['valid_from'] && $data['issued_on'] > $data['valid_from'])
            || ($expiry && ($expiry < ($data['valid_from'] ?? $data['issued_on'] ?? $expiry)))) {
            fail(422,'Periksa tipe masa berlaku, urutan tanggal, dan pengingat 1–365 hari.');
        }
        return;
    }
    if ($tab === 'decrees') {
        if (($data['service_history_id'] && !qu('SELECT 1 FROM service_history WHERE id=? AND personnel_id=?',
                [$data['service_history_id'],$personnel])->fetchColumn())
            || ($data['transfer_id'] && $data['transfer_id']!==($previous['transfer_id'] ?? null)
                && !profileTransferVisible($user,$personnel,$data['transfer_id']))) {
            fail(422,'Relasi riwayat atau mutasi berada di luar kewenangan.');
        }
        return;
    }
    $graduated = $data['graduated_on'];
    if ($tab === 'diplomas') {
        if (($graduated && (int) substr($graduated,0,4) !== $data['graduation_year'])
            || ($data['issued_on'] && ($graduated ? $data['issued_on'] < $graduated : (int) substr($data['issued_on'],0,4) < $data['graduation_year']))) {
            fail(422,'Periksa tahun kelulusan dan tanggal penerbitan ijazah.');
        }
        return;
    }
    if (($data['end_year'] && $data['end_year'] < $data['start_year'])
        || ($graduated && (int) substr($graduated,0,4) !== $data['end_year'])
        || ($data['education_status'] === 'graduated' && !$data['end_year'])
        || ($data['education_status'] !== 'graduated' && ($graduated || $data['diploma_id']))
        || (!empty($data['started_on']) && (int) substr($data['started_on'],0,4) !== $data['start_year'])
        || (!empty($data['ended_on']) && (!$data['end_year'] || (int) substr($data['ended_on'],0,4) !== $data['end_year']))
        || (!empty($data['started_on']) && ((!empty($data['ended_on']) && $data['ended_on'] < $data['started_on'])
            || ($graduated && $graduated < $data['started_on'])))) {
        fail(422,'Periksa status, tahun, serta kronologi pendidikan.');
    }
    foreach (['diploma_id'=>'diplomas','certificate_id'=>'certificates'] as $column=>$kind) {
        $id = $data[$column] ?? null;
        if ($id && $id !== ($previous[$column] ?? null)) {
            profileDemand($user,$kind);
            $linked = profileRow($user,$personnel,$kind,$id);
            if ($linked['archived_at'] || ($column === 'diploma_id' && $linked['graduation_year'] != $data['end_year'])) {
                fail(422,'Dokumen terkait harus aktif dan sesuai tahun kelulusan.');
            }
        }
    }
}

function profileSaveRow(array $user,string $personnel,string $tab,?string $id): string
{
    return saveUnique(static function () use ($user,$personnel,$tab,$id): string {
        return tx(static function () use ($user,$personnel,$tab,$id): string {
            $staff = person($user,$personnel,true);
            $before = $id ? profileRow($user,$personnel,$tab,$id,true) : [];
            profileRequireEditable($staff,$before);
            if ($tab === 'cases' && ($before['case_status'] ?? null) === 'closed') {
                fail(409,'Buka kembali kasus sebelum mengedit catatan.');
            }
            if ($tab === 'decrees' && ($before['document_status'] ?? 'current') !== 'current') {
                fail(409,'Riwayat petikan yang dicabut atau diganti hanya dapat dibaca.');
            }
            $version = $id ? profileVersion($before) : 1;
            $trainingId = $tab === 'certificates'
                ? ($id ? $before['training_id'] : (field($_POST,'training_id',32) === '' ? null : key32($_POST,'training_id')))
                : null;
            $training = $trainingId ? profileTrainingRow($user,$personnel,$trainingId,!$id,true) : null;
            if ($training && isset($_POST['training_version']) && number($_POST,'training_version') !== (int) $training['row_version']) {
                fail(409,'Catatan sumber Diklat berubah. Muat ulang sebelum menyimpan.');
            }
            $data = profileFieldsFromPost($user,$tab,$before);
            profileValidate($user,$personnel,$tab,$data,$before,$training);
            $table = PROFILE_TABLES[$tab];
            if ($id) {
                $set = implode(',',array_map(static fn (string $field): string => "$field=?",array_keys($data)));
                qu("UPDATE $table SET $set,updated_by=?,updated_at=UTC_TIMESTAMP(),row_version=?,
                    verification_status='pending',verified_at=NULL,verified_by=NULL,verification_notes=NULL WHERE id=?",
                    [...array_values($data),$user['id'],$version,$id]);
            } else {
                $id = bin2hex(random_bytes(16));
                $data += ['id'=>$id,'personnel_id'=>$personnel,'created_by'=>$user['id'],'updated_by'=>$user['id']];
                if (profileEducation($tab)) {
                    $data['education_type'] = $tab;
                }
                if ($tab === 'certificates') {
                    $data['training_id'] = $trainingId;
                }
                $columns = implode(',',array_keys($data));
                $marks = implode(',',array_fill(0,count($data),'?'));
                qu("INSERT INTO $table ($columns) VALUES ($marks)",array_values($data));
            }
            $changed = $before ? array_keys(array_filter($data,static function (mixed $value,string $key) use ($before): bool {
                return (string) ($before[$key] ?? '') !== (string) $value;
            },ARRAY_FILTER_USE_BOTH)) : [];
            profileHistory($user,$personnel,$tab,$id,$version,$before ? 'update' : 'create',
                $changed ? substr(implode(', ',$changed),0,250) : null);
            return $id;
        });
    });
}

function profileReviewRow(array $user,string $personnel,string $tab,string $id): void
{
    tx(static function () use ($user,$personnel,$tab,$id): void {
        $staff = person($user,$personnel,true);
        $row = profileRow($user,$personnel,$tab,$id,true);
        profileRequireEditable($staff,$row);
        $version = profileVersion($row);
        if ($row['verification_status'] !== 'pending' || (int) $row['updated_by'] === $user['id']) {
            fail(409,'Verifikasi memerlukan catatan menunggu dan pemeriksa selain pengubah terakhir.');
        }
        if ($tab==='certificates' && $row['training_id']
            && (int) $row['training_version'] !== number($_POST,'training_version')) {
            fail(409,'Catatan Diklat berubah. Muat ulang sebelum verifikasi.');
        }
        $decision = choice($_POST,'decision',['verified','rejected']);
        if ($decision==='verified' && $tab==='diplomas' && $row['legalization_note']
            && !qu("SELECT 1 FROM personnel_record_attachments WHERE diploma_id=? AND purpose='legalization' LIMIT 1",[$id])->fetchColumn()) {
            fail(422,'Lampirkan bukti legalisasi sebelum memverifikasi informasi tersebut.');
        }
        $note = field($_POST,'review_note',4000,$decision === 'rejected');
        $table = PROFILE_TABLES[$tab];
        $extra = $tab === 'certificates' ? ',verified_training_version=?' : '';
        $extraArgs = $tab === 'certificates' ? [$row['training_version']] : [];
        qu("UPDATE $table SET verification_status=?,verified_by=?,verified_at=UTC_TIMESTAMP(),verification_notes=?,
            updated_at=UTC_TIMESTAMP(),row_version=?$extra WHERE id=?",[$decision,$user['id'],$note,$version,...$extraArgs,$id]);
        profileHistory($user,$personnel,$tab,$id,$version,'review_' . $decision);
    });
}

function profileArchiveRow(array $user,string $personnel,string $tab,string $id): void
{
    tx(static function () use ($user,$personnel,$tab,$id): void {
        $staff = person($user,$personnel,true);
        if ($staff['archived_at']) {
            fail(409,'Personel berada dalam arsip.');
        }
        $row = profileRow($user,$personnel,$tab,$id,true);
        $version = profileVersion($row);
        $action = choice($_POST,'action',['archive','restore']);
        if ((bool) $row['archived_at'] === ($action === 'archive')) {
            fail(409,'Status arsip telah berubah.');
        }
        $table = PROFILE_TABLES[$tab];
        qu("UPDATE $table SET archived_at=?,archived_by=?,updated_at=UTC_TIMESTAMP(),row_version=? WHERE id=?",
            [$action==='archive' ? gmdate('Y-m-d H:i:s') : null,$action==='archive' ? $user['id'] : null,$version,$id]);
        profileHistory($user,$personnel,$tab,$id,$version,$action);
    });
}

function profileCaseNext(array $user,array $row): array
{
    $allowed = match($row['case_status']) {
        'reported'=>['reported','review'],
        'review'=>$row['verification_status']==='verified' ? ['review','handling'] :
            ($row['verification_status']==='rejected' ? ['review','closed'] : ['review']),
        'handling'=>$row['verification_status']==='pending' ? ['handling'] : ['handling','closed'],
        'closed'=>['review'],
    };
    return profileAllowed($user,'cases','verify') ? $allowed :
        ($row['case_status']==='closed' ? [] : array_values(array_diff($allowed,['closed'])));
}

function profileCaseProgress(array $user,string $personnel,string $id): void
{
    tx(static function () use ($user,$personnel,$id): void {
        $staff = person($user,$personnel,true);
        $row = profileRow($user,$personnel,'cases',$id,true);
        profileRequireEditable($staff,$row);
        $version = profileVersion($row);
        $prior = $row['case_status'];
        $next = choice($_POST,'state',array_keys(PROFILE_CASE_STATES));
        $reopen = $prior === 'closed';
        if (!in_array($next,profileCaseNext($user,$row),true)) {
            fail(422,'Perpindahan tahap memerlukan verifikasi dan kewenangan yang sesuai.');
        }
        $date = calendar($_POST,'occurred_on') ?? fail(422,'Tanggal tindak lanjut diperlukan.');
        $last = qu('SELECT MAX(occurred_on) FROM personnel_case_updates WHERE case_id=?',[$id])->fetchColumn();
        if ($date < $row['reported_on'] || $date > day() || ($last && $date < $last)
            || ($next === 'closed' && $row['decision_on'] && $date < $row['decision_on'])) {
            fail(422,'Periksa urutan tanggal tindak lanjut atau keputusan.');
        }
        $note = field($_POST,'progress_note',4000,true);
        qu('INSERT INTO personnel_case_updates (case_id,actor_id,occurred_on,prior_status,next_status,note) VALUES (?,?,?,?,?,?)',
            [$id,$user['id'],$date,$prior,$next,$note]);
        $reset = $reopen ? ",verification_status='pending',verified_by=NULL,verified_at=NULL,verification_notes=NULL,updated_by=?" : '';
        $args = $reopen ? [$user['id']] : [];
        qu("UPDATE personnel_cases SET case_status=?,closed_on=?,follow_up_on=IF(?='closed',NULL,follow_up_on),
            updated_at=UTC_TIMESTAMP(),row_version=?$reset WHERE id=?",
            [$next,$next === 'closed' ? $date : null,$next,$version,...$args,$id]);
        profileHistory($user,$personnel,'cases',$id,$version,'case_progress',"$prior → $next");
    });
}

function profileDecreeAction(array $user,string $personnel,string $id): void
{
    tx(static function () use ($user,$personnel,$id): void {
        $staff = person($user,$personnel,true);
        $row = profileRow($user,$personnel,'decrees',$id,true);
        profileRequireEditable($staff,$row);
        $version = profileVersion($row);
        if ($row['document_status'] !== 'current') {
            fail(409,'Petikan ini sudah diganti atau dicabut.');
        }
        $state = choice($_POST,'document_status',['replaced','revoked']);
        $reason = field($_POST,'reason',250,true);
        $replacement = $state==='replaced' ? key32($_POST,'replacement') : null;
        if ($replacement) {
            $other = profileRow($user,$personnel,'decrees',$replacement,true);
            if ($replacement===$id || $other['archived_at'] || $other['document_status']!=='current'
                || $other['verification_status']!=='verified') {
                fail(422,'Pilih petikan pengganti aktif dan terverifikasi.');
            }
        }
        qu('UPDATE personnel_decree_extracts SET document_status=?,replacement_id=?,updated_at=UTC_TIMESTAMP(),row_version=? WHERE id=?',
            [$state,$replacement,$version,$id]);
        profileHistory($user,$personnel,'decrees',$id,$version,'decree_' . $state,$reason);
    });
}
