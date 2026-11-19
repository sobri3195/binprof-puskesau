# Matriks hak akses BINPROFKES

Setiap baris diperiksa di server. Selain izin, fasilitas dan personel harus berada dalam cakupan akun.

| Operasi | Super Admin | Admin Satuan | Operator | Viewer |
| --- | --- | --- | --- | --- |
| Marker, agregat, detail, dan kejadian | Global | Cakupan | Cakupan | Cakupan |
| Personel fasilitas | Global | Cakupan | Cakupan | — |
| Ekspor CSV fasilitas | Global | Cakupan | — | — |
| Tandai dibaca | Akun sendiri | Akun sendiri | Akun sendiri | Akun sendiri |
| Akui kejadian | Global | Cakupan | Cakupan | — |
| Laporkan dan selesaikan kejadian | Global | Cakupan | — | — |
| Filter tersimpan dan preferensi | Akun sendiri | Akun sendiri | Akun sendiri | Akun sendiri |

Cakupan terdiri dari satuan dan/atau fasilitas yang ditetapkan. Daftar personel diminta melalui endpoint tersendiri setelah pengguna memilihnya. Respons agregat untuk Viewer tidak memuat nama, NRP, atau isi klinis. Ekspor CSV mengikuti filter peta dan berisi agregat.

Di luar peta, tiga peran selain Viewer dapat membaca serta mengubah personel dan diklat dalam cakupan, termasuk mengajukan mutasi. Ekspor administratif dan audit dibatasi ke Super Admin dan Admin Satuan. Pengelolaan akun, master, persetujuan mutasi dan pemrosesan terjadwal dari halaman dibatasi ke Super Admin.

## Akses klinis

Setiap peran administratif berawal tanpa hak klinis. Supervisor ditetapkan melalui CLI di fasilitas yang belum memiliki supervisor aktif. Setelah itu, supervisor dapat menunjuk akun aktif lain di fasilitas yang sama.

| Kemampuan | Fasilitas tempat bertugas | Fasilitas lain |
| --- | --- | --- |
| Cari pasien, buka timeline/versi, unduh lampiran | Kemampuan baca dan penugasan aktif pada pasien | Kemampuan baca dan grant baca aktif untuk pasien, fasilitas sumber dan tujuan akses |
| Catat kunjungan, Rikkes, atau amendemen; unggah lampiran | Kemampuan tulis dan penugasan aktif; lampiran hanya ditambah pada draf sendiri | — |
| Unduh ringkasan PDF | Kemampuan ekspor dan penugasan aktif | Kemampuan ekspor dan grant ekspor terpisah |
| Tinjau dan cabut grant dari fasilitas sumber | Kemampuan persetujuan pada fasilitas sumber | — |
| Tetapkan kemampuan atau penugasan perawatan | Kemampuan persetujuan di fasilitas sendiri; hanya untuk akun lain | — |
| Lihat metadata audit klinis | Supervisor di fasilitas ruang kerja | — |

Pemohon tidak dapat menyetujui permintaannya sendiri walaupun memiliki kewenangan pada kedua fasilitas. Perubahan email atau kata sandi oleh pengelola dan penonaktifan akun mencabut kemampuan klinis, penugasan, grant, dan sesi terkait. Penunjukan ulang memerlukan supervisor lain. Notifikasi pengajuan menggunakan pesan generik.

## Kanal WhatsApp

Izin `whatsapp.link` memungkinkan akun aktif menautkan identitasnya sendiri. Setiap intent membutuhkan izin modul yang sama dengan website. Ringkasan personel memakai izin ikhtisar, ringkasan diklat memakai izin baca diklat, mutasi mengikuti peserta/peninjau, dan hitungan peta memakai scope satuan atau fasilitas. Tautan ke E-RM memerlukan penugasan klinis aktif dan selalu diperiksa ulang di portal. Pemberitahuan peta juga memerlukan opt-in aktif dan preferensi kategori.

## Tab tambahan Detail Personel

Setiap kategori menggunakan `personnel.<kategori>.read`, `write`, `verify`, `download`, serta `export`. Semua tindakan memerlukan akses Personel dan scope objek. Tautan lintas tab memeriksa izin kedua kategori. Sumber sertifikat Diklat memerlukan izin Diklat yang relevan.

| Kategori | Super Admin | Admin Satuan | Operator | Viewer |
| --- | --- | --- | --- | --- |
| Sertifikat, Petikan, Ijazah, Dikmil, Dikum | Baca, tulis, verifikasi, unduh, ekspor | Izin yang sama dalam scope | Baca, tulis, dan unduh dalam scope | — |
| Kasus | Penetapan per akun | Penetapan per akun dan scope | Penetapan per akun dan scope | — |

Hak Kasus diberikan secara eksplisit lewat CLI privat. Tanpa izin baca, tab dan seluruh akses data Kasus disembunyikan. Hak penulisan kasus mencakup tahap penanganan sesuai workflow. Penutupan dan pembukaan kembali membutuhkan izin verifikasi tambahan. Riwayat dan unduhan menggunakan scope personel dan scope unit penanganan. [Panduan catatan personel](CATATAN_PERSONEL.md)

## Pensiun/Purnawirawan

