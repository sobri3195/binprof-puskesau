<?php
declare(strict_types=1);

const RETIREMENT = '/app/pensiun';
const RETIREMENT_TAB = 'retirement';
const RETIREMENT_PERIODS = [1=>'1 bulan',3=>'3 bulan',6=>'6 bulan',12=>'12 bulan',24=>'24 bulan'];
const RETIREMENT_STATES = [
    'draft'=>'Draf','submitted'=>'Diajukan','verified'=>'Diverifikasi','approved'=>'Disetujui / dijadwalkan',
    'effective'=>'Resmi pensiun','returned'=>'Dikembalikan','cancelled'=>'Dibatalkan',
];

function retirementReady(): bool
{
    static $ready;
    return $ready ??= (bool) qu("SELECT 1 FROM schema_migrations WHERE version='006_personnel_retirements'")->fetchColumn();
}

function retirementRequire(array $user,string $operation = 'read'): void
{
    if (!retirementReady() || !can($user,'retirement.' . $operation)) {
        fail(403,'Akses pensiun tidak tersedia.');
    }
}

function retirementCanDecide(array $user,string $action,string $state): bool
{
    return match ($action) {
        'submit'=>can($user,'retirement.write'),
        'verify'=>can($user,'retirement.verify'),
        'approve'=>can($user,'retirement.approve'),
        'return'=>($state!=='approved' && can($user,'retirement.verify'))
            || (in_array($state,['verified','approved'],true) && can($user,'retirement.approve')),
        'cancel'=>in_array($state,['draft','returned'],true) ? can($user,'retirement.write') : can($user,'retirement.approve'),
        default=>false,
    };
}

function retirementMonths(string $date,int $months): string
{
    $original = new DateTimeImmutable($date,new DateTimeZone('UTC'));
    $first = $original->modify('first day of this month')->modify("+$months months");
    return $first->setDate((int) $first->format('Y'),(int) $first->format('n'),
        min((int) $original->format('j'),(int) $first->format('t')))->format('Y-m-d');
}

function retirementAccountHasRetired(int $user,bool $lock = false): bool
{
    return retirementReady() && (bool) qu('SELECT 1 FROM personnel_account_links l JOIN personnel p ON p.id=l.personnel_id
        WHERE l.user_id=? AND p.retired_on IS NOT NULL' . ($lock ? ' FOR UPDATE' : ''),[$user])->fetchColumn();
}

function retirementAccountEligible(int $user,string $role): bool
{
    if (!retirementAccountHasRetired($user)) {
        return true;
    }
    return $role === 'viewer' && (bool) qu('SELECT 1 FROM retirement_assignments WHERE user_id=? AND active_slot=1 AND expires_on>=?',
        [$user,day()])->fetchColumn();
}

function notificationLiveSuffix(): string
{
    return retirementReady() ? ' AND cancelled_at IS NULL' : '';
}

function retirementRow(string $personnel,?string $id = null,bool $lock = false): ?array
{
    $filter = $id ? 'id=?' : 'active_slot=1';
    $args = $id ? [$personnel,$id] : [$personnel];
    $row = qu("SELECT * FROM personnel_retirements WHERE personnel_id=? AND $filter" . ($lock ? ' FOR UPDATE' : ''),$args)->fetch();
    return $row ?: null;
}

function retirementHistory(string $id,?int $actor,string $action,?string $reason=null): void
{
    qu('INSERT INTO personnel_retirement_versions
        (retirement_id,actor_id,row_version,action,state,reason,planned_on,effective_on,decree_version,request_id)
        SELECT id,?,row_version,?,state,?,planned_on,effective_on,decree_version,? FROM personnel_retirements WHERE id=?',
        [$actor,$action,$reason,REQUEST_ID,$id]);
    $unit = qu('SELECT p.unit_id FROM personnel_retirements r JOIN personnel p ON p.id=r.personnel_id WHERE r.id=?',[$id])->fetchColumn();
    audit($actor,'retirement_' . $action,'personnel_retirements',$id,(int) $unit);
    retirementRefreshMap();
}

function retirementVersion(array $row): int
{
    if ((int) $row['row_version'] !== number($_POST,'version')) {
        fail(409,'Data pensiun berubah. Muat ulang sebelum melanjutkan.');
    }
    return (int) $row['row_version']+1;
}

function retirementInvalidateNotifications(string $id): void
{
    qu("UPDATE notifications SET cancelled_at=UTC_TIMESTAMP() WHERE event_key LIKE ? AND cancelled_at IS NULL",
        ["retirement:$id:%"]);
}

function retirementRefreshMap(): void
{
    qu('UPDATE map_revision SET refresh_revision=revision+1,revision=revision+1 WHERE id=1');
}
