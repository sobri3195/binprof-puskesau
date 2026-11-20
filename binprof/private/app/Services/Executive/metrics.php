<?php
declare(strict_types=1);

require_once __DIR__ . '/policy.php';

/** Return only counts and an internal roster fingerprint, never person records. */
function executiveRoster(array $ids,array $filters): array
{
    if (!$ids) {
        return [];
    }
    $in = executiveIn($ids);
    $specialty = $filters['specialty_id'] ?? null;
    $profession = $filters['profession_id'] ?? null;
    $matches = $specialty ? 'EXISTS (SELECT 1 FROM personnel_specialties z WHERE z.personnel_id=p.id AND z.specialty_id=?)' : '1';
    $staff = qu("SELECT p.id,p.facility_id,p.row_version,p.active,p.profession_id,pr.code,pr.name,$matches AS specialty_match,
        IF(p.active=1 AND d.id IS NOT NULL,r.effective_on,NULL) AS official
        FROM personnel p JOIN professions pr ON pr.id=p.profession_id
        LEFT JOIN personnel_retirements r ON r.personnel_id=p.id AND r.active_slot=1 AND r.state='approved'
        LEFT JOIN personnel_decree_extracts d ON d.id=r.decree_id AND d.personnel_id=p.id AND d.archived_at IS NULL
          AND d.document_status='current' AND d.verification_status='verified'
          AND d.row_version=r.decree_version AND r.effective_on=d.effective_on
        WHERE p.facility_id IN ($in) AND p.archived_at IS NULL ORDER BY p.facility_id,p.id",
        $specialty ? [$specialty,...$ids] : $ids);
    $end = retirementMonths(day(),$filters['months'] ?? 12);
    $out = [];
    foreach ($ids as $id) {
        $out[$id] = ['ctx'=>hash_init('sha256'),'records'=>0,'matches'=>0,'active'=>0,'doctors'=>0,'nurses'=>0,
            'projected'=>['active'=>0,'doctors'=>0,'nurses'=>0],'projected_distribution'=>[],'distribution'=>[],'by_profession'=>[],'future_staff'=>[]];
    }
    while ($p = $staff->fetch()) {
        $entry = &$out[$p['facility_id']];
        hash_update($entry['ctx'],$p['id'] . ':' . $p['row_version'] . ':' . $p['active'] . ':' . $p['profession_id'] . "\n");
        ++$entry['records'];
        if (!$p['specialty_match'] || ($profession !== null && (int) $p['profession_id'] !== $profession)) {
            unset($entry);
            continue;
        }
        ++$entry['matches'];
        $key = match($p['code']) {'doctor'=>'doctors','nurse'=>'nurses',default=>null};
        $id = (int) $p['profession_id'];
        if ($p['active']) {
            ++$entry['active'];
            if ($key) {
                ++$entry[$key];
            }
            $entry['distribution'][$p['name']] = ($entry['distribution'][$p['name']] ?? 0)+1;
            $entry['by_profession'][$id] = ($entry['by_profession'][$id] ?? 0)+1;
            if (!$p['official'] || $p['official']>$end) {
                ++$entry['projected']['active'];
                if ($key) {
                    ++$entry['projected'][$key];
                }
                $entry['projected_distribution'][$p['name']] = ($entry['projected_distribution'][$p['name']] ?? 0)+1;
                $entry['future_staff'][$id] = ($entry['future_staff'][$id] ?? 0)+1;
            }
        }
        unset($entry);
    }
    foreach ($out as &$entry) {
        $entry['digest'] = hash_final($entry['ctx']);
        unset($entry['ctx']);
    }
    unset($entry);
    return $out;
}

function executiveRules(array $ids,string $on): array
{
    $in = executiveIn($ids);
    return qu("WITH ranked AS (
        SELECT r.*,ROW_NUMBER() OVER(PARTITION BY facility_id ORDER BY valid_from DESC,id DESC) AS position
        FROM facility_assessment_rules r WHERE verified_at IS NOT NULL AND valid_from<=?
          AND (valid_until IS NULL OR valid_until>=?) AND facility_id IN ($in)
    ) SELECT facility_id,ranked.* FROM ranked WHERE position=1",[$on,$on,...$ids])->fetchAll(PDO::FETCH_UNIQUE);
}

function executiveTargets(array $ids,string $on,?int $profession): array
{
    $in = executiveIn($ids);
    $where = $profession === null ? '' : ' AND t.profession_id=?';
    $rows = qu("WITH ranked AS (
        SELECT t.*,p.name,ROW_NUMBER() OVER(PARTITION BY facility_id,t.profession_id ORDER BY valid_from DESC,t.id DESC) AS position
        FROM facility_staffing_targets t JOIN professions p ON p.id=t.profession_id
        WHERE t.verified_at IS NOT NULL AND t.valid_from<=? AND (t.valid_until IS NULL OR t.valid_until>=?)
          AND t.facility_id IN ($in)$where
    ) SELECT * FROM ranked WHERE position=1 ORDER BY name",[$on,$on,...$ids,...($profession===null ? [] : [$profession])])->fetchAll();
    $group = [];
    foreach ($rows as $row) {
        $group[$row['facility_id']][] = $row;
    }
    return $group;
}

function executiveAssessment(string $level,string $reason,mixed $actual=null,mixed $target=null,?string $date=null,
    ?string $source=null,?string $version=null): array
{
    return compact('level','reason','actual','target','date','source','version');
}

