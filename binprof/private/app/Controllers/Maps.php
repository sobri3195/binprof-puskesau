<?php
declare(strict_types=1);

require_once PROJECT . '/private/app/Services/maps.php';

const MAP_PATH = '/app/peta';
const MAP_API = '/api/maps';
const MAP_HEADERS = ['Fasilitas','Kategori','Satuan','Komando','Personel tercatat','Dokter','Dokter gigi','Perawat'];

function mapExplore(array $user): void
{
    $route = ($_GET['r'] ?? parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)) === '/app/peta-sebaran'
        ? '/app/peta-sebaran' : MAP_PATH;
    $filters = mapFilters($_GET);
    $rows = mapRows($user,$filters);
    page($route === MAP_PATH ? 'Peta fasilitas' : 'Peta sebaran',static function () use ($route,$filters,$rows,$user): void {
        ?>
        <section class="surface atlas" data-atlas data-rows="<?= e(json_encode($rows,JSON_THROW_ON_ERROR)) ?>"
            data-routes="<?= e(json_encode(array_map('url',[
                'markers'=>MAP_API . '/markers','detail'=>MAP_API . '/detail','personnel'=>MAP_API . '/personnel',
                'events'=>MAP_API . '/events','preferences'=>MAP_API . '/preferences','views'=>MAP_API . '/views',
            ]),JSON_THROW_ON_ERROR)) ?>" data-csrf="<?= e(csrf()) ?>"
            data-categories="<?= e(json_encode(MAP_CATEGORIES,JSON_THROW_ON_ERROR)) ?>"
            data-severity="<?= e(json_encode(MAP_SEVERITIES,JSON_THROW_ON_ERROR)) ?>"
            data-manage="<?= (int) can($user,'map.manage') ?>" data-respond="<?= (int) can($user,'map.respond') ?>"
            data-zone="<?= e(cfg('timezone') ?? 'Asia/Jakarta') ?>">
            <div class="atlas-toolbar">
                <div>
                    <h2>Eksplorasi fasilitas</h2>
                    <p class="subtle" data-connect aria-live="polite">Menyiapkan sinkronisasi…</p>
                </div>
                <div class="actions" data-enhanced hidden>
                    <button class="secondary" type="button" data-fullscreen aria-pressed="false">Layar penuh</button>
                    <button class="secondary" type="button" data-view="map" aria-pressed="true">Peta 2D</button>
                    <button class="secondary" type="button" data-view="globe" aria-pressed="false">Globe 3D</button>
                    <button class="secondary" type="button" data-motion aria-pressed="false" hidden>Jeda animasi</button>
                    <button class="secondary" type="button" data-fit>Lihat semua</button>
                    <button class="secondary" type="button" data-show-events>Kejadian <span data-unread></span></button>
                </div>
            </div>
            <details class="atlas-box" open>
                <summary>Filter fasilitas</summary>
                <?php getStart($route); ?>
                <div class="atlas-fields" data-filters>
                    <?php
                    input('q','Nama atau kode fasilitas',$filters['q'],max:100);
                    foreach (MAP_FILTERS as $key=>[$master,$label]) {
                        selectField($key,$label,optionsFor($master,$master === 'units' ? $user : null,false),$filters[$key],'Semua');
                    }
                    ?>
                </div>
                <div class="actions"><button>Terapkan</button><a class="button secondary" data-reset href="<?= e(url($route)) ?>">Reset</a>
                    <?php if (can($user,'map.export')): ?>
                        <a class="button secondary" data-export href="<?= e(url($route,['format'=>'csv'] + array_diff_key($_GET,['r'=>true]))) ?>">Unduh CSV</a>
                    <?php endif; ?>
                </div></form>
            </details>
            <div class="atlas-status" data-status role="status" hidden></div>
            <div class="atlas-grid">
                <div class="atlas-visual">
                    <div class="atlas-layers" data-enhanced hidden>
                        <label>Layer tematik<select data-theme>
                            <option value="category">Kategori</option><option value="personnel">Personel tercatat</option><option value="events">Kejadian aktif</option>
                        </select></label>
                        <div class="atlas-legend" data-legend aria-label="Legenda"></div>
                    </div>
                    <div class="map-canvas atlas-map" data-map role="region" aria-label="Peta fasilitas dua dimensi" hidden></div>
                    <div class="map-canvas atlas-map globe" data-globe role="region" aria-label="Globe fasilitas tiga dimensi" hidden></div>
                    <noscript><p class="subtle">Aktifkan JavaScript untuk peta, detail interaktif, dan pemantauan kejadian.</p></noscript>
                    <details class="atlas-box" data-enhanced hidden>
                        <summary>Tampilan tersimpan</summary>
                        <form class="atlas-inline" data-save-view>
                            <?php input('name','Nama tampilan',required:true,max:80); ?>
                            <button>Simpan filter dan layer</button>
                        </form>
                        <ul class="atlas-list" data-saved></ul>
                    </details>
                </div>
                <aside class="atlas-side" aria-label="Detail dan kejadian fasilitas" data-enhanced hidden>
                    <section class="atlas-box atlas-detail" data-detail aria-live="polite"><h3>Detail fasilitas</h3><p>Pilih marker atau fasilitas dari tabel.</p></section>
                    <section class="atlas-box" data-near hidden>
                        <h3>Fasilitas sekitar</h3>
                        <p data-coordinate></p>
                        <label>Radius (km)<select data-radius>
                            <option>25</option><option selected>100</option><option>250</option><option>500</option><option>1000</option>
                        </select></label>
                        <p class="subtle">Jarak garis lurus, bukan rute perjalanan.</p>
                        <ul class="atlas-list" data-near-results></ul>
                    </section>
                    <details class="atlas-box" data-events-panel>
                        <summary>Kejadian fasilitas</summary>
                        <div class="form-grid">
                            <?php selectField('event_severity','Severity',MAP_SEVERITIES,blank:'Semua'); ?>
                            <?php selectField('event_state','Status',['active'=>'Aktif','unread'=>'Belum dibaca','ack'=>'Diakui','resolved'=>'Selesai','all'=>'Semua'],'active'); ?>
                            <?php selectField('event_category','Kategori kejadian',MAP_CATEGORIES,blank:'Semua'); ?>
                        </div>
                        <ul class="atlas-list event-list" data-events aria-live="polite"></ul>
                        <details class="atlas-preferences">
                            <summary>Preferensi notifikasi</summary>
                            <form data-preferences>
                                <fieldset><legend>Kategori untuk pemberitahuan</legend>
                                <?php foreach (MAP_CATEGORIES as $id=>$label): ?>
                                    <label class="atlas-check"><input type="checkbox" name="<?= e($id) ?>" value="1" checked> <?= e($label) ?></label>
                                <?php endforeach; ?>
                                </fieldset>
                                <label class="atlas-check"><input type="checkbox" name="muted" value="1"> Bisukan pemberitahuan</label>
                                <div class="form-grid">
                                    <?php input('quiet_start','Mulai jam tenang',type:'time'); ?>
                                    <?php input('quiet_end','Akhiri jam tenang',type:'time'); ?>
                                </div>
                                <small>Zona waktu <?= e(cfg('timezone') ?? 'Asia/Jakarta') ?></small><br>
                                <button>Simpan preferensi</button>
                            </form>
                            <button type="button" class="secondary" data-browser>Aktifkan notifikasi browser</button>
                        </details>
                    </details>
                </aside>
            </div>
            <section class="atlas-box" data-compare hidden>
                <div class="section-heading"><h3>Perbandingan fasilitas</h3><button class="secondary" type="button" data-clear-compare>Hapus pilihan</button></div>
                <div data-compare-body></div>
            </section>
            <section class="atlas-box atlas-table">
                <h3>Daftar fasilitas</h3>
                <p class="subtle" data-result><?= count($rows) ?> fasilitas. Maksimal 1.000 hasil.</p>
                <div data-table>
                <?php table([...MAP_HEADERS,'Koordinat'],$rows,static function (array $row): void {
                    td(...mapCsvRow($row));
                }); ?>
                </div>
            </section>
            <div class="atlas-toast" role="status" aria-live="polite" hidden></div>
        </section>
        <script type="module" src="/assets/js/atlas.js"></script>
        <?php
    },$user,$route,'Pantau fasilitas, tenaga kesehatan, dan kejadian operasional dalam cakupan akun.');
}

