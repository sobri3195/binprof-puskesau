<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../private/app/Core/bootstrap.php';

try {
    if ($argc !== 3) {
        fwrite(STDERR, "Pemakaian: php tools/bootstrap.php NAMA EMAIL (kata sandi melalui stdin)\n");
        exit(2);
    }
    $password = fgets(STDIN);
    if ($password === false) {
        fail(422, 'Kata sandi diperlukan melalui stdin.');
    }
    install(field(['name' => $argv[1]], 'name', 160, true), $argv[2], rtrim($password, "\r\n"));
    fwrite(STDOUT, "Pengelola pertama dibuat.\n");
} catch (Throwable $error) {
    if (!$error instanceof HttpError) {
        reportFailure($error);
    }
    fwrite(STDERR, ($error instanceof HttpError ? $error->getMessage() : 'Bootstrap gagal. ID ' . REQUEST_ID) . "\n");
    exit(1);
}
