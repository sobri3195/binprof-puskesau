<?php
declare(strict_types=1);

const NAVIGATION = [
    '/app/dashboard' => ['Ikhtisar', 'dashboard', '▦'],
    '/app/personel' => ['Personel', 'personnel.read', '◎'],
    '/app/pelatihan' => ['Diklat', 'training.read', '◇'],
    '/app/peta' => ['Peta fasilitas', 'map', '◉'],
    '/app/peta-sebaran' => ['Peta sebaran', 'map', '◌'],
    '/app/mutasi' => ['Mutasi', 'transfers.request', '⇄'],
    '/app/notifikasi' => ['Notifikasi', 'notifications', '◈'],
    '/app/log' => ['Log aktivitas', 'audit', '▤'],
    '/app/users' => ['Pengguna', 'users', '☷'],
    '/app/master' => ['Master data', 'masters', '⊞'],
];

function page(string $title, callable $content, ?array $user = null, string $section = '', string $subtitle = ''): void
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    ob_start();
    try {
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?= e($title) ?> · BINPROFKES</title>
        <link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
        <link rel="stylesheet" href="/assets/css/app.css">
        <script type="module" src="/assets/js/app.js"></script>
    </head>
    <body class="<?= $user ? 'workspace' : 'guest' ?>">
    <a class="skip" href="#content">Ke konten utama</a>
    <?php if ($user): ?>
        <details class="navigation" open>
            <summary>Menu navigasi</summary>
            <div class="navigation-inner">
                <div class="brand"><span class="brand-symbol" aria-hidden="true">＋</span><div>BINPROFKES<small>KESEHATAN TNI AU</small></div></div>
                <nav aria-label="Menu utama">
                    <?php foreach (NAVIGATION as $path => [$label, $permission, $symbol]):
                        if (can($user, $permission)): ?>
                            <a href="<?= e(url($path)) ?>" <?= $section === $path ? 'aria-current="page"' : '' ?>>
                                <span aria-hidden="true"><?= e($symbol) ?></span><?= e($label) ?>
                            </a>
                        <?php endif;
                    endforeach; ?>
                </nav>
                <div class="session-panel">
                    <strong><?= e($user['name']) ?></strong><small><?= e($user['role_name']) ?></small>
                    <?php postButton('/logout', 'Keluar', class: 'signout'); ?>
                </div>
            </div>
        </details>
    <?php endif; ?>
    <main id="content">
        <?php if ($user): ?>
            <header class="page-header">
                <p class="breadcrumb">BINPROFKES <span aria-hidden="true">/</span> <?= e(NAVIGATION[$section][0] ?? $title) ?></p>
                <h1><?= e($title) ?></h1>
                <?php if ($subtitle !== ''): ?><p><?= e($subtitle) ?></p><?php endif; ?>
            </header>
        <?php endif; ?>
        <?php if ($flash): ?><p class="flash" role="status"><?= e($flash) ?></p><?php endif; ?>
        <?php $content(); ?>
    </main>
    </body>
    </html>
    <?php
    } catch (Throwable $error) {
        ob_end_clean();
        throw $error;
    }
    ob_end_flush();
}

function postStart(string $path, array $hidden = [], bool $upload = false): void
{
    echo '<form method="post" action="' . e(url($path)) . '"' . ($upload ? ' enctype="multipart/form-data"' : '') . '>';
    foreach (['csrf' => csrf()] + $hidden as $name => $value) {
        echo '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
    }
}

function postButton(string $path, string $label, array $hidden = [], string $class = 'secondary'): void
{
    postStart($path, $hidden);
    echo '<button type="submit" class="' . e($class) . '">' . e($label) . '</button></form>';
}

function input(string $name, string $label, mixed $value = '', string $type = 'text', bool $required = false, int $max = 160): void
{
    echo '<label>' . e($label) . '<input type="' . e($type) . '" name="' . e($name) . '" value="' . e($value)
        . '" maxlength="' . e($max) . '"' . ($required ? ' required' : '') . '></label>';
}

