<?php
declare(strict_types=1);

require_once PROJECT . '/private/app/Controllers/Personnel.php';
require_once PROJECT . '/private/app/Services/PersonnelRecords/files.php';

function profileActionContext(array $user,array $input,string $operation): array
{
    $tab = choice($input,'tab',array_keys(PROFILE_TABS));
    profileDemand($user,$tab,$operation);
    $personnel = key32($input,'personnel');
    person($user,$personnel);
    return [$personnel,$tab];
}

function profileRecordSave(array $user): never
{
    [$personnel,$tab] = profileActionContext($user,$_POST,'write');
    $id = field($_POST,'record',32) === '' ? null : key32($_POST,'record');
    $saved = profileSaveRow($user,$personnel,$tab,$id);
    done('Catatan disimpan.',PROFILE_PATH,profileQuery($personnel,$tab,['record'=>$saved]));
}

function profileRecordVerify(array $user): never
{
    [$personnel,$tab] = profileActionContext($user,$_POST,'verify');
    $record = key32($_POST,'record');
    profileReviewRow($user,$personnel,$tab,$record);
    done('Hasil verifikasi disimpan.',PROFILE_PATH,profileQuery($personnel,$tab,['record'=>$record]));
}

function profileRecordArchive(array $user): never
{
    [$personnel,$tab] = profileActionContext($user,$_POST,'write');
    $record = key32($_POST,'record');
    profileArchiveRow($user,$personnel,$tab,$record);
    done('Status arsip diperbarui.',PROFILE_PATH,profileQuery($personnel,$tab,['record'=>$record]));
}

function profileRecordUpload(array $user): never
{
    [$personnel,$tab] = profileActionContext($user,$_POST,'write');
    $record = key32($_POST,'record');
    profileStoreAttachment($user,$personnel,$tab,$record);
    done('Lampiran disimpan. Pratinjau memerlukan hasil pemindaian yang lulus.',PROFILE_PATH,
        profileQuery($personnel,$tab,['record'=>$record]));
}

function profileRecordDownload(array $user): never
{
    [$personnel,$tab] = profileActionContext($user,$_GET,'download');
    $training = choice($_GET,'source',['record','training'],'record') === 'training';
    if ($training && $tab!=='certificates') {
        fail(404,'Lampiran tidak ditemukan.');
    }
    profileFetchAttachment($user,$personnel,$tab,key32($_GET,'record'),$training,key32($_GET,'file'),
        choice($_GET,'mode',['download','preview'],'download') === 'preview');
}

function profileRecordProgress(array $user): never
{
    [$personnel,$tab] = profileActionContext($user,$_POST,'write');
    if ($tab!=='cases') {
        fail(404,'Catatan tidak ditemukan.');
    }
    $record = key32($_POST,'record');
    profileCaseProgress($user,$personnel,$record);
    done('Tindak lanjut dicatat.',PROFILE_PATH,profileQuery($personnel,$tab,['record'=>$record]));
}

function profileRecordDecree(array $user): never
{
    [$personnel,$tab] = profileActionContext($user,$_POST,'verify');
    if ($tab!=='decrees') {
        fail(404,'Catatan tidak ditemukan.');
    }
    $record = key32($_POST,'record');
    profileDecreeAction($user,$personnel,$record);
    done('Status petikan disimpan tanpa mengubah profil personel.',PROFILE_PATH,profileQuery($personnel,$tab,['record'=>$record]));
}

function profileCategoryPage(array $user): void
{
    if (!profileSchemaReady()) {
        fail(503,'Migrasi catatan personel belum diterapkan.');
    }
    $kinds = array_diff_key(PROFILE_TABS,['diplomas'=>true]);
    $kind = choice($_GET,'kind',array_keys($kinds),'general');
    $id = number($_GET,'id');
    $row = $id ? qu('SELECT * FROM personnel_record_categories WHERE id=? AND kind=?',[$id,$kind])->fetch() : [];
    if ($id && !$row) {
        fail(404,'Kategori tidak ditemukan.');
    }
    [$rows,$total,$current,$per] = limitedRows('FROM personnel_record_categories','kind=?',[$kind],
        'id,code,name,sort_order,active','sort_order,name,id');
    page('Kategori catatan personel',static function () use ($kinds,$kind,$rows,$row,$total,$current,$per): void {
        echo '<section class="surface"><h2>Master jenis dan jenjang</h2>';
        getStart(PROFILE_MASTER);
        selectField('kind','Kelompok',$kinds,$kind);
        echo '<button>Tampilkan</button></form>';
        table(['Kode','Nama','Urutan','Status','Aksi'],$rows,static function (array $record) use ($kind): void {
            td($record['code'],$record['name'],$record['sort_order'],$record['active'] ? 'Aktif' : 'Nonaktif');
            echo '<td>';
            linkButton(PROFILE_MASTER,'Ubah',['kind'=>$kind,'id'=>$record['id']]);
            echo '</td>';
        });
        paginate(PROFILE_MASTER,$total,$current,$per);
        echo '</section><section class="surface"><h2>' . ($row ? 'Ubah kategori' : 'Tambah kategori') . '</h2>';
        postStart(PROFILE_MASTER . '/save',['kind'=>$kind,'id'=>$row['id'] ?? '']);
        echo '<div class="form-grid">';
        input('code','Kode',$row['code'] ?? '',required:true,max:32);
        input('name','Nama',$row['name'] ?? '',required:true);
        input('sort_order','Urutan jenjang (0 = belum disetujui)',$row['sort_order'] ?? 0,'number',true);
        selectField('active','Status',[1=>'Aktif',0=>'Nonaktif'],$row['active'] ?? 1);
        echo '</div><button>Simpan</button></form></section>';
    },$user,'/app/master','Jenjang Dikum dengan urutan lebih besar tampil lebih tinggi setelah disetujui pada master.');
}

function profileCategorySave(array $user): never
{
    if (!profileSchemaReady()) {
        fail(503,'Migrasi catatan personel belum diterapkan.');
    }
    $kind = choice($_POST,'kind',array_keys(array_diff_key(PROFILE_TABS,['diplomas'=>true])));
    $id = number($_POST,'id');
    $code = field($_POST,'code',32,true);
    $name = field($_POST,'name',160,true);
    $rank = field($_POST,'sort_order',5,true);
    $active = (int) choice($_POST,'active',['0','1']);
    if (!preg_match('/^[a-zA-Z0-9_-]+$/D',$code) || !ctype_digit($rank)) {
        fail(422,'Kode dan urutan kategori tidak valid.');
    }
    saveUnique(static function () use ($user,$kind,$id,$code,$name,$rank,$active): void {
        tx(static function () use ($user,$kind,$id,$code,$name,$rank,$active): void {
            if ($id) {
                if (!qu('SELECT 1 FROM personnel_record_categories WHERE id=? AND kind=? FOR UPDATE',[$id,$kind])->fetchColumn()) {
                    fail(404,'Kategori tidak ditemukan.');
                }
                qu('UPDATE personnel_record_categories SET code=?,name=?,sort_order=?,active=? WHERE id=?',[$code,$name,(int) $rank,$active,$id]);
            } else {
                qu('INSERT INTO personnel_record_categories (kind,code,name,sort_order,active) VALUES (?,?,?,?,?)',
                    [$kind,$code,$name,(int) $rank,$active]);
                $id = (int) db()->lastInsertId();
            }
            audit($user['id'],'profile_category','personnel_record_categories',(string) $id);
        });
    });
    done('Kategori disimpan.',PROFILE_MASTER,['kind'=>$kind]);
}
