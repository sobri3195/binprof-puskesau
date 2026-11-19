<?php
declare(strict_types=1);

require_once __DIR__ . '/metrics.php';

function executiveReviewedRosters(array $ids): array
{
    $in = executiveIn($ids);
    return qu("WITH ranked AS (
        SELECT r.*,IF(rp.role_code IS NOT NULL AND (u.role_code='super_admin' OR us.user_id IS NOT NULL
          OR fs.user_id IS NOT NULL),u.name,NULL) AS pic,ROW_NUMBER() OVER(PARTITION BY r.facility_id ORDER BY r.roster_on DESC,r.id DESC) AS position
        FROM executive_roster_reviews r LEFT JOIN users u ON u.id=r.pic_id AND u.active=1
        JOIN facilities f ON f.id=r.facility_id
        LEFT JOIN role_permissions rp ON rp.role_code=u.role_code AND rp.permission_code='executive.work'
        LEFT JOIN user_unit_scopes us ON us.user_id=u.id AND us.unit_id=f.unit_id
        LEFT JOIN user_facility_scopes fs ON fs.user_id=u.id AND fs.facility_id=f.id
        WHERE r.facility_id IN ($in) AND r.verified_at IS NOT NULL
    ) SELECT facility_id,ranked.* FROM ranked WHERE position=1",$ids)->fetchAll(PDO::FETCH_UNIQUE);
}

function executiveStaffing(array $targets,array $staff,?array $rule,bool $fresh,array $filters): array
{
    if ($filters['specialty_id']) {
        return [executiveAssessment('gray','Target spesialisasi belum tersedia.'),[],null];
    }
    $measured = $targets && $rule && $fresh;
    $population = $filters['mode']==='projection' ? $staff['future_staff'] : $staff['by_profession'];
    $details = [];
    $gap = 0;
    foreach ($targets as $target) {
        $name = $target['name'];
        $actual = $measured ? ($population[$target['profession_id']] ?? 0) : null;
        $missing = $actual===null ? null : max(0,(int) $target['target_count']-$actual);
        $gap += $missing ?? 0;
        $details[] = ['profession'=>$name,'actual'=>$actual,'target'=>(int) $target['target_count'],'gap'=>$missing,
            'source'=>$target['source_reference'],'version'=>$target['version_label'],'date'=>$target['verified_at']];
    }
    if (!$measured) {
        return [executiveAssessment('gray','Penilaian memerlukan target dan aturan terverifikasi serta roster mutakhir.',
            null,$rule['critical_gap'] ?? null,$rule['verified_at'] ?? null,$rule['source_reference'] ?? null,
            $rule['version_label'] ?? null),$details,null];
    }
    $level = $gap >= (int) $rule['critical_gap'] ? 'red' : ($gap > 0 ? 'yellow' : 'green');
    return [executiveAssessment($level,'Jumlah kekurangan pada profesi dengan target terverifikasi.',
        $gap,(int) $rule['critical_gap'],$rule['verified_at'],$rule['source_reference'],$rule['version_label']),$details,$gap];
}

function executiveLiveRows(array $user,array $facilities,array $filters): array
{
    $today = day();
    $periodEnd = $filters['mode']==='projection' ? retirementMonths($today,$filters['months']) : $today;
    $output = [];
    foreach (array_chunk($facilities,250) as $group) {
        $ids = array_column($group,'id');
        $rules = executiveRules($ids,$today);
        $targets = executiveTargets($ids,$periodEnd,$filters['profession_id']);
        $reviews = executiveReviewedRosters($ids);
        $staff = executiveRoster($ids,$filters);
        $certs = executiveCertificates($ids,$filters,$rules,$today);
        $retirement = retirementMetrics($user,$ids,$filters,$filters['months'],true);
        [$training,$events,$directives] = executiveAncillary($ids,$filters + ['user_id'=>$user['id']]);
        foreach ($group as $facility) {
            $id = $facility['id'];
            $row = executiveFacilityMetrics($facility,$staff[$id],$rules[$id] ?? null,$targets[$id] ?? [],
                $reviews[$id] ?? null,$certs[$id] ?? null,$retirement[$id] ?? null,$training[$id] ?? null,
                $events[$id] ?? [],$directives[$id] ?? [],$filters,$today);
            $output[] = $row;
        }
    }
    return $output;
}

