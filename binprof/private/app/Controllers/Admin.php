<?php
declare(strict_types=1);

require_once PROJECT . '/private/app/Services/admin.php';
require_once PROJECT . '/private/app/Policies/clinical.php';
require_once PROJECT . '/private/app/Services/PersonnelRecords/catalog.php';

function masterIndex(array $user): void
{
    $kind = choice($_GET, 'kind', array_keys(MASTERS), 'units');
    $id = number($_GET, 'id');
    $row = $id ? qu("SELECT * FROM $kind WHERE id=?", [$id])->fetch() : [];
    if ($id && !$row) {
        fail(404, 'Master tidak ditemukan.');
    }
    $per = pageSize();
    $total = (int) qu("SELECT COUNT(*) FROM $kind")->fetchColumn();
    $current = min(pageNumber(), max(1, (int) ceil($total / $per)));
    $rows = qu("SELECT id,code,name,active FROM $kind ORDER BY name,id LIMIT ? OFFSET ?", [$per, ($current-1)*$per])->fetchAll();
    page('Master data', static function () use ($kind, $rows, $row, $id, $total, $current, $per): void {
        echo '<section class="surface"><div class="section-heading"><h2>' . e(MASTERS[$kind]) . '</h2>';
        if (profileSchemaReady()) {
            linkButton(PROFILE_MASTER,'Kategori catatan personel');
        }
        echo '</div>';
        getStart('/app/master');
        selectField('kind', 'Kelompok', MASTERS, $kind);
        echo '<button>Tampilkan</button></form>';
        table(['Kode','Nama','Status','Aksi'], $rows, static function (array $line) use ($kind): void {
            td($line['code'], $line['name'], $line['active'] ? 'Aktif' : 'Nonaktif');
            echo '<td>';
            linkButton('/app/master', 'Ubah', ['kind'=>$kind,'id'=>$line['id']]);
            echo '</td>';
        });
        paginate('/app/master', $total, $current, $per);
        echo '</section><section class="surface"><h2>' . ($row ? 'Ubah entri' : 'Tambah entri') . '</h2>';
        postStart('/app/master/save', ['kind'=>$kind] + ($id ? ['id'=>$id] : []));
        echo '<div class="form-grid">';
        input('code', 'Kode', $row['code'] ?? '', required: true, max: 32);
        input('name', 'Nama', $row['name'] ?? '', required: true);
        selectField('active', 'Status', [1=>'Aktif',0=>'Nonaktif'], $row['active'] ?? 1);
        if ($kind === 'units') {
            selectField('command_id', 'Komando', optionsFor('commands'), $row['command_id'] ?? '', 'Tanpa komando');
        }
        if ($kind === 'ranks') {
            input('group_name', 'Kelompok pangkat', $row['group_name'] ?? '', required: true, max: 80);
        }
        if ($kind === 'facilities') {
            selectField('unit_id', 'Satuan', optionsFor('units'), $row['unit_id'] ?? null);
            selectField('type_id', 'Jenis', optionsFor('facility_types'), $row['type_id'] ?? null);
            input('latitude', 'Lintang', $row['latitude'] ?? '', max: 12);
            input('longitude', 'Bujur', $row['longitude'] ?? '', max: 12);
            input('address', 'Alamat', $row['address'] ?? '', max: 250);
        }
        echo '</div><button>Simpan</button></form></section>';
    }, $user, '/app/master', 'Kelola data rujukan untuk modul administrasi.');
}

function coordinate(string $name, int $limit): ?string
{
    $value = field($_POST, $name, 12);
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^-?\d{1,3}(?:\.\d{1,6})?$/D', $value) || abs((float) $value) > $limit) {
        fail(422, 'Koordinat harus menggunakan angka desimal dalam rentang yang sah.');
    }
    return $value;
}

