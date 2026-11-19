# Hasil pengujian

Pengujian lokal memakai PHP 8.4.27, MariaDB 10.11.19, Apache 2.4.68, Chromium 156.0.8078.79, dan Playwright 1.61.1. Fixture bertanda DATA DEMO. Aplikasi, database, dan browser berjalan di lingkungan pengujian terisolasi.

| Pemeriksaan | Hasil |
| --- | --- |
| Impor skema dan migrasi 002 pada MariaDB | Lulus. Impor berurutan, relasi, versi skema, dan akun pertama diperiksa. |
| Bootstrap dan routing | Lulus. Bootstrap CLI kedua ditolak. URL bersih dan fallback `index.php?r` diperiksa. Apache project root menolak probe privat, berkas tersembunyi, SQL, dan salinan cadangan; aset dipetakan melalui `/assets/`. |
| Autentikasi dan batas satuan | Lulus. Login salah dan benar, CSRF kosong atau salah, origin asing, logout, penolakan akses detail dan dokumen di luar satuan, serta batas ekspor diperiksa. |
| Personel, diklat, dan mutasi | Lulus. Nama di-escape, konflik versi ditolak, tanggal diklat divalidasi, PDF dan XLSX memiliki format keluaran yang diharapkan, formula CSV dinetralkan, dokumen PDF valid disimpan dan diunduh, dokumen tersamar ditolak, dan penerapan mutasi memperbarui cakupan. |
| Fullscreen desktop dan mobile | Lulus. Fullscreen native 1366 px, fallback saat API ditolak, tutup melalui Escape, akses toolbar dan panel kejadian, serta lebar viewport 390 dan 360 px diperiksa. |
| Peta, radius, dan 3D | Lulus. Cluster, klik marker, detail dan muat daftar personel, filter gabungan, kesesuaian tabel/marker, radius dan label garis lurus, perbandingan maksimal empat, privasi tampilan tersimpan, inisialisasi WebGL, pelepasan canvas, dan fallback WebGL tanpa konteks diperiksa. |
| Kejadian fasilitas | Lulus. Deduplicasi, delta dan delta kosong, status baca, pengakuan, selesai, pengelolaan preferensi, pembatasan tindakan per peran, fokus fasilitas, badge, dan status luring diperiksa. |
| Notifikasi browser | Lulus untuk alur izin opt-in, penekanan pada mode bisu, dan teks generik. Pengujian menggunakan izin Chromium dan mencegat konstruktor notifikasi dalam browser untuk memeriksa payload. Tampilan notifikasi oleh sistem operasi belum diperiksa. |
| Aset lokal dan scope | Lulus. Suite browser menolak permintaan luar origin dan melaporkan nol permintaan eksternal serta nol error JavaScript. Viewer tidak menerima nama personel dan tidak bisa mengakses endpoint identitas ataupun ekspor. |

## Menjalankan ulang

1. Jalankan Apache, PHP, dan database pengujian baru dengan origin loopback. Impor skema dan migrasi 002 hingga 005, konfigurasikan origin dan DB tersebut, lalu bootstrap `admin@example.invalid` dengan kata sandi sintetis uji. Gunakan `BINPROF_TEST_URL` bila port berbeda dari 18484. Gunakan `BINPROF_TEST_PASSWORD` bila memasukkan kata sandi sintetis lain.
2. Jalankan `python3 tools/tests/smoke_admin.py` dari folder `binprof/`. Skrip menulis data sintetis sebagai fixture dasar. Gunakan database bersih setiap kali.
3. Impor `tools/tests/map_fixtures.sql` ke database pengujian yang sama. Siapkan `playwright@1.61.1` di mesin pengembangan dan jalankan `node tools/tests/maps.mjs`. Berikan lokasi modul melalui `NODE_PATH` bila diinstal di luar folder proyek dan gunakan `BINPROF_CHROMIUM` untuk path executable yang berbeda.
4. Untuk menguji mode document root alternatif, buat probe tanpa rahasia di direktori privat pada Apache staging, verifikasi penolakan, lalu aktifkan konfigurasi root. Periksa bahwa probe, ekstensi yang dilarang, dan akses langsung melalui `/public/` ditolak sementara `/ready` dan aset bekerja.

Uji penerapan di cPanel/LiteSpeed, Apache HTTPS produksi, Nginx, MySQL, PHP 8.5, browser selain Chromium, pemulihan backup dan rotasi kunci belum dilakukan. Web Push belum diimplementasikan.

## E-RM dan Rikkes

