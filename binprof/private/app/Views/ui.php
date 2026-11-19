<?php
declare(strict_types=1);

const NAVIGATION = [
    'Ringkasan' => [
        '/app/dashboard' => ['Ikhtisar', 'dashboard', '▦'],
        '/app/peta-kendali' => ['Peta Kendali Pimpinan', 'executive.view', '◎'],
        '/app/peta-kendali/arahan' => ['Arahan', 'executive.work', '▤'],
        '/app/notifikasi' => ['Notifikasi', 'notifications', '◈'],
    ],
    'Kepegawaian' => [
        '/app/personel' => ['Personel', 'personnel.read', '◉'],
        '/app/pelatihan' => ['Diklat', 'training.read', '◇'],
        '/app/pensiun' => ['Pensiun', 'retirement.read', '◷'],
        '/app/mutasi' => ['Mutasi', 'transfers.request', '⇄'],
    ],
    'Fasilitas dan layanan' => [
        '/app/peta' => ['Peta fasilitas', 'map', '◉'],
        '/app/peta-sebaran' => ['Peta sebaran', 'map', '◌'],
        '/app/peta-kendali/sumber' => ['Sumber penilaian', 'executive.configure', '◇'],
        '/app/erm' => ['E-RM dan Rikkes', '@clinical', '✚'],
        '/app/whatsapp' => ['WhatsApp', 'whatsapp.link', '◈'],
    ],
    'Administrasi' => [
        '/app/log' => ['Log aktivitas', 'audit', '▤'],
        '/app/users' => ['Pengguna', 'users', '☷'],
        '/app/master' => ['Master data', 'masters', '⊞'],
    ],
];

function navigationGroups(array $user): array
{
    $visible = [];
    foreach (NAVIGATION as $group=>$entries) {
        foreach ($entries as $path=>[$label,$permission,$symbol]) {
            $allowed = $permission === '@clinical' ? ($user['clinical_menu'] ?? false) : can($user,$permission);
            if ($allowed) {
                $visible[$group][$path] = [$path === '/app/dashboard' && isset($user['operator_facility']) ? 'Input Fasilitas' : $label,$symbol];
            }
        }
    }
    return $visible;
}

function appBrand(): void
{
    echo '<span class="brand-symbol" aria-hidden="true">+</span><span class="brand-word">BINPROFKES<small>KESEHATAN TNI AU</small></span>';
}

function page(string $title, callable $content, ?array $user = null, string $section = '', string $subtitle = '', bool $showHeader = true): void
{
    $groups = $user ? navigationGroups($user) : [];
    $activeGroup = '';
    foreach ($groups as $group=>$entries) {
        if (isset($entries[$section])) {
            $activeGroup = $group;
            break;
        }
    }
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
        <?php if ($user): ?><link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css"><?php endif; ?>
        <link rel="stylesheet" href="/assets/css/app.css">
        <script type="module" src="/assets/js/app.js"></script>
    </head>
    <body class="<?= $user ? 'workspace' : 'guest' ?>">
    <a class="skip" href="#content">Ke konten utama</a>
    <?php if ($user): ?>
        <details class="navigation" open>
            <summary>
                <span class="brand"><?php appBrand(); ?></span>
                <span class="menu-control">Menu <span class="menu-chevron" aria-hidden="true"></span></span>
            </summary>
            <div class="navigation-inner">
                <a class="brand navigation-brand" href="<?= e(url(homeFor($user))) ?>"><?php appBrand(); ?></a>
                <nav class="navigation-menu" aria-label="Menu utama">
                    <?php foreach ($groups as $group=>$entries): ?>
                        <div class="navigation-group">
                            <p><?= e($group) ?></p>
                            <?php foreach ($entries as $path=>[$label,$symbol]): ?>
                                <a href="<?= e(url($path)) ?>" <?= $section === $path ? 'aria-current="page"' : '' ?>>
                                    <span class="nav-icon" aria-hidden="true"><?= e($symbol) ?></span><?= e($label) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </nav>
                <div class="session-panel">
                    <div class="session-identity"><strong><?= e($user['name']) ?></strong><small><?= e($user['role_name']) ?></small></div>
                    <?php postButton('/logout', 'Keluar', class: 'signout'); ?>
                </div>
            </div>
        </details>
    <?php endif; ?>
    <main id="content" tabindex="-1">
        <?php if ($user && $showHeader): ?>
            <header class="page-header">
                <?php if ($activeGroup !== ''): ?><p class="breadcrumb"><?= e($activeGroup) ?></p><?php endif; ?>
                <h1><?= e($title) ?></h1>
                <?php if ($subtitle !== ''): ?><p><?= e($subtitle) ?></p><?php endif; ?>
                <?php if ($section !== '/app/dashboard' && isset($user['operator_facility'])): ?>
                    <p class="page-workplace"><?= e($user['operator_facility']['name']) ?></p>
                <?php endif; ?>
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

function definition(array $values): void
{
    echo '<dl class="definitions">';
    foreach ($values as $name => $value) {
        echo '<div><dt>' . e($name) . '</dt><dd>' . e($value === null || $value === '' ? '—' : $value) . '</dd></div>';
    }
    echo '</dl>';
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

function loginView(bool $setup = false, string $email = '', ?string $feedback = null): void
{
    page($setup ? 'Instalasi' : 'Masuk', static function () use ($setup,$email,$feedback): void {
        ?>
        <div class="login-frame">
            <div class="login-identity">
                <div class="brand"><?php appBrand(); ?></div>
                <p>Bina Profesional<br>Kesehatan TNI AU</p>
            </div>
            <section class="login-card" aria-labelledby="login-heading">
                <h1 id="login-heading"><?= $setup ? 'Buat akun pengelola' : 'Masuk ke BINPROFKES' ?></h1>
                <p><?= $setup ? 'Siapkan token instalasi untuk melanjutkan.' : 'Gunakan email dan kata sandi akun Anda.' ?></p>
                <?php if ($feedback !== null): ?>
                    <div class="login-feedback" role="alert" tabindex="-1" data-login-feedback><?= e($feedback) ?></div>
                <?php endif; ?>
                <?php postStart($setup ? '/setup' : '/login'); ?>
                <?php if ($setup): input('name', 'Nama', required: true); endif; ?>
                <label>Email<input name="email" type="email" value="<?= e($email) ?>" maxlength="254" autocomplete="username" required></label>
                <div class="password-control">
                    <label for="login-password">Kata sandi</label>
                    <div class="password-entry">
                        <input id="login-password" name="password" type="password" maxlength="72"
                            autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>" required>
                        <button type="button" class="password-toggle" aria-controls="login-password" aria-label="Tampilkan kata sandi"
                            aria-pressed="false" hidden data-password-toggle>Tampilkan</button>
                    </div>
                </div>
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
