<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once PROJECT . '/private/app/Services/maps.php';
require_once PROJECT . '/private/app/Policies/clinical.php';

const WA_INTENTS = [
    'staff'       => ['dashboard','waStaff'],
    'pending'     => ['training.read','waTrainingPending'],
    'expiry'      => ['training.read','waExpiry'],
    'training'    => ['training.read','waTrainingReport'],
    'specialty'   => ['map','waFacilities'],
    'map'         => ['map','waMapLink'],
    'priority'    => ['map','waPriority'],
    'notices'     => ['notifications','waNotices'],
    'transfer'    => ['transfers.request','waTransfers'],
    'personnel'   => ['personnel.read','waPersonnelPortal'],
    'clinical'    => [null,'waClinicalPortal'],
    'help'        => [null,'waHelp'],
    'stopped'     => [null,'waStopped'],
];

function waIntent(string $message): array
{
    $text = mb_strtolower(trim($message),'UTF-8');
    $text = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $text) ?? '';
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    $text = trim($text, " ?!.");
    $subject = '(?:dokter gigi|dokter spesialis|dokter|perawat|personel)';
    if (preg_match('/\b(?:e[ -]?rm|rikkes|rekam medis|diagnosis|diagnosa|pasien)\b/u',$text)) {
        return ['intent'=>'clinical'];
    }
    if (preg_match('/\b(?:belum (?:mengikuti|menyelesaikan|ikut)|tidak pernah mengikuti)\b\s*(.*)$/u',$text,$matches)) {
        return ['intent'=>'pending','term'=>mb_substr(trim($matches[1]),0,80)];
    }
    if (preg_match('/\bsertifikat\b.*\b(?:habis|kedaluwarsa|kadaluwarsa|berakhir)\b/u',$text)) {
        preg_match('/\b(\d{1,3})\s*hari\b/u',$text,$matches);
        $explicit = isset($matches[1]);
        $days = $explicit ? (int) $matches[1] : 30;
        $unsupported = preg_match('/\b(?:bulan|minggu|kemarin|tahun)\b/u',$text) === 1;
        return ['intent'=>'expiry','days'=>$unsupported ? 0 : $days];
    }
    if (preg_match('/\b(?:rekap|laporan|ringkas(?:an)?)\b.*\b(?:diklat|pelatihan)\b/u',$text)
        || preg_match('/\b(?:diklat|pelatihan)\b.*\b(?:rekap|laporan)\b/u',$text)) {
        $period = str_contains($text,'bulan lalu') ? 'previous' : (str_contains($text,'bulan ini') ? 'current' : '');
        return ['intent'=>'training','period'=>$period];
    }
    if (preg_match('/\b(?:fasilitas|puskesau|rumah sakit)\b.*\bspesialis(?:asi)?\s*(.*)$/u',$text,$matches)) {
        return ['intent'=>'specialty','term'=>mb_substr(trim($matches[1]),0,80)];
    }
    if (preg_match('/\b(?:peta|fasilitas)\b/u',$text)) {
        return ['intent'=>'map','follow'=>preg_match('/\b(?:itu|tadi|tersebut)\b/u',$text)===1];
    }
    if (preg_match('/\b(?:notifikasi|kejadian|prioritas|pemberitahuan)\b/u',$text)) {
        if (preg_match('/\b(?:prioritas|kejadian)\b/u',$text)) {
            return ['intent'=>'priority','period'=>preg_match('/\b(?:kemarin|bulan|minggu|tahun)\b/u',$text) ? '' : 'today'];
        }
        return ['intent'=>'notices'];
    }
    if (preg_match('/\bmutasi\b/u',$text)) {
        return ['intent'=>'transfer','period'=>preg_match('/\b(?:kemarin|bulan|minggu|tahun)\b/u',$text) ? '' : 'current'];
    }
    if (preg_match('/\b(?:berapa|jumlah|total)\b.*\b(' . $subject . ')\b/u',$text,$matches)
        && !preg_match('/\b(?:bulan|tahun|kemarin|dulu|tren|nonaktif|tidak aktif)\b/u',$text)
        && !preg_match('/\b(?:dan|atau)\s+' . $subject . '\b/u',$text)) {
        return ['intent'=>'staff','profession'=>$matches[1],'own'=>str_contains($text,'satuan saya'),
            'other'=>preg_match('/\bsatuan\s+(?!saya\b)\w/u',$text)===1];
    }
    if (preg_match('/\b(?:personel|dokumen)\b/u',$text)
        || preg_match('/\b(?:siapa|nama|nrp|daftar)\b.*\b' . $subject . '\b/u',$text)) {
        return ['intent'=>'personnel'];
    }
    return ['intent'=>'help'];
}

