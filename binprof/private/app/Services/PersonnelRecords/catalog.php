<?php
declare(strict_types=1);

const PROFILE_TABS = [
    'cases'=>'Kasus','certificates'=>'Sertifikat','decrees'=>'Petikan','diplomas'=>'Ijazah',
    'military'=>'Dikmil','general'=>'Dikum',
];
const PROFILE_TABLES = [
    'cases'=>'personnel_cases','certificates'=>'personnel_certificates','decrees'=>'personnel_decree_extracts',
    'diplomas'=>'personnel_diplomas','military'=>'personnel_education','general'=>'personnel_education',
];
const PROFILE_ATTACHMENTS = [
    'cases'=>'case_id','certificates'=>'certificate_id','decrees'=>'decree_id','diplomas'=>'diploma_id',
    'military'=>'education_id','general'=>'education_id',
];
const PROFILE_PATH = '/app/personel/detail';
const PROFILE_ACTIONS = '/app/personel/records';
const PROFILE_MASTER = '/app/master/personnel-records';
const PROFILE_VERIFY = ['pending'=>'Menunggu verifikasi','verified'=>'Terverifikasi','rejected'=>'Ditolak'];
const PROFILE_CASE_STATES = ['reported'=>'Laporan','review'=>'Verifikasi','handling'=>'Penanganan','closed'=>'Ditutup'];
const PROFILE_EDUCATION_STATES = ['planned'=>'Direncanakan','ongoing'=>'Sedang mengikuti','graduated'=>'Lulus','incomplete'=>'Tidak selesai'];
const PROFILE_DECREE_STATES = ['current'=>'Tercatat','replaced'=>'Diganti','revoked'=>'Dicabut'];
const PROFILE_VALIDITY = ['dated'=>'Berbatas tanggal','unlimited'=>'Tanpa batas pada dokumen','unknown'=>'Belum diketahui'];
const PROFILE_CASE_OPERATIONS = ['read','write','verify','download','export'];

function profileSchemaReady(): bool
{
    static $ready;
    return $ready ??= (bool) qu("SELECT 1 FROM schema_migrations WHERE version='005_personnel_records'")->fetchColumn();
}

function profileAllowed(array $user,string $tab,string $operation): bool
{
    if (!can($user,'personnel.read')) {
        return false;
    }
    if ($tab !== 'cases') {
        return can($user,"personnel.$tab.$operation");
    }
    static $permissions = [];
    $permissions[$user['id']] ??= qu('SELECT permission_code FROM personnel_case_permissions WHERE user_id=?',
        [$user['id']])->fetchAll(PDO::FETCH_COLUMN);
    return in_array("personnel.cases.$operation",$permissions[$user['id']],true);
}

function profileDemand(array $user,string $tab,string $operation = 'read'): void
{
    if (!profileSchemaReady() || !profileAllowed($user,$tab,'read')) {
        fail(404,'Bagian personel tidak ditemukan.');
    }
    if ($operation !== 'read' && !profileAllowed($user,$tab,$operation)) {
        fail(403,'Tindakan dokumen tidak diizinkan.');
    }
}

function profileEducation(string $tab): bool
{
    return $tab === 'military' || $tab === 'general';
}

function profileCategoryKinds(string $tab): array
{
    return $tab === 'diplomas' ? ['general','military'] : [$tab];
}

function profileCategories(string $tab,bool $onlyActive = true,?int $retain = null): array
{
    $categories = profileCategoryKinds($tab);
    $in = implode(',',array_fill(0,count($categories),'?'));
    $condition = $onlyActive ? ' AND (active=1 OR id=?)' : '';
    $args = $onlyActive ? [...$categories,$retain] : $categories;
    return qu("SELECT id,name FROM personnel_record_categories WHERE kind IN ($in)$condition ORDER BY sort_order,name,id",
        $args)->fetchAll(PDO::FETCH_KEY_PAIR);
}

