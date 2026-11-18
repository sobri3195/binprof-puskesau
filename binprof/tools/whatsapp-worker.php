<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../private/app/Core/bootstrap.php';
require PROJECT . '/private/app/Services/WhatsApp/worker.php';

try {
    if (!waRunWorker()) {
        fwrite(STDOUT,"WhatsApp belum siap atau worker lain sedang berjalan.\n");
        exit;
    }
    fwrite(STDOUT,waTransportReady() ? "Antrean WhatsApp diperiksa. Lihat status di portal.\n"
        : "Inbox diperiksa. Pengiriman Cloud API belum dikonfigurasi.\n");
} catch (Throwable $error) {
    reportFailure($error);
    fwrite(STDERR,"Worker WhatsApp gagal. ID permintaan: " . REQUEST_ID . "\n");
    exit(1);
}
