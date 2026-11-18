<?php
declare(strict_types=1);

require __DIR__ . '/../private/app/Core/bootstrap.php';
require PROJECT . '/private/app/Views/ui.php';
require PROJECT . '/private/app/Policies/clinical.php';

headersForApp();
$user = null;
$route = '';
try {
    if (!defined('BINPROF_ROOT_MODE') && realpath($_SERVER['DOCUMENT_ROOT'] ?? '') === realpath(PROJECT)) {
        fail(403, 'Akses langsung tidak tersedia.');
    }
    if (defined('BINPROF_ROOT_MODE') && !cfg('root_mode_verified')) {
        fail(503, 'Mode document root belum diverifikasi.');
    }
    enforceOrigin();
    $route = $_GET['r'] ?? (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if (!is_string($route)) {
        $route = '';
        fail(404, 'Halaman tidak ditemukan.');
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($route === '/ready' && $method === 'GET') {
        if (!installed() || !qu("SELECT 1 FROM schema_migrations WHERE version='002_interactive_maps'")->fetchColumn()) {
            fail(503, 'Instalasi dan migrasi peta belum selesai.');
        }
        jsonResponse(['status' => 'ready']);
    }
    if ($route === '/webhooks/whatsapp') {
        require_once PROJECT . '/private/app/Services/WhatsApp/webhook.php';
        whatsappWebhook($method);
    }
    sessionBegin();
    if ($method === 'POST') {
        checkCsrf();
    }
    if ($route === '/setup' && in_array($method, ['GET', 'POST'], true)) {
        $tokenHash = cfg('setup_token_hash');
        if (!str_starts_with(cfg('app_url'), 'https://') || !$tokenHash || installed()
            || is_file(STORAGE . '/install.lock')) {
            fail(404, 'Halaman tidak ditemukan.');
        }
        if ($method === 'POST') {
            $token = field($_POST, 'token', 128, true);
            if (!hash_equals($tokenHash, hash('sha256', $token))) {
                usleep(random_int(180000, 330000));
                fail(403, 'Token instalasi tidak valid.');
            }
            $password = $_POST['password'] ?? '';
            if (!is_string($password)) {
                fail(422, 'Kata sandi tidak valid.');
            }
            install(field($_POST, 'name', 160, true), field($_POST, 'email', 254, true), $password);
            done('Akun pertama dibuat. Hapus hash token setup dari konfigurasi.', '/login');
        }
        loginView(true);
        exit;
    }
    if (!installed()) {
        fail(503, 'Instalasi belum selesai. Jalankan bootstrap akun pertama.');
    }
    $user = currentUser();
    if (in_array($route, ['/', '/index.php', '/login'], true) && $method === 'GET') {
        if ($user) {
            redirect('/app/dashboard');
        }
        loginView();
        exit;
    }
    if ($route === '/login' && $method === 'POST' && !$user) {
        attemptLogin();
    }
    if (!$user) {
        if (str_starts_with($route, '/api/')) {
            fail(401, 'Masuk terlebih dahulu.');
        }
        redirect('/login');
    }
    $user['clinical_menu'] = clinicalSchemaReady() && clinicalWorkplaces($user) !== [];
    if ($route === '/logout' && $method === 'POST') {
        logout($user);
    }
    $routes = require PROJECT . '/private/app/Core/routes.php';
    $handler = $routes[$method . ' ' . $route] ?? null;
    if (!$handler) {
        fail(404, 'Halaman tidak ditemukan.');
    }
    [$permission, $controller, $function] = $handler;
    if ($permission === '@clinical') {
        if (!clinicalSchemaReady()) {
            fail(503, 'Migrasi klinis belum diterapkan.');
        }
        require_once PROJECT . '/private/app/Controllers/Clinical.php';
        clinicalDispatch($user, $function);
        exit;
    }
    if (!can($user, $permission)) {
        fail(403, 'Akses ditolak.');
    }
    require_once PROJECT . '/private/app/Controllers/' . $controller . '.php';
    $function($user);
} catch (Throwable $error) {
    $status = $error instanceof HttpError ? $error->status : 500;
    if ($status === 500) {
        reportFailure($error);
    }
    $message = $error instanceof HttpError ? $error->getMessage() : 'Permintaan tidak dapat diproses.';
    http_response_code($status);
    if (str_starts_with($route, '/api/') || $route === '/webhooks/whatsapp') {
        jsonResponse(['error' => $message, 'request_id' => REQUEST_ID]);
    }
    page('Permintaan bermasalah', static function () use ($message, $status, $user): void {
        echo '<section class="error-card"><h1>' . e($status) . '</h1><p>' . e($message) . '</p><small>ID permintaan '
            . e(REQUEST_ID) . '</small><p><a href="' . e($user ? url('/app/dashboard') : '/login') . '">Kembali</a></p></section>';
    }, $user);
}
