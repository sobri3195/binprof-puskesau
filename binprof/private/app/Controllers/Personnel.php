<?php
declare(strict_types=1);

require_once PROJECT . '/private/app/Services/admin.php';

const BP = ['unknown' => 'Belum dicatat', 'yes' => 'BP', 'no' => 'Non-BP'];
const PERSONNEL = '/app/personel';

function personnelQuery(array $user): array
{
    [$scope, $params] = scope($user, 'p.unit_id', 'p.facility_id');
    $from = 'FROM personnel p JOIN units u ON u.id=p.unit_id JOIN professions pr ON pr.id=p.profession_id
        LEFT JOIN ranks r ON p.rank_id=r.id';
    $hasRetirement = retirementReady();
    $state = choice($_GET, 'state', $hasRetirement ? ['active','inactive','archived','all','retired'] : ['active','inactive','archived','all'], 'all');
    $where = [$scope, match ($state) {
        'active' => 'p.archived_at IS NULL AND p.active=1',
        'inactive' => 'p.archived_at IS NULL AND p.active=0' . ($hasRetirement ? ' AND p.retired_on IS NULL' : ''),
        'retired' => 'p.archived_at IS NULL AND p.retired_on IS NOT NULL',
        'archived' => 'p.archived_at IS NOT NULL',
        default => 'p.archived_at IS NULL',
    }];
    if (($q = field($_GET, 'q', 100)) !== '') {
        $where[] = '(LOCATE(?,p.name)>0 OR LOCATE(?,p.nrp)>0)';
        array_push($params, $q, $q);
    }
    foreach (['unit_id' => 'p.unit_id', 'facility_id'=>'p.facility_id', 'profession_id' => 'p.profession_id'] as $name => $column) {
        if (($value = number($_GET, $name)) !== null) {
            $where[] = "$column=?";
            $params[] = $value;
        }
    }
    if (($group = field($_GET, 'rank_group', 80)) !== '') {
        $where[] = 'r.group_name=?';
        $params[] = $group;
    }
    if (($sp = number($_GET, 'specialty_id')) !== null) {
        $where[] = 'EXISTS (SELECT 1 FROM personnel_specialties s WHERE s.personnel_id=p.id AND s.specialty_id=?)';
        $params[] = $sp;
    }
    if (($bp = choice($_GET, 'bp', ['', ...array_keys(BP)])) !== '') {
        $where[] = 'p.bp_status=?';
        $params[] = $bp;
    }
    if (choice($_GET, 'promotion', ['0','1'], '0') === '1') {
        $where[] = 'p.promotion_date BETWEEN ? AND ?';
        array_push($params, day(), day(90));
    }
    if (choice($_GET, 'transfer', ['0','1'], '0') === '1') {
        $where[] = "NOT EXISTS (SELECT 1 FROM unit_transfers t WHERE t.personnel_id=p.id AND t.status='applied')";
    }
    if (($training = number($_GET, 'missing_training')) !== null) {
        if (!can($user,'training.read')) {
            fail(403,'Akses diklat diperlukan.');
        }
        $where[] = 'p.active=1 AND p.archived_at IS NULL AND NOT EXISTS (
            SELECT 1 FROM training_records t WHERE t.personnel_id=p.id AND t.type_id=?
            AND t.completed=1 AND t.archived_at IS NULL)';
        $params[] = $training;
    }
    $order = match (choice($_GET, 'sort', ['name','unit','promotion'], 'name')) {
        'unit' => 'u.name,p.name,p.id', 'promotion' => 'p.promotion_date IS NULL,p.promotion_date,p.name,p.id',
        default => 'p.name,p.id',
    };
    return [$from, implode(' AND ', $where), $params, $order];
}

