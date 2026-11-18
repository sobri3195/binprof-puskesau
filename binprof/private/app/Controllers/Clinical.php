<?php
declare(strict_types=1);

require_once PROJECT . '/private/app/Policies/clinical.php';
require_once PROJECT . '/private/app/Controllers/Personnel.php';
require_once PROJECT . '/private/app/Services/clinical_workflow.php';
require_once PROJECT . '/private/app/Services/clinical_files.php';

const CLINICAL = '/app/erm';
const CLINICAL_FIELDS = [
    'summary'=>'Resume', 'diagnoses'=>'Diagnosis', 'procedures'=>'Tindakan', 'medicines'=>'Obat', 'allergies'=>'Alergi',
];
const RIKKES_FIELDS = [
    'blood'=>'Lab darah','urine'=>'Lab urine','radiology'=>'Rontgen','ekg'=>'EKG','hearing'=>'Audiometri','substances'=>'Tes narkoba',
    'general'=>'Penilaian umum','eyes'=>'Mata','teeth'=>'Gigi','ent'=>'THT','mental'=>'Jiwa','recommendations'=>'Rekomendasi',
];
const RIKKES_KIND = ['periodic'=>'Periodik','dikbangum'=>'Dikbangum','outside'=>'Dinas luar','other'=>'Lainnya'];
const RIKKES_RESULT = ['fit'=>'Layak','unfit'=>'Tidak layak','observation'=>'Perlu observasi'];

function clinicalDispatch(array $user, string $operation): void
{
    $facility = null;
    $purpose = null;
    try {
        if ($operation === 'home') {
            clinicalHome($user);
            return;
        }
        if ($operation === 'audit') {
            clinicalAuditPage($user);
            return;
        }
        $facility = number($_POST, 'workplace') ?? fail(422, 'Pilih fasilitas tempat bertugas.');
        $priv = clinicalPrivilege($user['id'], $facility);
        $purpose = in_array($operation, ['search','read','export','save','request','upload','download','history'], true)
            ? choice($_POST, 'purpose', array_keys(PURPOSES))
            : null;
        match ($operation) {
            'search' => clinicalSearch($user, $priv, $purpose),
            'read', 'export' => clinicalRead($user, $priv, $purpose, $operation === 'export'),
            'history' => clinicalHistory($user, $priv, $purpose),
            'save' => clinicalSave($user, $priv, $purpose),
            'request' => clinicalRequest($user, $priv, $purpose),
            'decision' => clinicalDecision($user, $priv),
            'privileges' => clinicalPrivileges($user, $priv),
            'care' => clinicalCare($user, $priv),
            'upload' => clinicalUpload($user, $priv, $purpose),
            'download' => clinicalDownload($user, $priv, $purpose),
            default => fail(404, 'Tindakan tidak ditemukan.'),
        };
    } catch (Throwable $error) {
        // Use no object identifier for requests that failed authorization.
        try {
            $auditedFacility = $facility !== null && isset($priv) ? $facility : null;
            clinicalAudit($user['id'], $auditedFacility, null, $operation, $purpose, 'denied');
        } catch (Throwable $auditError) {
            reportFailure($auditError);
            fail(503, 'Audit klinis tidak tersedia. Tindakan dihentikan.');
        }
        throw $error;
    }
}