function profileFields(string $tab): array
{
    $categoryLabel = match($tab) {
        'cases'=>'Jenis kasus','decrees'=>'Jenis petikan','military'=>'Jenis atau jenjang', 'general','diplomas'=>'Jenjang',
        default=>'Kategori sertifikat',
    };
    $fields = match ($tab) {
        'cases' => [
            'registration_no'=>['text','Nomor registrasi internal',true,100], 'title'=>['text','Judul kasus',true],
            'reported_on'=>['date','Tanggal laporan',true], 'occurred_on'=>['date','Tanggal kejadian'],
            'handling_unit_id'=>['unit','Unit penanganan',true], 'handler_id'=>['handler','Petugas penanggung jawab',true],
            'report_source'=>['text','Sumber laporan',true], 'summary'=>['note','Ringkasan faktual',true],
            'follow_up_on'=>['date','Jadwal tindak lanjut'], 'decision'=>['note','Keputusan resmi'],
            'decision_no'=>['text','Nomor dokumen keputusan',false,100], 'decision_on'=>['date','Tanggal dokumen keputusan'],
        ],
        'certificates' => [
            'title'=>['text','Nama sertifikat',true], 'document_no'=>['text','Nomor sertifikat',false,100],
            'issuer'=>['text','Lembaga penerbit'], 'activity_name'=>['text','Kegiatan terkait'],
            'issued_on'=>['date','Tanggal terbit'], 'valid_from'=>['date','Mulai berlaku'],
            'validity_kind'=>['validity','Tipe masa berlaku',true], 'expires_on'=>['date','Tanggal akhir'],
            'reminder_days'=>['number','Pengingat (hari sebelum berakhir)'],
        ],
        'decrees' => [
            'document_no'=>['text','Nomor keputusan atau surat',true,100], 'title'=>['text','Perihal',true],
            'issuer'=>['text','Instansi atau pejabat penerbit',true], 'decreed_on'=>['date','Tanggal penetapan',true],
            'effective_on'=>['date','Mulai berlaku'], 'parent_reference'=>['text','Referensi dokumen induk'],
            'service_history_id'=>['service','Riwayat kedinasan'], 'transfer_id'=>['transfer','Mutasi'],
            'summary'=>['note','Ringkasan'],
        ],
        'diplomas' => [
            'document_no'=>['text','Nomor ijazah',true,100], 'institution'=>['text','Institusi',true],
            'study_program'=>['text','Program studi atau jurusan',true], 'degree_title'=>['text','Gelar tercantum',false,100],
            'graduation_year'=>['year','Tahun kelulusan',true], 'graduated_on'=>['date','Tanggal kelulusan'],
            'issued_on'=>['date','Tanggal penerbitan'], 'legalization_note'=>['text','Informasi legalisasi',false,250],
        ],
        'military' => [
            'title'=>['text','Nama pendidikan',true], 'institution'=>['text','Lembaga penyelenggara',true],
            'cohort'=>['text','Angkatan atau gelombang',false,100], 'start_year'=>['year','Tahun angkatan',true],
            'end_year'=>['year','Tahun selesai'], 'started_on'=>['date','Tanggal mulai'], 'ended_on'=>['date','Tanggal selesai'],
            'graduated_on'=>['date','Tanggal kelulusan'], 'education_status'=>['education','Status pendidikan',true],
            'qualification'=>['text','Kualifikasi atau predikat'], 'diploma_id'=>['diploma','Ijazah'],
            'certificate_id'=>['certificate','Sertifikat'],
        ],
        'general' => [
            'institution'=>['text','Institusi',true], 'faculty'=>['text','Fakultas'],
            'study_program'=>['text','Program studi atau jurusan',true], 'degree_title'=>['text','Gelar',false,100],
            'start_year'=>['year','Tahun masuk',true], 'end_year'=>['year','Tahun selesai'],
            'graduated_on'=>['date','Tanggal kelulusan'], 'education_status'=>['education','Status pendidikan',true],
            'diploma_id'=>['diploma','Ijazah dan transkrip terkait'],
        ],
    };
    return ['category_id'=>['category',$categoryLabel,true]] + $fields + ['notes'=>['note','Catatan']];
}

function profileQuery(string $personnel,string $tab,array $extra = []): array
{
    return ['id'=>$personnel,'tab'=>$tab] + $extra;
}
