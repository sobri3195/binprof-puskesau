<?php
declare(strict_types=1);

require_once __DIR__ . '/model.php';

function executiveConfigAction(array $user,int $id,string $action): void
{
    $verify = str_starts_with($action,'verify_');
    executiveNeed($user,$verify ? 'verify' : 'configure');
    saveUnique(static function () use ($user,$id,$action,$verify): void {
        tx(static function () use ($user,$id,$action,$verify): void {
            $facility = executiveFacility($user,$id,true);
            if ($verify) {
                $record = executiveVerifySource($user,$facility,$action);
            } elseif ($action==='roster') {
                $date = calendar($_POST,'roster_on');
                $pic = number($_POST,'pic') ?? fail(422,'PIC diperlukan.');
                if (!$date || $date>day() || !executiveOperator($pic,$facility)) {
                    fail(422,'Tanggal roster dan PIC dalam cakupan diperlukan.');
                }
                $roster = executiveRoster([$id],[])[$id];
                qu('INSERT INTO executive_roster_reviews (facility_id,roster_on,roster_digest,source_reference,submitted_by,pic_id)
                    VALUES (?,?,?,?,?,?)',[$id,$date,$roster['digest'],field($_POST,'source',250,true),$user['id'],$pic]);
                $record = db()->lastInsertId();
            } else {
                $record = executiveSaveStandard($user,$id,$action);
            }
            audit($user['id'],'executive_' . $action,'executive_sources',(string) $record,(int) $facility['unit_id']);
        });
    });
}

function executiveSaveStandard(array $user,int $facility,string $action): string
{
    $from = calendar($_POST,'valid_from');
    $until = calendar($_POST,'valid_until');
    if (!$from || ($until && $until<$from)) {
        fail(422,'Periksa masa berlaku sumber.');
    }
    $common = [$facility,field($_POST,'label',40,true),field($_POST,'source',250,true),$from,$until,$user['id']];
    if ($action==='target') {
        $profession = verifyMaster('professions',number($_POST,'profession_id'));
        $count = field($_POST,'target_count',7,true);
        if (!ctype_digit($count)) {
            fail(422,'Target harus berupa bilangan bulat nol atau lebih.');
        }
        qu('INSERT INTO facility_staffing_targets (facility_id,version_label,source_reference,valid_from,valid_until,created_by,profession_id,target_count)
            VALUES (?,?,?,?,?,?,?,?)',[...$common,$profession,(int) $count]);
    } else {
        $age = number($_POST,'freshness_days');
        $gap = number($_POST,'critical_gap');
        $window = number($_POST,'certificate_window');
        $category = number($_POST,'certificate_category_id');
        if (!$age || $age>730 || !$gap || !$window || $window>365 || ($category
            && !qu("SELECT 1 FROM personnel_record_categories WHERE id=? AND kind='certificates' AND active=1",[$category])->fetchColumn())) {
            fail(422,'Periksa ambang mutu, kekurangan kritis, serta kategori dan jendela sertifikat.');
        }
        qu('INSERT INTO facility_assessment_rules (facility_id,version_label,source_reference,valid_from,valid_until,created_by,
            freshness_days,critical_gap,certificate_window,certificate_category_id) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [...$common,$age,$gap,$window,$category]);
    }
    return db()->lastInsertId();
}

function executiveVerifySource(array $user,array $facility,string $action): string
{
    if ($action==='verify_event') {
        $event = key32($_POST,'event');
        $row = qu("SELECT * FROM map_events WHERE id=? AND facility_id=? AND active_slot=1 AND category='operations' FOR UPDATE",
            [$event,$facility['id']])->fetch();
        if (!$row || (int) $row['created_by']===$user['id']) {
            fail(409,'Verifikasi gangguan aktif memerlukan pemeriksa lain.');
        }
        qu('INSERT INTO executive_event_verifications (event_id,source_reference,verified_by) VALUES (?,?,?)',
            [$event,field($_POST,'source',250,true),$user['id']]);
        return $event;
    }
    [$table,$submitted] = match($action) {
        'verify_rule'=>['facility_assessment_rules','created_by'],
        'verify_target'=>['facility_staffing_targets','created_by'],
        'verify_roster'=>['executive_roster_reviews','submitted_by'],
    };
    $id = number($_POST,'record') ?? fail(422,'Catatan diperlukan.');
    $row = qu("SELECT * FROM $table WHERE id=? AND facility_id=? FOR UPDATE",[$id,$facility['id']])->fetch();
    if (!$row || $row['verified_at'] || (int) $row[$submitted]===$user['id']) {
        fail(409,'Sumber memerlukan pemeriksa lain dan belum pernah diverifikasi.');
    }
    if ($action==='verify_roster') {
        $roster = executiveRoster([$facility['id']],[])[$facility['id']];
        if (!hash_equals($row['roster_digest'],$roster['digest']) || !executiveOperator((int) $row['pic_id'],$facility)) {
            fail(409,'Roster atau mandat PIC berubah. Ajukan pemeriksaan baru.');
        }
    }
    qu("UPDATE $table SET verified_by=?,verified_at=UTC_TIMESTAMP() WHERE id=?",[$user['id'],$id]);
    return (string) $id;
}

function executiveCapture(array $user,array $filters): int
{
    return tx(static function () use ($user,$filters): int {
        $now = day();
        $rows = executiveLiveRows($user,executiveFacilities($user,$filters),executiveFilters([]));
        $count = 0;
        foreach ($rows as $row) {
            // Persist only aggregate data. Per-account read state and live action IDs are ephemeral.
            unset($row['events']);
            foreach ($row['issues'] as &$issue) {
                unset($issue['read']);
            }
            unset($issue);
            $count += qu('INSERT INTO facility_metric_snapshots (facility_id,snapshot_on,snapshot_json,captured_by)
                VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE facility_id=facility_metric_snapshots.facility_id',
                [$row['id'],$now,json_encode($row,JSON_THROW_ON_ERROR),$user['id']])->rowCount();
        }
        audit($user['id'],'executive_snapshot','facility_metric_snapshots');
        return $count;
    });
}