Lingkungan sama dengan tabel di atas. Pengujian klinis memakai personel dan akun DATA DEMO. Impor migrasi 003 pada database yang telah menjalankan administrasi dan peta berhasil.

| Pemeriksaan | Hasil |
| --- | --- |
| Penetapan supervisor per fasilitas | Lulus. Bootstrap kedua ditolak saat supervisor aktif sudah tersedia. Super Admin dan Viewer tanpa penunjukan ditolak. |
| Penugasan dan grant | Lulus. Penugasan sendiri dan persetujuan sendiri ditolak. Pencarian NIK tepat hanya mengembalikan ruang lingkup sah. Grant terikat fasilitas, pasien, tujuan, hak, dan waktu. Grant kedaluwarsa dan pencabutan menolak akses. |
| Catatan dan lampiran | Lulus. Draf privat, tanda tangan internal, kontrol versi, amendemen, histori, validasi PDF, dan unduh lintas fasilitas dengan grant sah diperiksa. |
| Ekspor privat | Lulus. Grant baca tidak mengizinkan ekspor. Grant ekspor yang valid menghasilkan PDF attachment dengan `Cache-Control: no-store`. |
| Mutasi, enkripsi, dan audit | Lulus. Identitas pasien bertahan setelah perpindahan satuan. Payload dan alasan akses tidak menyimpan teks uji mentah. Saat tabel audit dibuat tidak tersedia pada database uji, ekspor ditolak dengan 503. |
| Sesi dan perubahan identitas | Lulus. Penggantian kata sandi oleh pengelola mencabut sesi serta penunjukan klinis sebelumnya. |
| Regresi | Lulus. Suite administratif dan pengujian browser Chromium peta dijalankan ulang. |

Untuk mereproduksi uji klinis, jalankan smoke administratif lalu impor fixture peta pada database lokal dengan migrasi 003. Dari folder proyek:

```sh
python3 tools/tests/clinical.py seed
php tools/clinical-bootstrap.php clinical-a@example.invalid 1
php tools/clinical-bootstrap.php clinical-b@example.invalid 2
python3 tools/tests/clinical.py
```

Gunakan hanya database sintetis. Uji gangguan audit dan kedaluwarsa dilakukan pada salinan database lokal. Penggunaan operasional, integrasi eksternal, dan validasi alur internal situs referensi masih memerlukan akses sah ke staging.

## WhatsApp Cloud API

Uji integrasi dijalankan pada PHP 8.4 dan MariaDB 10.11 dengan request HTTP lokal, database sintetis, dan transport Meta palsu. Migrasi 004 diterapkan setelah skema administrasi, peta, dan klinis. Tidak ada pesan yang dikirim ke nomor nyata.

| Pemeriksaan | Hasil |
| --- | --- |
| Webhook | Lulus. Challenge GET, HMAC raw body, penolakan signature/JSON yang salah, isolasi Phone Number ID, dan replay ID provider diuji. |
| Tautan akun | Lulus. Kode sekali pakai, CSRF, konfirmasi oleh akun yang tepat, BSUID dan nomor, penolakan BSUID berbeda dengan nomor sama, serta status belum tertaut diuji. |
| Pertanyaan dan izin | Lulus. Hitungan dokter, BTCLS, sertifikat, spesialisasi/peta, prioritas, mutasi, rekap bulanan, dan navigasi klinis diperiksa. Viewer ditolak untuk data diklat dan data personal tetap di portal. |
| Antrean dan pencabutan | Lulus. Perubahan role/sesi dan putus tautan menghalangi pesan antrean. Pemberitahuan dihasilkan dari kejadian peta yang sama, memakai scope, opt-in, kategori, jam tenang, dan STOP. |
| Retry dan status | Lulus. Penolakan 429 dijadwalkan ulang, kegagalan 403 disimpan, 502 ambigu tidak diulang, callback mengubah status accepted/sent/delivered/read, dan callback terlambat tidak menurunkan status read. |

Untuk reproduksi, gunakan origin loopback, database baru, dan jalankan smoke administratif serta fixture peta. Buat blok WhatsApp sintetis dengan WABA ID `2222222222`, Phone Number ID `1111111111`, app secret `synthetic-whatsapp-webhook-fixture`, token verifikasi `synthetic-whatsapp-verification`, template `binprof_test_map_fixture`, bahasa `id` dan access token kosong. Jalankan `python3 tools/tests/whatsapp.py` dari `binprof/`. Jika PHP berjalan di kontainer, gunakan `BINPROF_WA_TEST_COMMAND` untuk menunjuk eksekusi `tools/tests/whatsapp_fixture.php` di kontainer. Fixture menolak konfigurasi non-loopback, ID produksi, dan access token yang terisi.

