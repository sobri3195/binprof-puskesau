<?php
declare(strict_types=1);

require_once __DIR__ . '/catalog.php';

function profileObjectScope(array $user,string $tab): array
{
    if ($tab === 'cases') {
        return scope($user,'r.handling_unit_id');
    }
    if (profileEducation($tab)) {
        return ['r.education_type=?',[$tab]];
    }
    if ($tab === 'certificates') {
        return [can($user,'training.read') ? '(r.training_id IS NULL OR t.archived_at IS NULL)' : 'r.training_id IS NULL',[]];
    }
    return ['1=1',[]];
}

function profileTrainingJoin(string $tab): string
{
    return $tab === 'certificates' ? ' LEFT JOIN training_records t ON t.id=r.training_id' : '';
}

function profileRow(array $user,string $personnel,string $tab,string $record,bool $lock = false): array
{
    $table = PROFILE_TABLES[$tab];
    [$where,$args] = profileObjectScope($user,$tab);
    $join = profileTrainingJoin($tab);
    $training = $tab === 'certificates' ? ',t.expires_on AS training_expiry,t.row_version AS training_version' : '';
    $row = qu("SELECT r.*$training FROM $table r$join WHERE r.personnel_id=? AND r.id=? AND $where" . ($lock ? ' FOR UPDATE' : ''),
        [$personnel,$record,...$args])->fetch();
    if (!$row) {
        fail(404,'Catatan tidak ditemukan.');
    }
    if ($tab === 'certificates' && $row['training_id']
        && (int) $row['verified_training_version'] !== (int) $row['training_version']) {
        $row['verification_status'] = 'pending';
        $row['verified_by'] = $row['verified_at'] = $row['verification_notes'] = null;
    }
    return $row;
}

function profileTrainingRow(array $user,string $personnel,string $id,bool $unlinked = true,bool $lock = false): array
{
    if (!can($user,'training.read')) {
        fail(404,'Catatan sertifikat tidak ditemukan.');
    }
    $filter = $unlinked ? ' AND NOT EXISTS (SELECT 1 FROM personnel_certificates r WHERE r.training_id=t.id)' : '';
    $row = qu("SELECT t.*,tt.name AS title FROM training_records t JOIN training_types tt ON tt.id=t.type_id
        WHERE t.id=? AND t.personnel_id=? AND t.archived_at IS NULL$filter" . ($lock ? ' FOR UPDATE' : ''),
        [$id,$personnel])->fetch();
    if (!$row) {
        fail(404,'Catatan sertifikat tidak ditemukan.');
    }
    return $row;
}

function profileDataset(array $user,string $personnel,string $tab): array
{
    $table = PROFILE_TABLES[$tab];
    [$scope,$args] = profileObjectScope($user,$tab);
    [$title,$reference,$date,$year,$terms,$state] = match ($tab) {
        'cases'=>['r.title','r.registration_no','r.reported_on','YEAR(r.reported_on)',"CONCAT(r.title,' ',r.registration_no)",'r.case_status'],
        'certificates'=>['r.title','r.document_no','r.issued_on','YEAR(r.issued_on)',"CONCAT_WS(' ',r.title,r.document_no,r.issuer)","''"],
        'decrees'=>['r.title','r.document_no','r.decreed_on','YEAR(r.decreed_on)',"CONCAT(r.title,' ',r.document_no)",'r.document_status'],
        'diplomas'=>["CONCAT(r.institution,' / ',r.study_program)",'r.document_no','r.graduated_on','r.graduation_year',
            "CONCAT_WS(' ',r.document_no,r.institution,r.study_program)","''"],
        default=>["COALESCE(r.title,CONCAT_WS(' / ',r.institution,r.study_program))",'r.cohort',
            'COALESCE(r.graduated_on,r.ended_on,r.started_on)','COALESCE(r.end_year,r.start_year)',
            "CONCAT_WS(' ',r.title,r.institution,r.study_program,r.cohort)",'r.education_status'],
    };
    $extras = $tab === 'certificates' ? "COALESCE(t.expires_on,r.expires_on) expiry,r.valid_from,CASE WHEN t.expires_on IS NOT NULL THEN 'dated'
            WHEN r.training_id IS NOT NULL AND r.validity_kind='dated' THEN 'unknown' ELSE r.validity_kind END validity,r.reminder_days"
        : 'NULL expiry,NULL valid_from,NULL validity,NULL reminder_days';
    $join = profileTrainingJoin($tab);
    $verify = $tab === 'certificates'
        ? "IF(r.training_id IS NOT NULL AND NOT (r.verified_training_version <=> t.row_version),'pending',r.verification_status)"
        : 'r.verification_status';
    $query = "SELECT r.id,'record' source,$title title,$reference reference_no,$date event_on,$year record_year,
        $terms search_terms,$state process_state,r.category_id,c.name category,r.archived_at,$verify verification_status,$extras
        FROM $table r LEFT JOIN personnel_record_categories c ON c.id=r.category_id$join
        WHERE r.personnel_id=? AND $scope";
    $params = [$personnel,...$args];
    if ($tab === 'certificates' && can($user,'training.read')) {
        $query .= " UNION ALL SELECT t.id,'training',tt.name,NULL,NULL,NULL,tt.name,'',NULL,NULL,NULL,'pending',
            t.expires_on,NULL,IF(t.expires_on IS NULL,'unknown','dated'),90
            FROM training_records t JOIN training_types tt ON tt.id=t.type_id
            WHERE t.personnel_id=? AND t.archived_at IS NULL
            AND (t.expires_on IS NOT NULL OR EXISTS (SELECT 1 FROM training_documents d WHERE d.training_id=t.id))
            AND NOT EXISTS (SELECT 1 FROM personnel_certificates c WHERE c.training_id=t.id)";
        $params[] = $personnel;
    }
    return ["FROM ($query) v",$params];
}

