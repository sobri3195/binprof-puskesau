<?php
declare(strict_types=1);

function executiveView(array $user,array $data,array $pickers,array $presets,string $scope): void
{
    $data['presets'] = array_map(static fn(array $p): array => ['id'=>$p['id'],'name'=>$p['name'],
        'settings'=>json_decode($p['settings_json'],true,flags:JSON_THROW_ON_ERROR)],$presets);
    $endpoint = array_map('url',[
        'data'=>EXEC_API . '/data','preset'=>EXEC_API . '/preset','pdf'=>EXECUTIVE . '/pdf','home'=>EXECUTIVE,
        'source'=>EXECUTIVE . '/sumber','directives'=>EXECUTIVE . '/arahan','maps'=>'/app/peta','mapEvents'=>'/api/maps/events','personnel'=>'/app/personel',
        'training'=>'/app/pelatihan','retirement'=>'/app/pensiun','login'=>'/login',
    ]);
    $filters = $data['filters'];
    ?>
    <section class="surface atlas exec" data-exec data-csrf="<?= e(csrf()) ?>"
        data-initial="<?= e(json_encode($data,JSON_THROW_ON_ERROR)) ?>"
        data-urls="<?= e(json_encode($endpoint,JSON_THROW_ON_ERROR)) ?>"
        data-zone="<?= e(cfg('timezone') ?? 'Asia/Jakarta') ?>"
        data-permissions="<?= e(json_encode(array_values(array_intersect($user['permissions'],[
            'personnel.read','training.read','retirement.read','executive.issue','executive.work','executive.export',
        ])),JSON_THROW_ON_ERROR)) ?>">
        <div class="exec-header">
            <div><p class="exec-brand">BINPROFKES · KESEHATAN TNI AU</p>
            <h1>Peta Kendali Pimpinan</h1>
            <p><?= e($scope) ?> <span aria-hidden="true">·</span> <span data-exec-scope><?= e($data['scope']) ?></span></p></div>
            <div class="actions">
                <button type="button" data-fullscreen aria-pressed="false">Layar penuh</button>
                <button type="button" class="secondary" data-exec-meeting aria-pressed="false">Mode rapat</button>
                <?php postButton('/logout','Keluar',class:'secondary'); ?>
            </div>
        </div>
        <div data-exec-content>
            <div class="exec-reference">
                <p data-exec-period>Tanggal acuan <?= e($data['reference'] ?? 'belum dipilih') ?></p>
                <p data-exec-sync role="status">Pembaruan sumber <?= e($data['source_at'] ? $data['source_at'] . ' UTC' : 'belum tersedia') ?></p>
            </div>
            <div class="exec-kpis" data-exec-kpi>
                <?php foreach ($data['labels'] as $key=>$label): ?>
                    <button class="exec-kpi<?= $data['kpi'][$key] === null ? ' is-unavailable' : '' ?>" type="button" data-kpi="<?= e($key) ?>" aria-pressed="<?= $filters['metric']===$key ? 'true' : 'false' ?>">
                        <span><?= e($label) ?></span><strong><?= e($data['kpi'][$key] ?? ($key==='shortage' ? 'Belum dapat dinilai' : 'Belum tersedia')) ?></strong>
                        <small><?= e($data['covered'][$key]) ?> fasilitas dengan data</small>
                    </button>
                <?php endforeach; ?>
            </div>
            <section class="exec-search-panel" aria-label="Pencarian dan cakupan">
                <?php getStart(EXECUTIVE); ?>
                <div data-exec-filter>
                    <div class="exec-search-row">
                        <label class="exec-search-field">Nama atau kode fasilitas
                            <input type="search" name="q" value="<?= e($filters['q']) ?>" maxlength="100">
                        </label>
                        <?php selectField('mode','Mode waktu',['now'=>'Saat ini','history'=>'Historis','projection'=>'Proyeksi'],$filters['mode']); ?>
                        <div class="actions"><button>Terapkan</button><a class="button secondary" data-exec-reset href="<?= e(url(EXECUTIVE)) ?>">Reset</a></div>
                    </div>
                    <details class="exec-filters" <?= $filters['mode']==='now' ? '' : 'open' ?>>
                        <summary>Filter lanjutan <span data-exec-filter-status></span></summary>
                        <div class="atlas-fields">
                            <?php
                            foreach (['command_id'=>'Komando','unit_id'=>'Satuan','facility_id'=>'Fasilitas','type_id'=>'Jenis fasilitas',
                                'profession_id'=>'Profesi','specialty_id'=>'Spesialisasi'] as $key=>$label) {
                                selectField($key,$label,$pickers[$key],$filters[$key],'Semua');
                            }
                            ?>
                            <div data-exec-date><?php input('date','Tanggal snapshot',$filters['date'] ?? '','date'); ?></div>
                            <div data-exec-horizon><?php selectField('months','Periode proyeksi',RETIREMENT_MAP_PERIODS,$filters['months']); ?></div>
                        </div>
                    </details>
                </div>
                </form>
            </section>
            <section class="exec-presets" aria-label="Preset">
                <div class="actions" data-exec-builtins>
                    <?php foreach (['now'=>'Situasi Hari Ini','staffing'=>'Kebutuhan Tenaga','retirement'=>'Pensiun 12 Bulan',
                        'certificate'=>'Kesiapan Kompetensi','directive'=>'Tindak Lanjut Pimpinan'] as $key=>$label): ?>
                        <button type="button" class="secondary" data-exec-preset="<?= e($key) ?>" aria-pressed="false"><?= e($label) ?></button>
                    <?php endforeach; ?>
                    <button type="button" class="secondary" data-exec-rotate aria-pressed="false">Putar preset</button>
                </div>
                <details class="exec-tools">
                    <summary>Preset dan laporan</summary>
                    <form class="actions" data-exec-save>
                        <?php input('name','Nama preset',required:true,max:80); ?>
                        <button>Simpan preset</button>
                    </form>
                    <ul class="atlas-list" data-exec-saved></ul>
                    <div class="actions">
                        <?php if (can($user,'executive.export')): ?>
                            <a class="button secondary" data-exec-export href="<?= e(url(EXECUTIVE . '/pdf',$filters)) ?>">Unduh PDF ringkas</a>
                        <?php endif; ?>
                        <?php if (can($user,'executive.configure') || can($user,'executive.verify')) {
                            linkButton(EXECUTIVE . '/sumber','Kelola sumber');
                        }
                        if (can($user,'executive.snapshot')) {
                            postButton(EXECUTIVE . '/snapshot','Simpan snapshot hari ini');
                        } ?>
                    </div>
                </details>
            </section>
            <p data-exec-status class="exec-note" role="status" <?= $data['status'] ? '' : 'hidden' ?>><?= e($data['status']) ?></p>
            <div class="exec-grid">
                <section class="exec-map-box" aria-label="Peta interaktif">
                    <div class="exec-map-tools">
                        <?php selectField('layer','Layer',EXEC_LAYERS,$filters['layer']); ?>
                        <div class="actions">
                            <button type="button" class="secondary" data-exec-fit>Lihat semua</button>
                            <button type="button" class="secondary" data-exec-globe aria-pressed="false">Globe 3D</button>
                            <button type="button" class="secondary" data-exec-motion hidden>Jeda animasi</button>
                            <button type="button" class="secondary" data-exec-compare>Bandingkan (0/4)</button>
                        </div>
                    </div>
                    <div class="atlas-legend exec-legend" aria-label="Legenda kondisi">
                        <span><i class="exec-green"></i> Memenuhi kriteria</span><span><i class="exec-yellow"></i> Perlu perhatian</span>
                        <span><i class="exec-red"></i> Prioritas tinggi</span><span><i class="exec-gray"></i> Data belum cukup</span>
                    </div>
                    <div class="map-canvas exec-map" data-exec-map role="region" aria-label="Peta fasilitas 2D" hidden></div>
                    <div class="map-canvas exec-map globe" data-exec-scene role="region" aria-label="Globe fasilitas 3D" hidden></div>
                    <noscript><p>Gunakan filter dan tabel fasilitas. Peta interaktif memerlukan JavaScript.</p></noscript>
                </section>
                <section class="exec-priority-box" aria-labelledby="exec-priority-heading">
                    <h3 id="exec-priority-heading">Lima prioritas utama</h3><ol class="exec-priorities" data-exec-priorities>
                        <?php foreach ($data['priorities'] as $item): ?><li><?= e($item['facility'] . ' · ' . $item['reason']) ?></li><?php endforeach; ?>
                    </ol>
                </section>
                <section class="exec-detail-box" data-exec-detail aria-label="Detail fasilitas">
                    <h3>Detail fasilitas</h3><p>Pilih fasilitas di peta, prioritas, atau tabel.</p>
                </section>
            </div>
            <section class="exec-results"><h3>Fasilitas dalam filter</h3><div data-exec-table>
                <?php table(['Fasilitas','Satuan','Personel tercatat','Gap SDM'],$data['rows'],static fn(array $r) =>
                    td($r['name'],$r['unit'],$r['totals']['active'] ?? 'Belum tersedia',$r['gap'] ?? 'Belum dapat dinilai')); ?>
            </div></section>
            <div class="exec-bottom">
                <section><h3>Linimasa Pensiun dan Tenaga</h3><div data-exec-timeline></div></section>
                <section><h3>Progres tindak lanjut</h3><div data-exec-progress></div></section>
            </div>
            <dialog data-exec-comparison aria-labelledby="comparison-heading">
                <div class="section-heading"><h3 id="comparison-heading">Perbandingan fasilitas</h3>
                    <form method="dialog"><button class="secondary">Tutup</button></form></div>
                <div data-exec-compare-table></div>
            </dialog>
        </div>
        <section data-exec-expired hidden><h3>Sesi berakhir atau akses berubah</h3>
            <p>Masuk kembali untuk melihat data terbaru.</p>
            <?php linkButton('/login','Masuk'); ?></section>
    </section>
    <script type="module" src="/assets/js/executive.js"></script>
    <?php
}