Verifikasi callback dan template pada WABA Meta yang sebenarnya, serta pengiriman melalui Cloud API produksi, belum dijalankan.

## Enam tab catatan Detail Personel

Migrasi 005 diuji pada PHP 8.4, MariaDB 10.11, dan Apache 2.4 dari baseline yang sudah memiliki Personel, Diklat, Mutasi, Peta, E-RM, dan WhatsApp. Seluruh data bersifat sintetis.

| Pemeriksaan | Hasil |
| --- | --- |
| CRUD dan UI | Lulus. Enam tab dapat membuat, menampilkan, mengubah, memverifikasi, mengarsipkan dan memulihkan catatan. Filter, pagination, dan riwayat diuji. Lima tab lama tetap berfungsi. |
| Kasus dan isolasi | Lulus. Hak default kosong. Uji penolakan akses tanpa penetapan, beda satuan, salah kategori, salah personel, setelah pencabutan, dan tanpa hak ekspor. Timeline dan tahap penanganan diperiksa. |
| Dokumen dan relasi | Lulus. Unggah PDF valid, tolak signature salah, periksa link satu file Diklat dan ijazah/transkrip melewati kategori yang sah. Pratinjau menolak status belum dipindai dan mengembalikan respons sandbox tanpa cache untuk fixture yang disimulasikan lulus pemeriksaan. |
| Tanggal dan verifikasi | Lulus. Tanggal belum diketahui, periode pengingat, stale row version, pembaruan sumber Diklat, dan bukti legalisasi ijazah diperiksa. Ringkasan Dikum hanya memakai data lulus dan terverifikasi dengan jenjang aktif yang diurutkan. |
| Status dan pengingat | Lulus. Pencabutan atau penggantian Petikan mempertahankan histori dan jabatan personel. Pengingat sertifikat portal dideduplikasi saat cron dijalankan ulang. |
| Chromium | Lulus pada 1366 px dan 390 px. Cek 11 tab, scroll horizontal mobile, ringkasan Dikum, serta simpan Sertifikat. Regresi Peta, administrasi, klinis, dan WhatsApp sintetis juga lulus. |

Untuk menjalankan ulang, gunakan database sintetis yang telah diisi oleh `tools/tests/smoke_admin.py` dan `tools/tests/map_fixtures.sql`, lalu terapkan migrasi sampai 005. Jalankan `python3 tools/tests/personnel_records.py` dari `binprof/`. Bila PHP ada di kontainer, gunakan `BINPROF_PROFILE_CLI` untuk menunjuk PHP dan `tools/personnel-case-access.php` di kontainer. Lanjutkan dengan `node tools/tests/personnel_records.mjs` memakai Playwright dan Chromium. Suite mengubah data serta izin pada database uji.

Pemindai malware produksi belum tersedia di pengujian. Penerapan pada hosting BINPROFKES belum dilakukan.

## Pensiun/Purnawirawan

Migrasi 006 berhasil diimpor ke MariaDB 10.11 setelah skema 005 berisi fixture sintetis. Pengujian menggunakan PHP 8.4, Apache 2.4, Chromium dan Playwright pada server lokal.

| Pemeriksaan | Hasil |
| --- | --- |
| Perkiraan dan aturan | Lulus. Versi aturan perlu pengelola kedua, kalkulasi memerlukan tanggal lahir terverifikasi, 29 Februari menyesuaikan akhir bulan, dan tanggal yang belum diketahui tetap kosong. |
| Keputusan dan checklist | Lulus. Unggah Petikan tidak mengubah status dinas. Pemeriksaan dokumen wajib, TMT hari ini, keputusan masa depan, pengembalian, pembatalan beralasan, dan perubahan Petikan sebelum penerapan diuji. |
| Cron dan riwayat | Lulus. Cron menandai keputusan yang terhalang, menerapkan yang memenuhi syarat tepat sekali, dan aman dipanggil ulang melalui cron maupun aksi manual. Kasus, Petikan, berkas, dan sertifikat lama tetap tersedia sesuai izin. |
| Roster dan agregat | Lulus. Purnawirawan dipisahkan dari nonaktif biasa serta keluar dari roster aktif. Peta menurunkan hitungan aktif dan memisahkan estimasi, keputusan dan target yang belum tersedia. |
| Dokumen, scope, dan akun | Lulus. Akses lintas satuan, unduh privat Viewer dan upaya aktivasi akun lewat menu pengguna ditolak. Sesi lama dicabut. Penugasan Viewer terbatas dapat diberikan dan dicabut. |
| Notifikasi portal | Lulus. Dua putaran cron menghasilkan satu pengingat. Penyimpanan tanpa perubahan jadwal tetap deduplikasi dan perpindahan di luar periode membatalkan pengingat. |
| Chromium desktop dan mobile | Lulus. Layer dan periode pensiun, timeline, tabel perbandingan, batas agregat Viewer, serta roster/profil pada lebar 360 px diperiksa. Regresi fullscreen native dan mode perluas, 3D, serta kejadian peta lulus. |

