# Pensiun/Purnawirawan

## Pemasangan

Cadangkan database dan storage privat. Terapkan `private/database/migrations/006_personnel_retirements.sql` sekali setelah migrasi 005, lalu periksa baris versi `006_personnel_retirements` di `schema_migrations`. Migrasi menambah kolom, tabel, dan izin. Personel lama tetap memakai identitas, status dan relasi semula. Tanggal pensiun semua data lama tetap kosong sampai keputusan diproses melalui workflow.

## Menyiapkan aturan dan checklist

1. Super Admin membuka **Pensiun → Aturan dan checklist**. Masukkan kode, versi, kategori, usia dalam bulan, sumber resmi, dan rentang berlaku. Sumber dan kategori mengikuti ketentuan yang sudah disahkan organisasi. Migrasi tidak berisi aturan usia bawaan.
2. Super Admin lain memverifikasi versi tersebut. Tambahkan versi baru ketika ketentuan berubah. Perkiraan yang sudah tersimpan mempertahankan versi dan tanggal perhitungannya. Untuk menghitung ulang, pilih versi yang cocok pada draf atau pengajuan yang dikembalikan.
3. Tambahkan butir dokumen dan serah terima sesuai prosedur organisasi. Tandai wajib atau opsional. Pengaturan disalin pada saat draf dibuat sehingga perubahan template berikutnya tidak mengubah persyaratan pengajuan lama.
4. Operator dapat mencatat perkiraan manual dengan tanggal dan rujukan, atau mengisi tanggal lahir beserta sumbernya untuk diperiksa petugas lain. Perhitungan versi aturan memakai tanggal lahir ditambah usia dalam bulan. Jika hari yang sama tidak ada di bulan hasil, sistem memakai hari terakhir bulan tersebut. Perkiraan dapat dibiarkan kosong.

## Proses dan arsip

- Di **Detail personel → Pensiun/Purnawirawan**, buat draf, pilih PIC sah dalam cakupan personel, dan catat perkiraan atau sumbernya. Kelola SK melalui tab **Petikan**, unggah berkas di sana dan pilih Petikan tersebut di tab Pensiun. Petikan dan lampiran menggunakan ID dan jalur unduh privat yang sama. Preview mengikuti hasil pemindaian berkas dan izin Petikan yang sudah berlaku.
- Operator mengajukan draf. Unit Admin atau Super Admin yang berbeda dari pengaju/pengubah melengkapi pemeriksaan dokumen, memeriksa Petikan dalam cakupan, lalu memverifikasi keputusan pensiun. Super Admin lain yang berwenang menyetujui setelah verifikasi. Perubahan versi atau status Petikan sebelum penerapan memerlukan pengembalian dan verifikasi ulang.
- **Draf → Diajukan → Diverifikasi → Disetujui/dijadwalkan → Resmi pensiun.** Pengembalian dan pembatalan memerlukan alasan. Keputusan yang disetujui memerlukan izin persetujuan untuk dikembalikan atau dibatalkan. Pengajuan yang sudah berlaku tersimpan sebagai arsip workflow. Tanggal SK, waktu input, TMT, waktu penerapan, dan tanggal serah terima ditampilkan terpisah.
- Pada atau setelah TMT, Super Admin dapat memilih **Terapkan jika jatuh tempo**. Cron memakai validasi yang sama, termasuk Petikan yang masih berlaku, verifikator dan approver yang masih berwenang, serta butir wajib yang selesai. TMT menggunakan tanggal kalender zona waktu aplikasi. Transaksi mengunci catatan, mengisi `retired_on`, menonaktifkan status dinas, menyimpan pangkat/jabatan/satuan/fasilitas terakhir, dan mencatat audit tepat sekali. Bila prasyarat gagal, catatan tetap dapat ditinjau. Personel yang nonaktif karena alasan lain tetap dapat dibedakan dari purnawirawan.
- Riwayat dinas, penghargaan, karya, Kasus, Petikan, ijazah, diklat, dan sertifikat tetap menggunakan `personnel_id` lama. Status kasus dan sertifikat serta identitas pasien tetap dikelola pada modul sumber. Daftar arsip pengajuan dan versi hanya tersedia bagi petugas yang memiliki cakupan personel.

