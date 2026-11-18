<?php
declare(strict_types=1);

function validatedDocument(array $file): array
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

/** Persist the file and its authorized association as one operation. */
function storePrivateDocument(array $upload,string $context,callable $attach): void
{
    [$name,$mime,$bytes] = validatedDocument($upload);
    $id = bin2hex(random_bytes(16));
    $cipher = seal($bytes,"$id:$context");
    $stage = directory('quarantine') . '/' . $id;
    $target = directory('uploads') . '/' . $id;
    try {
        if (file_put_contents($stage,$cipher,LOCK_EX) !== strlen($cipher)) {
            throw new RuntimeException('Unable to stage private document');
        }
        tx(static function () use ($id,$name,$mime,$bytes,$cipher,$stage,$target,$attach): void {
            if (!rename($stage,$target)) {
                throw new RuntimeException('Unable to store private document');
            }
            qu("INSERT INTO file_objects (id,original_name,media_type,byte_length,key_id,ciphertext_sha256,scan_status)
                VALUES (?,?,?,?,?,?,'unavailable')",[$id,$name,$mime,strlen($bytes),cfg('key_id'),hash('sha256',$cipher,true)]);
            $attach($id);
        });
    } catch (Throwable $error) {
        foreach ([$stage,$target] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        throw $error;
    }
}

function readPrivateDocument(array $meta,string $context): string
{
    $id = $meta['id'];
    $path = directory('uploads') . '/' . $id;
    $cipher = is_file($path) ? file_get_contents($path) : false;
    if ($cipher === false || !hash_equals($meta['ciphertext_sha256'],hash('sha256',$cipher,true))) {
        fail(503,'Lampiran tidak dapat diverifikasi.');
    }
    return unseal($cipher,"$id:$context");
}

function sendPrivateDocument(array $meta,string $bytes,bool $preview = false): never
{
    $mimes = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'];
    $extension = $mimes[$meta['media_type']] ?? fail(415,'Jenis lampiran tidak didukung.');
    if ($preview && $meta['scan_status'] !== 'clean') {
        fail(409,'Pratinjau memerlukan pemindaian berkas yang lulus.');
    }
    header('Cache-Control: no-store, private');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Content-Type: ' . $meta['media_type']);
    header('Content-Length: ' . strlen($bytes));
    $mode = $preview ? 'inline' : 'attachment';
    header("Content-Disposition: $mode; filename=\"lampiran.$extension\"; filename*=UTF-8''" . rawurlencode($meta['original_name']));
    echo $bytes;
    exit;
}