Jalankan suite pada database lokal sekali pakai. Setelah menyiapkan fixture administratif, fixture peta, dan migrasi sampai 006, jalankan `python3 tools/tests/personnel_records.py` lalu `python3 tools/tests/retirement.py` dari folder `binprof/`. Setel `BINPROF_PROFILE_CLI` dan `BINPROF_RETIREMENT_CLI` apabila PHP berjalan di kontainer. Jalankan `BINPROF_RETIREMENT_FIXTURE=1 node tools/tests/maps.mjs` setelah suite tersebut. Cron cPanel dan migrasi produksi belum dijalankan.

## Peta Kendali Pimpinan

Migrasi 007 berhasil diterapkan pada database sintetis MariaDB 10.11 dengan migrasi 002–006 dan fixture peta. Pengujian HTTP memakai PHP 8.4 di Apache, dilanjutkan Chromium dengan Playwright.

| Pemeriksaan | Hasil |
| --- | --- |
| Landing dan otorisasi | Lulus. Pimpinan masuk ke Peta Kendali, akun tanpa izin ditolak, cakupan fasilitas aktif membatasi API, formulir, arahan, dokumen, preset, dan snapshot. |
| KPI, sumber dan data kosong | Lulus. KPI sesuai baris peta dan tabel, target belum terverifikasi belum dinilai, perubahan roster membatalkan penilaian sebelumnya, sertifikat wajib dan kejadian kritis memakai sumber terverifikasi. |
| Proyeksi dan historis | Lulus. Keputusan resmi mengurangi proyeksi pada horizon, angka historis mempertahankan snapshot, tanggal tanpa snapshot dan dimensi riwayat yang belum diliput tetap kosong. |
| Arahan | Lulus. PIC luar scope ditolak, CSRF diperiksa, dokumen tersamar ditolak, bukti sah tetap pada tahap kerja, pengembalian memerlukan alasan, PIC tidak dapat menyelesaikan sendiri, dan reviewer menyelesaikan setelah pengajuan kembali. |
| Desktop dan mobile | Lulus di desktop 1440 px dan mobile 360 px. Fullscreen native dan mode perluas, Escape, dialog perbandingan, rotasi yang dijeda saat interaksi, mode rapat, Three.js lokal, cleanup canvas, serta fallback WebGL diperiksa. |
| Delta dan sesi | Lulus. Respons delta kosong tidak mengulang baris. Status luring menandai data lama. Logout membatalkan permintaan berikutnya dan membersihkan data serta dialog dari tampilan. |
| Ekspor dan data privat | Lulus. Ekspor menghasilkan berkas PDF A4 lanskap satu halaman dengan header privat, memakai agregat dan filter dari cakupan yang sah. Judul/instruksi arahan serta catatan personel privat tidak ikut dalam payload Peta Kendali. |

Untuk reproduksi, jalankan `tools/tests/smoke_admin.py` dan impor `tools/tests/map_fixtures.sql` pada server uji loopback sesuai bagian awal. Terapkan migrasi hingga 007. Dari `binprof/`, jalankan `python3 tools/tests/executive.py` lalu `node tools/tests/executive.mjs` dengan Playwright dan Chromium. Gunakan database sintetis yang belum memiliki data suite Peta Kendali. Lingkungan produksi dan cPanel belum diuji untuk perubahan ini.

## Lima jabatan Binprof

Migrasi 008 diuji pada MariaDB 10.11 dan PHP 8.4. Suite memakai data sintetis serta akun yang telah ada dari migrasi 001–007.