## Peta dan pengingat

Layer **Menjelang pensiun** memakai fasilitas dinas dan filter profesi/spesialisasi yang sama dengan hasil peta. Periode 3, 6, 12 dan 24 bulan berlaku pada tabel, panel detail dan perbandingan. Panel menampilkan perkiraan tercatat dan keputusan resmi secara terpisah. Badge administratif menghitung pengajuan yang menunggu pemeriksaan, dikembalikan, atau keputusan resmi yang jatuh tempo atau perlu ditinjau. Detail identitas hanya tersedia melalui daftar Pensiun dengan izin dan cakupan.

Perkiraan dihitung dari tanggal hari ini hingga akhir periode, termasuk kedua tanggal. Keputusan yang dijadwalkan mencakup keputusan aktif dengan Petikan sah hingga akhir periode, termasuk yang lewat TMT dan masih menunggu penerapan. Proyeksi aktif memakai jumlah aktif saat ini dikurangi keputusan resmi tersebut. Ini menghitung dampak pensiun tercatat. Nilai kosong menunjukkan belum ada roster yang cocok. Selisih terhadap target kebutuhan belum tersedia karena skema saat ini belum memuat target kebutuhan terverifikasi.

PIC dapat mengaktifkan pengingat portal untuk 1, 3, 6, 12 atau 24 bulan dari periode pengaturannya. Pengingat menggunakan preferensi dan cakupan pelaksana aktif. Kunci deduplikasi mencakup versi jadwal dan periode. Jadwal yang dipindahkan, dibatalkan, tidak lagi sah, atau keluar dari jendela pengingat membuat pemberitahuan lama tidak tampil lagi. Notifikasi portal memakai teks generik. Badge administratif peta bersumber dari status pengurusan dan diperbarui lewat sinkronisasi peta.

## Akun, scope, dan audit

| Kewenangan | Pemberian awal |
| --- | --- |
| Agregat dashboard dan peta | Super Admin, Unit Admin, Operator, Viewer, sesuai scope |
| Daftar, profil, draf, checklist, dan pengajuan | Super Admin, Unit Admin, Operator, sesuai scope |
| Pemeriksaan kelahiran, verifikasi, dan pengembalian | Super Admin, Unit Admin, sesuai scope |
| Persetujuan, penerapan manual, konfigurasi, dan akun | Super Admin |

Super Admin menautkan akun dinas dengan memilih email yang telah diperiksa dan mencatat referensi bukti. Saat diterapkan, transaksi mencabut sesi, cakupan dinas, izin Kasus, mandat klinis akun dan tautan WhatsApp yang ada. Persyaratan Super Admin aktif terakhir tetap berlaku. Profil dan data pasien tetap tersimpan. Kewenangan klinis maupun Kasus terus diperiksa melalui kebijakannya sendiri.

Penugasan pascapensiun memerlukan dasar kewenangan dan tanggal akhir. Penugasan memberi Viewer untuk satu satuan atau satu fasilitas. Sesi terdahulu tetap tercabut. Portal menolak akses setelah tanggal akhir meski pekerjaan terjadwal tertunda. Catatan versi dan log audit merekam tahap, aktor, waktu dan ID permintaan.

## Cron cPanel

Jalankan PHP CLI dengan versi dan ekstensi yang sama seperti web. Di **Cron Jobs**, contoh interval tiap 15 menit dengan path privat:

```cron
*/15 * * * * /usr/local/bin/php /home/AKUN/binprof/tools/retirement-cron.php >> /home/AKUN/binprof/private/storage/retirement-cron.log 2>&1
```

Sesuaikan path CLI dan folder proyek. Periksa izin tulis dan retensi log. Cron memproses hingga 100 keputusan dan 100 penugasan kedaluwarsa per putaran. Keluaran mencatat jumlah keputusan yang diterapkan dan jumlah yang perlu ditinjau. Jika terhalang, buka tab Pensiun dan periksa pesan workflow sebelum memproses ulang. Detail personel, identitas, dan isi dokumen tetap di portal. Jadwal mutasi yang sudah ada berjalan melalui skrip cron sebelumnya.
