<?php
declare(strict_types=1);

const PROJECT = __DIR__ . '/../../..';
const STORAGE = PROJECT . '/private/storage';
umask(0077);
ini_set('display_errors', '0');
date_default_timezone_set('UTC');
define('REQUEST_ID', bin2hex(random_bytes(16)));

class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

function fail(int $code, string $message): never
{
    throw new HttpError($code, $message);
}

function cfg(string $key): mixed
{
    static $settings;
    if ($settings === null) {
        $file = PROJECT . '/private/config/config.local.php';
        if (!is_file($file)) {
            fail(503, 'Konfigurasi lokal belum tersedia.');
        }
        $settings = require $file;
    }
    return $settings[$key] ?? null;
}

function qu(string $sql, array $params = []): PDOStatement
{
    $statement = db()->prepare($sql);
    foreach (array_values($params) as $i => $value) {
        $type = match (true) {
            is_int($value), is_bool($value) => PDO::PARAM_INT,
            $value === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
        $statement->bindValue($i + 1, $value, $type);
    }
    $statement->execute();
    return $statement;
}

function db(): PDO
{
    static $pdo;
    if ($pdo === null) {
        $pdo = new PDO(cfg('db_dsn'), cfg('db_user'), cfg('db_password'), [
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function tx(callable $work): mixed
{
    db()->beginTransaction();
    try {
        $result = $work();
        db()->commit();
        return $result;
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $error;
    }
}

function directory(string $name): string
{
    $path = STORAGE . '/' . $name;
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Private storage unavailable');
    }
    return $path;
}

function installed(): bool
{
    return (bool) qu('SELECT installed_at FROM application_state WHERE id=1')->fetchColumn();
}

function install(string $name, string $email, string $password): void
{
    $lock = fopen(STORAGE . '/install.guard', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        fail(503, 'Kunci instalasi tidak tersedia.');
    }
    try {
        tx(static function () use ($name, $email, $password): void {
            $state = qu('SELECT installed_at FROM application_state WHERE id=1 FOR UPDATE')->fetch();
            if (!$state || $state['installed_at'] !== null || is_file(STORAGE . '/install.lock')) {
                fail(409, 'Instalasi telah dikunci.');
            }
            qu("INSERT INTO users (name,email,password_hash,role_code) VALUES (?,?,?,'super_admin')",
                [$name, email($email), passwordHash($password)]);
            qu('UPDATE application_state SET installed_at=UTC_TIMESTAMP() WHERE id=1');
        });
        if (file_put_contents(STORAGE . '/install.lock', "installed\n", LOCK_EX) === false) {
            fail(503, 'Instalasi dicatat di database. Hubungi pengelola untuk memperbaiki kunci berkas.');
        }
    } finally {
        fclose($lock);
    }
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function today(): DateTimeImmutable
{
    return new DateTimeImmutable('today', new DateTimeZone(cfg('timezone') ?? 'Asia/Jakarta'));
}

function day(int $offset = 0): string
{
    return today()->modify(sprintf('%+d days', $offset))->format('Y-m-d');
}

function field(array $data, string $key, int $max = 160, bool $required = false): string
{
    $value = $data[$key] ?? '';
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
        fail(422, "Nilai {$key} tidak valid.");
    }
    $value = trim($value);
    if (mb_strlen($value) > $max || ($required && $value === '')) {
        fail(422, "Periksa panjang isian {$key}.");
    }
    return $value;
}

function choice(array $data, string $key, array $allowed, string $default = ''): string
{
    $value = field($data + [$key => $default], $key);
    if (!in_array($value, $allowed, true)) {
        fail(422, "Pilihan {$key} tidak valid.");
    }
    return $value;
}

function number(array $data, string $key): ?int
{
    $value = field($data, $key, 10);
    if ($value === '') {
        return null;
    }
    if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 4294967295) {
        fail(422, "Pilihan {$key} tidak valid.");
    }
    return (int) $value;
}

function calendar(array $data, string $key): ?string
{
    $value = field($data, $key, 10);
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m)
        || (int) $m[1] < 1000 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        fail(422, "Tanggal {$key} tidak valid.");
    }
    return $value;
}

function key32(array $data, string $key = 'id'): string
{
    $value = field($data, $key, 32, true);
    if (!preg_match('/^[0-9a-f]{32}$/D', $value)) {
        fail(404, 'Data tidak ditemukan.');
    }
    return $value;
}

function secretBytes(?string $encoded): string
{
    $value = base64_decode($encoded ?? '', true);
    if ($value === false || strlen($value) !== 32) {
        throw new RuntimeException('Invalid private key configuration');
    }
    return $value;
}

function seal(string $plain, string $context): string
{
    $keyId = cfg('key_id');
    if (!is_string($keyId) || !preg_match('/^[a-zA-Z0-9_-]{1,32}$/D', $keyId)) {
        throw new RuntimeException('Invalid encryption key ID');
    }
    $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    $key = secretBytes(cfg('keys')[$keyId] ?? null);
    $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $context, $nonce, $key);
    return $keyId . ':' . base64_encode($nonce . $cipher);
}

function unseal(string $envelope, string $context): string
{
    [$id, $encoded] = array_pad(explode(':', $envelope, 2), 2, '');
    $data = base64_decode($encoded, true);
    $length = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
    if ($data === false || strlen($data) < $length + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
        throw new RuntimeException('Invalid encrypted envelope');
    }
    $key = secretBytes(cfg('keys')[$id] ?? null);
    $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
        substr($data, $length), $context, substr($data, 0, $length), $key
    );
    if ($plain === false) {
        throw new RuntimeException('Encrypted envelope failed authentication');
    }
    return $plain;
}

function reportFailure(Throwable $error): void
{
    try {
        // Exception messages can contain SQL, identifying data or credentials.
        error_log(gmdate('c') . ' ' . REQUEST_ID . ' ' . $error::class . ' ' . $error->getCode()
            . ' ' . basename($error->getFile()) . ':' . $error->getLine() . "\n", 3, directory('logs') . '/application.log');
    } catch (Throwable) {
        error_log('BINPROFKES request failed ' . REQUEST_ID);
    }
}

require __DIR__ . '/http.php';
require __DIR__ . '/auth.php';
require PROJECT . '/private/app/Services/map-revision.php';