function mapCsvRow(array $row): array
{
    return [$row['name'],$row['kind'],$row['unit'],$row['command_name'],$row['total'],$row['doctors'],$row['dentists'],$row['nurses'],
        $row['latitude'] === null ? null : $row['latitude'] . ', ' . $row['longitude']];
}

function mapPage(array $user): void
{
    if (!isset($_GET['format'])) {
        mapExplore($user);
        return;
    }
    if (!can($user,'map.export')) {
        fail(403,'Akses ekspor ditolak.');
    }
    choice($_GET,'format',['csv']);
    require_once PROJECT . '/private/app/Services/exports.php';
    $rows = mapRows($user);
    audit($user['id'],'export_csv','facilities');
    sendExport('csv','Fasilitas',[...MAP_HEADERS,'Koordinat'],array_map('mapCsvRow',$rows));
}

function mapDetail(array $user): never
{
    $id = number($_GET,'facility') ?? fail(422,'Fasilitas diperlukan.');
    $result = tx(static function () use ($user,$id): array {
        $facility = mapFacility($user,$id);
        $professions = qu('SELECT pr.name,COUNT(*) AS total FROM personnel p JOIN professions pr ON pr.id=p.profession_id
            WHERE p.facility_id=? AND p.active=1 AND p.archived_at IS NULL GROUP BY pr.id,pr.name ORDER BY total DESC,pr.name',[$id])->fetchAll();
        $training = qu('SELECT COUNT(*) AS total,SUM(t.completed=1) AS completed,SUM(t.completed=0) AS pending,
            SUM(t.expires_on BETWEEN ? AND ?) AS expiring
            FROM training_records t JOIN personnel p ON p.id=t.personnel_id
            WHERE p.facility_id=? AND p.active=1 AND p.archived_at IS NULL AND t.archived_at IS NULL',[day(),day(90),$id])->fetch();
        $events = array_filter(eventRows($user,mapRevision(),facility:$id),static fn (array $row): bool => $row['resolved_at'] === null);
        unset($facility['unit_id']);
        return [
            'facility'=>$facility,'total'=>$professions ? array_sum(array_column($professions,'total')) : null,
            'professions'=>$professions ?: null,'training'=>(int) $training['total'] > 0 ? $training : null,
            'events'=>array_values($events),'personnel_allowed'=>can($user,'personnel.read'),
        ];
    });
    jsonResponse($result);
}

function mapPersonnel(array $user): never
{
    $id = number($_GET,'facility') ?? fail(422,'Fasilitas diperlukan.');
    mapFacility($user,$id);
    [$scoped,$params] = scope($user,'p.unit_id','p.facility_id');
    $offset = ((number($_GET,'page') ?? 1)-1)*25;
    $rows = qu("SELECT p.id,p.name,pr.name AS profession FROM personnel p JOIN professions pr ON pr.id=p.profession_id
        WHERE p.facility_id=? AND p.archived_at IS NULL AND p.active=1 AND $scoped
        ORDER BY p.name,p.id LIMIT 26 OFFSET ?",[$id,...$params,$offset])->fetchAll();
    $next = count($rows) > 25;
    $rows = array_slice($rows,0,25);
    foreach ($rows as &$row) {
        $row['url'] = url('/app/personel/detail',['id'=>$row['id']]);
        unset($row['id']);
    }
    unset($row);
    jsonResponse(['rows'=>$rows,'next'=>$next]);
}

function mapMarkers(array $user): never
{
    jsonResponse(mapRows($user));
}
