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
