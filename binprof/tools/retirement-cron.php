<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../private/app/Core/bootstrap.php';
require PROJECT . '/private/app/Services/Retirement/jobs.php';

try {
    if (!installed()) {
        fail(503,'Instalasi belum selesai.');
    }
    [$applied,$blocked] = retirementRunJobs();
    fwrite(STDOUT,"$applied pensiun diterapkan, $blocked perlu ditinjau; penugasan dan pengingat diperiksa.\n");
} catch (Throwable $error) {
    if (!$error instanceof HttpError) {
        reportFailure($error);
    }
    fwrite(STDERR,($error instanceof HttpError ? $error->getMessage() : 'Proses pensiun gagal. ID ' . REQUEST_ID) . "\n");
    exit(1);
}
