<?php
declare(strict_types=1);

require_once __DIR__ . '/policy.php';

const RETIREMENT_MAP_PERIODS = [3=>'3 bulan',6=>'6 bulan',12=>'12 bulan',24=>'24 bulan'];

function retirementMetricsAllowed(array $user): bool
{
    return can($user,'retirement.summary') && retirementReady();
}

function retirementMapMonths(array $input): int
{
    if (($input['retirement_months'] ?? '') === '') {
        return 12;
    }
    return (int) choice($input,'retirement_months',array_map('strval',array_keys(RETIREMENT_MAP_PERIODS)),'12');
}

function retirementMetrics(array $user,?array $facilities,array $filters,int $months,bool $timeline=false): array
{
    if ($facilities === []) {
        return [];
    }
    [$scoped,$args] = scope($user,'p.unit_id','p.facility_id');
    $conditions = [$scoped,'p.archived_at IS NULL'];
    if ($facilities !== null) {
        $conditions[] = 'p.facility_id IN (' . implode(',',array_fill(0,count($facilities),'?')) . ')';
        array_push($args,...$facilities);
    }
    if (($profession = $filters['profession_id'] ?? null) !== null) {
        $conditions[] = 'p.profession_id=?';
        $args[] = $profession;
    }
    if (($specialty = $filters['specialty_id'] ?? null) !== null) {
        $conditions[] = 'EXISTS (SELECT 1 FROM personnel_specialties ps WHERE ps.personnel_id=p.id AND ps.specialty_id=?)';
        $args[] = $specialty;
    }
    $today = day();
    $periods = $timeline ? array_keys(RETIREMENT_MAP_PERIODS) : [$months];
    $counts = [];
    $dates = [];
    foreach ($periods as $period) {
        $counts[] = "SUM(CASE WHEN planned_on BETWEEN ? AND ? THEN 1 ELSE 0 END) AS p$period";
        $counts[] = "SUM(CASE WHEN scheduled_on<=? THEN 1 ELSE 0 END) AS s$period";
        $end = retirementMonths($today,$period);
        array_push($dates,$today,$end,$end);
    }
    $group = $facilities === null ? '0 AS facility_id' : 'facility_id';
    $partition = $facilities === null ? '' : ' GROUP BY facility_id';
    $rows = qu("WITH roster AS (
        SELECT p.facility_id,p.active,r.state,r.effective_on,r.updated_at,r.last_apply_error,
            IF(p.active=1 AND r.state IN ('draft','submitted','verified','returned'),r.planned_on,NULL) AS planned_on,
            IF(p.active=1 AND r.state='approved' AND d.id IS NOT NULL,r.effective_on,NULL) AS scheduled_on
        FROM personnel p LEFT JOIN personnel_retirements r ON r.personnel_id=p.id AND r.active_slot=1
        LEFT JOIN personnel_decree_extracts d ON d.id=r.decree_id AND d.personnel_id=p.id
          AND d.row_version=r.decree_version AND d.effective_on=r.effective_on AND d.archived_at IS NULL
          AND d.document_status='current' AND d.verification_status='verified'
        WHERE " . implode(' AND ',$conditions) . "
    )
    SELECT $group,SUM(active) AS active,MAX(updated_at) AS updated_at,
        SUM(CASE WHEN state IN ('submitted','verified','returned') OR (state='approved'
            AND (effective_on<=? OR scheduled_on IS NULL OR last_apply_error IS NOT NULL)) THEN 1 ELSE 0 END) AS followup,
        " . implode(',',$counts) . " FROM roster$partition",[...$args,$today,...$dates])->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $active = $row['active'] === null ? null : (int) $row['active'];
        $report = [
            'active'=>$active,'updated_at'=>$row['updated_at'],'as_of'=>gmdate('Y-m-d H:i:s'),
            'followup'=>$row['followup'] === null ? null : (int) $row['followup'],'timeline'=>[],
        ];
        foreach ($periods as $period) {
            $scheduled = $row["s$period"] === null ? null : (int) $row["s$period"];
            $snapshot = [
                'months'=>$period,'through'=>retirementMonths($today,$period),
                'planned'=>$row["p$period"] === null ? null : (int) $row["p$period"],
                'scheduled'=>$scheduled,'remaining'=>$active === null ? null : $active-$scheduled,'gap'=>null,
            ];
            if ($timeline) {
                $report['timeline'][] = $snapshot;
            }
            if ($period === $months) {
                $report += $snapshot;
            }
        }
        $result[(int) $row['facility_id']] = $report;
    }
    return $result;
}

function retirementMapRows(array $user,array $rows,array $filters): array
{
    if (!retirementMetricsAllowed($user)) {
        return $rows;
    }
    $data = retirementMetrics($user,array_column($rows,'id'),$filters,$filters['retirement_months']);
    foreach ($rows as &$row) {
        $row['retirement'] = $data[(int) $row['id']] ?? null;
    }
    unset($row);
    return $rows;
}
