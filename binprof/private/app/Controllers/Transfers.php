<?php
declare(strict_types=1);

require_once PROJECT . '/private/app/Controllers/Personnel.php';
require_once PROJECT . '/private/app/Services/jobs.php';

const TRANSFER = '/app/mutasi';
const TRANSFER_STATE = ['pending'=>'Menunggu','approved'=>'Disetujui','applied'=>'Diterapkan','rejected'=>'Ditolak / batal'];

function transfersIndex(array $user): void
{
    $approver = can($user, 'transfers.approve');
    $where = $approver ? '1=1' : 't.requester_id=?';
    $params = $approver ? [] : [$user['id']];
    if (($status = choice($_GET, 'status', ['', ...array_keys(TRANSFER_STATE)])) !== '') {
        $where .= ' AND t.status=?';
        $params[] = $status;
    }
    $from = 'FROM unit_transfers t JOIN units a ON t.from_unit_id=a.id JOIN units b ON t.to_unit_id=b.id
        JOIN personnel p ON p.id=t.personnel_id';
    [$rows, $total, $current, $per] = limitedRows($from, $where, $params,
        't.id,t.status,t.effective_on,t.requester_id,a.name AS asal,b.name AS tujuan,' . ($approver ? 'p.name' : 'NULL AS name'),
        't.created_at DESC,t.id');
    $person = isset($_GET['id']) ? person($user, key32($_GET)) : null;
    page('Mutasi satuan', static function () use ($rows, $person, $approver, $user, $total, $current, $per): void {
        if ($person && !$person['archived_at']) {
            echo '<section class="surface"><h2>Ajukan mutasi</h2><p>' . e($person['name']) . '</p>';
            postStart(TRANSFER . '/request', ['id'=>$person['id']]);
            echo '<div class="form-grid">';
            $units = optionsFor('units');
            unset($units[(int) $person['unit_id']]);
            selectField('unit_id', 'Satuan tujuan', $units);
            selectField('facility_id', 'Fasilitas tujuan', optionsFor('facilities'), blank: 'Tanpa fasilitas');
            input('effective_on', 'Tanggal efektif', day(), 'date', true);
            echo '</div><button>Ajukan</button></form></section>';
        }
        echo '<section class="surface"><div class="section-heading"><h2>Pengajuan</h2><div class="actions">';
        linkButton(PERSONNEL, 'Pilih personel');
        if ($approver) {
            postButton(TRANSFER . '/process', 'Proses jadwal');
        }
        echo '</div></div>';
        getStart(TRANSFER);
        selectField('status','Status',TRANSFER_STATE,$_GET['status'] ?? '', 'Semua');
        echo '<button>Terapkan</button></form>';
        table(['Personel','Asal','Tujuan','Efektif','Status','Aksi'], $rows, static function (array $row) use ($approver, $user): void {
            td($row['name'], $row['asal'], $row['tujuan'], $row['effective_on'], TRANSFER_STATE[$row['status']]);
            echo '<td><div class="actions">';
            if ($approver && $row['status'] === 'pending' && (int) $row['requester_id'] !== $user['id']) {
                postButton(TRANSFER . '/decide', 'Setujui', ['id'=>$row['id'],'decision'=>'approved']);
                postButton(TRANSFER . '/decide', 'Tolak', ['id'=>$row['id'],'decision'=>'rejected']);
            }
            echo '</div></td>';
        });
        paginate(TRANSFER, $total, $current, $per);
        echo '</section>';
    }, $user, TRANSFER, 'Ajukan perpindahan, tinjau persetujuan, dan terapkan pada tanggal efektif.');
}

