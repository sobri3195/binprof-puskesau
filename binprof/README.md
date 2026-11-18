# BINPROFKES

Sistem Informasi Bina Profesional Kesehatan TNI AU. Aplikasi PHP native dalam folder `binprof/`, dengan data MySQL/MariaDB dan aset lokal.

Rilis awal ini mencakup autentikasi administratif, personel, diklat, mutasi, master, log, dan eksplorasi fasilitas dengan Leaflet dan Three.js. Implementasi E-RM/Rikkes, pemulihan mandiri melalui email, dan integrasi eksternal masih memerlukan pekerjaan terpisah. Gunakan data sintetis untuk peninjauan.

## Instalasi di cPanel

1. Gunakan PHP 8.4 atau 8.5 dengan patch terbaru dari penyedia. Aktifkan `pdo_mysql`, `mbstring`, `sodium`, `fileinfo`, `iconv`, dan `zlib`. Tetapkan `upload_max_filesize=10M` dan `post_max_size=12M` atau lebih untuk lampiran sertifikat.
2. Unggah folder `binprof`. Tetapkan document root subdomain ke `/home/AKUN/binprof/public`. Ganti `AKUN` sesuai akun hosting. Aktifkan HTTPS di subdomain.
3. Buat database kosong dan akun migrasi melalui cPanel. Di phpMyAdmin, pilih database tersebut dan impor `private/database/binprofkes.sql`, lalu `private/database/migrations/002_interactive_maps.sql`. Pada database yang sudah memakai skema awal, buat backup dan jalankan hanya migrasi 002 jika belum tercatat. Skema situs lama memerlukan pemetaan dan migrasi data tersendiri.
4. Salin `private/config/config.example.php` menjadi `private/config/config.local.php`. Tetapkan DSN, akun runtime, origin lengkap, zona waktu, dan kunci. Buat tiga nilai berbeda untuk kunci AEAD dan dua pepper. Jalankan perintah berikut satu kali per nilai dari terminal privat. Simpan bersama konfigurasi privat dan backup terenkripsi.
   ```sh
   php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
   ```
5. Untuk berkas biasa gunakan 0644 dan direktori 0755. Beri akses hanya kepada user PHP untuk konfigurasi privat dan storage. Gunakan 0600/0700 bila PHP berjalan sebagai pemiliknya. Pastikan direktori `private/storage` dapat ditulis oleh PHP.
6. Buat akun pertama dengan CLI. Ketik kata sandi di prompt tanpa echo lalu tekan Enter.
   ```bash
   cd /home/AKUN/binprof
   read -rsp 'Kata sandi pengelola: ' BINPROF_SETUP_PASSWORD
   printf '\n'
   printf '%s\n' "$BINPROF_SETUP_PASSWORD" | php tools/bootstrap.php 'Nama Pengelola' 'pengelola@example.org'
   unset BINPROF_SETUP_PASSWORD
   ```
7. Jika SSH tidak tersedia, siapkan token acak yang kuat secara privat dan masukkan SHA-256 token ke `setup_token_hash`. Buka `/setup` melalui HTTPS dan masukkan token hanya melalui formulir. Setelah berhasil, hapus hash dari konfigurasi. Instalasi dicatat dalam database dan dikunci dengan berkas privat. Jika penulisan berkas gagal setelah transaksi selesai, perbaiki izin storage dan pulihkan berkas lock berdasarkan status instalasi di database.
8. Periksa `/ready` dan masuk. Tambahkan data master sebelum personel, fasilitas dan diklat. Super Admin dapat mereset kata sandi pengguna dari menu Pengguna; perubahan kata sandi mencabut sesi terdahulu. Gunakan prosedur pemulihan administrasi organisasi untuk pemegang akun terakhir.
9. Tambahkan cron cPanel bila diperlukan, misalnya `*/15 * * * * /usr/local/bin/php /home/AKUN/binprof/tools/cron.php`. Sesuaikan path PHP hosting. Super Admin juga dapat memilih **Proses jadwal** di halaman Mutasi.

### Routing dan webserver

Gunakan vhost Apache/LiteSpeed yang mengizinkan aturan `.htaccess` di `public/`. URL bersih ditangani front controller. Untuk server tanpa rewrite, setel `rewrite => false` dan gunakan `/index.php?r=/app/peta`. `/assets/` tetap sama dalam kedua mode.

Jika terpaksa menempatkan project root sebagai document root Apache, gunakan `.htaccess` di folder `binprof/`. Tes dokumen probe tanpa rahasia di `private/`, `docs/`, dan `tools/`, termasuk subdirektori dan ekstensi sensitif. Verifikasi bahwa halaman dan aset bisa dirutekan dan probe ditolak. Setelah itu setel `root_mode_verified => true`. Untuk Nginx, gunakan document root `public/` dan contoh kebijakan di dokumen operasional.

## Menggunakan peta

- Buka **Peta fasilitas** atau **Peta sebaran**. Gunakan filter gabungan, layer kategori/personel/kejadian, tombol layar penuh, serta daftar yang tetap tersedia saat visualisasi gagal.
- Klik lokasi tanpa marker untuk melihat koordinat, ubah radius, dan pilih fasilitas sekitar dalam hasil filter. Jarak dihitung sebagai garis lurus di permukaan bumi dan digunakan untuk perkiraan spasial.
- Pilih hingga empat fasilitas untuk dibandingkan. Kesimpulan personel dan pelatihan berlabel **Belum tersedia** jika belum ada catatan pendukung. Simpan filter dan layer sebagai tampilan pribadi.
- Gunakan **Kejadian** untuk mencari dan memfokuskan fasilitas terkait. Status baca melekat pada akun. Pengakuan dan penyelesaian dicatat sebagai tahap operasional terpisah.
- Atur kategori, bisu, dan jam tenang melalui preferensi. Aktifkan notifikasi browser secara eksplisit saat halaman digunakan. Pemberitahuan latar belakang setelah halaman ditutup memerlukan proyek Web Push tersendiri.

## Dokumen

- [Hasil pengujian](docs/HASIL_PENGUJIAN.md)
- [Matriks hak akses](docs/MATRIKS_HAK_AKSES.md)
- [Keamanan dan operasional](docs/KEAMANAN_DAN_OPERASIONAL.md)
- [Peta database](docs/PETA_DATABASE.md)
- [Dependensi](docs/DEPENDENCIES.md)
- [Analisis referensi](docs/ANALISIS_REFERENSI.md)
