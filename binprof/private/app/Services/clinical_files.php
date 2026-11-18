<?php
declare(strict_types=1);

require_once PROJECT . '/private/app/Services/documents.php';

function clinicalDocuments(string $record, int $version): array
{
    return qu('SELECT f.id,f.media_type,f.scan_status FROM clinical_documents d
        JOIN file_objects f ON f.id=d.file_id WHERE d.encounter_id=? AND d.version=? ORDER BY f.created_at,f.id',
        [$record,$version])->fetchAll();
}

function clinicalAttachmentLinks(array $hidden, array $record): void
{
    if ($record['documents'] === []) {
        return;
    }
    echo '<h3>Lampiran</h3><ul class="atlas-list">';
    foreach ($record['documents'] as $i => $file) {
        echo '<li>';
        postButton(CLINICAL . '/download', 'Unduh lampiran ' . ($i + 1), $hidden
            + ['id'=>$record['id'],'version'=>$record['row_version'],'file'=>$file['id']]);
        if ($file['scan_status'] !== 'clean') {
            echo '<small>Pemindaian malware tidak tersedia</small>';
        }
        echo '</li>';
    }
    echo '</ul>';
}

function clinicalAttachmentForm(array $hidden, array $record): void
{
    echo '<details><summary>Tambah lampiran ke draf</summary><p>PDF, JPEG, atau PNG sampai 10 MB.</p>';
    postStart(CLINICAL . '/upload',$hidden + ['id'=>$record['id'],'version'=>$record['row_version']],true);
    echo '<label>Lampiran klinis<input name="document" type="file" accept=".pdf,.jpg,.jpeg,.png" required></label>';
    echo '<button>Unggah</button></form></details>';
}

function clinicalUpload(array $user, array $priv, string $purpose): never
{
    $patient = key32($_POST,'patient');
    $record = key32($_POST);
    $version = number($_POST,'version') ?? fail(422,'Versi catatan diperlukan.');
    $facility = (int) $priv['facility_id'];
    $reason = clinicalReason();
    requireRecordFacilities($priv,$patient,$purpose,'write');
    [, $mime, $data] = validatedDocument($_FILES['document'] ?? []);
    $id = bin2hex(random_bytes(16));
    $extensions = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'];
    $filename = 'lampiran-klinis.' . $extensions[$mime];
    $cipher = seal($data,"$id:clinical_attachment");
    $stage = directory('quarantine') . '/' . $id;
    $target = directory('uploads') . '/' . $id;
    try {
        if (file_put_contents($stage,$cipher,LOCK_EX) !== strlen($cipher)) {
            throw new RuntimeException('Unable to stage clinical attachment');
        }
        tx(static function () use ($user,$priv,$purpose,$patient,$record,$version,$facility,$reason,$id,$mime,$data,$filename,$cipher,$stage,$target): void {
            requireRecordFacilities($priv,$patient,$purpose,'write');
            $row = qu('SELECT e.row_version,v.state,v.author_id FROM clinical_encounters e JOIN clinical_record_versions v
                ON v.encounter_id=e.id AND v.version=e.row_version WHERE e.id=? AND e.patient_id=? AND e.facility_id=? FOR UPDATE',
                [$record,$patient,$facility])->fetch();
            if (!$row || $row['state'] !== 'draft' || $version !== (int) $row['row_version'] || (int) $row['author_id'] !== $user['id']) {
                fail(409,'Lampiran hanya dapat ditambah pada versi draf milik Anda yang masih aktif.');
            }
            if ((int) qu('SELECT COUNT(*) FROM clinical_documents WHERE encounter_id=? AND version=?',[$record,$version])->fetchColumn() >= 10) {
                fail(422,'Maksimal 10 lampiran per catatan.');
            }
            if (!rename($stage,$target)) {
                throw new RuntimeException('Unable to store clinical attachment');
            }
            qu("INSERT INTO file_objects (id,original_name,media_type,byte_length,key_id,ciphertext_sha256,scan_status)
                VALUES (?,?,?,?,?,?,'unavailable')", [$id,$filename,$mime,strlen($data),cfg('key_id'),hash('sha256',$cipher,true)]);
            qu('INSERT INTO clinical_documents VALUES (?,?,?)',[$record,$version,$id]);
            clinicalAudit($user['id'],$facility,$patient,'attach',$purpose,reason:$reason);
        });
    } catch (Throwable $error) {
        foreach ([$stage,$target] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        throw $error;
    }
    page('Lampiran tersimpan',static function () use ($patient,$facility,$purpose,$reason): void {
        echo '<section class="surface"><p role="status">Lampiran disimpan. Pemindaian malware tidak tersedia.</p>';
        postButton(CLINICAL . '/read','Buka timeline',['patient'=>$patient,'workplace'=>$facility,'purpose'=>$purpose,'reason'=>$reason]);
        echo '</section>';
    },$user,CLINICAL);
    exit;
}

function clinicalDownload(array $user, array $priv, string $purpose): never
{
    $patient = key32($_POST,'patient');
    $record = key32($_POST);
    $id = key32($_POST,'file');
    $version = number($_POST,'version') ?? fail(422,'Versi catatan diperlukan.');
    $reason = clinicalReason();
    [$meta,$data] = tx(static function () use ($user,$priv,$purpose,$patient,$record,$id,$version,$reason): array {
        $facilities = requireRecordFacilities($priv,$patient,$purpose,'read');
        $in = implode(',',array_fill(0,count($facilities),'?'));
        $meta = qu("SELECT f.media_type,f.original_name,f.ciphertext_sha256 FROM clinical_encounters e
            JOIN clinical_record_versions v ON v.encounter_id=e.id AND v.version=e.row_version
            JOIN clinical_documents d ON d.encounter_id=e.id AND d.version=v.version
            JOIN file_objects f ON f.id=d.file_id WHERE e.id=? AND e.patient_id=? AND v.version=? AND f.id=?
            AND e.facility_id IN ($in) AND (v.state<>'draft' OR v.author_id=?)",
            [$record,$patient,$version,$id,...$facilities,$user['id']])->fetch();
        if (!$meta) {
            fail(404,'Lampiran tidak ditemukan.');
        }
        $path = directory('uploads') . '/' . $id;
        $cipher = is_file($path) ? file_get_contents($path) : false;
        if ($cipher === false || !hash_equals($meta['ciphertext_sha256'],hash('sha256',$cipher,true))) {
            fail(503,'Lampiran tidak dapat diverifikasi.');
        }
        $data = unseal($cipher,"$id:clinical_attachment");
        clinicalAudit($user['id'],(int) $priv['facility_id'],$patient,'download',$purpose,reason:$reason);
        return [$meta,$data];
    });
    header('Cache-Control: no-store, private');
    header('Content-Type: ' . $meta['media_type']);
    header('Content-Length: ' . strlen($data));
    header('Content-Disposition: attachment; filename="' . $meta['original_name'] . '"');
    echo $data;
    exit;
}