function requestTransfer(array $user): never
{
    $personId = key32($_POST);
    $target = number($_POST, 'unit_id') ?? fail(422, 'Pilih satuan tujuan.');
    $facility = number($_POST, 'facility_id');
    $effective = calendar($_POST, 'effective_on') ?? fail(422, 'Tanggal efektif diperlukan.');
    if ($effective < day()) {
        fail(422, 'Tanggal efektif tidak boleh di masa lalu.');
    }
    tx(static function () use ($user, $personId, $target, $facility, $effective): void {
        $person = person($user, $personId, true);
        $from = (int) $person['unit_id'];
        if ($person['archived_at'] || $from === $target) {
            fail(422, 'Personel aktif dalam daftar dan satuan tujuan yang berbeda diperlukan.');
        }
        verifyUnitFacility($target, $facility);
        if (qu("SELECT 1 FROM unit_transfers WHERE personnel_id=? AND status IN ('pending','approved')", [$personId])->fetchColumn()) {
            fail(409, 'Sudah ada pengajuan aktif untuk personel ini.');
        }
        $id = bin2hex(random_bytes(16));
        qu('INSERT INTO unit_transfers (id,personnel_id,from_unit_id,to_unit_id,to_facility_id,requester_id,effective_on)
            VALUES (?,?,?,?,?,?,?)', [$id,$personId,$from,$target,$facility,$user['id'],$effective]);
        qu("INSERT INTO notifications (user_id,event_key,message)
            SELECT u.id,?,? FROM users u JOIN role_permissions r ON r.role_code=u.role_code
            WHERE r.permission_code='transfers.approve' AND u.active=1 AND u.id<>?",
            ["transfer:$id:request", 'Pengajuan mutasi baru menunggu peninjauan.', $user['id']]);
        audit($user['id'], 'request_transfer', 'unit_transfers', $id, $from);
    });
    done('Pengajuan mutasi dicatat.', TRANSFER);
}

function decideTransfer(array $user): never
{
    $id = key32($_POST);
    $decision = choice($_POST, 'decision', ['approved','rejected']);
    tx(static function () use ($user, $id, $decision): void {
        $row = qu('SELECT * FROM unit_transfers WHERE id=? FOR UPDATE', [$id])->fetch();
        if (!$row) {
            fail(404, 'Pengajuan tidak ditemukan.');
        }
        if ((int) $row['requester_id'] === $user['id']) {
            fail(403, 'Pengajuan memerlukan persetujuan pengelola lain.');
        }
        if ($row['status'] !== 'pending') {
            fail(409, 'Pengajuan sudah ditinjau.');
        }
        qu('UPDATE unit_transfers SET status=?,approver_id=?,decided_at=UTC_TIMESTAMP() WHERE id=?', [$decision,$user['id'],$id]);
        notify((int) $row['requester_id'], "transfer:$id:$decision", 'Status pengajuan mutasi Anda diperbarui.');
        audit($user['id'], $decision . '_transfer', 'unit_transfers', $id, (int) $row['from_unit_id']);
    });
    if ($decision === 'approved') {
        applyTransfer($id, $user['id']);
    }
    done('Keputusan mutasi disimpan.', TRANSFER);
}

function runScheduledWork(array $user): never
{
    $count = scheduledWork($user['id']);
    done("$count mutasi diterapkan. Pengingat diperbarui.", TRANSFER);
}

function notificationIndex(array $user): void
{
    [$rows, $total, $current, $per] = limitedRows('FROM notifications', 'user_id=?', [$user['id']], '*', 'id DESC');
    page('Notifikasi', static function () use ($rows, $total, $current, $per): void {
        echo '<section class="surface"><div class="section-heading"><h2>Pemberitahuan akun</h2>';
        postButton('/app/notifikasi/read', 'Tandai semua dibaca');
        echo '</div>';
        table(['Pesan','Waktu UTC','Status','Aksi'], $rows, static function (array $row): void {
            td($row['message'], $row['created_at'], $row['read_at'] ? 'Dibaca' : 'Belum dibaca');
            echo '<td>';
            if (!$row['read_at']) {
                postButton('/app/notifikasi/read', 'Tandai dibaca', ['id'=>$row['id']]);
            }
            echo '</td>';
        });
        paginate('/app/notifikasi', $total, $current, $per);
        echo '</section>';
    }, $user, '/app/notifikasi');
}

function notificationRead(array $user): never
{
    $id = number($_POST, 'id');
    qu('UPDATE notifications SET read_at=UTC_TIMESTAMP() WHERE user_id=? AND read_at IS NULL' . ($id ? ' AND id=?' : ''),
        $id ? [$user['id'],$id] : [$user['id']]);
    done('Status notifikasi diperbarui.', '/app/notifikasi');
}
