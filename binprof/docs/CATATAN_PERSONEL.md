# Catatan dan dokumen Detail Personel

Enam tab baru berada di Detail Personel bersama Info Dasar, Kontak, Riwayat Kedinasan, Penghargaan, dan Karya. Identitas personel diambil dari profil. Nav mobile dapat digulir horizontal. Daftar memiliki pencarian, filter, pagination, arsip/pemulihan, dan ekspor CSV berizin. Detail menampilkan status, pemeriksa, waktu, dan riwayat perubahan.

## Persiapan

Terapkan `005_personnel_records.sql` setelah 004 pada database yang sudah dibackup. Migrasi bersifat aditif dan menambah indeks untuk mengikat metadata sertifikat ke pemilik catatan Diklat. Lima tab lama tetap berfungsi pada instalasi yang belum menerapkan migrasi 005.

Di **Master Data → Kategori catatan personel**, buat jenis Kasus, kategori Sertifikat, jenis Petikan, serta jenjang Dikmil dan Dikum sesuai daftar resmi. Ijazah memakai jenjang pendidikan yang sama. Urutan Dikum yang lebih tinggi diprioritaskan pada ringkasan, lalu tahun dan tanggal kelulusan. Urutan 0 berarti belum disetujui untuk ringkasan. Master tanpa data produksi awal memerlukan penetapan organisasi.

Hak Kasus dimulai kosong. Operator yang memiliki akses CLI privat dan mandat pengelolaan dapat mencatat penetapan dari akun Super Admin aktif. Nama pada contoh bersifat ilustratif.

```sh
cd /home/AKUN/binprof
php tools/personnel-case-access.php pengelola@example.org petugas@example.org read,write,download
php tools/personnel-case-access.php pengelola@example.org pemeriksa@example.org read,write,verify,download
php tools/personnel-case-access.php pengelola@example.org petugas@example.org -
```

Perintah mengganti seluruh daftar hak Kasus untuk akun penerima. Gunakan `read` bersama tindakan lain. Perubahan akun yang mencabut sesi juga mencabut penetapan Kasus. Izin baca Personel, scope personel, dan scope unit penanganan tetap berlaku. Pemilihan petugas memerlukan kewenangan Kasus aktif pada unit penanganan saat ditetapkan.

## Alur setiap tab

| Tab | Alur dan sumber |
| --- | --- |
| Kasus | Catat laporan, buka tahap verifikasi, minta pemeriksa lain, lanjutkan penanganan, dan catat tindak lanjut dan keputusan resmi. Penutupan dan pembukaan kembali memerlukan hak verifikasi serta tulis. Ditutup menunjukkan akhir alur penanganan. Isi dan keputusan resmi tersimpan terpisah dari status. |
| Sertifikat | Isi metadata dan pilih berbatas tanggal, tanpa batas menurut dokumen, atau belum diketahui. Tanggal kosong ditampilkan sesuai tipe. Catatan yang berasal dari Diklat memakai tanggal akhir dan `file_objects` yang sama. Lengkapi metadata untuk memverifikasi atau mengarsipkannya pada tab Sertifikat. Perubahan pada sumber Diklat mengharuskan verifikasi ulang. |
| Petikan | Simpan nomor, perihal, penerbit, tanggal, referensi dan relasi riwayat kedinasan atau mutasi. Peninjau dapat mencatat pencabutan atau menunjuk pengganti terverifikasi milik personel yang sama. Riwayat penggantian tersedia pada detail. Perubahan pangkat, jabatan, dan satuan dijalankan melalui workflow administratifnya. |
| Ijazah | Catat institusi, program, jenjang, tahun dan tanggal kelulusan/penerbitan. Arsipkan ijazah atau gunakan tautan dari riwayat pendidikan. Tipe lampiran mencakup transkrip dan bukti legalisasi. Informasi legalisasi memerlukan bukti sebelum verifikasi lulus. |
| Dikmil | Catat jenis pendidikan, angkatan, tahun, tanggal dan status. Jika tersedia, pilih ijazah yang sesuai untuk riwayat lulus serta sertifikat terkait milik personel yang sama. |
| Dikum | Catat jenjang dari master, program studi, institusi, fakultas, gelar, serta tahun dan status. Ringkasan hanya mencakup pendidikan lulus, terverifikasi, aktif, dan memakai urutan master yang disetujui. Lampiran ijazah dan transkrip dibaca melalui relasi dokumen yang sama. |

Verifikasi dilakukan oleh akun selain pengubah isi terakhir. Unggahan atau perubahan isi mengembalikan status ke menunggu verifikasi. Arsip mempertahankan catatan, audit, dan lampiran untuk pembaca yang berizin. Alur perubahan memeriksa versi terbaru agar penyimpanan paralel tidak menimpa perubahan tanpa disadari.

## Pengingat, file, dan kanal

Isi jumlah hari pengingat untuk mengaktifkan pengingat portal pada sertifikat bertanggal. Kosongkan untuk menonaktifkannya pada catatan tersebut. Tanggal akhir bersifat inklusif. Sertifikat Diklat tanpa metadata tambahan mengikuti jendela pengingat Diklat. Setelah metadata tersedia dan aktif, jendela yang tercatat pada tab Sertifikat dipakai oleh cron yang sama. Pengingat memakai pesan generik dan deduplikasi per tanggal akhir.

Unggah PDF, JPG, atau PNG hingga 10 MB melalui controller. Berkas memakai nama acak, penyimpanan privat, hash integritas, dan enkripsi yang sama dengan layanan dokumen. Pengunduhan membutuhkan izin kategori dan pengikatan file ke record serta personel terkait. Pratinjau inline tersedia setelah hasil pemindaian ditandai lulus oleh proses pemindaian yang disetujui. Instalasi tanpa pemindai melayani unduhan sebagai lampiran. Dokumen dan isi Kasus tetap di portal dengan izin per kategori. [Matriks izin](MATRIKS_HAK_AKSES.md) · [Peta database](PETA_DATABASE.md)