function executiveFacilityMetrics(array $facility,array $staff,?array $rule,array $targets,?array $review,?array $cert,
    ?array $retirement,?array $training,array $events,array $directives,array $filters,string $today): array
{
    $digestValid = $review && hash_equals($review['roster_digest'],$staff['digest']);
    $age = $review ? (int) (new DateTimeImmutable($review['roster_on']))->diff(new DateTimeImmutable($today))->days : null;
    $fresh = $rule && $digestValid && $age <= (int) $rule['freshness_days'];
    $quality = executiveAssessment($fresh ? 'green' : 'gray',
        $fresh ? 'Roster terverifikasi dan masih dalam ambang umur.' :
            ($review && !$digestValid ? 'Roster berubah setelah verifikasi.' : 'Pembaruan roster mutakhir belum terverifikasi.'),
        $age,$rule ? (int) $rule['freshness_days'] : null,$review['roster_on'] ?? null,
        $review['source_reference'] ?? null,$rule['version_label'] ?? null);
    [$staffing,$targetDetails,$gap] = executiveStaffing($targets,$staff,$rule,$fresh,$filters);
    $projected = $filters['mode']==='projection';
    $total = null;
    if ($staff['matches'] || $digestValid) {
        $total = array_intersect_key($projected ? $staff['projected'] : $staff,array_fill_keys(['active','doctors','nurses'],true));
    }
    $official = $retirement['scheduled'] ?? null;
    $scheduled12 = $retirement['timeline'][2]['scheduled'] ?? null;
    $planned12 = $retirement['timeline'][2]['planned'] ?? null;
    $replacement = false;
    $progress = array_fill_keys(['issued','accepted','working','review','done'],0);
    $overdue = [];
    foreach ($directives as $directive) {
        ++$progress[$directive['state']];
        $replacement = $replacement || ($directive['kind']==='retirement' && $directive['documented']);
        if ($directive['due_on']<$today && $directive['state']!=='done') {
            $overdue[] = $directive;
        }
    }
    $verifiedEvents = array_values(array_filter($events,static fn(array $event): bool => $event['verified_at']!==null));
    usort($verifiedEvents,static fn(array $a,array $b): int =>
        array_search($b['severity'],array_keys(MAP_SEVERITIES),true) <=> array_search($a['severity'],array_keys(MAP_SEVERITIES),true));
    $event = $verifiedEvents[0] ?? null;
    $certificate = executiveAssessment(
        !$cert ? 'gray' : ((int) $cert['attention']>0 ? 'yellow' : 'green'),
        !$cert ? 'Sertifikat wajib terverifikasi belum tercatat.' : 'Sertifikat pada kategori wajib yang habis atau mendekati akhir berlaku.',
        $cert ? (int) $cert['attention'] : null,$rule && $rule['certificate_category_id'] ? (int) $rule['certificate_window'] . ' hari' : null,
        $cert['earliest'] ?? $cert['updated_at'] ?? null,$rule['source_reference'] ?? null,$rule['version_label'] ?? null);
    $incident = executiveAssessment(
        !$event ? ($events ? 'gray' : 'green') : match($event['severity']) {'critical'=>'red','warning'=>'yellow',default=>'green'},
        !$event ? ($events ? 'Kejadian aktif masih memerlukan verifikasi.' : 'Tidak ada kejadian aktif tercatat.') : 'Kejadian aktif telah diverifikasi.',
        count($verifiedEvents),'Severity kejadian terverifikasi',$event['verified_at'] ?? null,
        $event['source_reference'] ?? 'Register kejadian peta','kejadian-v1');
    $retirementAssessment = executiveAssessment($official === null ? 'gray' : ($official && !$replacement ? 'yellow' : 'green'),
        $replacement ? 'Referensi rencana pengganti tercatat.' : 'Rencana pengganti belum tercatat.',
        $official,'Keputusan resmi dalam periode',$retirement['updated_at'] ?? null,'Personel, Petikan dan workflow pensiun','006/v1');
    $directiveAssessment = executiveAssessment($overdue ? 'yellow' : 'green','Arahan aktif dengan tenggat terlewati.',
        count($overdue),'Tenggat arahan',$overdue[0]['due_on'] ?? null,'Register arahan','arahan-v1');
    if (array_filter($overdue,static fn(array $d): bool => $d['severity']==='critical')) {
        $directiveAssessment['level'] = 'red';
    }
    $assess = ['staffing'=>$staffing,'certificate'=>$certificate,'retirement'=>$retirementAssessment,'event'=>$incident,
        'quality'=>$quality,'directive'=>$directiveAssessment,
        'staff'=>executiveAssessment($fresh ? 'green' : 'gray','Distribusi personel tercatat menurut profesi.',
            $total['active'] ?? null,'Umur roster dalam ambang',$review['roster_on'] ?? null,$review['source_reference'] ?? 'Roster personel',
            $rule['version_label'] ?? null)];
    $levels = array_count_values(array_column($assess,'level'));
    $overall = isset($levels['red']) ? 'red' : (isset($levels['yellow']) ? 'yellow' : (isset($levels['gray']) ? 'gray' : 'green'));
    $pic = $review['pic'] ?? null;
    $issues = [];
    $actions = ['staffing'=>'Tinjau penempatan dan kebutuhan terverifikasi.',
        'certificate'=>'Periksa masa berlaku dan rencana perpanjangan.',
        'retirement'=>'Catat rencana pengganti pada arahan pensiun.',
        'event'=>'Koordinasikan penanganan gangguan dengan PIC.',
        'quality'=>'Perbarui roster dan ajukan verifikasi sumber.'];
    foreach (['staffing','certificate','retirement','event','quality'] as $kind) {
        $entry = $assess[$kind];
        if (!in_array($entry['level'],['red','yellow'],true) && !($kind==='quality' && !$fresh)) {
            continue;
        }
        $entry['level'] = $kind==='quality' ? 'yellow' : $entry['level'];
        $issues[] = ['kind'=>$kind,'level'=>$entry['level'],'reason'=>$entry['reason'],'action'=>$actions[$kind],'pic'=>$pic,
            'date'=>$entry['date'],'due'=>$kind==='certificate' ? $cert['earliest'] : null,
            'state'=>$kind==='event' && $event['acknowledged_at'] ? 'Diakui' : 'Perlu tindak lanjut',
            'read'=>$kind==='event' ? $event['read_at'] : null];
    }
    foreach ($overdue as $item) {
        $issues[] = ['kind'=>'directive','level'=>$item['severity']==='critical' ? 'red' : 'yellow',
            'reason'=>'Arahan aktif melewati tenggat.','action'=>'Konfirmasi progres dan perbarui penugasan.','pic'=>$item['pic'],'state'=>EXEC_STATES[$item['state']],
            'due'=>$item['due_on'],'date'=>$item['updated_at'],'read'=>$item['read_at']];
    }
    $eventList = array_map(static fn(array $e): array => array_intersect_key($e,array_fill_keys(
        ['id','category','severity','created_at','verified_at','acknowledged_at','read_at'],true)),$events);
    $latest = array_filter([$facility['updated_at'],$review['verified_at'] ?? null,$cert['updated_at'] ?? null,
        $retirement['updated_at'] ?? null,$event['verified_at'] ?? null,...array_column($directives,'updated_at')]);
    return $facility + ['totals'=>$total,'gap'=>$gap,'target_details'=>$targetDetails,'distribution'=>$projected ? $staff['projected_distribution'] : $staff['distribution'],
        'training'=>$training,'near'=>$planned12 === null ? null : $planned12+$scheduled12,'retirement'=>$retirement,
        'assessments'=>$assess,'condition'=>$overall,'pic'=>$pic,'issues'=>$issues,'progress'=>$progress,'events'=>$eventList,
        'source_at'=>$latest ? max($latest) : null,'replacement'=>$replacement];
}