| Pemeriksaan | Hasil |
| --- | --- |
| Akun dan cakupan | Lulus. Jabatan aktif memerlukan cakupan. Kapuskesau dan Dirbinprof menuju Peta Kendali. Kasipa, Kasibata, dan Staf Binprof menuju Ikhtisar. Cakupan fasilitas saja mengizinkan pembuatan personel di fasilitas tersebut dan menolak pembuatan di tingkat satuan. |
| Izin dan perlindungan data | Lulus. Akses lintas cakupan, PDF tanpa wewenang, administrasi pengguna, serta NIK/kontak pada lima jabatan baru ditolak. Sunting data umum mempertahankan NIK/kontak yang sudah tersimpan. Pembaca lama tetap mendapat akses semula. Hak Kasus dan klinis diuji terpisah. |
| Pensiun | Lulus. Staf mengajukan, Kasipa memverifikasi Petikan, Dirbinprof memverifikasi dan menerapkan, dan Kapuskesau mengembalikan, menyetujui, atau membatalkan sesuai tahap. Percobaan melewati tahap atau wewenang ditolak. |
| Perubahan akun | Lulus. Perubahan jabatan dan cakupan mencabut sesi lama. Login baru hanya memberi akses dengan pengaturan terbaru. |

Untuk reproduksi, siapkan server dan database uji loopback dengan fixture `tools/tests/smoke_admin.py`, `tools/tests/map_fixtures.sql`, dan migrasi hingga 008. Dari `binprof/`, jalankan `python3 tools/tests/position_roles.py`.

## Operator Fasilitas RSAU/FKTP

Migrasi 009 diuji setelah 008 pada database sintetis MariaDB 10.11 dengan PHP 8.4.

| Pemeriksaan | Hasil |
| --- | --- |
| Penugasan | Lulus. RSAU dan FKTP memakai peran yang sama. Akun tanpa fasilitas, dengan cakupan satuan, dengan lebih dari satu fasilitas, atau dengan jenis lain ditolak. |
| Input dan isolasi | Lulus. Operator membuat dan menyunting personel, mencatat diklat dan sertifikat, serta mengunggah dan membaca lampiran pada fasilitas sendiri. Daftar, detail, perubahan fasilitas, dan unduhan di luar cakupan ditolak, termasuk untuk fasilitas lain dalam satu satuan. |
| Pembagian tugas | Lulus. Operator mengajukan pensiun, melaporkan kejadian, dan mengerjakan arahan yang ditugaskan. Verifikasi sertifikat, persetujuan pensiun, penyelesaian kejadian, penutupan arahan, dan ekspor ditolak. |
| Sesi | Lulus. Penggantian fasilitas akun mencabut sesi lama. Penggantian jenis fasilitas ke jenis yang tidak memenuhi syarat memblokir sesi dan login baru. |
| Formulir mobile | Lulus pada Chromium 390 px. Halaman Input Fasilitas dan pelaporan kejadian dari peta dapat digunakan. |

Untuk reproduksi uji HTTP, siapkan fixture administratif dan peta pada server uji loopback dengan migrasi hingga 009. Jalankan `python3 tools/tests/facility_operator.py` dari folder `binprof/`. Gunakan akun dan data sintetis.

## Pembaruan UI/UX

| Pemeriksaan | Hasil |
| --- | --- |
| Login dan navigasi | Lulus di Chromium. Galat 401 tetap di formulir, email terbawa, kata sandi kosong, toggle berfungsi, panel akun masuk viewport desktop, dan Escape mengembalikan fokus ke Menu di ponsel. |
| Input Fasilitas dan diklat | Lulus di Chromium 390 px. Pintasan profil membuka peserta terpilih, URL lintas fasilitas menghasilkan 404, dan halaman tidak meluber secara horizontal. |
| Peta Kendali | Lulus di Chromium desktop dan mobile. Judul tunggal, peta dalam viewport desktop, bidang periode kondisional, preset aktif, fullscreen, fallback, mode rapat, perbandingan, dan pembersihan sesi diperiksa. |
| Regresi alur | Lulus untuk smoke administratif, klinis, enam tab personel, pensiun, lima jabatan, operator RSAU/FKTP, workflow Peta Kendali, serta browser Peta Fasilitas. |
| Aset lokal | Lulus. Suite UX dan Peta Kendali tidak menerima error JavaScript atau permintaan lintas origin. |

Siapkan fixture sintetis dari suite Peta Kendali dan Operator Fasilitas pada migrasi hingga 009. Jalankan `node tools/tests/ux.mjs` dan `node tools/tests/executive.mjs` dengan Playwright dan Chromium pada origin loopback. Pengukuran dan batas pengujian dicatat di [analisis UI/UX](UI_UX.md).
