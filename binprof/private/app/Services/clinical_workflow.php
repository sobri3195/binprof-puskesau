<?php
declare(strict_types=1);

require_once PROJECT . '/private/app/Services/jobs.php';

function supervisor(array $user, int $facility): void
{
    if (!clinicalPrivilege($user['id'], $facility)['can_approve']) {
        fail(403, 'Kewenangan supervisor fasilitas diperlukan.');
    }
}

function facilityHasPatient(string $patient, int $facility): bool
{
    return (bool) qu('SELECT 1 FROM clinical_patients cp JOIN personnel p ON p.id=cp.personnel_id
        WHERE cp.id=? AND (p.facility_id=? OR EXISTS (
            SELECT 1 FROM clinical_encounters e WHERE e.patient_id=cp.id AND e.facility_id=?
        ))', [$patient, $facility, $facility])->fetchColumn();
}

function clinicalRequest(array $user, array $priv, string $purpose): never
{
    $patient = key32($_POST, 'patient');
    $source = number($_POST, 'source') ?? fail(422, 'Pilih fasilitas sumber.');
    $workplace = (int) $priv['facility_id'];
    $scope = choice($_POST, 'scope', ['read','export']);
    $reason = clinicalReason();
    tx(static function () use ($user, $patient, $source, $workplace, $scope, $purpose, $reason): void {
        if ($source === $workplace) {
            fail(422, 'Untuk fasilitas sendiri, hubungi supervisor penugasan perawatan.');
        }
        $current = clinicalPrivilege($user['id'], $workplace);
        if (!$current['can_' . $scope]) {
            fail(403, 'Akun Anda tidak memiliki kewenangan untuk lingkup ini.');
        }
        qu('SELECT id FROM users WHERE id=? FOR UPDATE', [$user['id']]);
        $limit = (int) qu("SELECT COUNT(*) FROM clinical_access_logs WHERE actor_id=? AND action='request'
            AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 HOUR", [$user['id']])->fetchColumn();
        if ($limit >= 15) {
            header('Retry-After: 3600');
            fail(429, 'Batas pengajuan klinis tercapai. Coba lagi nanti.');
        }
        $exists = qu('SELECT id FROM clinical_patients WHERE id=? FOR UPDATE', [$patient])->fetchColumn();
        $activeFacility = qu('SELECT 1 FROM facilities WHERE id=? AND active=1', [$source])->fetchColumn();
        if (!$exists || !$activeFacility || !facilityHasPatient($patient, $source)) {
            // Use the same confirmation as a submitted request to avoid disclosing patient existence.
            clinicalAudit($user['id'], $workplace, null, 'request', $purpose, 'denied');
            return;
        }
        $existing = qu("SELECT r.id FROM clinical_access_requests r
            LEFT JOIN clinical_access_grants g ON g.request_id=r.id
            WHERE r.patient_id=? AND r.requester_id=? AND r.workplace_id=? AND r.source_facility_id=? AND r.purpose=?
              AND r.access_scope=? AND (r.status='pending' OR (r.status='approved' AND g.revoked_at IS NULL
                  AND g.expires_at>UTC_TIMESTAMP())) LIMIT 1", [$patient, $user['id'], $workplace, $source, $purpose, $scope])->fetchColumn();
        if (!$existing) {
            $id = bin2hex(random_bytes(16));
            qu('INSERT INTO clinical_access_requests (id,patient_id,requester_id,workplace_id,source_facility_id,purpose,
                access_scope,justification_ciphertext) VALUES (?,?,?,?,?,?,?,?)',
                [$id, $patient, $user['id'], $workplace, $source, $purpose, $scope, seal($reason, "$id:request")]);
            qu('INSERT INTO notifications (user_id,event_key,message)
                SELECT c.user_id,?,? FROM user_clinical_privileges c JOIN users u ON u.id=c.user_id
                WHERE c.facility_id=? AND c.user_id<>? AND c.active=1 AND c.can_approve=1 AND u.active=1',
                ["clinical:$id:request",'Ada pengajuan akses klinis yang memerlukan peninjauan.',$source,$user['id']]);
        }
        clinicalAudit($user['id'], $workplace, $patient, 'request', $purpose, reason: $reason);
    });
    done('Pengajuan diterima untuk diproses. Status pengajuan yang dapat ditinjau muncul di daftar Anda.',
        CLINICAL, ['workplace'=>$workplace]);
}

function clinicalDecision(array $user, array $priv): never
{
    $facility = (int) $priv['facility_id'];
    $id = key32($_POST);
    $decision = choice($_POST, 'decision', ['approve','reject','revoke']);
    tx(static function () use ($user, $facility, $id, $decision): void {
        supervisor($user, $facility);
        $request = qu('SELECT * FROM clinical_access_requests WHERE id=? AND source_facility_id=? FOR UPDATE',
            [$id, $facility])->fetch();
        if (!$request) {
            fail(404, 'Pengajuan tidak ditemukan.');
        }
        $requester = (int) $request['requester_id'];
        if ($requester === $user['id']) {
            fail(403, 'Pemohon tidak dapat meninjau pengajuannya sendiri.');
        }
        if ($decision === 'revoke') {
            $updated = qu('UPDATE clinical_access_grants SET revoked_at=UTC_TIMESTAMP()
                WHERE request_id=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP()', [$id])->rowCount();
            if (!$updated) {
                fail(409, 'Grant tidak aktif.');
            }
        } else {
            if ($request['status'] !== 'pending') {
                fail(409, 'Pengajuan sudah ditinjau.');
            }
            if ($decision === 'approve') {
                $recipient = clinicalPrivilege($requester, (int) $request['workplace_id']);
                if (!$recipient['can_' . $request['access_scope']] || !facilityHasPatient($request['patient_id'], $facility)) {
                    fail(409, 'Permohonan tidak lagi memenuhi syarat.');
                }
                qu('INSERT INTO clinical_access_grants (request_id,expires_at) VALUES (?,UTC_TIMESTAMP()+INTERVAL 24 HOUR)', [$id]);
            }
            qu('UPDATE clinical_access_requests SET status=?,approver_id=?,decided_at=UTC_TIMESTAMP() WHERE id=?',
                [$decision === 'approve' ? 'approved' : 'rejected', $user['id'], $id]);
        }
        clinicalAudit($user['id'], $facility, $request['patient_id'], 'grant_' . $decision, $request['purpose']);
        notify($requester, "clinical:$id:$decision", 'Status pengajuan akses klinis diperbarui.');
    });
    done('Status pengajuan diperbarui.', CLINICAL, ['workplace'=>$facility]);
}

function clinicalPrivileges(array $user, array $priv): never
{
    $facility = (int) $priv['facility_id'];
    $active = (int) choice($_POST, 'active', ['0','1']);
    $rights = [];
    foreach (['read','write','export','approve'] as $key) {
        $rights[$key] = (int) choice($_POST, $key, ['0','1']);
    }
    if ($active && ((!$rights['read'] && ($rights['write'] || $rights['export'])) || !in_array(1, $rights, true))) {
        fail(422, 'Pilih kewenangan yang sah. Tulis dan ekspor memerlukan kewenangan baca.');
    }
    $target = email(field($_POST,'email',254,true));
    tx(static function () use ($user, $facility, $active, $rights, $target): void {
        qu('SELECT id FROM application_state WHERE id=1 FOR UPDATE');
        supervisor($user, $facility);
        $recipient = (int) qu('SELECT id FROM users WHERE email=? AND active=1',[$target])->fetchColumn();
        if (!$recipient || $recipient === $user['id']) {
            fail(422, 'Pilih akun petugas aktif lain.');
        }
        if ($active) {
            qu('INSERT INTO user_clinical_privileges (user_id,facility_id,can_read,can_write,can_export,can_approve,active,designated_by)
                VALUES (?,?,?,?,?,?,1,?) ON DUPLICATE KEY UPDATE can_read=VALUES(can_read),can_write=VALUES(can_write),
                can_export=VALUES(can_export),can_approve=VALUES(can_approve),active=1,designated_by=VALUES(designated_by)',
                [$recipient,$facility,...array_values($rights),$user['id']]);
        } else {
            if (!qu('UPDATE user_clinical_privileges SET active=0,designated_by=? WHERE user_id=? AND facility_id=? AND active=1',
                    [$user['id'],$recipient,$facility])->rowCount()) {
                fail(404,'Kewenangan aktif tidak ditemukan.');
            }
        }
        // Revocation is final for existing access. Re-enabling a privilege requires fresh assignments and grants.
        if (!$active || !$rights['read']) {
            qu('UPDATE patient_care_assignments SET revoked_at=UTC_TIMESTAMP()
                WHERE user_id=? AND facility_id=? AND revoked_at IS NULL', [$recipient, $facility]);
        }
        if (!$active || !$rights['read'] || !$rights['export']) {
            $scope = $active && $rights['read'] ? 'export' : null;
            $condition = $scope ? ' AND r.access_scope=?' : '';
            $params = $scope ? [$recipient,$facility,$scope] : [$recipient,$facility];
            qu("UPDATE clinical_access_grants g JOIN clinical_access_requests r ON r.id=g.request_id
                SET g.revoked_at=UTC_TIMESTAMP() WHERE r.requester_id=? AND r.workplace_id=? AND g.revoked_at IS NULL$condition", $params);
            qu("UPDATE clinical_access_requests r SET r.status='rejected',r.approver_id=?,r.decided_at=UTC_TIMESTAMP()
                WHERE r.requester_id=? AND r.workplace_id=? AND r.status='pending'$condition", [$user['id'],...$params]);
        }
        clinicalAudit($user['id'], $facility, null, $active ? 'privilege_set' : 'privilege_revoke', null);
    });
    done('Kewenangan klinis disimpan.', CLINICAL, ['workplace'=>$facility]);
}

function clinicalCare(array $user, array $priv): never
{
    $facility = (int) $priv['facility_id'];
    $decision = choice($_POST, 'decision', ['assign','revoke']);
    $target = email(field($_POST,'email',254,true));
    $nrp = field($_POST,'nrp',40);
    $patient = field($_POST,'patient',32);
    if (($nrp === '') === ($patient === '') || ($decision === 'revoke' && $patient === '')) {
        fail(422, 'Masukkan salah satu identitas pasien. Untuk mencabut, gunakan kode pasien.');
    }
    if ($patient !== '') {
        $patient = key32($_POST,'patient');
    }
    $days = $decision === 'assign' ? number($_POST,'days') : null;
    if ($decision === 'assign' && (!$days || $days > 30)) {
        fail(422, 'Masa penugasan harus 1–30 hari.');
    }
    $id = tx(static function () use ($user,$facility,$decision,$target,$nrp,$patient,$days): string {
        supervisor($user, $facility);
        $recipient = (int) qu('SELECT id FROM users WHERE email=? AND active=1',[$target])->fetchColumn();
        if (!$recipient || $recipient === $user['id']) {
            fail(422, 'Pilih akun petugas aktif lain.');
        }
        if ($decision === 'revoke') {
            if (!qu('UPDATE patient_care_assignments SET revoked_at=UTC_TIMESTAMP()
                WHERE patient_id=? AND user_id=? AND facility_id=? AND revoked_at IS NULL',
                [$patient,$recipient,$facility])->rowCount()) {
                fail(404, 'Penugasan tidak ditemukan.');
            }
        } else {
            if (!clinicalPrivilege($recipient,$facility)['can_read']) {
                fail(422, 'Petugas memerlukan kewenangan baca klinis aktif.');
            }
            if ($nrp !== '') {
                $person = qu('SELECT id FROM personnel WHERE nrp=? AND facility_id=? AND archived_at IS NULL FOR UPDATE',
                    [$nrp,$facility])->fetchColumn();
                if (!$person) {
                    fail(404,'Personel tidak ditemukan di fasilitas ini.');
                }
                $patient = qu('SELECT id FROM clinical_patients WHERE personnel_id=?',[$person])->fetchColumn();
                if (!$patient) {
                    $patient = bin2hex(random_bytes(16));
                    qu('INSERT INTO clinical_patients (id,personnel_id) VALUES (?,?)',[$patient,$person]);
                }
            } elseif (!facilityHasPatient($patient, $facility)) {
                fail(404,'Pasien tidak terdaftar di fasilitas ini.');
            }
            qu('INSERT INTO patient_care_assignments (patient_id,user_id,facility_id,assigned_by,expires_at)
                VALUES (?,?,?,?,TIMESTAMPADD(DAY,?,UTC_TIMESTAMP())) ON DUPLICATE KEY UPDATE
                assigned_by=VALUES(assigned_by),expires_at=VALUES(expires_at),revoked_at=NULL',
                [$patient,$recipient,$facility,$user['id'],$days]);
        }
        clinicalAudit($user['id'], $facility, $patient, 'assignment_' . $decision, null);
        return $patient;
    });
    page('Status penugasan', static function () use ($decision,$id,$facility): void {
        echo '<section class="surface">';
        echo '<p role="status">' . ($decision === 'assign' ? 'Penugasan disimpan.' : 'Penugasan dicabut.') . '</p>';
        if ($decision === 'assign') {
            definition(['Kode pasien untuk rujukan resmi'=>$id]);
        }
        linkButton(CLINICAL, 'Kembali', ['workplace'=>$facility]);
        echo '</section>';
    }, $user, CLINICAL);
    exit;
}

function clinicalAuditPage(array $user): void
{
    $facility = number($_GET,'workplace') ?? fail(422,'Fasilitas diperlukan.');
    $rows = tx(static function () use ($user,$facility): array {
        supervisor($user,$facility);
        clinicalAudit($user['id'],$facility,null,'review_audit',null);
        return qu('SELECT l.created_at,u.name AS actor,l.action,l.purpose,l.result,l.request_id
            FROM clinical_access_logs l JOIN users u ON u.id=l.actor_id WHERE l.facility_id=?
            AND l.action<>\'review_audit\' ORDER BY l.id DESC LIMIT 100',[$facility])->fetchAll();
    });
    page('Audit akses klinis', static function () use ($rows,$facility): void {
        echo '<section class="surface"><h2>100 kejadian terbaru (UTC)</h2>';
        table(['Waktu','Petugas','Tindakan','Tujuan','Hasil','ID permintaan'], $rows, static fn (array $row) =>
            td($row['created_at'],$row['actor'],$row['action'],PURPOSES[$row['purpose']] ?? null,
                $row['result']==='success' ? 'Berhasil' : 'Ditolak',$row['request_id']));
        linkButton(CLINICAL,'Kembali',['workplace'=>$facility]);
        echo '</section>';
    }, $user, CLINICAL);
}