function executiveCertificates(array $ids,array $filters,array $rules,string $today): array
{
    $in = executiveIn($ids);
    $rules = array_filter($rules,static fn(array $rule): bool => $rule['certificate_category_id']!==null);
    if (!$rules) {
        return [];
    }
    $ruleIds = array_column($rules,'id');
    $ruleIn = executiveIn($ruleIds);
    $population = '';
    $populationArgs = [];
    if ($filters['profession_id']) {
        $population .= ' AND p.profession_id=?';
        $populationArgs[] = $filters['profession_id'];
    }
    if ($filters['specialty_id']) {
        $population .= ' AND EXISTS (SELECT 1 FROM personnel_specialties z WHERE z.personnel_id=p.id AND z.specialty_id=?)';
        $populationArgs[] = $filters['specialty_id'];
    }
    return qu("WITH valid AS (
        SELECT c.personnel_id,c.category_id,COALESCE(t.expires_on,c.expires_on) AS expiry,c.validity_kind,
            GREATEST(c.updated_at,c.verified_at) AS updated_at
        FROM personnel_certificates c LEFT JOIN training_records t ON t.id=c.training_id
        WHERE c.archived_at IS NULL AND c.verification_status='verified'
          AND (c.valid_from IS NULL OR c.valid_from<=?)
          AND (c.training_id IS NULL OR (t.archived_at IS NULL AND t.completed=1 AND c.verified_training_version=t.row_version))
          AND (c.validity_kind='unlimited' OR COALESCE(t.expires_on,c.expires_on) IS NOT NULL)
    ), coverage AS (
        SELECT p.facility_id,p.id,r.certificate_window,MAX(x.expiry) AS expiry,
            MAX(CASE WHEN x.expiry IS NULL AND x.validity_kind='unlimited' THEN 1 ELSE 0 END) AS unlimited,
            MAX(x.updated_at) AS updated_at
        FROM personnel p JOIN facility_assessment_rules r ON r.facility_id=p.facility_id AND r.id IN ($ruleIn)
        LEFT JOIN valid x ON x.personnel_id=p.id AND x.category_id=r.certificate_category_id
        WHERE p.facility_id IN ($in) AND p.active=1 AND p.archived_at IS NULL$population
        GROUP BY p.facility_id,p.id,r.certificate_window
    )
    SELECT facility_id,COUNT(*) AS eligible,MAX(updated_at) AS updated_at,
        SUM(CASE WHEN unlimited=1 OR expiry>=? THEN 1 ELSE 0 END) AS covered,
        SUM(CASE WHEN unlimited=0 AND expiry<=TIMESTAMPADD(DAY,certificate_window,?) THEN 1 ELSE 0 END) AS attention,
        MIN(CASE WHEN unlimited=0 AND expiry<=TIMESTAMPADD(DAY,certificate_window,?) THEN expiry END) AS earliest
    FROM coverage GROUP BY facility_id",
        [$today,...$ruleIds,...$ids,...$populationArgs,$today,$today,$today])->fetchAll(PDO::FETCH_UNIQUE);
}

function executiveAncillary(array $ids,array $filters): array
{
    $in = executiveIn($ids);
    $p = '';
    $args = [];
    if ($filters['profession_id']) {
        $p .= ' AND p.profession_id=?';
        $args[] = $filters['profession_id'];
    }
    if ($filters['specialty_id']) {
        $p .= ' AND EXISTS (SELECT 1 FROM personnel_specialties z WHERE z.personnel_id=p.id AND z.specialty_id=?)';
        $args[] = $filters['specialty_id'];
    }
    $training = qu("SELECT p.facility_id,COUNT(*) AS total,SUM(t.completed=1) AS completed
        FROM personnel p JOIN training_records t ON t.personnel_id=p.id WHERE p.facility_id IN ($in)
          AND p.active=1 AND p.archived_at IS NULL AND t.archived_at IS NULL$p GROUP BY p.facility_id",
        [...$ids,...$args])->fetchAll(PDO::FETCH_UNIQUE);
    $events = qu("SELECT e.id,e.facility_id,e.category,e.severity,e.created_at,e.acknowledged_at,v.verified_at,v.source_reference,
        rd.read_at FROM map_events e LEFT JOIN executive_event_verifications v ON v.event_id=e.id
        LEFT JOIN map_event_reads rd ON rd.event_id=e.id AND rd.user_id=?
        WHERE e.facility_id IN ($in) AND e.active_slot=1 ORDER BY e.created_at DESC",[$filters['user_id'],...$ids])->fetchAll();
    $directives = qu("SELECT d.id,d.facility_id,d.kind,d.state,d.severity,d.due_on,d.updated_at,
        IF(w.active=1 AND (w.role_code='super_admin' OR us.user_id IS NOT NULL OR fs.user_id IS NOT NULL),w.name,NULL) AS pic,
        IF(d.plan_reference IS NULL,0,1) AS documented, rd.read_at
        FROM executive_directives d JOIN users w ON w.id=d.pic_id
        JOIN facilities f ON f.id=d.facility_id
        LEFT JOIN user_unit_scopes us ON us.user_id=w.id AND us.unit_id=f.unit_id
        LEFT JOIN user_facility_scopes fs ON fs.user_id=w.id AND fs.facility_id=f.id
        LEFT JOIN executive_directive_reads rd ON rd.directive_id=d.id AND rd.user_id=?
        WHERE d.facility_id IN ($in) AND d.state NOT IN ('draft','cancelled') ORDER BY d.due_on,d.id",
        [$filters['user_id'],...$ids])->fetchAll();
    return [$training, executiveGrouped($events), executiveGrouped($directives)];
}

function executiveGrouped(array $rows): array
{
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[$row['facility_id']][] = $row;
    }
    return $grouped;
}