Agregat dashboard dan peta memakai `retirement.summary` dan scope organisasi. Operator, Unit Admin, serta Super Admin memiliki `retirement.read` dan `retirement.write`. `retirement.verify` tersedia bagi Unit Admin dan Super Admin. `retirement.approve`, `retirement.apply`, `retirement.manage`, dan `retirement.account` diberikan pada Super Admin. Dokumen SK tetap membutuhkan izin unduh Petikan. Ketentuan dokumen, pemisahan pejabat, dan penugasan terbatas dijelaskan di [panduan Pensiun](PENSIUN.md).

## Peta Kendali Pimpinan

Peran Pimpinan mendapat `executive.home`, `executive.view`, `executive.export`, `executive.issue`, `executive.review`, `executive.verify`, `executive.work`, dan `executive.snapshot`. Peran ini mengikuti scope satuan/fasilitas akun. Super Admin mengelola sumber dan mendapat izin operasional Peta Kendali, dengan Ikhtisar sebagai landing tetap. Unit Admin dapat mengajukan dan memeriksa sumber serta menangani arahan yang ditugaskan. Operator mengerjakan arahan yang secara eksplisit ditugaskan. Matriks tugas dan verifikasi dijelaskan di [panduan Peta Kendali](PETA_KENDALI.md).

## Lima jabatan Binprof

Setelah migrasi `008_binprof_position_roles.sql`, Super Admin membuka **Pengguna**, memilih jabatan, dan menentukan satuan atau fasilitas. Setiap akun jabatan aktif memerlukan setidaknya satu cakupan. Untuk akses hanya ke fasilitas tertentu, pilih fasilitas saja. Pemilihan satuan mencakup semua fasilitas dan personel dalam satuan itu. Dua pilihan digabungkan bila keduanya diisi. Perubahan peran atau cakupan mencabut sesi lama.

| Tindakan dalam cakupan | Kapuskesau | Dirbinprof | Kasipa | Kasibata | Staf Binprof |
| --- | --- | --- | --- | --- | --- |
| Ikhtisar, peta, baca profil/diklat/pensiun, notifikasi | Ya | Ya | Ya | Ya | Ya |
| Peta Kendali sebagai halaman awal, laporan agregat, terbitkan dan tinjau arahan | Ya | Ya | — | — | — |
| Kerjakan arahan yang ditugaskan | Ya | Ya | Ya | Ya | Ya |
| Kelola personel dan diklat, catat dokumen administratif, ajukan mutasi dan pensiun | — | — | Ya | Ya | Ya |
| Verifikasi Petikan, sertifikat, Ijazah, Dikmil dan Dikum | — | — | Ya | Ya | — |
| Ajukan target, aturan dan pemeriksaan roster melalui Sumber penilaian | — | — | Ya | Ya | — |
| Verifikasi sumber penilaian dan gangguan | — | Ya | — | — | — |
| Verifikasi pengajuan pensiun dan terapkan keputusan jatuh tempo | — | Ya | — | — | — |
| Persetujuan akhir pensiun serta pengembalian atau pembatalan sesuai tahap | Ya | — | — | — | — |

Kasipa dan Kasibata mendapat kewenangan setingkat. Pembagian bidang kerja ditetapkan melalui cakupan akun. Dalam alur pensiun, Staf Binprof dapat menyiapkan pengajuan, Kasipa atau Kasibata memverifikasi Petikan, Dirbinprof memverifikasi pengajuan, lalu Kapuskesau menyetujui atau mengembalikan untuk perbaikan. Server tetap memeriksa pemisahan pengaju, pemeriksa dan pemberi persetujuan.

Kontak dan NIK memerlukan `personnel.identity`, yang belum diberikan kepada lima jabatan baru. Hak Kasus dan klinis memerlukan penetapan tersendiri. Pengelolaan akun, master, serta persetujuan dan pemrosesan mutasi tetap memakai peran Super Admin. Akun lama dapat diubah satu per satu lewat menu **Pengguna**.

## Operator Fasilitas RSAU/FKTP

1. Impor `009_facility_operator.sql` setelah 008. Di **Master data → Fasilitas**, gunakan jenis RSAU atau FKTP sesuai klasifikasi fasilitas. Migrasi menambah pilihan jenis FKTP bila belum tersedia.
2. Di **Pengguna**, buat akun per petugas dengan peran **Operator Fasilitas RSAU/FKTP**. Pilih satu fasilitas aktif dan kosongkan seluruh pilihan satuan. Fasilitas, jenis fasilitas, dan satuan induknya harus aktif.
3. Setelah login, petugas membuka **Input Fasilitas**. Pintasan mengarah ke personel, diklat, sertifikat/dokumen, pelaporan kejadian, dan arahan yang ditugaskan. Formulir personel mengisi fasilitas dari penugasan akun. Operator juga dapat mengajukan berkas pensiun fasilitasnya.

Server memeriksa fasilitas pada sesi, daftar, detail, penyimpanan, dan unduhan. Fasilitas lain dalam satu satuan tetap berada di luar cakupan. Penggantian fasilitas pada akun mencabut sesi lama. Perubahan klasifikasi atau penonaktifan fasilitas, jenis, atau satuan membuat penugasan tersebut berhenti berlaku.

Operator dapat memasukkan dan memperbarui data administratif serta melampirkan dokumen dalam cakupannya. Verifikasi dokumen, persetujuan pensiun, pengakuan/penyelesaian kejadian, dan penutupan arahan memerlukan kewenangan petugas lain. Administrasi pengguna/master, ekspor, dan NIK/kontak memerlukan izin tersendiri. Kasus dan klinis menggunakan penetapan akses terpisah.