function textArea(string $name, string $label, string $value = ''): void
{
    echo '<label>' . e($label) . '<textarea name="' . e($name) . '" maxlength="4000" rows="3">' . e($value) . '</textarea></label>';
}

function selectField(string $name, string $label, array $options, mixed $value = null, string $blank = ''): void
{
    echo '<label>' . e($label) . '<select name="' . e($name) . '"' . ($blank === '' ? ' required' : '') . '>';
    if ($blank !== '') {
        echo '<option value="">' . e($blank) . '</option>';
    }
    foreach ($options as $id => $text) {
        echo '<option value="' . e($id) . '"' . ((string) $id === (string) $value ? ' selected' : '') . '>' . e($text) . '</option>';
    }
    echo '</select></label>';
}

function getStart(string $path): void
{
    echo '<form class="filters" method="get" action="' . e(cfg('rewrite') ? $path : '/index.php') . '">';
    if (!cfg('rewrite')) {
        echo '<input type="hidden" name="r" value="' . e($path) . '">';
    }
}

function linkButton(string $path, string $label, array $query = [], string $class = 'secondary'): void
{
    echo '<a class="button ' . e($class) . '" href="' . e(url($path, $query)) . '">' . e($label) . '</a>';
}

function table(array $labels, array $rows, callable $cells, string $empty = 'Belum ada data yang sesuai.'): void
{
    if (!$rows) {
        echo '<p class="empty">' . e($empty) . '</p>';
        return;
    }
    echo '<div class="table-wrap" tabindex="0" role="region" aria-label="Tabel hasil"><table><thead><tr>';
    foreach ($labels as $label) {
        echo '<th scope="col">' . e($label) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        $cells($row);
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function td(mixed ...$values): void
{
    foreach ($values as $value) {
        echo '<td>' . e($value ?? '—') . '</td>';
    }
}

function pageSize(): int
{
    return (int) choice($_GET, 'per', ['25', '50', '100'], '25');
}

function pageNumber(): int
{
    return min(number($_GET, 'page') ?? 1, 100000);
}

function paginate(string $path, int $total, int $current, int $per): void
{
    $pages = max(1, (int) ceil($total / $per));
    $query = array_diff_key($_GET, array_flip(['r', 'page', 'format']));
    echo '<nav class="pagination" aria-label="Halaman hasil"><span>' . e("$total hasil · Halaman $current dari $pages") . '</span>';
    if ($current > 1) {
        linkButton($path, 'Sebelumnya', ['page' => $current - 1] + $query);
    }
    if ($current < $pages) {
        linkButton($path, 'Berikutnya', ['page' => $current + 1] + $query);
    }
    echo '</nav>';
}

function loginView(bool $setup = false): void
{
    page($setup ? 'Instalasi' : 'Masuk', static function () use ($setup): void {
        ?>
        <div class="login-frame">
            <div class="login-identity">
                <div class="brand"><span class="brand-symbol" aria-hidden="true">＋</span><div>BINPROFKES<small>KESEHATAN TNI AU</small></div></div>
                <p>Sistem Informasi Bina Profesional Kesehatan TNI AU</p>
                <div class="orb" data-orb aria-hidden="true"></div>
            </div>
            <section class="login-card" aria-labelledby="login-heading">
                <h1 id="login-heading"><?= $setup ? 'Buat akun pengelola' : 'Masuk ke BINPROFKES' ?></h1>
                <p><?= $setup ? 'Gunakan token instalasi yang disimpan oleh pengelola.' : 'Gunakan email dan kata sandi akun Anda.' ?></p>
                <?php postStart($setup ? '/setup' : '/login'); ?>
                <?php if ($setup): input('name', 'Nama', required: true); endif; ?>
                <label>Email<input name="email" type="email" maxlength="254" autocomplete="username" required></label>
                <label>Kata sandi<input name="password" type="password" maxlength="72"
                    autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>" required></label>
                <?php if ($setup): ?>
                    <label>Token instalasi<input name="token" type="password" maxlength="128" autocomplete="off" required></label>
                <?php endif; ?>
                <button type="submit"><?= $setup ? 'Buat akun' : 'Masuk' ?></button>
                </form>
            </section>
        </div>
        <?php
    });
}
