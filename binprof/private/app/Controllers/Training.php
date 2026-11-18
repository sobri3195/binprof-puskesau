<?php
declare(strict_types=1);

require_once PROJECT . '/private/app/Controllers/Personnel.php';

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
    foreach (['unit_id'=>'p.unit_id','type_id'=>'t.type_id'] as $name => $column) {
        if (($value = number($_GET, $name)) !== null) {
            $where[] = "$column=?";
            $params[] = $value;
        }
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
    switch ($certificate) {
        case 'unknown': $where[] = 't.expires_on IS NULL'; break;
        case 'current': $where[] = 't.expires_on>?'; $params[] = day(90); break;
        case 'soon': $where[] = 't.expires_on BETWEEN ? AND ?'; array_push($params, day(), day(90)); break;
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
        input('q', 'Nama, NRP atau jenis', $_GET['q'] ?? '', max: 100);
        selectField('unit_id', 'Satuan', optionsFor('units', $user, false), $_GET['unit_id'] ?? '', 'Semua');
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
    $people = [];
    if (!$id) {
        [$scoped, $params] = scope($user, 'p.unit_id', 'p.facility_id');
        $q = field($_GET, 'q', 100);
        if (mb_strlen($q) >= 2) {
            $people = qu("SELECT p.id,CONCAT(p.name,' / ',p.nrp)
                FROM personnel p WHERE $scoped AND p.archived_at IS NULL AND (LOCATE(?,p.name)>0 OR p.nrp=?)
                ORDER BY p.name,p.id LIMIT 50", [...$params, $q, $q])->fetchAll(PDO::FETCH_KEY_PAIR);
        }
    }
    page($id ? 'Ubah diklat' : 'Tambah diklat', static function () use ($id, $row, $people): void {
        echo '<section class="surface form-surface">';
        if (!$id) {
            getStart(TRAINING . '/edit');
            input('q', 'Cari personel (minimal 2 karakter)', $_GET['q'] ?? '', required: true, max: 100);
            echo '<button>Cari</button></form>';
            if (!$people) {
                echo '<p class="empty">Cari berdasarkan nama atau NRP untuk memilih peserta.</p></section>';
                return;
            }
        }
        postStart(TRAINING . '/save', $id ? ['id'=>$id,'version'=>$row['row_version']] : []);
        echo '<div class="form-grid">';
        if (!$id) {
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
        linkButton(TRAINING, 'Batal');
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
    });
    done('Status diklat diperbarui.', TRAINING . '/detail', ['id'=>$id]);
}

function validatedCertificate(array $file): array
{
    $limits = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png'];
    $name = $file['name'] ?? '';
    $path = $file['tmp_name'] ?? '';
    if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($name) || !is_string($path) || !is_uploaded_file($path)
        || !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} _()-]{0,130}\.(pdf|jpe?g|png)$/iuD', $name, $matches)
        || (($file['full_path'] ?? $name) !== $name)) {
        fail(422, 'Unggah satu berkas PDF, JPEG atau PNG dengan nama sederhana.');
    }
    $size = filesize($path);
    if (!$size || $size > 10 * 1024 * 1024) {
        fail(422, 'Ukuran berkas harus kurang dari atau sama dengan 10 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $bytes = file_get_contents($path);
    if ($bytes === false || $mime !== $limits[strtolower($matches[1])]) {
        fail(422, 'Isi berkas tidak cocok dengan ekstensi.');
    }
    if ($mime === 'application/pdf') {
        if (!preg_match('/^%PDF-[12]\.\d[\r\n]/D', $bytes) || !str_ends_with(rtrim($bytes), '%%EOF')) {
            fail(422, 'Struktur dasar PDF tidak valid.');
        }
    } else {
        $image = @getimagesizefromstring($bytes);
        if (!$image || $image['mime'] !== $mime || $image[0] > 12000 || $image[1] > 12000
            || ($mime === 'image/png' && !str_ends_with($bytes, "IEND\xaeB\x60\x82"))
            || ($mime === 'image/jpeg' && !str_ends_with($bytes, "\xff\xd9"))) {
            fail(422, 'Struktur dasar gambar tidak valid.');
        }
    }
    return [$name, $mime, $bytes];
}

function trainingUpload(array $user): never
{
    $training = key32($_POST);
    trainingRecord($user, $training);
    [$name, $mime, $bytes] = validatedCertificate($_FILES['certificate'] ?? []);
    $id = bin2hex(random_bytes(16));
    $cipher = seal($bytes, "$id:attachment");
    $stage = directory('quarantine') . '/' . $id;
    $target = directory('uploads') . '/' . $id;
    try {
        if (file_put_contents($stage, $cipher, LOCK_EX) !== strlen($cipher)) {
            throw new RuntimeException('Unable to stage attachment');
        }
        tx(static function () use ($user, $training, $id, $name, $mime, $bytes, $cipher, $stage, $target): void {
            $row = trainingRecord($user, $training, true);
            if ($row['archived_at']) {
                fail(409, 'Diklat berada dalam arsip.');
            }
            if (!rename($stage, $target)) {
                throw new RuntimeException('Unable to store attachment');
            }
            qu("INSERT INTO file_objects (id,original_name,media_type,byte_length,key_id,ciphertext_sha256,scan_status)
                VALUES (?,?,?,?,?,?,'unavailable')",
                [$id, $name, $mime, strlen($bytes), cfg('key_id'), hash('sha256', $cipher, true)]);
            qu('INSERT INTO training_documents (training_id,file_id) VALUES (?,?)', [$training, $id]);
            audit($user['id'], 'upload', 'training', $training, (int) $row['unit_id']);
        });
    } catch (Throwable $error) {
        foreach ([$stage, $target] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        throw $error;
    }
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
    $path = directory('uploads') . '/' . $file;
    $cipher = is_file($path) ? file_get_contents($path) : false;
    if ($cipher === false || !hash_equals($meta['ciphertext_sha256'], hash('sha256', $cipher, true))) {
        fail(503, 'Lampiran tidak dapat diverifikasi.');
    }
    $bytes = unseal($cipher, "$file:attachment");
    audit($user['id'], 'download', 'training', $training, (int) $row['unit_id']);
    $ext = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'][$meta['media_type']];
    header('Content-Type: ' . $meta['media_type']);
    header('Content-Length: ' . strlen($bytes));
    header('Content-Disposition: attachment; filename="sertifikat.' . $ext . '"; filename*=UTF-8\'\'' . rawurlencode($meta['original_name']));
    echo $bytes;
    exit;
}
