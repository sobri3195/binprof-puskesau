<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../private/app/Core/bootstrap.php';
require PROJECT . '/private/app/Services/PersonnelRecords/catalog.php';

try {
    if ($argc!==4 || !profileSchemaReady()) {
        fail(422,'Penggunaan: php tools/personnel-case-access.php EMAIL_PEMBERI EMAIL_PETUGAS read,write,verify,download,export (atau -).');
    }
    $operations = $argv[3]==='-' ? [] : array_unique(explode(',',$argv[3]));
    if (array_diff($operations,PROFILE_CASE_OPERATIONS) || ($operations && !in_array('read',$operations,true))) {
        fail(422,'Daftar kewenangan kasus tidak valid. Hak tambahan memerlukan read.');
    }
    tx(static function () use ($argv,$operations): void {
        $issuer = qu("SELECT id FROM users WHERE email=? AND role_code='super_admin' AND active=1",
            [email($argv[1])])->fetchColumn();
        $recipient = qu('SELECT id,role_code FROM users WHERE email=? AND active=1 FOR UPDATE',[email($argv[2])])->fetch();
        if (!$issuer || !$recipient || ($operations && !can(withPermissions($recipient),'personnel.read'))) {
            fail(403,'Pemberi aktif dan penerima yang sesuai diperlukan.');
        }
        $id = (int) $recipient['id'];
        qu('DELETE FROM personnel_case_permissions WHERE user_id=?',[$id]);
        foreach ($operations as $operation) {
            qu('INSERT INTO personnel_case_permissions (user_id,permission_code,granted_by) VALUES (?,?,?)',
                [$id,"personnel.cases.$operation",(int) $issuer]);
        }
        audit((int) $issuer,'scope_permissions_update','users',(string) $id);
    });
    fwrite(STDOUT,"Kewenangan khusus Kasus diperbarui.\n");
} catch (Throwable $error) {
    if (!$error instanceof HttpError) {
        reportFailure($error);
    }
    fwrite(STDERR,($error instanceof HttpError ? $error->getMessage() : 'Pembaruan gagal. ID ' . REQUEST_ID) . "\n");
    exit(1);
}
