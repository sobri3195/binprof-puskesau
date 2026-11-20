<?php
declare(strict_types=1);

require_once PROJECT . '/private/app/Controllers/Personnel.php';
require_once PROJECT . '/private/app/Services/documents.php';

const TRAINING = '/app/pelatihan';
const CERTIFICATE = ['unknown'=>'Belum diketahui','current'=>'Berlaku','soon'=>'Segera berakhir','expired'=>'Kedaluwarsa'];
const TRAINING_FIELDS = 't.id,t.started_on,t.ended_on,t.expires_on,t.completed,t.organizer,p.name,p.nrp,u.name AS unit,tt.name AS kind';

function trainingQuery(array $user): array
{
    [$scoped, $params] = scope($user, 'p.unit_id', 'p.facility_id');
    $from = 'FROM training_records t JOIN personnel p ON p.id=t.personnel_id JOIN units u ON u.id=p.unit_id
        JOIN training_types tt ON tt.id=t.type_id';
    $state = choice($_GET, 'state', ['', '0', '1', 'archived']);
    $where = [$scoped, 'p.archived_at IS NULL', $state === 'archived' ? 't.archived_at IS NOT NULL' : 't.archived_at IS NULL'];
    if ($state === '0' || $state === '1') {
        $where[] = 't.completed=?';
        $params[] = (int) $state;
    }
    if (($q = field($_GET, 'q', 100)) !== '') {
        $where[] = '(LOCATE(?,p.name)>0 OR LOCATE(?,p.nrp)>0 OR LOCATE(?,tt.name)>0)';
        array_push($params, $q, $q, $q);
    }
    foreach (['unit_id'=>'p.unit_id','type_id'=>'t.type_id','facility_id'=>'p.facility_id','profession_id'=>'p.profession_id'] as $name => $column) {
        if (($value = number($_GET, $name)) !== null) {
            $where[] = "$column=?";
            $params[] = $value;
        }
    }
    if (($specialty = number($_GET,'specialty_id')) !== null) {
        $where[] = 'EXISTS (SELECT 1 FROM personnel_specialties s WHERE s.personnel_id=p.id AND s.specialty_id=?)';
        $params[] = $specialty;
    }
    if (choice($_GET,'active_personnel',['0','1'],'0')==='1') {
        $where[] = 'p.active=1';
    }
    $start = calendar($_GET, 'from');
    $end = calendar($_GET, 'until');
    if ($start && $end && $end < $start) {
        fail(422, 'Akhir rentang mendahului tanggal mulai.');
    }
    if ($start) {
        $where[] = 't.started_on>=?';
        $params[] = $start;
    }
    if ($end) {
        $where[] = 't.started_on<=?';
        $params[] = $end;
    }
    $certificate = choice($_GET, 'certificate', ['', ...array_keys(CERTIFICATE)]);
    $expiryDays = number($_GET,'expiry_days') ?? 90;
    if ($expiryDays > 90) {
        fail(422,'Rentang sertifikat adalah 1–90 hari.');
    }
    switch ($certificate) {
        case 'unknown': $where[] = 't.expires_on IS NULL'; break;
        case 'current': $where[] = 't.expires_on>?'; $params[] = day(90); break;
        case 'soon': $where[] = 't.expires_on BETWEEN ? AND ?'; array_push($params, day(), day($expiryDays)); break;
        case 'expired': $where[] = 't.expires_on<?'; $params[] = day(); break;
    }
    return [$from, implode(' AND ', $where), $params];
}

function trainingCells(array $row): array
{
    $expiry = $row['expires_on'];
    $certificate = $expiry === null ? 'unknown' : ($expiry < day() ? 'expired' : ($expiry <= day(90) ? 'soon' : 'current'));
    return [$row['name'], $row['nrp'], $row['unit'], $row['kind'], $row['organizer'], $row['started_on'], $row['ended_on'],
        $row['completed'] ? 'Sudah' : 'Belum', $expiry, CERTIFICATE[$certificate]];
}

