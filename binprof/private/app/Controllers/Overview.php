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
            ?>
            <section class="hero overview-hero">
                <div>
                    <?php if ($workplace): ?><p class="overview-scope"><?= e($workplace['type'] . ' · ' . $workplace['unit']) ?></p><?php endif; ?>
                    <h2><?= e($workplace['name'] ?? 'Ringkasan tenaga kesehatan') ?></h2>
                    <?php if (!$workplace): ?><p>Personel aktif, fasilitas, dan tindak lanjut dalam cakupan Anda.</p><?php endif; ?>
                </div>
                <div class="actions">
                    <?php
                    if ($workplace && can($user,'personnel.write')) {
                        linkButton('/app/personel/edit','Tambah personel',class:'hero-primary');
                    } elseif (can($user,'map')) {
                        linkButton('/app/peta','Lihat peta fasilitas',class:'hero-primary');
                    }
                    ?>
                </div>
            </section>
            <?php dashboardShortcuts($user,(bool) $workplace); ?>
            <section class="overview-roster" aria-labelledby="overview-roster">
                <h2 id="overview-roster">Komposisi tenaga aktif</h2>
                <div class="stats overview-stats">
                    <?php foreach (['active'=>'Personel aktif','doctors'=>'Dokter','dentists'=>'Dokter gigi','nurses'=>'Perawat','specialists'=>'Dokter spesialis'] as $key=>$label): ?>
                        <div<?= $key==='active' ? ' class="stat-primary"' : '' ?>>
                            <span><?= e($label) ?></span><strong><?= e($counts[$key] ?? 0) ?></strong>
                            <?php if ($key==='specialists'): ?><small>Termasuk dalam jumlah dokter</small><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <div class="overview-workbench">
                <section class="surface overview-map">
                    <div class="section-heading"><h2>Sebaran fasilitas</h2><span class="scope-count"><?= e($facilities) ?> fasilitas aktif</span></div>
                    <?php mapsPanel(); ?>
                </section>
                <?php if ($reminders || $retirement !== null): ?>
                    <aside class="overview-followup" aria-label="Tindak lanjut">
                        <?php if ($reminders): ?>
                            <section class="surface"><div class="section-heading"><h2>Perlu ditinjau</h2><span class="subtle">90 hari ke depan</span></div>
                                <?php foreach ($reminders as $label=>$amount):
                                    $promotion = $label==='Kenaikan pangkat'; ?>
                                    <a class="overview-reminder" href="<?= e($promotion ? url('/app/personel',['promotion'=>1,'state'=>'active']) :
                                        url('/app/pelatihan',['certificate'=>'soon','active_personnel'=>1])) ?>">
                                        <span><?= e($label) ?></span><strong><?= e($amount) ?></strong><span aria-hidden="true">›</span>
                                    </a>
                                <?php endforeach; ?>
                            </section>
                        <?php endif; ?>
                        <?php if ($retirement !== null): ?>
                            <section class="surface">
                                <h2>Pensiun dalam 12 bulan</h2>
                                <?php foreach (['planned'=>'Perkiraan tercatat','scheduled'=>'Keputusan resmi menunggu penerapan',
                                    'followup'=>'Perlu tindak lanjut'] as $key=>$label): ?>
                                    <p class="reminder"><span><?= e($label) ?></span><strong><?= e($retirement[$key] ?? 'Belum tersedia') ?></strong></p>
                                <?php endforeach; ?>
                                <?php if (can($user,'retirement.read')) {
                                    linkButton(RETIREMENT,'Buka daftar pensiun',['group'=>'near','months'=>12]);
                                } ?>
                            </section>
                        <?php endif; ?>
                    </aside>
                <?php endif; ?>
            </div>
            <div class="dashboard-grid">
                <?php
                distribution('Distribusi pangkat',$ranks);
                if (!$workplace) {
                    distribution('Satuan dengan personel terbanyak',$units);
                }
                ?>
            </div>
            <?php
        },$user,'/app/dashboard');
}

function dashboardShortcuts(array $user,bool $facility): void
{
    $entries = $facility ? [
        ['/app/pelatihan/edit','training.write','Catat diklat','Pilih peserta dan catat kegiatan.','◇'],
        ['/app/personel','personnel.read','Dokumen personel','Buka sertifikat, Petikan, dan catatan peserta.','▤'],
        ['/app/peta','map.report','Laporkan kejadian','Kirim laporan untuk fasilitas Anda.','◉'],
        ['/app/peta-kendali/arahan','executive.work','Arahan untuk saya','Tinjau dan perbarui tugas Anda.','◈'],
    ] : [
        ['/app/personel','personnel.read','Daftar personel','Cari personel dan buka profil.','◉'],
        ['/app/pelatihan','training.read','Diklat','Lihat kegiatan dan masa berlaku sertifikat.','◇'],
        ['/app/pensiun','retirement.read','Pensiun','Tinjau jadwal dan status pengajuan.','◷'],
    ];
    $visible = array_filter($entries,static fn(array $entry): bool => can($user,$entry[1]));
    if (!$visible) {
        return;
    }
    ?>
    <section class="overview-shortcuts" aria-labelledby="overview-tasks">
        <h2 id="overview-tasks"><?= $facility ? 'Tugas fasilitas' : 'Akses cepat' ?></h2>
        <div class="task-grid">
            <?php foreach ($visible as $index=>[$path,,$name,$detail,$icon]): ?>
                <a class="task-link" href="<?= e(url($path)) ?>" aria-labelledby="task-title-<?= e($index) ?>" aria-describedby="task-detail-<?= e($index) ?>">
                    <span class="task-icon" aria-hidden="true"><?= e($icon) ?></span>
                    <span class="task-text"><strong id="task-title-<?= e($index) ?>"><?= e($name) ?></strong><small id="task-detail-<?= e($index) ?>"><?= e($detail) ?></small></span>
                    <span class="task-arrow" aria-hidden="true">›</span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}

function distribution(string $title,array $rows): void
{
    echo '<section class="surface overview-distribution"><h2>' . e($title) . '</h2>';
    if (!$rows) {
        echo '<p class="empty">Belum ada personel aktif yang tercatat.</p></section>';
        return;
    }
    $maximum = max(1,...array_column($rows,'total'));
    echo '<ul class="distribution-bars">';
    foreach (array_slice($rows,0,6) as $row) {
        $relative = (int) round(100 * $row['total'] / $maximum);
        echo '<li><div><span>' . e($row['name']) . '</span><strong>' . e($row['total']) . '</strong></div>'
            . '<span class="distribution-track" aria-hidden="true"><span style="width:' . $relative . '%"></span></span></li>';
    }
    echo '</ul>';
    if (count($rows)>6) {
        echo '<details class="entry"><summary>Lihat semua kelompok</summary>';
        table(['Kelompok','Jumlah'],$rows,static fn(array $row) => td($row['name'],$row['total']));
        echo '</details>';
    }
    echo '</section>';
}
