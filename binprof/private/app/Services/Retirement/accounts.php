<?php
declare(strict_types=1);

require_once __DIR__ . '/workflow.php';
require_once PROJECT . '/private/app/Policies/clinical.php';
require_once PROJECT . '/private/app/Services/WhatsApp/link.php';

function retirementExistingAccount(string $personnel,bool $lock = false): ?array
{
    $row = qu('SELECT l.*,u.name,u.email,u.role_code,u.active FROM personnel_account_links l JOIN users u ON u.id=l.user_id
        WHERE l.personnel_id=?' . ($lock ? ' FOR UPDATE' : ''),[$personnel])->fetch();
    return $row ?: null;
}

function retirementLinkAccount(array $actor,string $personnel): void
{
    tx(static function () use ($actor,$personnel): void {
        qu('SELECT id FROM application_state WHERE id=1 FOR UPDATE');
        retirementCheckPerson(person($actor,$personnel,true));
        $target = qu('SELECT id FROM users WHERE email=? AND active=1 FOR UPDATE',
            [email(field($_POST,'email',254,true))])->fetchColumn();
        if (!$target || $actor['id']===(int) $target) {
            fail(422,'Pilih akun aktif selain akun pemeriksa.');
        }
        if (retirementExistingAccount($personnel) || qu('SELECT 1 FROM personnel_account_links WHERE user_id=?',[(int) $target])->fetchColumn()) {
            fail(409,'Akun atau personel telah terikat pada relasi terverifikasi.');
        }
        $reference = field($_POST,'reference',250,true);
        qu('INSERT INTO personnel_account_links (user_id,personnel_id,verified_by,verification_reference) VALUES (?,?,?,?)',
            [(int) $target,$personnel,$actor['id'],$reference]);
        audit($actor['id'],'retirement_account_link','personnel',$personnel);
    });
}

function retirementDisableAccount(array $target,?int $actor): void
{
    $id = (int) $target['user_id'];
    if ($target['active'] && $target['role_code']==='super_admin'
        && (int) qu("SELECT COUNT(*) FROM users WHERE active=1 AND role_code='super_admin'")->fetchColumn() <= 1) {
        fail(409,'Sediakan Super Admin aktif lain sebelum mencabut akun dinas terakhir.');
    }
    qu('UPDATE users SET active=0,session_version=session_version+1 WHERE id=?',[$id]);
    qu('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL',[$id]);
    qu('DELETE FROM user_unit_scopes WHERE user_id=?',[$id]);
    qu('DELETE FROM user_facility_scopes WHERE user_id=?',[$id]);
    qu('DELETE FROM personnel_case_permissions WHERE user_id=?',[$id]);
    // Clinical identity suspension records its own audit and needs a valid actor.
    suspendClinicalIdentity($actor ?? (int) $target['verified_by'],$id);
    if (waMigrated()) {
        $link = waLinkForUser($id);
        if ($link) {
            waCancelLink($link['id']);
        }
        qu('UPDATE whatsapp_link_challenges SET consumed_at=UTC_TIMESTAMP(),claim_ciphertext=NULL
            WHERE user_id=? AND consumed_at IS NULL',[$id]);
    }
    audit($actor,'retirement_access_revoked','users',(string) $id);
}

function retirementGrantAssignment(array $actor,string $personnel): void
{
    tx(static function () use ($actor,$personnel): void {
        qu('SELECT id FROM application_state WHERE id=1 FOR UPDATE');
        $staff = person($actor,$personnel,true);
        $row = retirementRow($personnel,null,true);
        $target = retirementExistingAccount($personnel,true);
        if (!$staff['retired_on'] || !$row || $row['state']!=='effective' || !$target) {
            fail(409,'Penugasan memerlukan pensiun efektif dan akun tertaut.');
        }
        $unit = number($_POST,'unit_id');
        $facility = number($_POST,'facility_id');
        if (($unit===null)===($facility===null)) {
            fail(422,'Pilih satu satuan atau satu fasilitas.');
        }
        if ($facility) {
            $owner = (int) qu('SELECT unit_id FROM facilities WHERE id=? AND active=1',[$facility])->fetchColumn();
            if (!$owner) {
                fail(422,'Fasilitas aktif tidak tersedia.');
            }
            verifyUnitFacility($owner,$facility,$actor);
        } else {
            verifyUnitFacility($unit,null,$actor);
        }
        $until = calendar($_POST,'expires_on');
        if (!$until || $until<day()) {
            fail(422,'Penugasan memerlukan tanggal akhir yang masih berlaku.');
        }
        $reference = field($_POST,'reference',250,true);
        $id = (int) $target['user_id'];
        retirementDisableAccount($target,$actor['id']);
        qu('UPDATE retirement_assignments SET active_slot=NULL,revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND active_slot=1',[$id]);
        qu('INSERT INTO retirement_assignments (id,retirement_id,user_id,unit_id,facility_id,authorization_reference,expires_on,granted_by)
            VALUES (?,?,?,?,?,?,?,?)',[bin2hex(random_bytes(16)),$row['id'],$id,$unit,$facility,$reference,$until,$actor['id']]);
        if ($unit) {
            qu('INSERT INTO user_unit_scopes (user_id,unit_id) VALUES (?,?)',[$id,$unit]);
        } else {
            qu('INSERT INTO user_facility_scopes (user_id,facility_id) VALUES (?,?)',[$id,$facility]);
        }
        qu("UPDATE users SET role_code='viewer',active=1 WHERE id=?",[$id]);
        audit($actor['id'],'retirement_assignment','users',(string) $id,$unit ?? $owner);
    });
}

function retirementRevokeAssignment(array $actor,string $personnel): void
{
    tx(static function () use ($actor,$personnel): void {
        qu('SELECT id FROM application_state WHERE id=1 FOR UPDATE');
        $staff = person($actor,$personnel,true);
        $target = retirementExistingAccount($personnel,true);
        if (!$staff['retired_on'] || !$target) {
            fail(409,'Penugasan purnawirawan tidak tersedia.');
        }
        $deleted = qu('UPDATE retirement_assignments SET active_slot=NULL,revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND active_slot=1',
            [(int) $target['user_id']])->rowCount();
        if ($deleted) {
            retirementDisableAccount($target,$actor['id']);
        }
    });
}

function retirementExpireAssignments(): void
{
    foreach (qu('SELECT a.id,l.personnel_id FROM retirement_assignments a JOIN personnel_account_links l ON l.user_id=a.user_id
        WHERE a.active_slot=1 AND a.expires_on<? ORDER BY a.expires_on,a.id LIMIT 100',[day()])->fetchAll() as $row) {
        tx(static function () use ($row): void {
            qu('SELECT id FROM application_state WHERE id=1 FOR UPDATE');
            $target = retirementExistingAccount($row['personnel_id'],true);
            $updated = qu('UPDATE retirement_assignments SET active_slot=NULL,revoked_at=UTC_TIMESTAMP()
                WHERE id=? AND active_slot=1 AND expires_on<?',[$row['id'],day()])->rowCount();
            if ($updated && $target) {
                retirementDisableAccount($target,null);
            }
        });
    }
}
