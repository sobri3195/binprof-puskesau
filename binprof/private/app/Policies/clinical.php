<?php
declare(strict_types=1);

const PURPOSES = ['rikkes'=>'Rikkes','dikbangum'=>'Dikbangum','referral'=>'Rujukan','follow_up'=>'Perawatan lanjutan'];

function clinicalSchemaReady(): bool
{
    static $ready;
    return $ready ??= (bool) qu("SELECT 1 FROM schema_migrations WHERE version='003_clinical_records'")->fetchColumn();
}

function clinicalWorkplaces(array $user): array
{
    return qu('SELECT f.id,f.name FROM user_clinical_privileges c JOIN facilities f ON f.id=c.facility_id
        JOIN users u ON u.id=c.user_id WHERE c.user_id=? AND c.active=1 AND u.active=1 AND f.active=1 AND (c.can_read=1 OR c.can_approve=1) ORDER BY f.name,f.id',
        [$user['id']])->fetchAll(PDO::FETCH_KEY_PAIR);
}

function clinicalPrivilege(int $user, int $facility): array
{
    $p = qu('SELECT c.* FROM user_clinical_privileges c JOIN facilities f ON f.id=c.facility_id
        JOIN users u ON u.id=c.user_id WHERE c.user_id=? AND c.facility_id=? AND c.active=1 AND f.active=1 AND u.active=1',
        [$user,$facility])->fetch();
    if (!$p) {
        fail(403, 'Kewenangan klinis tidak aktif untuk fasilitas ini.');
    }
    return $p;
}

function clinicalAudit(int $actor, ?int $facility, ?string $patient, string $action, ?string $purpose, string $result = 'success', ?string $reason = null): void
{
    qu('INSERT INTO clinical_access_logs (actor_id,facility_id,patient_id,action,purpose,result,reason_ciphertext,request_id)
        VALUES (?,?,?,?,?,?,?,?)',
        [$actor,$facility,$patient,$action,$purpose,$result,$reason === null ? null : seal($reason, REQUEST_ID . ':' . $action),REQUEST_ID]);
}

function assignedCare(int $user, int $facility, string $patient): bool
{
    return (bool) qu('SELECT 1 FROM patient_care_assignments WHERE patient_id=? AND user_id=? AND facility_id=?
        AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP()', [$patient,$user,$facility])->fetchColumn();
}

function recordFacilities(array $priv, string $patient, string $purpose, string $capability): array
{
    $priv = clinicalPrivilege((int) $priv['user_id'], (int) $priv['facility_id']);
    if (empty($priv['can_' . $capability])) {
        return [];
    }
    $actor = (int) $priv['user_id'];
    $workplace = (int) $priv['facility_id'];
    $sources = assignedCare($actor, $workplace, $patient) ? [$workplace] : [];
    if ($capability !== 'write') {
        $grants = qu('SELECT DISTINCT r.source_facility_id FROM clinical_access_requests r
            JOIN clinical_access_grants g ON g.request_id=r.id JOIN facilities f ON f.id=r.source_facility_id
            WHERE r.patient_id=? AND r.requester_id=? AND r.workplace_id=? AND r.purpose=? AND r.access_scope=?
            AND r.status=\'approved\' AND g.revoked_at IS NULL AND g.expires_at>UTC_TIMESTAMP() AND f.active=1',
            [$patient,$actor,$workplace,$purpose,$capability])->fetchAll(PDO::FETCH_COLUMN);
        $sources = [...$sources, ...array_map('intval', $grants)];
    }
    return $sources;
}

function requireRecordFacilities(array $priv, string $patient, string $purpose, string $capability): array
{
    $facilities = recordFacilities($priv, $patient, $purpose, $capability);
    if (!$facilities) {
        fail(403, 'Hubungan perawatan atau grant yang sesuai diperlukan.');
    }
    return $facilities;
}

/**
 * Administrative identity changes invalidate clinical credentials permanently.
 * A facility supervisor can subsequently reappoint an independently verified account.
 */
function suspendClinicalIdentity(int $actor, int $target): void
{
    if (!clinicalSchemaReady()) {
        return;
    }
    $facilities = qu('SELECT facility_id FROM user_clinical_privileges WHERE user_id=? AND active=1 FOR UPDATE',
        [$target])->fetchAll(PDO::FETCH_COLUMN);
    if (!$facilities) {
        return;
    }
    qu('UPDATE user_clinical_privileges SET active=0 WHERE user_id=?', [$target]);
    qu('UPDATE patient_care_assignments SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL',[$target]);
    qu('UPDATE clinical_access_grants g JOIN clinical_access_requests r ON r.id=g.request_id
        SET g.revoked_at=UTC_TIMESTAMP() WHERE r.requester_id=? AND g.revoked_at IS NULL',[$target]);
    qu("UPDATE clinical_access_requests SET status='rejected',decided_at=UTC_TIMESTAMP()
        WHERE requester_id=? AND status='pending'", [$target]);
    foreach ($facilities as $facility) {
        clinicalAudit($actor,(int) $facility,null,'identity_suspend',null);
    }
}