function executiveHistoricRows(array $user,array $filters): array
{
    if (!$filters['date'] || $filters['date']>day() || $filters['profession_id'] || $filters['specialty_id']) {
        return [];
    }
    [$scope,$args] = scope($user,'f.unit_id','f.id');
    $rows = qu("SELECT s.snapshot_json,s.captured_at FROM facility_metric_snapshots s JOIN facilities f ON f.id=s.facility_id
        WHERE s.snapshot_on=? AND $scope",[$filters['date'],...$args])->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $entry = json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);
        foreach (['facility_id'=>'id','command_id'=>'command_id','unit_id'=>'unit_id','type_id'=>'type_id'] as $key=>$field) {
            if ($filters[$key] && (int) $entry[$field]!==$filters[$key]) {
                continue 2;
            }
        }
        if ($filters['q']!=='' && !mb_stristr($entry['name'],$filters['q'])) {
            continue;
        }
        $entry['captured_at'] = $row['captured_at'];
        $out[] = $entry;
    }
    usort($out,static fn(array $a,array $b): int => strcasecmp($a['name'],$b['name']));
    return $out;
}

function executiveModel(array $user,array $filters): array
{
    $historical = $filters['mode']==='history';
    $rows = $historical ? executiveHistoricRows($user,$filters)
        : executiveLiveRows($user,executiveFacilities($user,$filters),$filters);
    $missingSnapshot = $historical && !$rows;
    $metric = $filters['metric'];
    $rows = array_values(array_filter($rows,static fn(array $row): bool => match($metric) {
        'active','doctors','nurses'=>($row['totals'][$metric] ?? 0)>0,
        'shortage'=>($row['gap'] ?? 0)>0,
        'near'=>($row['near'] ?? 0)>0,
        default=>true,
    }));
    $kpi = array_fill_keys(array_keys(EXEC_KPI),null);
    $covered = array_fill_keys(array_keys(EXEC_KPI),0);
    $issues = [];
    $progress = array_fill_keys(['issued','accepted','working','review','done'],0);
    $timeline = [];
    $latest = null;
    foreach ($rows as $row) {
        foreach (['active','doctors','nurses','shortage','near'] as $key) {
            $value = match($key) {
                'shortage'=>$row['gap'] === null ? null : (int) ($row['gap']>0),
                'near'=>$row['near'],default=>$row['totals'][$key] ?? null,
            };
            if ($value!==null) {
                $kpi[$key] = ($kpi[$key] ?? 0)+$value;
                ++$covered[$key];
            }
        }
        foreach ($row['progress'] as $key=>$value) {
            $progress[$key] += $value;
        }
        foreach ($row['issues'] as $issue) {
            $issues[] = $issue + ['facility'=>$row['name'],'facility_id'=>$row['id']];
        }
        foreach ($row['retirement']['timeline'] ?? [] as $period) {
            $month = $period['months'];
            $timeline[$month] ??= ['months'=>$month,'through'=>$period['through'],'planned'=>0,'scheduled'=>0,'remaining'=>0,'covered'=>0];
            foreach (['planned','scheduled','remaining'] as $key) {
                $timeline[$month][$key] += $period[$key];
            }
            ++$timeline[$month]['covered'];
        }
        if ($row['source_at']!==null && ($latest===null || $row['source_at']>$latest)) {
            $latest = $row['source_at'];
        }
    }
    $covered['facilities'] = count($rows);
    $kpi['facilities'] = $missingSnapshot ? null : count($rows);
    if ($missingSnapshot) {
        $progress = array_fill_keys(array_keys($progress),null);
    }
    $order = array_flip(['event','staffing','directive','certificate','retirement','quality']);
    usort($issues,static fn(array $a,array $b): int =>
        [($a['level']==='red' ? 0 : 1),$order[$a['kind']],$a['due'] ?? '9999-12-31',$a['facility_id']] <=>
        [($b['level']==='red' ? 0 : 1),$order[$b['kind']],$b['due'] ?? '9999-12-31',$b['facility_id']]);
    $reference = $historical ? $filters['date'] : day();
    return ['reference'=>$reference,'filters'=>$filters,'labels'=>executiveCardLabels($filters),'kpi'=>$kpi,'covered'=>$covered,
        'scope'=>count(array_unique(array_column($rows,'unit_id'))) . ' satuan dalam hasil',
        'source_at'=>$latest,'rows'=>$rows,'priorities'=>array_slice($issues,0,5),'timeline'=>array_values($timeline),
        'progress'=>$progress,'status'=>$missingSnapshot ?
            ($filters['profession_id'] || $filters['specialty_id'] ? 'Snapshot historis tersedia untuk agregat seluruh profesi dan spesialisasi.'
                : 'Snapshot belum tersedia untuk tanggal dan cakupan ini.') : null];
}
