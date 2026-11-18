<?php
declare(strict_types=1);

require_once PROJECT . '/private/app/Services/admin.php';
require_once PROJECT . '/private/app/Services/PersonnelRecords/catalog.php';

function notify(int $user, string $key, string $message): void
{
    qu('INSERT INTO notifications (user_id,event_key,message) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE event_key=VALUES(event_key)', [$user, $key, $message]);
}

function applyTransfer(string $id, ?int $actor = null): bool
{
    return tx(static function () use ($id, $actor): bool {
        $person = qu('SELECT p.unit_id,p.archived_at,p.position_title,p.id FROM personnel p
            WHERE p.id=(SELECT personnel_id FROM unit_transfers WHERE id=?) FOR UPDATE', [$id])->fetch();
        if (!$person) {
            return false;
        }
        $transfer = qu('SELECT * FROM unit_transfers WHERE id=? FOR UPDATE', [$id])->fetch();
        if ($transfer['status'] !== 'approved' || $transfer['effective_on'] > day()) {
            return false;
        }
        $from = (int) $transfer['from_unit_id'];
        $to = (int) $transfer['to_unit_id'];
        $facility = $transfer['to_facility_id'] === null ? null : (int) $transfer['to_facility_id'];
        $valid = !$person['archived_at'] && (int) $person['unit_id'] === $from;
        if ($valid) {
            try {
                verifyUnitFacility($to, $facility);
            } catch (HttpError $error) {
                if ($error->status !== 422) {
                    throw $error;
                }
                $valid = false;
            }
        }
        if (!$valid) {
            qu("UPDATE unit_transfers SET status='rejected' WHERE id=?", [$id]);
            notify((int) $transfer['requester_id'], "transfer:$id:invalidated", 'Pengajuan mutasi tidak dapat diterapkan. Periksa status pada menu Mutasi.');
            audit($actor, 'invalidate_transfer', 'unit_transfers', $id, $from);
            return false;
        }
        qu('UPDATE personnel SET unit_id=?,facility_id=?,row_version=row_version+1 WHERE id=?', [$to,$facility,$person['id']]);
        qu('UPDATE service_history SET ended_on=? WHERE personnel_id=? AND ended_on IS NULL',
            [$transfer['effective_on'],$person['id']]);
        qu('INSERT INTO service_history (personnel_id,unit_id,position_title,started_on) VALUES (?,?,?,?)',
            [$person['id'],$to,$person['position_title'],$transfer['effective_on']]);
        qu("UPDATE unit_transfers SET status='applied',applied_at=UTC_TIMESTAMP() WHERE id=?", [$id]);
        notify((int) $transfer['requester_id'], "transfer:$id:applied", 'Mutasi yang Anda ajukan telah diterapkan.');
        audit($actor, 'apply_transfer', 'unit_transfers', $id, $from);
        return true;
    });
}

function scheduledWork(?int $actor = null): int
{
    if ((int) qu("SELECT GET_LOCK(CONCAT('binprof:', SHA1(DATABASE()), ':jobs'),0)")->fetchColumn() !== 1) {
        fail(409, 'Pekerjaan terjadwal sedang berjalan.');
    }
    try {
        $ids = qu("SELECT id FROM unit_transfers WHERE status='approved' AND effective_on<=? ORDER BY effective_on,id LIMIT 200",
            [day()])->fetchAll(PDO::FETCH_COLUMN);
        $transferred = 0;
        foreach ($ids as $id) {
            $transferred += (int) applyTransfer($id, $actor);
        }
        $personScope = "(u.role_code='super_admin' OR
            EXISTS (SELECT 1 FROM user_unit_scopes us WHERE us.user_id=u.id AND us.unit_id=p.unit_id) OR
            EXISTS (SELECT 1 FROM user_facility_scopes fs WHERE fs.user_id=u.id AND fs.facility_id=p.facility_id))";
        $recipients = "u.active=1 AND p.active=1 AND p.archived_at IS NULL AND $personScope";
        qu("INSERT INTO notifications (user_id,event_key,message)
            SELECT u.id,CONCAT('promotion:',p.id,':',p.promotion_date),'Ada rencana kenaikan pangkat dalam 90 hari.'
            FROM users u JOIN role_permissions rp ON rp.role_code=u.role_code AND rp.permission_code='personnel.read'
            CROSS JOIN personnel p WHERE $recipients AND p.promotion_date BETWEEN ? AND ?
            ON DUPLICATE KEY UPDATE event_key=VALUES(event_key)", [day(),day(90)]);
        if (profileSchemaReady()) {
            qu("INSERT INTO notifications (user_id,event_key,message)
                SELECT u.id,CONCAT('profile-certificate:',c.id,':',c.expires_on),'Ada sertifikat personel yang mendekati tanggal akhir.'
                FROM users u JOIN role_permissions rp ON rp.role_code=u.role_code AND rp.permission_code='personnel.certificates.read'
                JOIN role_permissions base ON base.role_code=u.role_code AND base.permission_code='personnel.read'
                CROSS JOIN personnel p JOIN personnel_certificates c ON c.personnel_id=p.id
                WHERE $recipients AND c.archived_at IS NULL AND c.training_id IS NULL
                AND c.expires_on BETWEEN ? AND TIMESTAMPADD(DAY,c.reminder_days,?)
                ON DUPLICATE KEY UPDATE event_key=VALUES(event_key)",[day(),day()]);
        }
        $details = profileSchemaReady() ? ' LEFT JOIN personnel_certificates c ON c.training_id=t.id AND c.archived_at IS NULL' : '';
        $window = profileSchemaReady() ? 'TIMESTAMPADD(DAY,CASE WHEN c.id IS NULL THEN 90 ELSE c.reminder_days END,?)' : '?';
        qu("INSERT INTO notifications (user_id,event_key,message)
            SELECT u.id,CONCAT('certificate:',t.id,':',t.expires_on),'Ada sertifikat Diklat yang mendekati tanggal akhir.'
            FROM users u JOIN role_permissions rp ON rp.role_code=u.role_code AND rp.permission_code='training.read'
            CROSS JOIN personnel p JOIN training_records t ON t.personnel_id=p.id$details
            WHERE $recipients AND t.archived_at IS NULL AND t.expires_on BETWEEN ? AND $window
            ON DUPLICATE KEY UPDATE event_key=VALUES(event_key)", [day(),profileSchemaReady() ? day() : day(90)]);
        qu('DELETE FROM login_attempts WHERE attempted_at<UTC_TIMESTAMP()-INTERVAL 1 DAY');
        qu('DELETE FROM auth_sessions WHERE last_seen_at<UTC_TIMESTAMP()-INTERVAL 7 DAY');
        return $transferred;
    } finally {
        qu("SELECT RELEASE_LOCK(CONCAT('binprof:', SHA1(DATABASE()), ':jobs'))");
    }
}
