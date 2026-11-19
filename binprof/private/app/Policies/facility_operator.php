<?php
declare(strict_types=1);

const FACILITY_OPERATOR_ROLE = 'operator_fasilitas';
const FACILITY_OPERATOR_PLACES = "FROM facilities f JOIN facility_types t ON t.id=f.type_id
    JOIN units u ON u.id=f.unit_id";
const FACILITY_OPERATOR_ELIGIBILITY = "f.active=1 AND u.active=1 AND t.active=1 AND t.code IN ('rsau','fktp')";

function facilityOperatorPlaceIsEligible(int $facility): bool
{
    return (bool) qu('SELECT 1 ' . FACILITY_OPERATOR_PLACES .
        ' WHERE f.id=? AND ' . FACILITY_OPERATOR_ELIGIBILITY,[$facility])->fetchColumn();
}

function facilityOperatorAssignmentSql(): string
{
    return FACILITY_OPERATOR_PLACES . ' JOIN user_facility_scopes s ON s.facility_id=f.id
        WHERE s.user_id=? AND ' . FACILITY_OPERATOR_ELIGIBILITY . '
        AND NOT EXISTS (SELECT 1 FROM user_unit_scopes us WHERE us.user_id=s.user_id)
        AND NOT EXISTS (SELECT 1 FROM user_facility_scopes other
            WHERE other.user_id=s.user_id AND other.facility_id<>s.facility_id)';
}

function facilityOperatorAssignment(int $user): ?array
{
    $row = qu('SELECT f.id,f.name,f.unit_id,u.name AS unit,t.name AS type ' . facilityOperatorAssignmentSql(),[$user])->fetch();
    return $row ?: null;
}