function clinicalHome(array $user): void
{
    $workplaces = clinicalWorkplaces($user);
    if (!$workplaces) {
        fail(403, 'Penugasan klinis aktif diperlukan.');
    }
    $selected = number($_GET, 'workplace') ?? (int) array_key_first($workplaces);
    $priv = clinicalPrivilege($user['id'], $selected);
    $pending = [];
    if ($priv['can_approve']) {
        $pending = tx(static function () use ($user, $selected): array {
            supervisor($user, $selected);
            clinicalAudit($user['id'], $selected, null, 'review_inbox', null);
            return qu("SELECT r.id,r.patient_id,r.purpose,r.access_scope,r.created_at,r.justification_ciphertext,r.status,
                r.requester_id, f.name AS workplace, g.revoked_at, g.expires_at
                FROM clinical_access_requests r JOIN facilities f ON f.id=r.workplace_id
                LEFT JOIN clinical_access_grants g ON g.request_id=r.id
                WHERE r.source_facility_id=? AND r.requester_id<>?
                AND (r.status='pending' OR (r.status='approved' AND g.revoked_at IS NULL AND g.expires_at>UTC_TIMESTAMP()))
                ORDER BY r.created_at DESC,r.id LIMIT 50", [$selected,$user['id']])->fetchAll();
        });
    }
    $requests = $priv['can_read'] ? qu('SELECT f.name AS facility,r.purpose,r.access_scope,r.status,
        g.expires_at,g.revoked_at FROM clinical_access_requests r JOIN facilities f ON f.id=r.source_facility_id
        LEFT JOIN clinical_access_grants g ON g.request_id=r.id
        WHERE r.requester_id=? AND r.workplace_id=? ORDER BY r.created_at DESC,r.id LIMIT 30',
        [$user['id'],$selected])->fetchAll() : [];
    page('E-RM dan Rikkes', static function () use ($workplaces, $selected, $priv, $pending, $requests): void {
        echo '<section class="surface"><h2>Fasilitas tempat bertugas</h2>';
        getStart(CLINICAL);
        selectField('workplace', 'Fasilitas', $workplaces, $selected);
        echo '<button>Gunakan fasilitas</button></form></section>';
        if ($priv['can_read']) {
            echo '<section class="surface"><h2>Cari pasien yang dapat diakses</h2>';
            postStart(CLINICAL . '/search', ['workplace'=>$selected]);
            echo '<div class="form-grid">';
            selectField('method', 'Jenis pencarian', ['nrp'=>'NRP tepat','nik'=>'NIK tepat','name'=>'Nama (minimal 3 karakter)']);
            input('query', 'Kata pencarian', required: true, max: 100);
            selectField('purpose', 'Tujuan akses', PURPOSES);
            echo '</div>';
            textArea('reason', 'Justifikasi (minimal 12 karakter)');
            echo '<button>Cari</button></form></section><section class="surface"><h2>Ajukan akses lintas fasilitas</h2>';
            postStart(CLINICAL . '/request', ['workplace'=>$selected]);
            echo '<div class="form-grid">';
            input('patient', 'Kode pasien dari rujukan', required: true, max: 32);
            $sources = optionsFor('facilities');
            unset($sources[$selected]);
            selectField('source', 'Fasilitas pemilik rekam', $sources);
            selectField('purpose', 'Tujuan akses', PURPOSES);
            selectField('scope', 'Hak yang diajukan', $priv['can_export'] ? ['read'=>'Baca','export'=>'Ekspor'] : ['read'=>'Baca']);
            echo '</div>';
            textArea('reason', 'Justifikasi (minimal 12 karakter)');
            echo '<button>Ajukan</button></form></section><section class="surface"><h2>Pengajuan Anda</h2>';
            table(['Fasilitas sumber','Tujuan','Hak','Status','Berlaku sampai (UTC)'],$requests,static function (array $row): void {
                $status = match (true) {
                    $row['revoked_at'] !== null => 'Dicabut',
                    $row['status'] === 'pending' => 'Menunggu',
                    $row['status'] === 'rejected' => 'Ditolak',
                    $row['expires_at'] <= gmdate('Y-m-d H:i:s') => 'Kedaluwarsa',
                    default => 'Aktif',
                };
                td($row['facility'],PURPOSES[$row['purpose']],$row['access_scope']==='read' ? 'Baca' : 'Ekspor',$status,$row['expires_at']);
            });
            echo '</section>';
        }
        if ($priv['can_approve']) {
            clinicalAdministration($selected, $pending);
        }
    }, $user, CLINICAL, 'Akses klinis mengikuti penugasan, tujuan, dan masa berlaku.');
}

function clinicalAdministration(int $facility, array $requests): void
{
    echo '<section class="surface"><div class="section-heading"><h2>Peninjauan akses lintas fasilitas</h2>';
    linkButton(CLINICAL . '/audit','Audit klinis',['workplace'=>$facility]);
    echo '</div>';
    table(['Kode pasien','Fasilitas pemohon','Tujuan','Hak','Justifikasi','Aksi'], $requests, static function (array $row) use ($facility): void {
        td($row['patient_id'],$row['workplace'],PURPOSES[$row['purpose']],$row['access_scope'] === 'read' ? 'Baca' : 'Ekspor',
            unseal($row['justification_ciphertext'], $row['id'] . ':request'));
        echo '<td><div class="actions">';
        $hidden = ['id'=>$row['id'],'workplace'=>$facility];
        if ($row['status'] === 'pending') {
            postButton(CLINICAL . '/decision', 'Setujui 24 jam', $hidden + ['decision'=>'approve']);
            postButton(CLINICAL . '/decision', 'Tolak', $hidden + ['decision'=>'reject']);
        } else {
            postButton(CLINICAL . '/decision', 'Cabut grant', $hidden + ['decision'=>'revoke']);
        }
        echo '</div></td>';
    });
    echo '</section><section class="surface"><h2>Penetapan kewenangan klinis</h2>';
    postStart(CLINICAL . '/privileges', ['workplace'=>$facility]);
    echo '<div class="form-grid">';
    input('email', 'Email petugas', type:'email', required:true, max:254);
    selectField('active', 'Status', [1=>'Aktif',0=>'Cabut'], 1);
    foreach (['read'=>'Baca','write'=>'Tulis','export'=>'Ekspor','approve'=>'Setujui'] as $key=>$label) {
        selectField($key, $label, [0=>'Tidak',1=>'Ya'], 0);
    }
    echo '</div><button>Simpan kewenangan</button></form></section><section class="surface"><h2>Penugasan perawatan</h2>';
    postStart(CLINICAL . '/care', ['workplace'=>$facility]);
    echo '<div class="form-grid">';
    input('nrp', 'NRP (tepat) untuk pendaftaran pertama', max:40);
    input('patient', 'Atau kode pasien yang sudah terdaftar', max:32);
    input('email', 'Email petugas yang ditugaskan', type:'email', required:true, max:254);
    selectField('decision', 'Tindakan', ['assign'=>'Tetapkan','revoke'=>'Cabut']);
    input('days', 'Masa berlaku dalam hari (1–30)', '7', 'number');
    echo '</div><button>Simpan penugasan</button></form></section>';
}

function clinicalReason(): string
{
    $reason = field($_POST, 'reason', 1000, true);
    if (mb_strlen($reason) < 12) {
        fail(422, 'Jelaskan justifikasi dengan minimal 12 karakter.');
    }
    return $reason;
}

function clinicalSearch(array $user, array $priv, string $purpose): void
{
    if (!$priv['can_read']) {
        fail(403, 'Kewenangan baca klinis diperlukan.');
    }
    $reason = clinicalReason();
    $method = choice($_POST, 'method', ['nrp','nik','name']);
    $query = field($_POST, 'query', 100, true);
    $filter = match ($method) {
        'nrp' => 'p.nrp=?',
        'nik' => 'p.nik_lookup_hash=?',
        'name' => 'LOCATE(?, p.name)>0',
    };
    if (($method === 'name' && mb_strlen($query) < 3)
        || ($method === 'nik' && !preg_match('/^[0-9]{16}$/D', $query))) {
        fail(422, 'Periksa format pencarian.');
    }
    $term = $method === 'nik' ? hash_hmac('sha256', $query, secretBytes(cfg('lookup_pepper')), true) : $query;
    $facility = (int) $priv['facility_id'];
    $results = tx(static function () use ($user, $facility, $purpose, $term, $filter, $reason): array {
        qu('SELECT id FROM users WHERE id=? FOR UPDATE', [$user['id']]);
        if (!clinicalPrivilege($user['id'], $facility)['can_read']) {
            fail(403, 'Kewenangan baca klinis diperlukan.');
        }
        if ((int) qu("SELECT COUNT(*) FROM clinical_access_logs WHERE actor_id=? AND action='search'
                AND result='success' AND created_at>UTC_TIMESTAMP()-INTERVAL 10 MINUTE", [$user['id']])->fetchColumn() >= 15) {
            header('Retry-After: 600');
            fail(429, 'Batas pencarian tercapai. Coba lagi nanti.');
        }
        $results = qu("SELECT cp.id,p.name FROM clinical_patients cp JOIN personnel p ON p.id=cp.personnel_id
            WHERE $filter AND (
                EXISTS (SELECT 1 FROM patient_care_assignments ca WHERE ca.patient_id=cp.id AND ca.user_id=?
                    AND ca.facility_id=? AND ca.revoked_at IS NULL AND ca.expires_at>UTC_TIMESTAMP()) OR
                EXISTS (SELECT 1 FROM clinical_access_requests r JOIN clinical_access_grants g ON g.request_id=r.id
                    JOIN facilities f ON f.id=r.source_facility_id
                    WHERE r.patient_id=cp.id AND r.requester_id=? AND r.workplace_id=? AND r.purpose=?
                    AND r.access_scope='read' AND r.status='approved' AND g.revoked_at IS NULL
                    AND g.expires_at>UTC_TIMESTAMP() AND f.active=1))
            ORDER BY p.name,cp.id LIMIT 15", [$term,$user['id'],$facility,$user['id'],$facility,$purpose])->fetchAll();
        clinicalAudit($user['id'],$facility,null,'search',$purpose,reason:$reason);
        return $results;
    });
    page('Hasil pencarian pasien', static function () use ($results, $facility, $purpose, $reason): void {
        echo '<section class="surface"><h2>Hasil yang dapat dibuka</h2>';
        table(['Nama','Aksi'], $results, static function (array $row) use ($facility,$purpose,$reason): void {
            td($row['name']);
            echo '<td>';
            postButton(CLINICAL . '/read', 'Buka timeline', ['patient'=>$row['id'],'workplace'=>$facility,'purpose'=>$purpose,'reason'=>$reason]);
            echo '</td>';
        });
        echo '<div class="actions">';
        linkButton(CLINICAL, 'Kembali', ['workplace'=>$facility]);
        echo '</div></section>';
    }, $user, CLINICAL);
}

function clinicalRead(array $user, array $priv, string $purpose, bool $export): never
{
    $facility = (int) $priv['facility_id'];
    $patient = key32($_POST, 'patient');
    $reason = clinicalReason();
    $destination = $export ? (string) qu('SELECT name FROM facilities WHERE id=? AND active=1',
        [number($_POST, 'destination')])->fetchColumn() : null;
    if ($export && $destination === '') {
        fail(422, 'Pilih fasilitas tujuan yang aktif.');
    }
    [$identity, $records, $allowed, $allowExport] = tx(static function () use ($user, $priv, $purpose, $export, $patient, $facility, $reason): array {
        $sources = requireRecordFacilities($priv, $patient, $purpose, $export ? 'export' : 'read');
        $identity = qu('SELECT p.name FROM clinical_patients cp JOIN personnel p ON cp.personnel_id=p.id WHERE cp.id=?',
            [$patient])->fetchColumn();
        $in = implode(',', array_fill(0, count($sources), '?'));
        $records = qu("SELECT e.id,e.facility_id,e.encounter_on,e.kind,e.row_version,v.state,v.payload_ciphertext,v.created_at,u.name AS author,f.name AS facility
            FROM clinical_encounters e JOIN clinical_record_versions v ON v.encounter_id=e.id AND v.version=e.row_version
            JOIN facilities f ON f.id=e.facility_id JOIN users u ON u.id=v.author_id WHERE e.patient_id=? AND e.facility_id IN ($in)
            AND (v.state<>'draft' OR v.author_id=?) ORDER BY e.encounter_on DESC,v.created_at DESC,e.id LIMIT 101",
            [$patient,...$sources,$export ? 0 : $user['id']])->fetchAll();
        if ($export && count($records) > 100) {
            fail(422, 'Ringkasan melebihi 100 catatan. Hubungi pengelola klinis untuk ekspor bertahap.');
        }
        foreach ($records as &$record) {
            $record['payload'] = json_decode(unseal($record['payload_ciphertext'], $record['id'] . ':' . $record['row_version']), true, flags: JSON_THROW_ON_ERROR);
            unset($record['payload_ciphertext']);
            if (!$export) {
                $record['documents'] = clinicalDocuments($record['id'], (int) $record['row_version']);
            }
        }
        unset($record);
        clinicalAudit($user['id'],$facility,$patient,$export ? 'export' : 'read',$purpose,reason:$reason);
        return [$identity,$records,$export ? [] : recordFacilities($priv,$patient,$purpose,'write'),
            !$export && recordFacilities($priv,$patient,$purpose,'export') !== []];
    });
    if ($export) {
        clinicalPdf($user,$identity,array_slice($records,0,100),$destination);
    }
    page('Timeline klinis', static function () use ($identity,$records,$allowed,$patient,$facility,$purpose,$reason,$allowExport): void {
        $hidden = ['patient'=>$patient,'workplace'=>$facility,'purpose'=>$purpose,'reason'=>$reason];
        echo '<section class="surface">';
        definition(['Pasien'=>$identity,'Kode pasien'=>$patient,'Tujuan akses'=>PURPOSES[$purpose]]);
        linkButton(CLINICAL, 'Kembali', ['workplace'=>$facility]);
        if ($allowExport) {
            echo '<details class="entry"><summary>Ringkasan perawatan PDF</summary>';
            postStart(CLINICAL . '/export', $hidden);
            selectField('destination', 'Fasilitas tujuan', optionsFor('facilities'));
            echo '<button>Unduh PDF privat</button></form></details>';
        }
        echo '</section>';
        if (!$records) {
            echo '<p class="empty">Belum ada catatan yang dapat ditampilkan.</p>';
        }
        foreach (array_slice($records,0,100) as $record) {
            echo '<section class="surface"><h2>' . e($record['kind'] === 'rikkes' ? 'Rikkes' : 'Kunjungan') . ' · '
                . e($record['encounter_on']) . '</h2>';
            $states = ['draft'=>'Draf','signed'=>'Ditandatangani internal','amended'=>'Diamendemen'];
            definition(['Fasilitas'=>$record['facility'],'Status'=>$states[$record['state']], 'Versi'=>$record['row_version'],
                'Petugas'=>$record['author'],'Dicatat (UTC)'=>$record['created_at']]
                + clinicalValues($record));
            if ((int) $record['row_version'] > 1) {
                postButton(CLINICAL . '/history','Riwayat versi',$hidden + ['id'=>$record['id']]);
            }
            clinicalAttachmentLinks($hidden, $record);
            if (in_array((int) $record['facility_id'],$allowed,true)) {
                if ($record['state'] === 'draft') {
                    clinicalAttachmentForm($hidden, $record);
                }
                echo '<details><summary>' . ($record['state'] === 'draft' ? 'Ubah atau tandatangani draf' : 'Tambah amendemen') . '</summary>';
                clinicalRecordForm($hidden, $record);
                echo '</details>';
            }
            echo '</section>';
        }
        if (count($records) > 100) {
            echo '<p class="subtle">Menampilkan 100 catatan terbaru yang diizinkan.</p>';
        }
        if ($allowed) {
            echo '<section class="surface"><h2>Catat kunjungan</h2>';
            clinicalRecordForm($hidden, null);
            echo '</section>';
        }
    }, $user, CLINICAL);
    exit;
}

function clinicalHistory(array $user, array $priv, string $purpose): never
{
    $facility = (int) $priv['facility_id'];
    $patient = key32($_POST,'patient');
    $id = key32($_POST);
    $reason = clinicalReason();
    $rows = tx(static function () use ($user,$priv,$facility,$patient,$id,$purpose,$reason): array {
        $sources = requireRecordFacilities($priv,$patient,$purpose,'read');
        $in = implode(',',array_fill(0,count($sources),'?'));
        $rows = qu("SELECT v.version,v.state,v.created_at,u.name AS author,e.kind,v.payload_ciphertext
            FROM clinical_encounters e JOIN clinical_record_versions v ON v.encounter_id=e.id
            JOIN users u ON u.id=v.author_id WHERE e.id=? AND e.patient_id=? AND e.facility_id IN ($in)
            AND (v.state<>'draft' OR v.author_id=?) ORDER BY v.version DESC LIMIT 100",
            [$id,$patient,...$sources,$user['id']])->fetchAll();
        if (!$rows) {
            fail(404,'Riwayat catatan tidak ditemukan.');
        }
        foreach ($rows as &$row) {
            $row['payload'] = json_decode(unseal($row['payload_ciphertext'],$id . ':' . $row['version']),true,flags:JSON_THROW_ON_ERROR);
            unset($row['payload_ciphertext']);
        }
        unset($row);
        clinicalAudit($user['id'],$facility,$patient,'history',$purpose,reason:$reason);
        return $rows;
    });
    page('Riwayat versi catatan', static function () use ($patient,$facility,$purpose,$reason,$rows): void {
        echo '<section class="surface">';
        postButton(CLINICAL . '/read','Kembali ke timeline',['patient'=>$patient,'workplace'=>$facility,'purpose'=>$purpose,'reason'=>$reason]);
        echo '</section>';
        foreach ($rows as $row) {
            $label = ['draft'=>'Draf','signed'=>'Ditandatangani internal','amended'=>'Diamendemen'][$row['state']];
            echo '<section class="surface"><h2>Versi ' . e($row['version']) . '</h2>';
            definition(['Status'=>$label,'Petugas'=>$row['author'],'Dicatat (UTC)'=>$row['created_at']] + clinicalValues($row));
            echo '</section>';
        }
    },$user,CLINICAL);
    exit;
}

function clinicalValues(array $record): array
{
    $values = [];
    foreach (CLINICAL_FIELDS + RIKKES_FIELDS as $key=>$label) {
        if (!empty($record['payload'][$key])) {
            $values[$label] = $record['payload'][$key];
        }
    }
    if ($record['kind'] === 'rikkes') {
        $values['Tahun'] = $record['payload']['year'];
        $values['Jenis'] = RIKKES_KIND[$record['payload']['rikkes_kind']];
        $values['Kesimpulan klinisi'] = RIKKES_RESULT[$record['payload']['conclusion']] ?? 'Belum ditetapkan';
    }
    if (isset($record['payload']['amend_reason'])) {
        $values['Alasan amendemen'] = $record['payload']['amend_reason'];
    }
    return $values;
}

function clinicalRecordForm(array $hidden, ?array $record): void
{
    if ($record) {
        $hidden += ['id'=>$record['id'],'version'=>$record['row_version'],'kind'=>$record['kind']];
    }
    $payload = $record['payload'] ?? [];
    postStart(CLINICAL . '/save', $hidden);
    if (!$record) {
        echo '<div class="form-grid">';
        input('encounter_on','Tanggal pemeriksaan',day(),'date',true);
        selectField('kind','Jenis catatan',['visit'=>'Kunjungan','rikkes'=>'Rikkes']);
        echo '</div>';
    }
    foreach (CLINICAL_FIELDS as $key=>$label) {
        textArea($key,$label,$payload[$key] ?? '');
    }
    if (!$record || $record['kind'] === 'rikkes') {
        echo '<details><summary>Isian khusus Rikkes</summary><div class="form-grid">';
        input('year','Tahun',$payload['year'] ?? today()->format('Y'),'number');
        selectField('rikkes_kind','Jenis pemeriksaan',RIKKES_KIND,$payload['rikkes_kind'] ?? 'periodic');
        selectField('conclusion','Kesimpulan klinisi',RIKKES_RESULT,$payload['conclusion'] ?? '','Belum ditetapkan');
        echo '</div>';
        foreach (RIKKES_FIELDS as $key=>$label) {
            textArea($key,$label,$payload[$key] ?? '');
        }
        echo '</details>';
    }
    if ($record && $record['state'] !== 'draft') {
        textArea('amend_reason','Alasan amendemen (minimal 12 karakter)');
        echo '<button name="decision" value="amend">Simpan amendemen</button>';
    } else {
        echo '<div class="actions"><button name="decision" value="draft">Simpan draf</button>';
        echo '<button name="decision" value="sign">Tandatangani internal</button></div>';
    }
    echo '</form>';
}

function clinicalSave(array $user, array $priv, string $purpose): never
{
    $patient = key32($_POST,'patient');
    $reason = clinicalReason();
    $facility = (int) $priv['facility_id'];
    $id = isset($_POST['id']) ? key32($_POST) : null;
    $decision = choice($_POST,'decision',['draft','sign','amend']);
    tx(static function () use ($user,$priv,$purpose,$patient,$facility,$id,$decision,$reason): void {
        requireRecordFacilities($priv,$patient,$purpose,'write');
        $kind = choice($_POST,'kind',['visit','rikkes']);
        $payload = [];
        foreach (CLINICAL_FIELDS as $key=>$label) {
            $payload[$key] = field($_POST,$key,4000);
        }
        if ($kind === 'rikkes') {
            foreach (RIKKES_FIELDS as $key=>$label) {
                $payload[$key] = field($_POST,$key,4000);
            }
            $year = number($_POST,'year');
            if (!$year || $year < 1900 || $year > (int) today()->format('Y') + 1) {
                fail(422,'Tahun Rikkes tidak valid.');
            }
            $payload['year'] = $year;
            $payload['rikkes_kind'] = choice($_POST,'rikkes_kind',array_keys(RIKKES_KIND));
            $payload['conclusion'] = choice($_POST,'conclusion',$decision === 'draft' ? ['',...array_keys(RIKKES_RESULT)] : array_keys(RIKKES_RESULT));
        }
        if ($decision !== 'draft' && $payload['summary'] === '') {
            fail(422,'Resume diperlukan sebelum ditandatangani.');
        }
        if ($id) {
            $prior = qu('SELECT e.row_version,e.kind,v.state,v.author_id FROM clinical_encounters e JOIN clinical_record_versions v
                ON v.encounter_id=e.id AND v.version=e.row_version WHERE e.id=? AND e.patient_id=? AND e.facility_id=? FOR UPDATE',
                [$id,$patient,$facility])->fetch();
            if (!$prior || $kind !== $prior['kind'] || (int) $prior['row_version'] !== number($_POST,'version')) {
                fail(409,'Catatan telah berubah atau berada di luar penugasan.');
            }
            if ($prior['state'] === 'draft') {
                if ($decision === 'amend' || (int) $prior['author_id'] !== $user['id']) {
                    fail(403,'Perubahan draf hanya tersedia bagi penulis.');
                }
            } elseif ($decision !== 'amend') {
                fail(409,'Catatan bertanda tangan memerlukan amendemen.');
            }
            $version = (int) $prior['row_version'] + 1;
            qu('UPDATE clinical_encounters SET row_version=? WHERE id=?',[$version,$id]);
        } else {
            if ($decision === 'amend') {
                fail(422,'Pilih catatan yang akan diamendemen.');
            }
            $date = calendar($_POST,'encounter_on') ?? fail(422,'Tanggal pemeriksaan diperlukan.');
            if ($date > day()) {
                fail(422,'Tanggal pemeriksaan tidak boleh di masa depan.');
            }
            $version = 1;
            $id = bin2hex(random_bytes(16));
            qu('INSERT INTO clinical_encounters (id,patient_id,facility_id,encounter_on,kind) VALUES (?,?,?,?,?)',
                [$id,$patient,$facility,$date,$kind]);
        }
        if ($decision === 'amend') {
            $payload['amend_reason'] = field($_POST,'amend_reason',1000,true);
            if (mb_strlen($payload['amend_reason']) < 12) {
                fail(422,'Jelaskan alasan amendemen dengan minimal 12 karakter.');
            }
        }
        $state = match ($decision) {'draft'=>'draft','sign'=>'signed','amend'=>'amended'};
        qu('INSERT INTO clinical_record_versions (encounter_id,version,author_id,state,payload_ciphertext) VALUES (?,?,?,?,?)',
            [$id,$version,$user['id'],$state,seal(json_encode($payload,JSON_THROW_ON_ERROR),"$id:$version")]);
        if ($version > 1) {
            qu('INSERT INTO clinical_documents (encounter_id,version,file_id)
                SELECT encounter_id,?,file_id FROM clinical_documents WHERE encounter_id=? AND version=?', [$version,$id,$version-1]);
        }
        clinicalAudit($user['id'],$facility,$patient,$decision,$purpose,reason:$reason);
    });
    page('Catatan klinis tersimpan',static function () use ($patient,$facility,$purpose,$reason): void {
        echo '<section class="surface"><p role="status">Catatan klinis disimpan.</p>';
        postButton(CLINICAL . '/read','Buka timeline',['patient'=>$patient,'workplace'=>$facility,'purpose'=>$purpose,'reason'=>$reason]);
        echo '</section>';
    },$user,CLINICAL);
    exit;
}

function clinicalPdf(array $user, string $patient, array $records, string $destination): never
{
    require_once PROJECT . '/private/app/Services/exports.php';
    require_once PROJECT . '/private/vendor/fpdf/fpdf.php';
    $pdf = new class('P','mm','A4') extends FPDF {
        public function Header(): void
        {
            $this->SetFont('Helvetica','B',9);
            $this->SetTextColor(120,45,45);
            $this->Cell(0,6,'BINPROFKES | PRIVAT',0,1,'R');
            $this->SetTextColor(0,0,0);
        }
    };
    $pdf->SetAutoPageBreak(true,18);
    $pdf->SetTitle('Ringkasan Perawatan');
    $pdf->AddPage();
    $pdf->SetFont('Helvetica','B',16);
    $pdf->Cell(0,10,'PRIVAT / Ringkasan Perawatan',0,1);
    $pdf->SetFont('Helvetica','',10);
    $emit = static function (string $line) use ($pdf): void {
        $plain = preg_replace('/[\x00-\x1F\x7F]/u',' ', $line);
        $pdf->MultiCell(0,5,iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$plain) ?: '');
    };
    $emit('Pasien: ' . $patient);
    $emit('Tujuan: ' . $destination);
    $emit('Dibuat oleh: ' . $user['name'] . ' / ' . day());
    $latestRikkes = false;
    foreach ($records as $record) {
        if ($record['kind'] === 'rikkes' && $latestRikkes) {
            continue;
        }
        $latestRikkes = $latestRikkes || $record['kind'] === 'rikkes';
        $pdf->Ln(3);
        $emit($record['encounter_on'] . ' / ' . $record['facility'] . ' / ' . ($record['kind'] === 'rikkes' ? 'Rikkes terakhir' : 'Kunjungan'));
        foreach (clinicalValues($record) as $label=>$value) {
            $emit($label . ': ' . $value);
        }
    }
    sendPdfAttachment($pdf, 'ringkasan-perawatan.pdf');
}