function executivePicOptions(int $facility): array
{
    $rows = qu("SELECT DISTINCT u.id,u.name FROM users u JOIN role_permissions r ON r.role_code=u.role_code
        JOIN facilities f ON f.id=? LEFT JOIN user_unit_scopes us ON us.user_id=u.id AND us.unit_id=f.unit_id
        LEFT JOIN user_facility_scopes fs ON fs.user_id=u.id AND fs.facility_id=f.id
        WHERE u.active=1 AND r.permission_code='executive.work' AND (u.role_code='super_admin' OR us.user_id IS NOT NULL
        OR fs.user_id IS NOT NULL) ORDER BY u.name",[$facility])->fetchAll(PDO::FETCH_KEY_PAIR);
    return $rows;
}

function executiveSourceView(array $user,array $facilities,?int $id): void
{
    $path = EXECUTIVE . '/sumber';
    echo '<section class="surface"><h2>Sumber penilaian fasilitas</h2>';
    getStart($path);
    selectField('facility','Fasilitas',$facilities,$id,'Pilih fasilitas');
    echo '<button>Buka</button></form></section>';
    if (!$id) {
        return;
    }
    foreach ([
        'rule'=>['facility_assessment_rules','Aturan penilaian','version_label'],
        'target'=>['facility_staffing_targets','Target tenaga','version_label'],
        'roster'=>['executive_roster_reviews','Verifikasi roster','roster_on'],
    ] as $kind=>[$table,$label,$display]) {
        $rows = qu("SELECT * FROM $table WHERE facility_id=? ORDER BY id DESC LIMIT 60",[$id])->fetchAll();
        echo '<section class="surface"><h2>' . e($label) . '</h2>';
        table(['Versi atau tanggal','Rujukan','Nilai','Verifikasi'],$rows,static function (array $r) use ($kind,$display,$user,$id,$path): void {
            $values = match($kind) {
                'rule'=>$r['freshness_days'] . ' hari umur data / ' . $r['critical_gap'] . ' gap kritis / ' . $r['certificate_window'] . ' hari sertifikat',
                'target'=>$r['target_count'] . ' tenaga · ' . (optionsFor('professions',activeOnly:false)[$r['profession_id']] ?? ''),
                default=>'Tanggal roster ' . $r['roster_on'],
            };
            td($r[$display],$r['source_reference'],$values);
            echo '<td>';
            if ($r['verified_at']) {
                echo e($r['verified_at'] . ' UTC');
            } elseif (can($user,'executive.verify') && (int) ($r['created_by'] ?? $r['submitted_by'])!==$user['id']) {
                postButton($path,'Verifikasi',['facility'=>$id,'record'=>$r['id'],'action'=>'verify_' . $kind]);
            } else {
                echo 'Menunggu pemeriksa lain';
            }
            echo '</td>';
        });
        if (can($user,'executive.configure')) {
            echo '<details class="entry"><summary>Tambahkan ' . e(mb_strtolower($label)) . '</summary>';
            postStart($path,['facility'=>$id,'action'=>$kind]);
            echo '<div class="form-grid">';
            input('source','Rujukan sumber',required:true,max:250);
            if ($kind==='roster') {
                input('roster_on','Tanggal sumber roster',day(),'date',true);
                selectField('pic','PIC fasilitas',executivePicOptions($id),blank:'Pilih PIC');
            } else {
                input('label','Versi',required:true,max:40);
                input('valid_from','Berlaku mulai',type:'date',required:true);
                input('valid_until','Berlaku hingga',type:'date');
                if ($kind==='rule') {
                    input('freshness_days','Umur roster maksimum (hari)',type:'number',required:true);
                    input('critical_gap','Gap kritis (jumlah tenaga)',type:'number',required:true);
                    input('certificate_window','Jendela sertifikat (hari)',type:'number',required:true);
                    $categories = qu("SELECT id,name FROM personnel_record_categories WHERE kind='certificates' AND active=1 ORDER BY name")
                        ->fetchAll(PDO::FETCH_KEY_PAIR);
                    selectField('certificate_category_id','Kategori sertifikat wajib',$categories,blank:'Belum ditetapkan');
                } else {
                    selectField('profession_id','Profesi',optionsFor('professions'),blank:'Pilih profesi');
                    input('target_count','Jumlah target',type:'number',required:true);
                }
            }
            echo '</div><button>Ajukan</button></form></details>';
        }
        echo '</section>';
    }
    if (!can($user,'executive.verify')) {
        return;
    }
    echo '<section class="surface"><h2>Gangguan operasional aktif</h2>';
    $events = qu("SELECT e.*,v.verified_at FROM map_events e LEFT JOIN executive_event_verifications v ON v.event_id=e.id
        WHERE e.facility_id=? AND e.category='operations' AND e.active_slot=1",[$id])->fetchAll();
    table(['Terjadi (UTC)','Severity','Verifikasi'],$events,static function (array $event) use ($user,$id,$path): void {
        td($event['created_at'],MAP_SEVERITIES[$event['severity']]);
        echo '<td>';
        if ($event['verified_at']) {
            echo e($event['verified_at'] . ' UTC');
        } elseif ((int) $event['created_by']!==$user['id']) {
            postStart($path,['facility'=>$id,'action'=>'verify_event','event'=>$event['id']]);
            input('source','Rujukan verifikasi',required:true,max:250);
            echo '<button>Verifikasi</button></form>';
        } else {
            echo 'Menunggu pemeriksa lain';
        }
        echo '</td>';
    });
    echo '</section>';
}

function executiveWorkList(array $user,?int $facility,array $rows,int $total,int $current,int $per): void
{
    $path = EXECUTIVE . '/arahan';
    echo '<section class="surface"><h2>Daftar arahan</h2>';
    getStart($path);
    selectField('facility','Fasilitas',executiveFacilitiesSelect($user),$facility,'Semua fasilitas');
    echo '<button>Buka</button></form>';
    if ($facility && can($user,'executive.issue')) {
        echo '<details class="entry"><summary>Draf arahan baru</summary>';
        executiveWorkForm($facility,[]);
        echo '</details>';
    }
    table(['Judul','Fasilitas','PIC','Prioritas','Tenggat','Status','Detail'],$rows,static function (array $row) use ($path): void {
        td($row['title'],$row['facility'],$row['pic'],MAP_SEVERITIES[$row['severity']],$row['due_on'],EXEC_STATES[$row['state']]);
        echo '<td>';
        linkButton($path,'Buka',['id'=>$row['id']]);
        echo '</td>';
    });
    paginate($path,$total,$current,$per);
    echo '</section>';
}

function executiveWorkForm(int $facility,array $row): void
{
    $hidden = ['facility'=>$facility];
    if ($row) {
        $hidden += ['id'=>$row['id'],'version'=>$row['row_version']];
    }
    postStart(EXECUTIVE . '/arahan/save',$hidden);
    echo '<div class="form-grid">';
    input('title','Judul',$row['title'] ?? '',required:true);
    selectField('pic','PIC',executivePicOptions($facility),$row['pic_id'] ?? null,'Pilih PIC');
    selectField('kind','Kategori sumber',array_diff_key(EXEC_ISSUES,['directive'=>true]),$row['kind'] ?? 'general');
    selectField('severity','Prioritas',MAP_SEVERITIES,$row['severity'] ?? 'info');
    input('due_on','Tenggat',$row['due_on'] ?? '',type:'date',required:true);
    input('plan_reference','Referensi rencana pengganti untuk arahan pensiun',$row['plan_reference'] ?? '',max:250);
    echo '</div>';
    textArea('instruction','Instruksi',$row['instruction'] ?? '');
    echo '<button>Simpan draf</button></form>';
}

function executiveWorkDetail(array $user,array $row): void
{
    $path = EXECUTIVE . '/arahan';
    echo '<section class="surface"><div class="section-heading"><h2>' . e($row['title']) . '</h2>';
    linkButton($path,'Daftar arahan',['facility'=>$row['facility_id']]);
    echo '</div>';
    definition(['Fasilitas'=>$row['facility'],'PIC'=>$row['pic'],'Status'=>EXEC_STATES[$row['state']],
        'Dibaca oleh saya'=>$row['read_at'] ? $row['read_at'] . ' UTC' : 'Belum dibaca','Tenggat'=>$row['due_on'],
        'Prioritas'=>MAP_SEVERITIES[$row['severity']],'Instruksi'=>$row['instruction'],'Rencana pengganti'=>$row['plan_reference']]);
    $hidden = ['id'=>$row['id'],'version'=>$row['row_version']];
    if (!$row['read_at']) {
        postButton($path . '/action','Tandai dibaca',$hidden + ['action'=>'read']);
    }
    $author = $row['state']==='draft' && (int) $row['created_by']===$user['id'] && can($user,'executive.issue');
    if ($author) {
        echo '<details class="entry"><summary>Ubah draf</summary>';
        executiveWorkForm((int) $row['facility_id'],$row);
        echo '</details>';
    }
    $labels = ['issue'=>'Terbitkan','accept'=>'Terima arahan','work'=>'Mulai pekerjaan','submit'=>'Ajukan verifikasi',
        'finish'=>'Verifikasi dan selesaikan','return'=>'Kembalikan','cancel'=>'Batalkan'];
    foreach (executiveDirectiveActions($user,$row) as $action) {
        echo '<details class="entry"><summary>' . e($labels[$action]) . '</summary>';
        postStart($path . '/action',$hidden + ['action'=>$action]);
        input('note',in_array($action,['return','cancel'],true) ? 'Alasan' : 'Catatan',
            required:in_array($action,['return','cancel'],true),max:1000);
        echo '<button>Konfirmasi</button></form></details>';
    }
    if (can($user,'executive.review') && !in_array($row['state'],['draft','done','cancelled'],true)) {
        echo '<details class="entry"><summary>Alihkan PIC</summary>';
        postStart($path . '/action',$hidden + ['action'=>'reassign']);
        selectField('pic','PIC baru',executivePicOptions((int) $row['facility_id']),blank:'Pilih PIC');
        input('note','Alasan',required:true,max:1000);
        echo '<button>Alihkan</button></form></details>';
    }
    echo '</section><section class="surface"><h2>Lampiran privat</h2>';
    $files = qu('SELECT f.id,f.original_name,f.created_at FROM executive_directive_files a JOIN file_objects f ON f.id=a.file_id
        WHERE a.directive_id=? ORDER BY f.created_at',[$row['id']])->fetchAll();
    table(['Berkas','Diunggah (UTC)','Aksi'],$files,static function (array $file) use ($row,$path): void {
        td($file['original_name'],$file['created_at']);
        echo '<td>';
        linkButton($path . '/file','Unduh',['id'=>$row['id'],'file'=>$file['id']]);
        echo '</td>';
    });
    if ($author || ((int) $row['pic_id']===$user['id'] && in_array($row['state'],['accepted','working'],true))) {
        postStart($path . '/upload',$hidden,true);
        echo '<label>Bukti atau lampiran (PDF, JPEG, PNG hingga 10 MB)<input type="file" name="attachment" accept=".pdf,.jpeg,.jpg,.png" required></label>';
        echo '<button>Unggah</button></form>';
    }
    echo '</section><section class="surface"><h2>Riwayat perubahan</h2>';
    $history = qu('SELECT h.*,u.name AS actor FROM executive_directive_updates h JOIN users u ON u.id=h.actor_id
        WHERE h.directive_id=? ORDER BY h.id DESC LIMIT 100',[$row['id']])->fetchAll();
    table(['Tahap','Petugas','Waktu (UTC)','Catatan'],$history,static fn(array $r) =>
        td(EXEC_STATES[$r['to_state']],$r['actor'],$r['created_at'],$r['note']));
    echo '</section>';
}