function trainingIndex(array $user): void
{
    [$from, $where, $params] = trainingQuery($user);
    $order = 't.started_on DESC,t.id';
    $labels = ['Nama','NRP','Satuan','Jenis','Penyelenggara','Mulai','Selesai','Pelaksanaan','Akhir berlaku','Sertifikat'];
    if (isset($_GET['format'])) {
        if (!can($user, 'training.export')) {
            fail(403, 'Akses ekspor ditolak.');
        }
        require_once PROJECT . '/private/app/Services/exports.php';
        $format = choice($_GET, 'format', ['csv','xlsx','pdf']);
        $rows = qu('SELECT ' . TRAINING_FIELDS . " $from WHERE $where ORDER BY $order LIMIT 1001", $params)->fetchAll();
        audit($user['id'], 'export_' . $format, 'training');
        sendExport($format, 'Diklat', $labels, array_map('trainingCells', $rows));
    }
    [$rows, $total, $current, $per] = limitedRows($from, $where, $params, TRAINING_FIELDS, $order);
    $summary = qu("SELECT COUNT(*) AS total, SUM(t.completed=1) AS done_count, SUM(t.completed=0) AS pending_count,
        SUM(t.expires_on BETWEEN ? AND ?) AS soon_count, SUM(t.expires_on<?) AS expired_count $from WHERE $where",
        [day(), day(90), day(), ...$params])->fetch();
    page('Pendidikan & pelatihan', static function () use ($user, $rows, $total, $current, $per, $labels, $summary): void {
        echo '<div class="stats compact">';
        foreach (['total'=>'Total hasil','done_count'=>'Sudah','pending_count'=>'Belum','soon_count'=>'Segera berakhir','expired_count'=>'Kedaluwarsa'] as $key => $label) {
            echo '<div><span>' . e($label) . '</span><strong>' . e($summary[$key] ?? 0) . '</strong></div>';
        }
        echo '</div><section class="surface"><div class="section-heading"><h2>Daftar diklat</h2><div class="actions">';
        if (can($user, 'training.write')) {
            linkButton(TRAINING . '/edit', 'Tambah diklat', class: 'primary');
        }
        if (can($user, 'training.export')) {
            exportButtons(TRAINING);
        }
        echo '</div></div>';
        getStart(TRAINING);
        if (isset($_GET['expiry_days'])) {
            echo '<input type="hidden" name="expiry_days" value="' . e($_GET['expiry_days']) . '">';
        }
        input('q', 'Nama, NRP atau jenis', $_GET['q'] ?? '', max: 100);
        selectField('unit_id', 'Satuan', optionsFor('units', $user, false), $_GET['unit_id'] ?? '', 'Semua');
        selectField('facility_id','Fasilitas',optionsFor('facilities',$user,false),$_GET['facility_id'] ?? '','Semua');
        selectField('profession_id','Profesi',optionsFor('professions',activeOnly:false),$_GET['profession_id'] ?? '','Semua');
        selectField('specialty_id','Spesialisasi',optionsFor('specialties',activeOnly:false),$_GET['specialty_id'] ?? '','Semua');
        selectField('active_personnel','Status dinas peserta',['0'=>'Semua','1'=>'Aktif'],$_GET['active_personnel'] ?? '0');
        selectField('type_id', 'Jenis', optionsFor('training_types', activeOnly: false), $_GET['type_id'] ?? '', 'Semua');
        selectField('state', 'Pelaksanaan', ['0'=>'Belum','1'=>'Sudah','archived'=>'Arsip'], $_GET['state'] ?? '', 'Semua nonarsip');
        selectField('certificate', 'Sertifikat', CERTIFICATE, $_GET['certificate'] ?? '', 'Semua');
        input('from', 'Mulai dari', $_GET['from'] ?? '', 'date');
        input('until', 'Mulai hingga', $_GET['until'] ?? '', 'date');
        selectField('per', 'Per halaman', [25=>25,50=>50,100=>100], $per);
        echo '<div class="actions"><button>Terapkan</button>';
        linkButton(TRAINING, 'Reset');
        echo '</div></form>';
        table([...$labels,'Aksi'], $rows, static function (array $row): void {
            td(...trainingCells($row));
            echo '<td>';
            linkButton(TRAINING . '/detail', 'Detail', ['id'=>$row['id']]);
            echo '</td>';
        });
        paginate(TRAINING, $total, $current, $per);
        echo '</section>';
    }, $user, TRAINING, 'Pantau pelaksanaan dan masa berlaku sertifikat.');
}

function trainingRecord(array $user, string $id, bool $lock = false): array
{
    [$scoped, $params] = scope($user, 'p.unit_id', 'p.facility_id');
    $row = qu("SELECT t.*,p.unit_id,p.name,tt.name AS kind FROM training_records t JOIN personnel p ON p.id=t.personnel_id
        JOIN training_types tt ON tt.id=t.type_id WHERE t.id=? AND p.archived_at IS NULL AND $scoped"
        . ($lock ? ' FOR UPDATE' : ''), [$id, ...$params])->fetch();
    if (!$row) {
        fail(404, 'Diklat tidak ditemukan.');
    }
    return $row;
}

