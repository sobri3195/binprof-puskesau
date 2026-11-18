<?php
declare(strict_types=1);

require_once __DIR__ . '/accounts.php';

function retirementApplyOne(?array $actor,string $id,?int $expectedVersion=null): bool
{
    return tx(static function () use ($actor,$id,$expectedVersion): bool {
        qu('SELECT id FROM application_state WHERE id=1 FOR UPDATE');
        $personnel = qu('SELECT personnel_id FROM personnel_retirements WHERE id=?',[$id])->fetchColumn();
        if (!$personnel) {
            fail(404,'Catatan pensiun tidak ditemukan.');
        }
        $staff = $actor ? person($actor,$personnel,true) : qu('SELECT * FROM personnel WHERE id=? FOR UPDATE',[$personnel])->fetch();
        $row = retirementRow($personnel,$id,true);
        if (!$row || $row['state']!=='approved' || $row['effective_on']>day()) {
            return false;
        }
        if ($actor && !retirementScopedActor($actor['id'],$staff,'apply')) {
            fail(403,'Mandat penerapan pensiun sudah berubah.');
        }
        if ($expectedVersion !== null && $expectedVersion !== (int) $row['row_version']) {
            fail(409,'Data pensiun berubah. Muat ulang sebelum menerapkan.');
        }
        retirementCheckPerson($staff);
        if (!retirementScopedActor((int) $row['verified_by'],$staff,'verify')
            || !retirementScopedActor((int) $row['approved_by'],$staff,'approve')) {
            fail(409,'Mandat pemeriksa atau approver berubah. Verifikasi ulang diperlukan.');
        }
        retirementCheckDecreeVersion($row,retirementDecree($personnel,$row['decree_id'],true,true));
        if (!retirementChecklistDone($id,'document') || !retirementChecklistDone($id,'handover')) {
            fail(409,'Selesaikan dokumen dan serah terima wajib sebelum menerapkan.');
        }
        $linked = retirementExistingAccount($personnel,true);
        if ($linked) {
            retirementDisableAccount($linked,$actor['id'] ?? null);
        }
        qu('UPDATE personnel SET active=0,retired_on=?,row_version=row_version+1 WHERE id=?',[$row['effective_on'],$personnel]);
        $labels = qu('SELECT r.name AS rank_name,u.name AS unit_name,f.name AS facility_name FROM personnel p
            JOIN units u ON u.id=p.unit_id LEFT JOIN ranks r ON r.id=p.rank_id LEFT JOIN facilities f ON f.id=p.facility_id
            WHERE p.id=?',[$personnel])->fetch();
        qu("UPDATE personnel_retirements SET state='effective',effective_at=UTC_TIMESTAMP(),last_rank_id=?,
            last_unit_id=?,last_facility_id=?,last_position=?,last_rank_name=?,last_unit_name=?,last_facility_name=?,
            row_version=row_version+1,
            reminder_version=reminder_version+1,updated_at=UTC_TIMESTAMP(),last_apply_error=NULL WHERE id=?",
            [$staff['rank_id'],$staff['unit_id'],$staff['facility_id'],$staff['position_title'],
                $labels['rank_name'],$labels['unit_name'],$labels['facility_name'],$id]);
        retirementInvalidateNotifications($id);
        retirementHistory($id,$actor['id'] ?? null,'apply');
        return true;
    });
}

function retirementReminderCte(): string
{
    return "SELECT r.id,r.pic_id,r.reminder_version,
        CASE WHEN r.state='approved' THEN d.effective_on ELSE r.planned_on END AS due_on, rp.months
        FROM personnel_retirements r JOIN personnel p ON p.id=r.personnel_id
        LEFT JOIN personnel_decree_extracts d ON d.id=r.decree_id AND d.personnel_id=p.id
          AND d.effective_on=r.effective_on AND d.row_version=r.decree_version
          AND d.document_status='current' AND d.verification_status='verified' AND d.archived_at IS NULL
        JOIN users u ON u.id=r.pic_id AND u.active=1
        JOIN role_permissions p1 ON p1.role_code=u.role_code AND p1.permission_code='retirement.read'
        JOIN retirement_reminder_preferences rp ON rp.user_id=u.id AND rp.enabled=1
        WHERE r.active_slot=1 AND r.state NOT IN ('effective','cancelled') AND p.archived_at IS NULL AND p.active=1
        AND (u.role_code='super_admin' OR EXISTS (SELECT 1 FROM user_unit_scopes s WHERE s.user_id=u.id AND s.unit_id=p.unit_id)
          OR EXISTS (SELECT 1 FROM user_facility_scopes s WHERE s.user_id=u.id AND s.facility_id=p.facility_id))";
}

function retirementReminders(): void
{
    $eligible = retirementReminderCte();
    $key = "CONCAT('retirement:',e.id,':',e.reminder_version,':',e.months)";
    $window = '(e.due_on BETWEEN ? AND TIMESTAMPADD(MONTH,e.months,?))';
    tx(static function () use ($eligible,$key,$window): void {
        qu("UPDATE notifications n LEFT JOIN ($eligible) e ON n.user_id=e.pic_id AND n.event_key=$key AND $window
            SET n.cancelled_at=UTC_TIMESTAMP() WHERE n.event_key LIKE 'retirement:%' AND n.cancelled_at IS NULL AND e.id IS NULL",
            [day(),day()]);
        qu("INSERT INTO notifications (user_id,event_key,message)
            SELECT e.pic_id,$key,'Ada jadwal pensiun dalam periode pengingat Anda. Periksa modul Pensiun.'
            FROM ($eligible) e WHERE $window ON DUPLICATE KEY UPDATE
            read_at=IF(cancelled_at IS NULL,read_at,NULL),cancelled_at=NULL",[day(),day()]);
    });
}

function retirementRunJobs(): array
{
    if (!retirementReady()) {
        fail(503,'Migrasi pensiun belum diterapkan.');
    }
    $lock = 'binprof:retirement:' . hash('sha1',(string) qu('SELECT DATABASE()')->fetchColumn());
    if ((int) qu('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1) {
        fail(409,'Proses pensiun sedang berjalan.');
    }
    $applied = $blocked = 0;
    try {
        $ids = qu("SELECT id FROM personnel_retirements WHERE state='approved' AND effective_on<=?
            ORDER BY last_apply_checked_at IS NOT NULL,last_apply_checked_at,effective_on,id LIMIT 100",
            [day()])->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            try {
                $applied += (int) retirementApplyOne(null,$id);
            } catch (HttpError $error) {
                $blocked++;
                qu("UPDATE personnel_retirements SET last_apply_checked_at=UTC_TIMESTAMP(),last_apply_error=?
                    WHERE id=? AND state='approved'",[$error->getMessage(),$id]);
            }
        }
        retirementExpireAssignments();
        retirementReminders();
        return [$applied,$blocked];
    } finally {
        qu('SELECT RELEASE_LOCK(?)',[$lock]);
    }
}
