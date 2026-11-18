<?php
declare(strict_types=1);

function email(string $value): string
{
    $value = strtolower(trim($value));
    if (strlen($value) > 254 || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        fail(422, 'Alamat email tidak valid.');
    }
    return $value;
}

function passwordAlgorithm(): string|int
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function passwordHash(string $value): string
{
    // The byte limit also prevents truncation when the host falls back to bcrypt.
    if (strlen($value) < 12 || strlen($value) > 72 || str_contains($value, "\0")) {
        fail(422, 'Gunakan kata sandi 12–72 byte.');
    }
    return password_hash($value, passwordAlgorithm());
}

function audit(?int $actor, string $action, string $entity, ?string $id = null, ?int $unit = null, string $result = 'success'): void
{
    qu('INSERT INTO activity_logs (actor_id,unit_id,action,entity,entity_key,result,request_id) VALUES (?,?,?,?,?,?,?)',
        [$actor, $unit, $action, $entity, $id, $result, REQUEST_ID]);
}

function attemptLogin(): never
{
    $name = strtolower(field($_POST, 'email', 254));
    $password = $_POST['password'] ?? '';
    $pepper = secretBytes(cfg('rate_pepper'));
    $nameKey = hash_hmac('sha256', 'email:' . $name, $pepper, true);
    $ipKey = hash_hmac('sha256', 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? ''), $pepper, true);
    $lockNames = ['bpk:a:' . bin2hex(substr($nameKey, 0, 16)), 'bpk:i:' . bin2hex(substr($ipKey, 0, 16))];
    $held = [];
    try {
        foreach ($lockNames as $lockName) {
            if ((int) qu('SELECT GET_LOCK(?, 3)', [$lockName])->fetchColumn() !== 1) {
                fail(429, 'Terlalu banyak percobaan. Coba lagi nanti.');
            }
            $held[] = $lockName;
        }
        $recent = 'attempted_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE';
        $account = (int) qu("SELECT COUNT(*) FROM login_attempts WHERE account_hash=? AND $recent", [$nameKey])->fetchColumn();
        $address = (int) qu("SELECT COUNT(*) FROM login_attempts WHERE ip_hash=? AND $recent", [$ipKey])->fetchColumn();
        if ($account >= 6 || $address >= 25) {
            header('Retry-After: 900');
            fail(429, 'Terlalu banyak percobaan. Coba lagi nanti.');
        }
        qu('INSERT INTO login_attempts (account_hash,ip_hash) VALUES (?,?)', [$nameKey, $ipKey]);
    } finally {
        foreach (array_reverse($held) as $lockName) {
            qu('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }
    $row = qu('SELECT id,password_hash,active,session_version FROM users WHERE email=?', [$name])->fetch();
    // Fixed, unused Argon2id hash keeps the unknown-account path computationally comparable.
    $dummy = '$argon2id$v=19$m=65536,t=4,p=1$bW9ja19zYWx0X2JpbmJybw$u4qWhvN/ZauFtaKIZ9k2YHPSKsXASaMljvIEJ8LYrZU';
    $candidate = is_string($password) && strlen($password) <= 72 ? $password : '';
    $valid = password_verify($candidate, $row['password_hash'] ?? $dummy);
    if (!$row || !$valid || !$row['active'] || $candidate === '') {
        usleep(random_int(120000, 240000));
        audit(null, 'login', 'session', result: 'denied');
        fail(401, 'Email atau kata sandi tidak valid.');
    }
    if (!session_regenerate_id(true)) {
        fail(503, 'Sesi tidak dapat dibuat.');
    }
    $handle = bin2hex(random_bytes(32));
    $id = (int) $row['id'];
    tx(static function () use ($row, $candidate, $handle, $id, $nameKey): void {
        $current = qu('SELECT active,session_version,password_hash FROM users WHERE id=? FOR UPDATE', [$id])->fetch();
        if (!$current || !$current['active'] || (int) $current['session_version'] !== (int) $row['session_version']
            || !hash_equals($current['password_hash'], $row['password_hash'])) {
            fail(401, 'Email atau kata sandi tidak valid.');
        }
        if (password_needs_rehash($row['password_hash'], passwordAlgorithm())) {
            qu('UPDATE users SET password_hash=? WHERE id=?', [passwordHash($candidate), $id]);
        }
        qu('INSERT INTO auth_sessions VALUES (?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),NULL)',
            [hash('sha256', $handle, true), $id, (int) $row['session_version']]);
        qu('UPDATE users SET last_login_at=UTC_TIMESTAMP() WHERE id=?', [$id]);
        qu('DELETE FROM login_attempts WHERE account_hash=?', [$nameKey]);
        audit($id, 'login', 'session');
    });
    $_SESSION = ['handle' => $handle];
    redirect('/app/dashboard');
}

function currentUser(): ?array
{
    if (!isset($_SESSION['handle'])) {
        return null;
    }
    $key = hash('sha256', $_SESSION['handle'], true);
    $user = qu('SELECT u.id,u.name,u.role_code,r.name AS role_name FROM auth_sessions s JOIN users u ON u.id=s.user_id
        JOIN roles r ON r.code=u.role_code WHERE s.handle_hash=? AND u.active=1 AND s.revoked_at IS NULL
        AND s.session_version=u.session_version AND s.last_seen_at > TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP())
        AND s.created_at > TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP())',
        [$key, -(int) (cfg('idle_seconds') ?? 1800), -(int) (cfg('absolute_seconds') ?? 28800)])->fetch();
    if (!$user) {
        unset($_SESSION['handle'], $_SESSION['csrf']);
        return null;
    }
    qu('UPDATE auth_sessions SET last_seen_at=UTC_TIMESTAMP() WHERE handle_hash=?', [$key]);
    $user['id'] = (int) $user['id'];
    $user['permissions'] = qu('SELECT permission_code FROM role_permissions WHERE role_code=?',
        [$user['role_code']])->fetchAll(PDO::FETCH_COLUMN);
    return $user;
}

function logout(array $user): never
{
    tx(static function () use ($user): void {
        qu('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP() WHERE handle_hash=?',
            [hash('sha256', $_SESSION['handle'], true)]);
        audit($user['id'], 'logout', 'session');
    });
    $_SESSION = [];
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600, 'path' => '/', 'secure' => $cookie['secure'], 'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_destroy();
    redirect('/login');
}

function can(array $user, string $permission): bool
{
    return in_array($permission, $user['permissions'], true);
}

function scope(array $user, string $unitExpr, ?string $facilityExpr = null): array
{
    if ($user['role_code'] === 'super_admin') {
        return ['1=1', []];
    }
    $unit = "EXISTS (SELECT 1 FROM user_unit_scopes uus WHERE uus.user_id=? AND uus.unit_id=$unitExpr)";
    if ($facilityExpr === null) {
        return [$unit, [$user['id']]];
    }
    $facility = "EXISTS (SELECT 1 FROM user_facility_scopes ufs WHERE ufs.user_id=? AND ufs.facility_id=$facilityExpr)";
    return ["($unit OR $facility)", [$user['id'], $user['id']]];
}
