<?php
declare(strict_types=1);

require_once __DIR__ . '/write.php';
require_once PROJECT . '/private/app/Services/documents.php';

const PROFILE_FILE_PURPOSES = ['document'=>'Dokumen utama','transcript'=>'Transkrip',
    'legalization'=>'Bukti legalisasi','support'=>'Pendukung'];

function profileDocumentSet(array $user,string $tab,array $row): array
{
    if (!profileAllowed($user,$tab,'download')) {
        return [];
    }
    if ($tab==='certificates' && !empty($row['training_id'])) {
        if (!can($user,'training.download')) {
            return [];
        }
        return qu("SELECT f.id,f.original_name,f.scan_status,'document' AS purpose
            FROM training_documents d JOIN file_objects f ON f.id=d.file_id
            WHERE d.training_id=? ORDER BY f.created_at,f.id",[$row['training_id']])->fetchAll();
    }
    $column = PROFILE_ATTACHMENTS[$tab];
    return qu("SELECT f.id,f.original_name,f.scan_status,a.purpose FROM personnel_record_attachments a
        JOIN file_objects f ON f.id=a.file_id WHERE a.$column=? ORDER BY a.created_at,f.id",[$row['id']])->fetchAll();
}

function profileStoreAttachment(array $user,string $personnel,string $tab,string $id): void
{
    profileRow($user,$personnel,$tab,$id);
    $allowed = $tab === 'diplomas' ? array_keys(PROFILE_FILE_PURPOSES) : ['document','support'];
    $purpose = choice($_POST,'purpose',$allowed,'document');
    storePrivateDocument($_FILES['attachment'] ?? [],'profile_attachment',
        static function (string $file) use ($user,$personnel,$tab,$id,$purpose): void {
            $staff = person($user,$personnel,true);
            $row = profileRow($user,$personnel,$tab,$id,true);
            profileRequireEditable($staff,$row);
            if (!empty($row['training_id'])) {
                fail(409,'Unggah sertifikat ini dari Diklat untuk mempertahankan satu berkas sumber.');
            }
            if (($row['case_status'] ?? null)==='closed' || ($row['document_status'] ?? 'current')!=='current') {
                fail(409,'Catatan final ini tidak menerima lampiran baru.');
            }
            $version = profileVersion($row);
            $column = PROFILE_ATTACHMENTS[$tab];
            qu("INSERT INTO personnel_record_attachments (file_id,$column,purpose,uploaded_by) VALUES (?,?,?,?)",
                [$file,$id,$purpose,$user['id']]);
            $table = PROFILE_TABLES[$tab];
            qu("UPDATE $table SET updated_at=UTC_TIMESTAMP(),updated_by=?,row_version=?,verification_status='pending',
                verified_at=NULL,verified_by=NULL,verification_notes=NULL WHERE id=?",[$user['id'],$version,$id]);
            profileHistory($user,$personnel,$tab,$id,$version,'upload',$purpose);
        });
}

function profileFetchAttachment(array $user,string $personnel,string $tab,string $id,bool $training,string $file,bool $preview): never
{
    [$meta,$bytes] = tx(static function () use ($user,$personnel,$tab,$id,$training,$file,$preview): array {
        person($user,$personnel);
        $row = $training ? profileTrainingRow($user,$personnel,$id) : profileRow($user,$personnel,$tab,$id);
        $trainingId = $training ? $id : ($row['training_id'] ?? null);
        if ($trainingId) {
            if (!can($user,'training.download')) {
                fail(404,'Lampiran tidak ditemukan.');
            }
            $meta = qu('SELECT f.* FROM training_documents d JOIN file_objects f ON f.id=d.file_id
                WHERE d.training_id=? AND f.id=?',[$trainingId,$file])->fetch();
        } else {
            $column = PROFILE_ATTACHMENTS[$tab];
            $meta = qu("SELECT f.* FROM personnel_record_attachments a JOIN file_objects f ON f.id=a.file_id
                WHERE a.$column=? AND f.id=?",[$id,$file])->fetch();
        }
        if (!$meta) {
            fail(404,'Lampiran tidak ditemukan.');
        }
        if ($preview && $meta['scan_status'] !== 'clean') {
            fail(409,'Pratinjau memerlukan pemindaian berkas yang lulus.');
        }
        $bytes = readPrivateDocument($meta,$trainingId ? 'attachment' : 'profile_attachment');
        if ($training) {
            audit($user['id'],$preview ? 'preview' : 'download','training',$trainingId);
        } else {
            profileHistory($user,$personnel,$tab,$id,(int) $row['row_version'],$preview ? 'preview' : 'download');
        }
        return [$meta,$bytes];
    });
    sendPrivateDocument($meta,$bytes,$preview);
}