function trainingDetail(array $user): void
{
    $id = key32($_GET);
    $row = trainingRecord($user, $id);
    $docs = qu('SELECT f.id,f.original_name,f.media_type,f.scan_status FROM training_documents d
        JOIN file_objects f ON d.file_id=f.id WHERE d.training_id=? ORDER BY f.created_at,f.id', [$id])->fetchAll();
    page($row['kind'], static function () use ($user, $row, $docs, $id): void {
        echo '<section class="surface">';
        definition(['Personel'=>$row['name'],'Penyelenggara'=>$row['organizer'],'Mulai'=>$row['started_on'],
            'Selesai'=>$row['ended_on'],'Akhir sertifikat'=>$row['expires_on'],'Pelaksanaan'=>$row['completed'] ? 'Sudah' : 'Belum',
            'Catatan'=>$row['notes'],'Arsip'=>$row['archived_at'] ? 'Ya' : 'Tidak']);
        echo '<div class="actions">';
        linkButton(TRAINING, 'Kembali');
        if (!$row['archived_at'] && can($user, 'training.write')) {
            linkButton(TRAINING . '/edit', 'Ubah diklat', ['id'=>$id], 'primary');
        }
        echo '</div>';
        if (can($user, 'training.write')) {
            echo '<details class="entry"><summary>' . ($row['archived_at'] ? 'Pulihkan' : 'Arsipkan') . ' diklat</summary>';
            postButton(TRAINING . '/status', 'Konfirmasi', ['id'=>$id,'version'=>$row['row_version']]);
            echo '</details>';
        }
        echo '</section><section class="surface"><h2>Lampiran sertifikat</h2>';
        table(['Nama','Tipe','Pemindaian','Aksi'], $docs, static function (array $doc) use ($id, $user): void {
            td($doc['original_name'], $doc['media_type'], $doc['scan_status'] === 'clean' ? 'Lolos pemindaian' : 'Pemindai tidak tersedia');
            echo '<td>';
            if (can($user, 'training.download')) {
                linkButton(TRAINING . '/file', 'Unduh', ['id'=>$id,'file'=>$doc['id']]);
            }
            echo '</td>';
        });
        if (!$row['archived_at'] && can($user, 'training.write')) {
            echo '<p>PDF, JPEG atau PNG, maksimal 10 MB. Berkas disediakan sebagai unduhan.</p>';
            postStart(TRAINING . '/upload', ['id'=>$id], true);
            echo '<label>Sertifikat<input type="file" name="certificate" accept=".pdf,.jpeg,.jpg,.png" required></label>';
            echo '<button>Unggah</button></form>';
        }
        echo '</section>';
    }, $user, TRAINING);
}

function trainingForm(array $user): void
{
    $id = isset($_GET['id']) ? key32($_GET) : null;
    $row = $id ? trainingRecord($user, $id) : [];
    if ($row['archived_at'] ?? null) {
        fail(409, 'Pulihkan arsip sebelum mengubah diklat.');
    }
    $participant = !$id && isset($_GET['personnel_id']) ? person($user,key32($_GET,'personnel_id')) : null;
    if ($participant && $participant['archived_at']) {
        fail(409,'Pulihkan arsip personel sebelum mencatat diklat.');
    }
    $people = [];
    $q = '';
    if (!$id && !$participant) {
        [$scoped, $params] = scope($user, 'p.unit_id', 'p.facility_id');
        $q = field($_GET, 'q', 100);
        if (mb_strlen($q) >= 2) {
            $people = qu("SELECT p.id,CONCAT(p.name,' / ',p.nrp)
                FROM personnel p WHERE $scoped AND p.archived_at IS NULL AND (LOCATE(?,p.name)>0 OR p.nrp=?)
                ORDER BY p.name,p.id LIMIT 50", [...$params, $q, $q])->fetchAll(PDO::FETCH_KEY_PAIR);
        }
    }
    page($id ? 'Ubah diklat' : 'Tambah diklat', static function () use ($id,$row,$people,$participant,$q): void {
        echo '<section class="surface form-surface">';
        if (!$id && !$participant) {
            getStart(TRAINING . '/edit');
            input('q','Nama atau NRP (minimal 2 karakter)',$q,required:true,max:100);
            echo '<button>Cari</button></form>';
            if (!$people) {
                echo '<p class="empty">' . ($q==='' ? 'Cari peserta untuk mulai mencatat diklat.' :
                    (mb_strlen($q)<2 ? 'Masukkan sedikitnya dua karakter.' : 'Personel yang sesuai tidak ditemukan dalam cakupan Anda.')) . '</p></section>';
                return;
            }
        }
        $hidden = $id ? ['id'=>$id,'version'=>$row['row_version']] : ($participant ? ['personnel_id'=>$participant['id']] : []);
        if ($participant) {
            definition(['Peserta'=>$participant['name'] . ' · ' . $participant['nrp']]);
        }
        postStart(TRAINING . '/save',$hidden);
        echo '<div class="form-grid">';
        if (!$id && !$participant) {
            selectField('personnel_id', 'Peserta', $people);
        }
        selectField('type_id', 'Jenis', optionsFor('training_types'), $row['type_id'] ?? '');
        input('organizer', 'Penyelenggara', $row['organizer'] ?? '', required: true);
        input('started_on', 'Mulai', $row['started_on'] ?? '', 'date');
        input('ended_on', 'Selesai', $row['ended_on'] ?? '', 'date');
        input('expires_on', 'Akhir berlaku sertifikat', $row['expires_on'] ?? '', 'date');
        selectField('completed', 'Pelaksanaan', [0=>'Belum',1=>'Sudah'], $row['completed'] ?? 0);
        echo '</div>';
        textArea('notes', 'Catatan', $row['notes'] ?? '');
        echo '<div class="actions"><button>Simpan</button>';
        linkButton($participant ? '/app/personel/detail' : TRAINING,'Batal',$participant ? ['id'=>$participant['id']] : []);
        echo '</div></form></section>';
    }, $user, TRAINING);
}

function trainingSave(array $user): never
{
    $existing = isset($_POST['id']);
    $id = $existing ? key32($_POST) : bin2hex(random_bytes(16));
    $started = calendar($_POST, 'started_on');
    $ended = calendar($_POST, 'ended_on');
    $expires = calendar($_POST, 'expires_on');
    if (($started && $ended && $ended < $started) || ($ended && $expires && $expires < $ended)) {
        fail(422, 'Periksa urutan tanggal diklat dan akhir sertifikat.');
    }
    tx(static function () use ($user, $existing, $id, $started, $ended, $expires): void {
        $row = $existing ? trainingRecord($user, $id, true) : person($user, key32($_POST, 'personnel_id'), true);
        if ($row['archived_at'] || ($existing && (int) $row['row_version'] !== number($_POST, 'version'))) {
            fail(409, 'Data sudah berubah atau diarsipkan.');
        }
        $values = [verifyMaster('training_types', number($_POST, 'type_id')), field($_POST, 'organizer', 160, true),
            $started, $ended, $expires, (int) choice($_POST, 'completed', ['0','1']), field($_POST, 'notes', 4000)];
        if ($existing) {
            qu('UPDATE training_records SET type_id=?,organizer=?,started_on=?,ended_on=?,expires_on=?,completed=?,notes=?,
                row_version=row_version+1 WHERE id=?', [...$values, $id]);
        } else {
            qu('INSERT INTO training_records (type_id,organizer,started_on,ended_on,expires_on,completed,notes,id,personnel_id)
                VALUES (?,?,?,?,?,?,?,?,?)', [...$values, $id, $row['id']]);
        }
        audit($user['id'], $existing ? 'update' : 'create', 'training', $id, (int) $row['unit_id']);
        mapRefreshFacilities();
    });
    done('Diklat disimpan.', TRAINING . '/detail', ['id'=>$id]);
}

function trainingStatus(array $user): never
{
    $id = key32($_POST);
    tx(static function () use ($id, $user): void {
        $row = trainingRecord($user, $id, true);
        if ((int) $row['row_version'] !== number($_POST, 'version')) {
            fail(409, 'Status sudah berubah. Muat ulang detail.');
        }
        qu('UPDATE training_records SET archived_at=?,row_version=row_version+1 WHERE id=?',
            [$row['archived_at'] ? null : gmdate('Y-m-d H:i:s'), $id]);
        audit($user['id'], $row['archived_at'] ? 'restore' : 'archive', 'training', $id, (int) $row['unit_id']);
        mapRefreshFacilities();
    });
    done('Status diklat diperbarui.', TRAINING . '/detail', ['id'=>$id]);
}

function trainingUpload(array $user): never
{
    $training = key32($_POST);
    trainingRecord($user, $training);
    storePrivateDocument($_FILES['certificate'] ?? [],'attachment',static function (string $file) use ($user,$training): void {
        $row = trainingRecord($user,$training,true);
        if ($row['archived_at']) {
            fail(409,'Diklat berada dalam arsip.');
        }
        qu('INSERT INTO training_documents (training_id,file_id) VALUES (?,?)',[$training,$file]);
        qu('UPDATE training_records SET row_version=row_version+1 WHERE id=?',[$training]);
        audit($user['id'],'upload','training',$training,(int) $row['unit_id']);
    });
    done('Lampiran disimpan. Pemindai malware tidak tersedia.', TRAINING . '/detail', ['id'=>$training]);
}

function trainingDownload(array $user): never
{
    $training = key32($_GET);
    $file = key32($_GET, 'file');
    $row = trainingRecord($user, $training);
    $meta = qu('SELECT f.* FROM file_objects f JOIN training_documents d ON f.id=d.file_id
        WHERE d.training_id=? AND f.id=?', [$training, $file])->fetch();
    if (!$meta) {
        fail(404, 'Lampiran tidak ditemukan.');
    }
    $bytes = readPrivateDocument($meta,'attachment');
    audit($user['id'],'download','training',$training,(int) $row['unit_id']);
    sendPrivateDocument($meta,$bytes);
}
