<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../private/app/Core/bootstrap.php';
require PROJECT . '/private/app/Policies/clinical.php';

try {
    if ($argc !== 3 || !ctype_digit($argv[2]) || !installed() || !clinicalSchemaReady()) {
        fail(422, 'Pemakaian: php tools/clinical-bootstrap.php EMAIL ID_FASILITAS');
    }
    tx(static function () use ($argv): void {
        qu('SELECT id FROM application_state WHERE id=1 FOR UPDATE');
        $facility = (int) $argv[2];
        if (qu('SELECT 1 FROM user_clinical_privileges c JOIN users u ON u.id=c.user_id
            WHERE c.facility_id=? AND c.active=1 AND c.can_approve=1 AND u.active=1 LIMIT 1', [$facility])->fetchColumn()) {
            fail(409, 'Fasilitas masih memiliki supervisor klinis aktif.');
        }
        $user = (int) qu('SELECT id FROM users WHERE email=? AND active=1 FOR UPDATE', [email($argv[1])])->fetchColumn();
        if (!$user || !qu('SELECT 1 FROM facilities WHERE id=? AND active=1', [$facility])->fetchColumn()) {
            fail(422, 'Akun aktif dan fasilitas aktif diperlukan.');
        }
        qu('INSERT INTO user_clinical_privileges (user_id,facility_id,can_read,can_write,can_export,can_approve)
            VALUES (?,?,1,0,0,1) ON DUPLICATE KEY UPDATE can_read=1,can_write=0,can_export=0,can_approve=1,active=1,designated_by=NULL',
            [$user,$facility]);
        clinicalAudit($user,$facility,null,'cli_bootstrap',null);
    });
    fwrite(STDOUT, "Supervisor klinis pertama ditetapkan. Hubungan perawatan tetap diperlukan.\n");
} catch (Throwable $error) {
    if (!$error instanceof HttpError) {
        reportFailure($error);
    }
    fwrite(STDERR, ($error instanceof HttpError ? $error->getMessage() : 'Bootstrap klinis gagal. ID ' . REQUEST_ID) . "\n");
    exit(1);
}