function masterSave(array $user): never
{
    $kind = choice($_POST, 'kind', array_keys(MASTERS));
    $id = number($_POST, 'id');
    $code = field($_POST, 'code', 32, true);
    if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $code)) {
        fail(422, 'Gunakan huruf, angka, garis bawah, atau tanda hubung untuk kode.');
    }
    $fields = ['code'=>$code,'name'=>field($_POST, 'name', 160, true),'active'=>(int) choice($_POST, 'active', ['0','1'])];
    if ($kind === 'units') {
        $fields['command_id'] = verifyMaster('commands', number($_POST, 'command_id'), false);
    }
    if ($kind === 'ranks') {
        $fields['group_name'] = field($_POST, 'group_name', 80, true);
    }
    if ($kind === 'facilities') {
        $fields['unit_id'] = verifyMaster('units', number($_POST, 'unit_id'));
        $fields['type_id'] = verifyMaster('facility_types', number($_POST, 'type_id'));
        $fields['latitude'] = coordinate('latitude', 90);
        $fields['longitude'] = coordinate('longitude', 180);
        $fields['address'] = field($_POST, 'address', 250);
        $fields['updated_at'] = gmdate('Y-m-d H:i:s');
        if (($fields['latitude'] === null) !== ($fields['longitude'] === null)) {
            fail(422, 'Isi kedua koordinat atau kosongkan keduanya.');
        }
    }
    saveUnique(static function () use ($id, $kind, $fields, $user): void {
        tx(static function () use ($id, $kind, $fields, $user): void {
            $action = $id ? 'update' : 'create';
            if ($id) {
                $previous = qu("SELECT * FROM $kind WHERE id=? FOR UPDATE", [$id])->fetch();
                if (!$previous) {
                    fail(404, 'Master tidak ditemukan.');
                }
                if ($kind === 'facilities' && (int) $previous['unit_id'] !== $fields['unit_id']) {
                    fail(409, 'Satuan fasilitas tidak dapat diganti. Buat entri baru bila diperlukan.');
                }
                $assign = implode(',', array_map(static fn (string $name): string => "$name=?", array_keys($fields)));
                qu("UPDATE $kind SET $assign WHERE id=?", [...array_values($fields), $id]);
            } else {
                $columns = implode(',', array_keys($fields));
                $placeholders = implode(',', array_fill(0, count($fields), '?'));
                qu("INSERT INTO $kind ($columns) VALUES ($placeholders)", array_values($fields));
                $id = (int) db()->lastInsertId();
            }
            if ($kind === 'facilities') {
                qu('UPDATE map_revision SET refresh_revision=revision+1,revision=revision+1 WHERE id=1');
            }
            audit($user['id'], $action, $kind, (string) $id);
        });
    });
    done('Master disimpan.', '/app/master', ['kind'=>$kind]);
}

const BINPROF_POSITION_ROLES = ['kapuskesau','dirbinprof','kasipa','kasibata','staf_binprof'];

