<?php
declare(strict_types=1);

function enforceOrigin(): void
{
    $canonical = parse_url((string) cfg('app_url'));
    $host = $canonical['host'] ?? '';
    $scheme = $canonical['scheme'] ?? '';
    $authority = $host . (isset($canonical['port']) ? ':' . $canonical['port'] : '');
    $dev = in_array($host, ['localhost', '127.0.0.1'], true);
    if (($dev && !in_array($scheme, ['http', 'https'], true))
        || (!$dev && ($host !== 'binprof.puskesau.com' || $scheme !== 'https'))
        || cfg('app_url') !== $scheme . '://' . $authority) {
        fail(503, 'Origin aplikasi belum valid.');
    }
    if (strtolower($_SERVER['HTTP_HOST'] ?? '') !== $authority) {
        fail(400, 'Host tidak valid.');
    }
    if ($scheme === 'https' && ($_SERVER['HTTPS'] ?? '') !== 'on') {
        fail(400, 'Koneksi HTTPS diperlukan.');
    }
    if (!$dev) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function headersForApp(): void
{
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

function sessionBegin(): void
{
    $secure = str_starts_with((string) cfg('app_url'), 'https://');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string) (cfg('absolute_seconds') ?? 28800));
    session_save_path(directory('sessions'));
    session_name($secure ? '__Host-binprof_session' : 'binprof_dev_session');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    if (!session_start()) {
        fail(503, 'Sesi tidak dapat dimulai.');
    }
}

function csrf(): string
{
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function checkCsrf(): void
{
    $expected = $_SESSION['csrf'] ?? '';
    $provided = $_POST['csrf'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    $referrer = $_SERVER['HTTP_REFERER'] ?? '';
    $canonical = cfg('app_url');
    $validOrigin = $origin !== null ? $origin === $canonical : str_starts_with($referrer, $canonical . '/');
    if (!$validOrigin || $expected === '' || !is_string($provided) || !hash_equals($expected, $provided)) {
        fail(403, 'Permintaan tidak dapat diverifikasi. Muat ulang lalu coba lagi.');
    }
}

function url(string $path, array $query = []): string
{
    if (cfg('rewrite')) {
        return $path . ($query ? '?' . http_build_query($query) : '');
    }
    return '/index.php?' . http_build_query(['r' => $path] + $query);
}

function redirect(string $path, array $query = []): never
{
    header('Location: ' . url($path, $query), true, 303);
    exit;
}

function done(string $message, string $path, array $query = []): never
{
    $_SESSION['flash'] = $message;
    redirect($path, $query);
}

function jsonResponse(mixed $payload): never
{
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}
