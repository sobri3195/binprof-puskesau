<?php
declare(strict_types=1);

require_once PROJECT . '/private/app/Services/admin.php';

require_once PROJECT . '/private/app/Services/maps.php';

function mapsPanel(array $filters = [], bool $globe = true): void
{
    ?>
    <div class="map-panel" data-map-panel data-endpoint="<?= e(url('/api/facilities', $filters)) ?>">
        <div class="actions map-controls" hidden>
            <button type="button" class="secondary" data-view="map">Peta 2D</button>
            <?php if ($globe): ?><button type="button" class="secondary" data-view="globe">Globe 3D</button><?php endif; ?>
            <button type="button" class="secondary" data-fit>Lihat semua lokasi</button>
            <button type="button" class="secondary" data-motion aria-pressed="false" hidden>Jeda animasi</button>
        </div>
        <div class="map-canvas" data-map hidden></div>
        <div class="map-canvas globe" data-globe hidden></div>
        <p class="subtle" data-map-status aria-live="polite">Lihat daftar fasilitas di bawah untuk informasi lokasi.</p>
        <noscript><p class="subtle">Aktifkan JavaScript untuk melihat visualisasi peta.</p></noscript>
    </div>
    <?php
}

function dashboard(array $user): void
{
    [$personScope, $args] = scope($user, 'p.unit_id', 'p.facility_id');
    $population = "$personScope AND p.archived_at IS NULL AND p.active=1";
    $counts = qu("SELECT COUNT(*) AS active, SUM(pr.code='doctor') AS doctors,SUM(pr.code='dentist') AS dentists,
        SUM(pr.code='nurse') AS nurses,
        SUM(pr.code='doctor' AND EXISTS (SELECT 1 FROM personnel_specialties s WHERE s.personnel_id=p.id)) AS specialists
        FROM personnel p JOIN professions pr ON pr.id=p.profession_id WHERE $population", $args)->fetch();
    $ranks = qu("SELECT COALESCE(r.name,'Belum dicatat') AS name,COUNT(*) AS total FROM personnel p
        LEFT JOIN ranks r ON r.id=p.rank_id WHERE $population GROUP BY r.id,r.name ORDER BY total DESC,name LIMIT 40", $args)->fetchAll();
    $units = qu("SELECT u.name,COUNT(*) AS total FROM personnel p JOIN units u ON u.id=p.unit_id
        WHERE $population GROUP BY u.id,u.name ORDER BY total DESC,u.name LIMIT 5", $args)->fetchAll();
    [$facilityScope, $facilityArgs] = scope($user, 'f.unit_id', 'f.id');
    $facilities = qu("SELECT COUNT(*) FROM facilities f WHERE $facilityScope AND f.active=1", $facilityArgs)->fetchColumn();
    $reminders = [];
    if (can($user, 'personnel.read')) {
        $reminders['Kenaikan pangkat'] = qu("SELECT COUNT(*) FROM personnel p WHERE $population
            AND p.promotion_date BETWEEN ? AND ?", [...$args,day(),day(90)])->fetchColumn();
    }
    if (can($user, 'training.read')) {
        $reminders['Sertifikat berakhir'] = qu("SELECT COUNT(*) FROM personnel p JOIN training_records t ON t.personnel_id=p.id
            WHERE $population AND t.archived_at IS NULL AND t.expires_on BETWEEN ? AND ?", [...$args,day(),day(90)])->fetchColumn();
    }
    $retirement = retirementMetricsAllowed($user) ? retirementMetrics($user,null,[],12)[0] : null;
    $workplace = $user['operator_facility'] ?? null;
    page($workplace ? 'Input Fasilitas' : 'Ikhtisar',
        static function () use ($user,$counts,$ranks,$units,$facilities,$reminders,$retirement,$workplace): void {
        if ($workplace) {
            echo '<section class="hero"><div><h2>' . e($workplace['name']) . '</h2><p>'
                . e($workplace['type'] . ' · ' . $workplace['unit']) . '</p></div><div class="actions">';
            linkButton('/app/personel/edit','Tambah personel');
            linkButton('/app/pelatihan/edit','Catat diklat');
            linkButton('/app/personel','Sertifikat dan dokumen personel');
            linkButton('/app/peta','Laporkan kejadian');
            linkButton('/app/peta-kendali/arahan','Arahan untuk saya');
            echo '</div></section>';
        } else {
            echo '<section class="hero"><div><h2>Tenaga kesehatan</h2><p>Ringkasan personel aktif dan fasilitas dalam cakupan Anda.</p></div>';
            linkButton('/app/peta', 'Lihat peta fasilitas');
            echo '</section>';
        }
        echo '<div class="stats">';
        foreach (['active'=>'Personel aktif','doctors'=>'Dokter','dentists'=>'Dokter gigi','nurses'=>'Perawat','specialists'=>'Dokter spesialis'] as $key=>$label) {
            echo '<div><span>' . e($label) . '</span><strong>' . e($counts[$key] ?? 0) . '</strong></div>';
        }
        echo '</div><p class="subtle compact-note">Dokter spesialis termasuk dalam jumlah dokter.</p><div class="dashboard-grid">';
        distribution('Distribusi pangkat', $ranks);
        distribution('Lima satuan teratas', $units);
        echo '</div><div class="dashboard-grid"><section class="surface"><div class="section-heading"><h2>Fasilitas aktif</h2><strong class="total">'
            . e($facilities) . '</strong></div>';
        mapsPanel();
        echo '</section>';
        if ($reminders !== []) {
            echo '<section class="surface"><h2>Dalam 90 hari</h2>';
            foreach ($reminders as $label=>$value) {
                echo '<p class="reminder"><span>' . e($label) . '</span><strong>' . e($value) . '</strong></p>';
            }
            echo '</section>';
        }
        if ($retirement !== null) {
            echo '<section class="surface"><h2>Pensiun dalam 12 bulan</h2>';
            foreach (['planned'=>'Perkiraan tercatat','scheduled'=>'Keputusan resmi menunggu penerapan','followup'=>'Perlu tindak lanjut'] as $key=>$label) {
                echo '<p class="reminder"><span>' . e($label) . '</span><strong>' . e($retirement[$key] ?? 'Belum tersedia') . '</strong></p>';
            }
            if (can($user,'retirement.read')) {
                linkButton(RETIREMENT,'Buka daftar pensiun',['group'=>'near','months'=>12]);
            }
            echo '</section>';
        }
        echo '</div>';
    }, $user, '/app/dashboard', 'Sistem Informasi Bina Profesional Kesehatan TNI AU');
}

function distribution(string $title, array $rows): void
{
    echo '<section class="surface"><h2>' . e($title) . '</h2>';
    if ($rows) {
        echo '<div class="chart" data-chart="' . e(json_encode(array_slice($rows, 0, 12), JSON_THROW_ON_ERROR)) . '" hidden><canvas aria-hidden="true"></canvas></div>';
    }
    table(['Kelompok','Jumlah'], $rows, static fn (array $row) => td($row['name'],$row['total']));
    echo '</section>';
}
