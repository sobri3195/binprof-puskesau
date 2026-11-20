<?php
declare(strict_types=1);

const MASTERS = [
    'commands' => 'Komando', 'units' => 'Satuan', 'ranks' => 'Pangkat', 'corps' => 'Korps',
    'professions' => 'Profesi', 'specialties' => 'Spesialisasi', 'facility_types' => 'Jenis fasilitas',
    'facilities' => 'Fasilitas', 'training_types' => 'Jenis diklat',
];

function optionsFor(string $table, ?array $user = null, bool $activeOnly = true, ?int $retain = null): array
{
    if (!isset(MASTERS[$table])) {
        throw new LogicException('Unknown master table');
    }
    $where = $activeOnly ? 'm.active=1' : '1=1';
    $params = [];
    $label = 'm.name';
    if ($activeOnly && $retain !== null) {
        $where = '(m.active=1 OR m.id=?)';
        $params[] = $retain;
        $label = "CASE WHEN m.active=1 THEN m.name ELSE CONCAT(m.name,' (nonaktif)') END";
    }
    if ($user && $table === 'facilities') {
        [$scoped, $scopeArgs] = scope($user, 'm.unit_id', 'm.id');
        $where .= " AND $scoped";
        array_push($params, ...$scopeArgs);
    } elseif ($user && $table === 'units' && $user['role_code'] !== 'super_admin') {
        [$scoped, $scopeArgs] = scope($user, 'm.id');
        $where .= " AND ($scoped OR EXISTS (SELECT 1 FROM user_facility_scopes fs
            JOIN facilities f ON f.id=fs.facility_id WHERE fs.user_id=? AND f.unit_id=m.id))";
        array_push($params, ...$scopeArgs);
        $params[] = $user['id'];
    }
    return qu("SELECT m.id,$label FROM $table m WHERE $where ORDER BY m.name,m.id", $params)->fetchAll(PDO::FETCH_KEY_PAIR);
}

function verifyMaster(string $table, ?int $id, bool $required = true, ?int $retain = null): ?int
{
    if (!isset(MASTERS[$table]) || ($id === null && $required)) {
        fail(422, 'Lengkapi pilihan master.');
    }
    if ($id !== null && !qu("SELECT 1 FROM $table WHERE id=? AND (active=1 OR id=?)", [$id, $retain])->fetchColumn()) {
        fail(422, 'Pilihan master tidak tersedia.');
    }
    return $id;
}

function verifyUnitFacility(int $unit, ?int $facility, ?array $user = null): void
{
    verifyMaster('units', $unit);
    if ($facility !== null
        && !qu('SELECT 1 FROM facilities WHERE id=? AND unit_id=? AND active=1', [$facility, $unit])->fetchColumn()) {
        fail(422, 'Fasilitas harus berada di satuan yang dipilih.');
    }
    if ($user !== null) {
        [$condition, $params] = scope($user, 'x.unit_id', 'x.facility_id');
        if (!qu("SELECT 1 FROM (SELECT ? AS unit_id, ? AS facility_id) x WHERE $condition",
            [$unit, $facility, ...$params])->fetchColumn()) {
            fail(403, 'Satuan atau fasilitas berada di luar cakupan.');
        }
    }
}

function limitedRows(string $from, string $where, array $params, string $fields, string $order): array
{
    $total = (int) qu("SELECT COUNT(*) $from WHERE $where", $params)->fetchColumn();
    $per = pageSize();
    $current = min(pageNumber(), max(1, (int) ceil($total / $per)));
    $rows = qu("SELECT $fields $from WHERE $where ORDER BY $order LIMIT ? OFFSET ?",
        [...$params, $per, ($current - 1) * $per])->fetchAll();
    return [$rows, $total, $current, $per];
}

function saveUnique(callable $work): mixed
{
    try {
        return $work();
    } catch (PDOException $error) {
        if (($error->errorInfo[1] ?? null) === 1062) {
            fail(409, 'Nilai unik tersebut sudah dipakai.');
        }
        throw $error;
    }
}

function exportButtons(string $path): void
{
    foreach (['csv' => 'CSV', 'xlsx' => 'XLSX', 'pdf' => 'PDF'] as $type => $name) {
        linkButton($path, $name, ['format' => $type] + array_diff_key($_GET, array_flip(['r', 'page'])));
    }
}