function userIndex(array $actor): void
{
    $roles = qu('SELECT code,name FROM roles ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
    $id = number($_GET, 'id');
    $row = $id ? qu('SELECT id,name,email,role_code,active,session_version FROM users WHERE id=?', [$id])->fetch() : [];
    if ($id && !$row) {
        fail(404, 'Pengguna tidak ditemukan.');
    }
    $units = $id ? qu('SELECT unit_id FROM user_unit_scopes WHERE user_id=?', [$id])->fetchAll(PDO::FETCH_COLUMN) : [];
    $facilities = $id ? qu('SELECT facility_id FROM user_facility_scopes WHERE user_id=?', [$id])->fetchAll(PDO::FETCH_COLUMN) : [];
    [$rows, $total, $current, $per] = limitedRows('FROM users JOIN roles ON role_code=code', '1=1', [],
        'users.id,users.name,users.email,users.active,users.last_login_at,roles.name AS role_name', 'users.name,users.id');
    page('Pengguna', static function () use ($rows, $row, $id, $roles, $units, $facilities, $total, $current, $per): void {
        echo '<section class="surface"><h2>Daftar akun</h2>';
        table(['Nama','Email','Peran','Status','Masuk terakhir (UTC)','Aksi'], $rows, static function (array $line): void {
            td($line['name'], $line['email'], $line['role_name'], $line['active'] ? 'Aktif' : 'Nonaktif', $line['last_login_at']);
            echo '<td>';
            linkButton('/app/users', 'Kelola', ['id'=>$line['id']]);
            echo '</td>';
        });
        paginate('/app/users', $total, $current, $per);
        echo '</section><section class="surface"><h2>' . ($row ? 'Kelola akun' : 'Tambah akun') . '</h2>';
        postStart('/app/users/save', $id ? ['id'=>$id,'version'=>$row['session_version']] : []);
        echo '<div class="form-grid">';
        input('name', 'Nama', $row['name'] ?? '', required: true);
        input('email', 'Email', $row['email'] ?? '', 'email', true, 254);
        selectField('role', 'Peran', $roles, $row['role_code'] ?? 'viewer');
        selectField('active', 'Status', [1=>'Aktif',0=>'Nonaktif'], $row['active'] ?? 1);
        input('password', $id ? 'Kata sandi baru (opsional)' : 'Kata sandi awal', '', 'password', !$id, 72);
        if ($id) {
            selectField('revoke', 'Cabut sesi secara manual', [0=>'Tidak',1=>'Ya'], 0);
        }
        echo '</div>';
        if (array_intersect(BINPROF_POSITION_ROLES,array_keys($roles))) {
            echo '<p>Untuk akun aktif Kapuskesau, Dirbinprof, Kasipa, Kasibata, dan Staf Binprof, pilih minimal satu satuan atau fasilitas.';
            echo ' Satuan memberi akses ke fasilitas dan personel dalam satuan tersebut.</p>';
        }
        scopeFields('units', 'Satuan', optionsFor('units', activeOnly: false), $units);
        scopeFields('facilities', 'Fasilitas', optionsFor('facilities', activeOnly: false), $facilities);
        echo '<p>Super Admin memiliki cakupan administratif global. Pengguna lain mendapatkan cakupan dari pilihan di atas.</p>';
        echo '<div class="actions"><button>Simpan</button>';
        linkButton('/app/users', 'Batal');
        echo '</div></form></section>';
    }, $actor, '/app/users', 'Kelola akses administratif dan pencabutan sesi.');
}

function scopeFields(string $key, string $label, array $choices, array $selected): void
{
    echo '<fieldset><legend>' . e($label) . '</legend><div class="choices">';
    foreach ($choices as $id => $name) {
        echo '<label><input type="checkbox" name="' . e($key) . '[]" value="' . e($id) . '"'
            . (in_array($id, $selected) ? ' checked' : '') . '> ' . e($name) . '</label>';
    }
    echo '</div></fieldset>';
}

function submittedScope(string $key): array
{
    $selected = $_POST[$key] ?? [];
    if (!is_array($selected) || count($selected) > 200) {
        fail(422, 'Cakupan tidak valid atau melebihi 200 pilihan.');
    }
    $ids = [];
    foreach ($selected as $value) {
        $id = number([$key=>$value], $key);
        if (!$id || !qu("SELECT 1 FROM $key WHERE id=?", [$id])->fetchColumn()) {
            fail(422, 'Cakupan tidak ditemukan.');
        }
        $ids[$id] = $id;
    }
    sort($ids, SORT_NUMERIC);
    return $ids;
}

function userSave(array $actor): never
{
    $id = number($_POST, 'id');
    $name = field($_POST, 'name', 160, true);
    $email = email(field($_POST, 'email', 254, true));
    $role = choice($_POST, 'role', qu('SELECT code FROM roles')->fetchAll(PDO::FETCH_COLUMN));
    $active = (int) choice($_POST, 'active', ['0','1']);
    $revoke = choice($_POST, 'revoke', ['0','1'], '0') === '1';
    $password = $_POST['password'] ?? '';
    if (!is_string($password)) {
        fail(422, 'Kata sandi tidak valid.');
    }
    $hash = $password !== '' || !$id ? passwordHash($password) : null;
    $units = submittedScope('units');
    $facilities = submittedScope('facilities');
    if ($active && in_array($role,BINPROF_POSITION_ROLES,true) && !$units && !$facilities) {
        fail(422,'Pilih minimal satu satuan atau fasilitas untuk akun jabatan yang aktif.');
    }
    saveUnique(static function () use ($actor, $id, $name, $email, $role, $active, $revoke, $hash, $units, $facilities): void {
        tx(static function () use ($actor, $id, $name, $email, $role, $active, $revoke, $hash, $units, $facilities): void {
            qu('SELECT id FROM application_state WHERE id=1 FOR UPDATE');
            $created = !$id;
            if ($id) {
                $before = qu('SELECT * FROM users WHERE id=? FOR UPDATE', [$id])->fetch();
                if (!$before) {
                    fail(404, 'Pengguna tidak ditemukan.');
                }
                if ($active && retirementAccountHasRetired($id)) {
                    fail(409,'Atur masa akses purnawirawan melalui tab Pensiun.');
                }
                if ((int) $before['session_version'] !== number($_POST, 'version')) {
                    fail(409, 'Akun sudah berubah. Muat ulang formulir.');
                }
                if ($before['active'] && $before['role_code'] === 'super_admin' && (!$active || $role !== 'super_admin')
                    && (int) qu("SELECT COUNT(*) FROM users WHERE active=1 AND role_code='super_admin'")->fetchColumn() <= 1) {
                    fail(409, 'Super Admin aktif terakhir harus dipertahankan.');
                }
                $beforeUnits = array_map('intval', qu('SELECT unit_id FROM user_unit_scopes WHERE user_id=? ORDER BY unit_id', [$id])->fetchAll(PDO::FETCH_COLUMN));
                $beforeFacilities = array_map('intval', qu('SELECT facility_id FROM user_facility_scopes WHERE user_id=? ORDER BY facility_id', [$id])->fetchAll(PDO::FETCH_COLUMN));
                $terminate = $revoke || $hash !== null || $before['role_code'] !== $role || (int) $before['active'] !== $active
                    || $beforeUnits !== $units || $beforeFacilities !== $facilities || $before['email'] !== $email;
                qu('UPDATE users SET name=?,email=?,role_code=?,active=?,password_hash=COALESCE(?,password_hash),
                    session_version=session_version+? WHERE id=?', [$name,$email,$role,$active,$hash,(int) $terminate,$id]);
                if ($hash !== null || $before['email'] !== $email || ((int) $before['active'] === 1 && !$active)) {
                    suspendClinicalIdentity($actor['id'], $id);
                }
                if ($terminate) {
                    if (profileSchemaReady()) {
                        qu('DELETE FROM personnel_case_permissions WHERE user_id=?',[$id]);
                    }
                    qu('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL', [$id]);
                }
                qu('DELETE FROM user_unit_scopes WHERE user_id=?', [$id]);
                qu('DELETE FROM user_facility_scopes WHERE user_id=?', [$id]);
            } else {
                qu('INSERT INTO users (name,email,role_code,active,password_hash) VALUES (?,?,?,?,?)', [$name,$email,$role,$active,$hash]);
                $id = (int) db()->lastInsertId();
            }
            foreach ($units as $unit) {
                qu('INSERT INTO user_unit_scopes VALUES (?,?)', [$id,$unit]);
            }
            foreach ($facilities as $facility) {
                qu('INSERT INTO user_facility_scopes VALUES (?,?)', [$id,$facility]);
            }
            audit($actor['id'], $created ? 'create' : 'update', 'users', (string) $id);
        });
    });
    done('Akun disimpan.', '/app/users');
}

function activityIndex(array $user): void
{
    [$scoped, $params] = scope($user, 'l.unit_id');
    $where = [$scoped];
    foreach (['action','entity','request_id'] as $key) {
        if (($value = field($_GET, $key, 48)) !== '') {
            $where[] = "l.$key=?";
            $params[] = $value;
        }
    }
    if (($actor = number($_GET, 'actor')) !== null) {
        $where[] = 'l.actor_id=?';
        $params[] = $actor;
    }
    if (($date = calendar($_GET, 'date')) !== null) {
        $where[] = 'l.created_at>=? AND l.created_at<DATE_ADD(?,INTERVAL 1 DAY)';
        array_push($params, $date, $date);
    }
    if (($result = choice($_GET, 'result', ['', 'success', 'denied'])) !== '') {
        $where[] = 'l.result=?';
        $params[] = $result;
    }
    [$rows, $total, $current, $per] = limitedRows('FROM activity_logs l', implode(' AND ', $where), $params,
        'l.created_at,l.actor_id,l.action,l.entity,l.result,l.request_id', 'l.id DESC');
    page('Log aktivitas', static function () use ($rows, $total, $current, $per): void {
        echo '<section class="surface"><h2>Aktivitas administratif</h2>';
        getStart('/app/log');
        input('date', 'Tanggal UTC', $_GET['date'] ?? '', 'date');
        input('actor', 'ID aktor', $_GET['actor'] ?? '', 'number');
        input('action', 'Aksi', $_GET['action'] ?? '', max: 48);
        input('entity', 'Entitas', $_GET['entity'] ?? '', max: 48);
        input('request_id', 'ID permintaan', $_GET['request_id'] ?? '', max: 32);
        selectField('result', 'Hasil', ['success'=>'Berhasil','denied'=>'Ditolak'], $_GET['result'] ?? '', 'Semua');
        echo '<button>Terapkan</button></form>';
        table(['Waktu UTC','Aktor','Aksi','Entitas','Hasil','ID permintaan'], $rows, static fn (array $row) => td(...array_values($row)));
        paginate('/app/log', $total, $current, $per);
        echo '</section>';
    }, $user, '/app/log');
}