function waAnswer(array $actor, array $link, array $query): string
{
    $entry = WA_INTENTS[$query['intent'] ?? ''] ?? WA_INTENTS['help'];
    [$permission,$handler] = $entry;
    if ($permission !== null && !can($actor,$permission)) {
        return "Akun Anda tidak memiliki izin untuk permintaan itu. Periksa akses di portal:\n" . waPortal('/app/dashboard');
    }
    return $handler($actor,$link,$query);
}

function waMeasured(string $reply, array $actor, string $period): string
{
    $zone = new DateTimeZone(cfg('timezone') ?? 'Asia/Jakarta');
    $now = (new DateTimeImmutable('now',$zone))->format('d-m-Y H:i:s');
    $label = $actor['role_code'] === 'super_admin' ? 'seluruh cakupan administratif akun'
        : 'satuan dan fasilitas yang ditetapkan pada akun';
    return "$reply\nPeriode: $period\nCakupan: $label\nData diperiksa: $now " . $zone->getName();
}

function waStaff(array $actor, array $link, array $query): string
{
    $roles = ['dokter'=>'doctor','dokter gigi'=>'dentist','dokter spesialis'=>'doctor','perawat'=>'nurse','personel'=>null];
    $profession = $query['profession'];
    if ($query['other']) {
        return "Untuk satuan tertentu, gunakan filter satuan di portal. Anda juga dapat bertanya untuk “satuan saya”.\n"
            . waPortal('/app/dashboard');
    }
    if (!array_key_exists($profession,$roles)) {
        return 'Sebutkan profesinya, misalnya dokter, dokter gigi, dokter spesialis, atau perawat.';
    }
    if ($query['own'] && $actor['role_code'] === 'super_admin') {
        $hasScope = qu('SELECT EXISTS(SELECT 1 FROM user_unit_scopes WHERE user_id=?)
            OR EXISTS(SELECT 1 FROM user_facility_scopes WHERE user_id=?)',[$actor['id'],$actor['id']])->fetchColumn();
        if (!$hasScope) {
            return 'Akun ini belum memiliki satuan atau fasilitas khusus. Tanyakan “Berapa dokter aktif di cakupan akun saya?” untuk laporan global administratif.';
        }
        $actor['role_code'] = 'scoped';
    }
    [$where,$args] = scope($actor,'p.unit_id','p.facility_id');
    if ($roles[$profession] !== null) {
        $where .= ' AND pr.code=?';
        $args[] = $roles[$profession];
    }
    if ($profession === 'dokter spesialis') {
        $where .= ' AND EXISTS (SELECT 1 FROM personnel_specialties s WHERE s.personnel_id=p.id)';
    }
    $count = qu("SELECT COUNT(*) FROM personnel p JOIN professions pr ON pr.id=p.profession_id
        WHERE p.active=1 AND p.archived_at IS NULL AND $where",$args)->fetchColumn();
    return waMeasured("$count $profession aktif.",$actor,'status saat ini');
}

function waCatalogue(string $kind, string $term): array
{
    $source = match ($kind) { 'training'=>'training_types','specialty'=>'specialties' };
    if (mb_strlen($term) < 2) {
        return [];
    }
    $exact = qu("SELECT id,name FROM $source WHERE active=1 AND (code=? OR name=?) ORDER BY id LIMIT 6",[$term,$term])->fetchAll();
    return $exact ?: qu("SELECT id,name FROM $source WHERE active=1 AND LOCATE(?,name)>0 ORDER BY name,id LIMIT 6",[$term])->fetchAll();
}

function waTrainingPending(array $actor, array $link, array $query): string
{
    $matches = waCatalogue('training',$query['term']);
    if (count($matches) !== 1) {
        return 'Sebutkan satu nama atau kode diklat yang terdaftar, misalnya “Siapa yang belum mengikuti BTCLS?”'
            . (count($matches) > 1 ? "\nPilihan: " . implode(', ',array_column($matches,'name')) : '');
    }
    $training = $matches[0];
    [$where,$args] = scope($actor,'p.unit_id','p.facility_id');
    $count = qu("SELECT COUNT(*) FROM personnel p WHERE p.active=1 AND p.archived_at IS NULL AND $where
        AND NOT EXISTS (SELECT 1 FROM training_records t WHERE t.personnel_id=p.id AND t.type_id=?
            AND t.completed=1 AND t.archived_at IS NULL)",[...$args,(int) $training['id']])->fetchColumn();
    $details = can($actor,'personnel.read')
        ? waPortal('/app/personel',['state'=>'active','missing_training'=>(int) $training['id']])
        : waPortal('/app/pelatihan');
    return waMeasured("$count personel aktif tidak memiliki catatan selesai {$training['name']}.\nDaftar berlogin: $details",
        $actor,'catatan tersedia sampai hari ini');
}

function waExpiry(array $actor, array $link, array $query): string
{
    $days = $query['days'];
    if (!is_int($days) || $days < 1 || $days > 90) {
        return 'Sebutkan periode 1–90 hari, misalnya “Sertifikat apa yang habis dalam 30 hari?”';
    }
    [$where,$args] = scope($actor,'p.unit_id','p.facility_id');
    $from = day();
    $until = day($days);
    $count = qu("SELECT COUNT(*) FROM training_records t JOIN personnel p ON p.id=t.personnel_id
        WHERE p.archived_at IS NULL AND t.archived_at IS NULL AND t.expires_on BETWEEN ? AND ? AND $where",
        [$from,$until,...$args])->fetchColumn();
    $details = waPortal('/app/pelatihan',['certificate'=>'soon','expiry_days'=>$days]);
    return waMeasured("$count catatan sertifikat akan habis. Tanggal kosong tidak dihitung.\nDaftar berlogin: $details",$actor,"$from s.d. $until");
}

function waTrainingReport(array $actor, array $link, array $query): string
{
    if (!in_array($query['period'],['previous','current'],true)) {
        return 'Pilih periode “bulan ini” atau “bulan lalu” untuk rekap diklat.';
    }
    $month = today()->modify($query['period'] === 'previous' ? 'first day of last month' : 'first day of this month');
    $from = $month->format('Y-m-d');
    $until = $query['period'] === 'previous' ? $month->modify('last day of this month')->format('Y-m-d') : day();
    [$where,$args] = scope($actor,'p.unit_id','p.facility_id');
    $row = qu("SELECT COUNT(*) AS total,COALESCE(SUM(t.completed=1),0) AS done_count
        FROM training_records t JOIN personnel p ON p.id=t.personnel_id WHERE p.archived_at IS NULL
        AND t.archived_at IS NULL AND t.started_on BETWEEN ? AND ? AND $where",[$from,$until,...$args])->fetch();
    $details = waPortal('/app/pelatihan',['from'=>$from,'until'=>$until]);
    return waMeasured("Rekap berdasarkan tanggal mulai: {$row['total']} catatan, {$row['done_count']} tercatat selesai.\nDaftar berlogin: $details",
        $actor,"$from s.d. $until");
}

function waFacilities(array $actor, array $link, array $query): string
{
    $matches = waCatalogue('specialty',$query['term']);
    if (count($matches) !== 1) {
        return 'Sebutkan satu spesialisasi yang terdaftar, misalnya “Fasilitas mana yang memiliki spesialis jantung?”'
            . (count($matches) > 1 ? "\nPilihan: " . implode(', ',array_column($matches,'name')) : '');
    }
    $specialty = $matches[0];
    $filters = mapFilters(['specialty_id'=>(string) $specialty['id']]);
    $rows = mapRows($actor,$filters);
    qu('UPDATE whatsapp_links SET map_specialty_id=?,context_until=UTC_TIMESTAMP()+INTERVAL 20 MINUTE
        WHERE id=? AND active_slot=1',[(int) $specialty['id'],$link['id']]);
    $list = array_column(array_slice($rows,0,5),'name');
    $count = count($rows);
    $links = waPortal('/app/peta-sebaran',['specialty_id'=>(int) $specialty['id']]);
    $listed = $list ? "\n" . implode("\n",$list) : '';
    $total = $count === 1000 ? 'Setidaknya 1.000' : (string) $count;
    return waMeasured("$total fasilitas mencatat tenaga aktif dengan spesialisasi {$specialty['name']}.$listed\nPeta: $links",
        $actor,'status saat ini');
}

function waMapLink(array $actor, array $link, array $query): string
{
    if (!$query['follow']) {
        return "Peta fasilitas sesuai cakupan akun:\n" . waPortal('/app/peta-sebaran');
    }
    if (!$link['map_specialty_id'] || $link['context_until'] <= gmdate('Y-m-d H:i:s')) {
        return 'Fasilitas mana yang dimaksud? Sebutkan spesialisasinya untuk menemukan fasilitas pada peta.';
    }
    return "Peta hasil spesialisasi terakhir. Portal akan memeriksa ulang cakupan Anda:\n"
        . waPortal('/app/peta-sebaran',['specialty_id'=>(int) $link['map_specialty_id']]);
}

function waPriority(array $actor, array $link, array $query): string
{
    if ($query['period'] !== 'today') {
        return 'Rekap prioritas chatbot tersedia untuk hari ini. Buka portal untuk riwayat kejadian.';
    }
    [$where,$args] = scope($actor,'f.unit_id','f.id');
    $start = today()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $end = today()->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $row = qu("SELECT COUNT(*) AS total,COALESCE(SUM(rd.event_id IS NULL),0) AS unread
        FROM map_events e JOIN facilities f ON f.id=e.facility_id
        LEFT JOIN map_event_reads rd ON rd.event_id=e.id AND rd.user_id=?
        WHERE e.active_slot=1 AND f.active=1 AND e.severity IN ('warning','critical')
        AND e.created_at>=? AND e.created_at<? AND $where",[$actor['id'],$start,$end,...$args])->fetch();
    return waMeasured("Ada {$row['total']} kejadian peta prioritas aktif hari ini; {$row['unread']} belum dibaca.\nPeta: "
        . waPortal('/app/peta-sebaran'),$actor,day());
}

function waNotices(array $actor, array $link, array $query): string
{
    $count = qu('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL' . notificationLiveSuffix(),[$actor['id']])->fetchColumn();
    return waMeasured("$count pemberitahuan akun belum dibaca. Detail tersedia di portal:\n"
        . waPortal('/app/notifikasi'),$actor,'status saat ini');
}

function waTransfers(array $actor, array $link, array $query): string
{
    if ($query['period'] !== 'current') {
        return "Buka portal untuk riwayat mutasi:\n" . waPortal('/app/mutasi');
    }
    $approver = can($actor,'transfers.approve');
    $where = $approver ? '1=1' : 't.requester_id=?';
    $params = $approver ? [] : [$actor['id']];
    $row = qu("SELECT COALESCE(SUM(t.status='pending'),0) AS pending,COALESCE(SUM(t.status='approved'),0) AS approved
        FROM unit_transfers t WHERE $where",$params)->fetch();
    $label = $approver ? 'Pengajuan dalam antrean persetujuan' : 'Pengajuan akun Anda';
    return waMeasured("$label: {$row['pending']} menunggu, {$row['approved']} disetujui dan belum diterapkan.\nPortal: "
        . waPortal('/app/mutasi'),$actor,'status saat ini');
}

function waPersonnelPortal(array $actor, array $link, array $query): string
{
    return "Detail personel dan dokumen tersedia melalui portal berlogin:\n" . waPortal('/app/personel');
}

function waClinicalPortal(array $actor, array $link, array $query): string
{
    if (clinicalSchemaReady() && clinicalWorkplaces($actor) !== []) {
        return "Buka E-RM/Rikkes melalui portal. Pemeriksaan klinis dilakukan kembali di sana:\n" . waPortal('/app/erm');
    }
    return 'E-RM/Rikkes memerlukan penugasan klinis di portal. Hubungi supervisor klinis fasilitas Anda.';
}

function waHelp(): string
{
    return "Saya dapat membantu ringkasan personel aktif, diklat, sertifikat, fasilitas/spesialisasi, mutasi, kejadian prioritas hari ini, dan navigasi E-RM/Rikkes.\n"
        . 'Coba “Berapa dokter aktif di satuan saya?” atau “Buatkan rekap diklat bulan lalu”.';
}

function waStopped(): string
{
    return 'Pemberitahuan peta WhatsApp dihentikan. Aktifkan kembali (opt-in) melalui pengaturan portal.';
}