function personnelIndex(array $user): void
{
    [$from, $where, $params, $order] = personnelQuery($user);
    $fields = 'p.id,p.name,p.nrp,u.name AS unit,pr.name AS profession,r.name AS rank_name,p.active,p.archived_at,p.promotion_date'
        . (retirementReady() ? ',p.retired_on' : ',NULL AS retired_on');
    $labels = ['Nama','NRP','Pangkat','Satuan','Profesi','Status','Rencana kenaikan'];
    if (isset($_GET['format'])) {
        if (!can($user, 'personnel.export')) {
            fail(403, 'Akses ekspor ditolak.');
        }
        require_once PROJECT . '/private/app/Services/exports.php';
        $format = choice($_GET, 'format', ['csv','xlsx','pdf']);
        $rows = qu("SELECT $fields $from WHERE $where ORDER BY $order LIMIT 1001", $params)->fetchAll();
        audit($user['id'], 'export_' . $format, 'personnel');
        sendExport($format, 'Personel', $labels, array_map('personnelCells', $rows));
    }
    [$rows, $total, $current, $per] = limitedRows($from, $where, $params, $fields, $order);
    $groups = qu('SELECT DISTINCT group_name FROM ranks ORDER BY group_name')->fetchAll(PDO::FETCH_COLUMN);
    page('Personel', static function () use ($user, $rows, $total, $current, $per, $groups, $labels): void {
        echo '<section class="surface"><div class="section-heading"><h2>Daftar personel</h2><div class="actions">';
        if (can($user, 'personnel.write')) {
            linkButton(PERSONNEL . '/edit', 'Tambah personel', class: 'primary');
        }
        if (can($user, 'personnel.export')) {
            exportButtons(PERSONNEL);
        }
        echo '</div></div>';
        getStart(PERSONNEL);
        if (isset($_GET['missing_training'])) {
            echo '<input type="hidden" name="missing_training" value="' . e($_GET['missing_training']) . '">';
        }
        input('q', 'Nama atau NRP', $_GET['q'] ?? '', max: 100);
        selectField('unit_id', 'Satuan', optionsFor('units', $user, false), $_GET['unit_id'] ?? '', 'Semua');
        selectField('facility_id','Fasilitas',optionsFor('facilities',$user,false),$_GET['facility_id'] ?? '','Semua');
        selectField('profession_id', 'Profesi', optionsFor('professions', activeOnly: false), $_GET['profession_id'] ?? '', 'Semua');
        selectField('rank_group', 'Kelompok pangkat', array_combine($groups, $groups), $_GET['rank_group'] ?? '', 'Semua');
        selectField('specialty_id', 'Spesialisasi', optionsFor('specialties', activeOnly: false), $_GET['specialty_id'] ?? '', 'Semua');
        selectField('state', 'Status dinas', ['all'=>'Semua nonarsip','active'=>'Aktif','inactive'=>'Nonaktif','archived'=>'Arsip']
            + (retirementReady() ? ['retired'=>'Purnawirawan'] : []), $_GET['state'] ?? 'all');
        selectField('bp', 'Status BP', BP, $_GET['bp'] ?? '', 'Semua');
        selectField('promotion', 'Rencana kenaikan', ['0'=>'Semua','1'=>'Dalam 90 hari'], $_GET['promotion'] ?? '0');
        selectField('transfer', 'Riwayat mutasi', ['0'=>'Semua','1'=>'Belum pindah satuan'], $_GET['transfer'] ?? '0');
        selectField('sort', 'Urutan', ['name'=>'Nama','unit'=>'Satuan','promotion'=>'Tanggal kenaikan'], $_GET['sort'] ?? 'name');
        selectField('per', 'Per halaman', [25=>25,50=>50,100=>100], $per);
        echo '<div class="actions"><button type="submit">Terapkan</button>';
        linkButton(PERSONNEL, 'Reset');
        echo '</div></form>';
        table([...$labels, 'Aksi'], $rows, static function (array $row): void {
            td(...personnelCells($row));
            echo '<td>';
            linkButton(PERSONNEL . '/detail', 'Detail', ['id' => $row['id']]);
            echo '</td>';
        });
        paginate(PERSONNEL, $total, $current, $per);
        echo '</section>';
    }, $user, PERSONNEL, 'Biodata, kedinasan, dan riwayat profesional.');
}