function profileStates(string $tab): array
{
    return match($tab) {
        'cases'=>PROFILE_CASE_STATES, 'decrees'=>PROFILE_DECREE_STATES,
        'military','general'=>PROFILE_EDUCATION_STATES,
        'certificates'=>['expired'=>'Kedaluwarsa','soon'=>'Mendekati akhir','valid'=>'Berlaku',
            'unlimited'=>'Tanpa batas pada dokumen','unknown'=>'Belum diketahui','future'=>'Belum berlaku'],
        default=>[],
    };
}

function profileListFilter(array $user,string $personnel,string $tab): array
{
    [$source,$args] = profileDataset($user,$personnel,$tab);
    $archive = choice($_GET,'archive',['active','archived'],'active');
    $filters = [$archive === 'active' ? 'v.archived_at IS NULL' : 'v.archived_at IS NOT NULL'];
    if (($search = field($_GET,'q',100)) !== '') {
        $filters[] = 'LOCATE(?,v.search_terms)>0';
        $args[] = $search;
    }
    if (($category = number($_GET,'category')) !== null) {
        $filters[] = 'v.category_id=?';
        $args[] = $category;
    }
    if (($year = number($_GET,'year')) !== null) {
        $filters[] = 'v.record_year=?';
        $args[] = $year;
    }
    if (($verification = choice($_GET,'verification',['', ...array_keys(PROFILE_VERIFY)])) !== '') {
        $filters[] = 'v.verification_status=?';
        $args[] = $verification;
    }
    $state = choice($_GET,'state',['', ...array_keys(profileStates($tab))]);
    if ($state !== '') {
        if ($tab !== 'certificates') {
            $filters[] = 'v.process_state=?';
            $args[] = $state;
        } else {
            $filters[] = "CASE
                WHEN v.expiry IS NOT NULL AND v.expiry<? THEN 'expired'
                WHEN v.validity='unknown' THEN 'unknown'
                WHEN v.valid_from>? THEN 'future'
                WHEN v.expiry IS NULL THEN v.validity
                WHEN v.reminder_days IS NOT NULL AND v.expiry<=TIMESTAMPADD(DAY,v.reminder_days,?) THEN 'soon'
                ELSE 'valid' END=?";
            array_push($args,day(),day(),day(),$state);
        }
    }
    return [$source,implode(' AND ',$filters),$args];
}

function profileCertificateStatus(array $row): string
{
    $expiry = $row['expiry'];
    if ($expiry && $expiry < day()) {
        return 'expired';
    }
    if ($row['validity'] === 'unknown') {
        return 'unknown';
    }
    if ($row['valid_from'] && $row['valid_from'] > day()) {
        return 'future';
    }
    if (!$expiry) {
        return $row['validity'];
    }
    return $row['reminder_days'] && $expiry <= day((int) $row['reminder_days']) ? 'soon' : 'valid';
}

function profileLatestEducation(string $personnel): ?array
{
    $row = qu("SELECT e.institution,e.study_program,e.end_year,c.name AS level FROM personnel_education e
        JOIN personnel_record_categories c ON c.id=e.category_id WHERE e.personnel_id=? AND e.education_type='general'
        AND e.education_status='graduated' AND e.verification_status='verified' AND e.archived_at IS NULL
        AND c.active=1 AND c.sort_order>0 ORDER BY c.sort_order DESC,e.end_year DESC,e.graduated_on DESC,e.id LIMIT 1",
        [$personnel])->fetch();
    return $row ?: null;
}

function profileCaseHandlers(array $user,?int $selected = null): array
{
    // Name options are visible only to case writers and only for handlers in their own administrative scope.
    $args = [$selected];
    $where = '1=1';
    if ($user['role_code'] !== 'super_admin') {
        $where = '(EXISTS (SELECT 1 FROM user_unit_scopes own JOIN user_unit_scopes theirs ON theirs.unit_id=own.unit_id
            WHERE own.user_id=? AND theirs.user_id=u.id) OR u.id=?)';
        array_push($args,$user['id'],$user['id']);
    }
    return qu("SELECT u.id,u.name FROM users u JOIN personnel_case_permissions p ON p.user_id=u.id
        WHERE p.permission_code='personnel.cases.write' AND (u.active=1 OR u.id=?) AND $where ORDER BY u.name,u.id",
        $args)->fetchAll(PDO::FETCH_KEY_PAIR);
}