function personnelCells(array $row): array
{
    return [$row['name'], $row['nrp'], $row['rank_name'], $row['unit'], $row['profession'],
        $row['archived_at'] ? 'Arsip' : (!empty($row['retired_on']) ? 'Purnawirawan' : ($row['active'] ? 'Aktif' : 'Nonaktif')), $row['promotion_date']];
}

function person(array $user, string $id, bool $lock = false): array
{
    [$scope, $params] = scope($user, 'p.unit_id', 'p.facility_id');
    $row = qu("SELECT p.* FROM personnel p WHERE p.id=? AND $scope" . ($lock ? ' FOR UPDATE' : ''),
        [$id, ...$params])->fetch();
    if (!$row) {
        fail(404, 'Personel tidak ditemukan.');
    }
    return $row;
}

function personnelIdentityAllowed(array $user): bool
{
    if (can($user,'personnel.identity')) {
        return true;
    }
    static $migrationApplied;
    $migrationApplied ??= (bool) qu("SELECT 1 FROM schema_migrations WHERE version='008_binprof_position_roles'")->fetchColumn();
    return !$migrationApplied;
}

function personnelDetail(array $user): void
{
    $id = key32($_GET);
    $record = person($user, $id);
    $tabs = ['basic'=>'Info dasar','contact'=>'Kontak','history'=>'Riwayat kedinasan','awards'=>'Penghargaan','works'=>'Karya'];
    require_once PROJECT . '/private/app/Views/personnel_records.php';
    $tab = choice($_GET, 'tab', [...array_keys($tabs),...array_keys(PROFILE_TABS),RETIREMENT_TAB], 'basic');
    if (!personnelIdentityAllowed($user)) {
        unset($tabs['contact']);
        if ($tab==='contact') {
            fail(403,'Akses kontak dan NIK tidak tersedia.');
        }
    }
    $profileContent = isset(PROFILE_TABS[$tab]) ? profilePrepareTab($user,$record,$tab) : null;
    if ($tab === RETIREMENT_TAB) {
        require_once PROJECT . '/private/app/Views/retirement.php';
        $profileContent = retirementPrepareProfile($user,$record);
    }
    if (profileSchemaReady()) {
        foreach (PROFILE_TABS as $key=>$label) {
            if (profileAllowed($user,$key,'read')) {
                $tabs[$key] = $label;
            }
        }
    }
    if (retirementReady() && can($user,'retirement.read')) {
        $tabs[RETIREMENT_TAB] = 'Pensiun/Purnawirawan';
    }
    $writable = can($user, 'personnel.write');
    page($record['name'], static function () use ($user, $record, $id, $tabs, $tab, $writable, $profileContent): void {
        echo '<section class="surface"><div class="section-heading"><h2>Profil personel</h2><div class="actions">';
        linkButton(PERSONNEL, 'Kembali');
        if ($writable && !$record['archived_at']) {
            linkButton(PERSONNEL . '/edit', 'Ubah profil', ['id' => $id], 'primary');
            if (can($user, 'transfers.request') && empty($record['retired_on'])) {
                linkButton('/app/mutasi', 'Ajukan mutasi', ['id' => $id]);
            }
        }
        echo '</div></div><nav class="tabs personnel-tabs" aria-label="Bagian profil">';
        foreach ($tabs as $key => $label) {
            echo '<a ' . ($key === $tab ? 'aria-current="page" ' : '') . 'href="' . e(url(PERSONNEL . '/detail',
                ['id' => $id, 'tab' => $key])) . '">' . e($label) . '</a>';
        }
        echo '</nav>';
        if ($profileContent) {
            echo '<div data-profile-records><p class="subtle" role="status" hidden data-profile-busy>Memproses permintaan…</p>';
            $profileContent();
            echo '</div><script type="module" src="/assets/js/profile-records.js"></script>';
        } elseif ($tab === 'basic') {
            $relations = ['rank_id'=>'ranks','corps_id'=>'corps','profession_id'=>'professions','unit_id'=>'units','facility_id'=>'facilities'];
            foreach ($relations as $key => $master) {
                $record[$key] = optionsFor($master, activeOnly: false)[$record[$key]] ?? null;
            }
            $specialties = qu('SELECT s.name FROM personnel_specialties ps JOIN specialties s ON s.id=ps.specialty_id
                WHERE ps.personnel_id=? ORDER BY s.name', [$id])->fetchAll(PDO::FETCH_COLUMN);
            definition(['NRP'=>$record['nrp'], 'Pangkat'=>$record['rank_id'], 'Korps'=>$record['corps_id'],
                'Satuan'=>$record['unit_id'], 'Fasilitas'=>$record['facility_id'], 'Profesi'=>$record['profession_id'],
                'Spesialisasi'=>implode(', ', $specialties), 'Jabatan'=>$record['position_title'],
                'Pekerjaan'=>$record['occupation'], 'Kenaikan'=>$record['promotion_date'], 'Status BP'=>BP[$record['bp_status']],
                'Status'=>$record['archived_at'] ? 'Arsip' : (!empty($record['retired_on']) ? 'Purnawirawan' : ($record['active'] ? 'Aktif' : 'Nonaktif'))]);
            if ($writable) {
                $action = $record['archived_at'] ? 'restore' : 'archive';
                echo '<details><summary>' . ($record['archived_at'] ? 'Pulihkan personel' : 'Arsipkan personel') . '</summary>';
                echo '<p>Gunakan tombol konfirmasi untuk melanjutkan.</p>';
                postButton(PERSONNEL . '/status', 'Konfirmasi', ['id'=>$id,'version'=>$record['row_version'],'action'=>$action]);
                echo '</details>';
            }
        } elseif ($tab === 'contact') {
            $contact = $record['contact_ciphertext'] ? unseal($record['contact_ciphertext'], "$id:contact") : null;
            $nik = $record['nik_ciphertext'] ? unseal($record['nik_ciphertext'], "$id:nik") : null;
            definition(['Kontak' => $contact, 'NIK' => $nik]);
        } else {
            personnelAncillary($user, $record, $tab);
        }
        echo '</section>';
    }, $user, PERSONNEL);
}

function personnelForm(array $user): void
{
    $id = isset($_GET['id']) ? key32($_GET) : null;
    $row = $id ? person($user, $id) : [];
    if ($row['archived_at'] ?? null) {
        fail(409, 'Pulihkan arsip sebelum mengubah profil.');
    }
    $selected = $id ? qu('SELECT specialty_id FROM personnel_specialties WHERE personnel_id=?', [$id])->fetchAll(PDO::FETCH_COLUMN) : [];
    page($id ? 'Ubah personel' : 'Tambah personel', static function () use ($user, $row, $id, $selected): void {
        echo '<section class="surface form-surface">';
        postStart(PERSONNEL . '/save', $id ? ['id'=>$id,'version'=>$row['row_version']] : []);
        echo '<div class="form-grid">';
        input('name', 'Nama', $row['name'] ?? '', required: true);
        input('nrp', 'NRP', $row['nrp'] ?? '', required: true, max: 40);
        $facility = $user['operator_facility'] ?? null;
        if ($facility) {
            if (!$id) {
                echo '<input type="hidden" name="unit_id" value="' . e($facility['unit_id']) . '">';
            }
            echo '<input type="hidden" name="facility_id" value="' . e($facility['id']) . '">';
            definition(['Satuan'=>$facility['unit'],'Fasilitas'=>$facility['name']]);
        } else {
            if (!$id) {
                [$scoped,$args] = scope($user,'u.id','f.id');
                $units = qu("SELECT DISTINCT u.id,u.name FROM units u LEFT JOIN facilities f ON f.unit_id=u.id AND f.active=1
                    WHERE u.active=1 AND $scoped ORDER BY u.name,u.id",$args)->fetchAll(PDO::FETCH_KEY_PAIR);
                selectField('unit_id', 'Satuan', $units);
            }
            selectField('facility_id', 'Fasilitas', optionsFor('facilities', $user), $row['facility_id'] ?? '', 'Tanpa fasilitas');
        }
        selectField('profession_id', 'Profesi', optionsFor('professions'), $row['profession_id'] ?? '');
        selectField('rank_id', 'Pangkat', optionsFor('ranks'), $row['rank_id'] ?? '', 'Belum dicatat');
        selectField('corps_id', 'Korps', optionsFor('corps'), $row['corps_id'] ?? '', 'Belum dicatat');
        input('position_title', 'Jabatan', $row['position_title'] ?? '');
        input('occupation', 'Pekerjaan', $row['occupation'] ?? '');
        selectField('active', 'Status dinas', !empty($row['retired_on']) ? [0=>'Purnawirawan'] : [1=>'Aktif',0=>'Nonaktif'], $row['active'] ?? 1);
        selectField('bp', 'Status BP', BP, $row['bp_status'] ?? 'unknown');
        input('promotion_date', 'Rencana kenaikan pangkat', $row['promotion_date'] ?? '', 'date');
        if (personnelIdentityAllowed($user)) {
            input('contact', 'Kontak', isset($row['contact_ciphertext']) ? unseal($row['contact_ciphertext'], "$id:contact") : '', 'tel', max: 80);
            input('nik', 'NIK', isset($row['nik_ciphertext']) ? unseal($row['nik_ciphertext'], "$id:nik") : '', max: 16);
        }
        echo '</div><fieldset><legend>Spesialisasi</legend><div class="choices">';
        foreach (optionsFor('specialties', activeOnly: false) as $key => $label) {
            echo '<label><input type="checkbox" name="specialties[]" value="' . e($key) . '"'
                . (in_array($key, $selected) ? ' checked' : '') . '> ' . e($label) . '</label>';
        }
        echo '</div></fieldset><div class="actions"><button>Simpan</button>';
        linkButton(PERSONNEL, 'Batal');
        echo '</div></form></section>';
    }, $user, PERSONNEL, $id ? 'Perpindahan satuan diajukan melalui menu Mutasi.' : '');
}

function personnelSave(array $user): never
{
    $existing = isset($_POST['id']);
    $id = $existing ? key32($_POST) : bin2hex(random_bytes(16));
    $name = field($_POST, 'name', 160, true);
    $nrp = field($_POST, 'nrp', 40, true);
    if (!preg_match('~^[a-zA-Z0-9./-]+$~D', $nrp)) {
        fail(422, 'Format NRP tidak valid.');
    }
    $identity = personnelIdentityAllowed($user);
    if (!$identity && (array_key_exists('contact',$_POST) || array_key_exists('nik',$_POST))) {
        fail(403,'Perubahan kontak dan NIK tidak tersedia.');
    }
    $nik = field($_POST, 'nik', 16);
    if ($nik !== '' && !preg_match('/^[0-9]{16}$/D', $nik)) {
        fail(422, 'NIK harus berisi 16 angka.');
    }
    $contact = field($_POST, 'contact', 80);
    $selected = $_POST['specialties'] ?? [];
    if (!is_array($selected) || count($selected) > 50) {
        fail(422, 'Pilihan spesialisasi tidak valid.');
    }
    $facility = number($_POST, 'facility_id');
    $position = field($_POST, 'position_title');
    saveUnique(static function () use ($user, $id, $existing, $name, $nrp, $nik, $contact, $identity, $selected, $facility, $position): void {
        tx(static function () use ($user, $id, $existing, $name, $nrp, $nik, $contact, $identity, $selected, $facility, $position): void {
            $prior = $existing ? person($user, $id, true) : null;
            $unit = $prior ? (int) $prior['unit_id'] : number($_POST, 'unit_id');
            if (!$unit) {
                fail(422, 'Pilih satuan.');
            }
            verifyUnitFacility($unit, $facility, $user);
            if ($prior && ($prior['archived_at'] || (int) $prior['row_version'] !== number($_POST, 'version'))) {
                fail(409, 'Profil telah berubah. Buka ulang formulir.');
            }
            $active = (int) choice($_POST,'active',['0','1']);
            if (!empty($prior['retired_on']) && $active) {
                fail(409,'Status Purnawirawan tidak dapat diaktifkan sebagai personel dinas.');
            }
            $privateFields = $identity ? [
                $contact === '' ? null : seal($contact, "$id:contact"),
                $nik === '' ? null : seal($nik, "$id:nik"),
                $nik === '' ? null : hash_hmac('sha256', $nik, secretBytes(cfg('lookup_pepper')), true),
            ] : [$prior['contact_ciphertext'] ?? null,$prior['nik_ciphertext'] ?? null,$prior['nik_lookup_hash'] ?? null];
            $values = [$name, $nrp, $unit, $facility,
                verifyMaster('ranks', number($_POST, 'rank_id'), false),
                verifyMaster('corps', number($_POST, 'corps_id'), false),
                verifyMaster('professions', number($_POST, 'profession_id')),
                $position, field($_POST, 'occupation'), $active,
                choice($_POST, 'bp', array_keys(BP)), calendar($_POST, 'promotion_date'), ...$privateFields];
            if ($existing) {
                qu('UPDATE personnel SET name=?,nrp=?,unit_id=?,facility_id=?,rank_id=?,corps_id=?,profession_id=?,position_title=?,
                    occupation=?,active=?,bp_status=?,promotion_date=?,contact_ciphertext=?,nik_ciphertext=?,nik_lookup_hash=?,
                    row_version=row_version+1 WHERE id=?', [...$values, $id]);
                qu('DELETE FROM personnel_specialties WHERE personnel_id=?', [$id]);
            } else {
                qu('INSERT INTO personnel (name,nrp,unit_id,facility_id,rank_id,corps_id,profession_id,position_title,occupation,
                    active,bp_status,promotion_date,contact_ciphertext,nik_ciphertext,nik_lookup_hash,id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [...$values, $id]);
                qu('INSERT INTO service_history (personnel_id,unit_id,position_title,started_on) VALUES (?,?,?,?)',
                    [$id, $unit, $position, day()]);
            }
            foreach (array_unique($selected, SORT_REGULAR) as $specialty) {
                $sp = number(['specialty'=>$specialty], 'specialty');
                if (!$sp || !qu('SELECT 1 FROM specialties WHERE id=?', [$sp])->fetchColumn()) {
                    fail(422, 'Spesialisasi tidak ditemukan.');
                }
                qu('INSERT INTO personnel_specialties VALUES (?,?)', [$id, $sp]);
            }
            audit($user['id'], $existing ? 'update' : 'create', 'personnel', $id, $unit);
        });
    });
    done('Profil disimpan.', PERSONNEL . '/detail', ['id'=>$id]);
}

function personnelStatus(array $user): never
{
    $id = key32($_POST);
    $action = choice($_POST, 'action', ['archive','restore']);
    tx(static function () use ($user, $id, $action): void {
        $row = person($user, $id, true);
        if ((int) $row['row_version'] !== number($_POST, 'version') || (bool) $row['archived_at'] === ($action === 'archive')) {
            fail(409, 'Status profil sudah berubah.');
        }
        qu('UPDATE personnel SET archived_at=?,row_version=row_version+1 WHERE id=?',
            [$action === 'archive' ? gmdate('Y-m-d H:i:s') : null, $id]);
        audit($user['id'], $action, 'personnel', $id, (int) $row['unit_id']);
    });
    done('Status arsip diperbarui.', PERSONNEL . '/detail', ['id'=>$id]);
}

function personnelAncillary(array $user, array $record, string $tab): void
{
    $id = $record['id'];
    [$labels, $rows] = match ($tab) {
        'history' => [['Jabatan','Satuan','Mulai','Selesai'], qu('SELECT h.position_title,u.name,h.started_on,h.ended_on
            FROM service_history h JOIN units u ON u.id=h.unit_id WHERE h.personnel_id=? ORDER BY h.started_on DESC,h.id DESC', [$id])->fetchAll()],
        'awards' => [['Nama','Pemberi','Tanggal','Catatan'], qu('SELECT name,issuer,awarded_on,notes FROM awards
            WHERE personnel_id=? ORDER BY awarded_on DESC,id DESC', [$id])->fetchAll()],
        'works' => [['Judul','Jenis','Tahun','Deskripsi'], qu('SELECT title,kind,work_year,description FROM works
            WHERE personnel_id=? ORDER BY work_year DESC,id DESC', [$id])->fetchAll()],
    };
    table($labels, $rows, static fn (array $row) => td(...array_values($row)));
    if ($tab === 'history' || $record['archived_at'] || !can($user, 'personnel.write')) {
        return;
    }
    echo '<details class="entry"><summary>Tambah ' . ($tab === 'awards' ? 'penghargaan' : 'karya') . '</summary>';
    postStart(PERSONNEL . '/riwayat', ['id'=>$id,'tab'=>$tab]);
    echo '<div class="form-grid">';
    if ($tab === 'awards') {
        input('name', 'Nama penghargaan', required: true);
        input('issuer', 'Pemberi', required: true);
        input('date', 'Tanggal', type: 'date', required: true);
    } else {
        input('title', 'Judul', required: true);
        input('kind', 'Jenis', required: true, max: 100);
        input('year', 'Tahun', type: 'number', required: true);
    }
    echo '</div>';
    textArea('notes', $tab === 'awards' ? 'Catatan' : 'Deskripsi');
    echo '<button>Simpan</button></form></details>';
}

function personnelAncillarySave(array $user): never
{
    $id = key32($_POST);
    $tab = choice($_POST, 'tab', ['awards','works']);
    tx(static function () use ($user, $id, $tab): void {
        $row = person($user, $id, true);
        if ($row['archived_at']) {
            fail(409, 'Profil dalam arsip.');
        }
        $notes = field($_POST, 'notes', 4000);
        if ($tab === 'awards') {
            $date = calendar($_POST, 'date') ?? fail(422, 'Tanggal penghargaan diperlukan.');
            qu('INSERT INTO awards (personnel_id,name,issuer,awarded_on,notes) VALUES (?,?,?,?,?)',
                [$id, field($_POST, 'name', 160, true), field($_POST, 'issuer', 160, true), $date, $notes]);
        } else {
            $year = number($_POST, 'year');
            if (!$year || $year < 1900 || $year > (int) today()->format('Y') + 1) {
                fail(422, 'Tahun karya tidak valid.');
            }
            qu('INSERT INTO works (personnel_id,title,kind,work_year,description) VALUES (?,?,?,?,?)',
                [$id, field($_POST, 'title', 160, true), field($_POST, 'kind', 100, true), $year, $notes]);
        }
        audit($user['id'], 'add_' . $tab, 'personnel', $id, (int) $row['unit_id']);
    });
    done('Riwayat disimpan.', PERSONNEL . '/detail', ['id'=>$id,'tab'=>$tab]);
}
