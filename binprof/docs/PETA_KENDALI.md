# Peta Kendali Pimpinan

## Pemasangan dan penggunaan

1. Cadangkan database dan penyimpanan privat. Terapkan `private/database/migrations/007_executive_dashboard.sql` sekali setelah 006. Pastikan versi 007 tercatat di `schema_migrations`.
2. Super Admin memberi peran **Pimpinan** dan mengatur scope satuan atau fasilitas melalui **Pengguna**. Login Pimpinan menuju **Peta Kendali Pimpinan**. Super Admin dapat membuka halaman dari navigasi. Pimpinan tanpa scope melihat hasil kosong.
3. Unit Admin atau Super Admin memilih fasilitas di **Kelola sumber**. Ajukan versi target per profesi, aturan penilaian, dan pemeriksaan roster. Gunakan nomor register atau rujukan administrasi yang boleh tampil pada laporan agregat. Petugas verifikasi lain dalam scope harus memeriksa setiap sumber. Migrasi tidak berisi target, stok, ambang, atau riwayat bawaan.
4. Isi periode berlaku, sumber, ambang umur roster, ambang kekurangan kritis, serta kategori sertifikat wajib dan jendela kedaluwarsa bila ketentuan tersedia. Roster yang berubah setelah pemeriksaan memerlukan attestation baru. Jika beberapa versi berlaku, gunakan versi sah dengan tanggal mulai terbaru, lalu ID terbaru.
5. Pilih komando, satuan, fasilitas, jenis, profesi atau spesialisasi. Kartu, peta, tabel, panel, prioritas, dan ekspor menggunakan himpunan hasil yang sama. Kartu menyaring fasilitas yang mempunyai nilai positif pada indikator tersebut. Kartu fasilitas menampilkan seluruh fasilitas aktif sesuai filter. Cakupan jumlah tersedia tercantum pada tiap indikator.
6. Klik prioritas atau marker untuk fokus fasilitas. Buka **Dasar penilaian, sumber, dan versi** untuk nilai aktual, target/ambang, tanggal, sumber, dan versi. Bandingkan hingga empat fasilitas. Daftar personel, Diklat, Pensiun dan Arahan membuka modul asal dengan izin dan filter fasilitas yang sesuai.

## Kamus indikator

| Indikator | Sumber dan perhitungan |
| --- | --- |
| Fasilitas aktif | Master fasilitas aktif yang terlihat berdasarkan scope dan filter organisasi/pencarian. Pada Historis, fasilitas yang mempunyai snapshot sah pada tanggal tersebut. |
| Personel aktif | Personel aktif, belum diarsipkan, dan ditugaskan ke fasilitas dalam filter. Personel tanpa fasilitas berada di luar agregat peta. Profesi dan spesialisasi membatasi populasi tenaga. |
| Dokter dan perawat | Subset populasi aktif dengan kode profesi `doctor` dan `nurse`. |
| Fasilitas dengan kekurangan | Jumlah fasilitas dengan gap positif yang dapat dinilai. Gap fasilitas adalah jumlah `max(0, target − aktual)` per profesi yang bertarget. Kelebihan pada profesi lain tidak mengimbangi kekurangan. Nilai kosong menunjukkan belum dapat dinilai. Target spesialisasi belum tersedia. |
| Menjelang pensiun 12 bulan | Perkiraan tercatat ditambah keputusan resmi yang menunggu penerapan dalam horizon tetap 12 bulan. Keputusan dan perkiraan memakai kategori terpisah. Purnawirawan yang sudah efektif tidak masuk proyeksi keluar berikutnya atau roster aktif. |
| Kompetensi | Sertifikat pada kategori wajib yang ditetapkan aturan terverifikasi. Hitung dokumen terverifikasi yang telah berakhir atau akan berakhir dalam jendela hari. Sertifikat yang memakai sumber Diklat perlu versi Diklat yang sesuai dan selesai. Pembaruan sumber tidak mengubah fakta bahwa cakupan sertifikat lain dapat belum diketahui. |
| Mutu roster | Pemeriksaan independen memakai tanggal data dan sidik roster. Status menjadi abu-abu bila pemeriksaan belum sah, roster berubah, atau umur data melewati ambang. |
| Kejadian | Kejadian aktif dari peta. Pengaruh merah/kuning memerlukan verifikasi pihak lain pada gangguan operasional. Dibaca dan diakui disimpan melalui modul peta. |
| Tindak lanjut | Jumlah arahan per tahap setelah terbit dan jumlah arahan aktif melewati tanggal tenggat. PIC, tahap, dan waktu berasal dari register arahan. |

Tampilan **Saat ini** memakai roster yang berlaku. **Proyeksi** memakai roster sekarang dikurangi keputusan resmi yang tercatat hingga tanggal horizon 3, 6, 12 atau 24 bulan. Penerimaan, mutasi, perkiraan pensiun, atau pengganti yang belum pasti tidak dimasukkan sebagai pengurang atau penambah. Proyeksi gap menggunakan target yang berlaku pada akhir horizon dan verifikasi roster pada tanggal acuan. Rencana pengganti ditandai tercatat jika ada referensi pada arahan pensiun yang sudah diterbitkan. Ketiadaan referensi berarti belum tercatat.

**Historis** membaca snapshot agregat yang disimpan lewat tombol **Simpan snapshot hari ini**. Snapshot pertama untuk setiap fasilitas pada satu tanggal dipertahankan. Pada skema ini snapshot memuat agregat seluruh profesi dan spesialisasi. Pilihan historis dengan filter profesi/spesialisasi, atau tanggal tanpa snapshot, menampilkan data belum tersedia. Snapshot tidak diisi dari roster terkini.

## Warna dan prioritas

- **Merah** untuk kekurangan yang mencapai ambang terverifikasi, gangguan kritis terverifikasi, atau arahan prioritas kritis yang sudah melewati tenggat.
- **Kuning** untuk kekurangan di bawah ambang kritis, sertifikat wajib yang mendekati akhir berlaku atau sudah berakhir, keputusan pensiun tanpa rujukan pengganti tercatat, gangguan berstatus perhatian yang terverifikasi, atau arahan lain melewati tenggat.
- **Abu-abu** untuk sumber yang kurang, tidak terverifikasi, atau kedaluwarsa. Kekurangan data roster juga bisa tampil sebagai prioritas administratif berwarna kuning.
- **Hijau** menandakan indikator yang dapat dinilai memenuhi kriterianya. Warna gabungan memakai urutan merah, kuning, abu-abu, lalu hijau. Detail tetap menampilkan keadaan tiap indikator.

Lima prioritas diurutkan menurut tingkat merah lalu kuning, kemudian gangguan, gap SDM, arahan melewati tenggat, sertifikat, pensiun, dan mutu data. Untuk kategori yang sama, tenggat terdekat didahulukan, lalu ID fasilitas. Panel memberi alasan dan tindakan yang dapat dilakukan. Perangkat, layanan, dan kesiapan klinis memerlukan sumber terverifikasi sebelum dapat dinilai.

## Arahan dan pembatasan akses

| Tugas | Peran awal |
| --- | --- |
| Buka Peta Kendali dan ekspor privat | Pimpinan dan Super Admin, sesuai scope |
| Buat/terbitkan arahan, verifikasi hasil, alihkan PIC | Pimpinan dan Super Admin, sesuai scope |
| Terima, mulai, unggah bukti, ajukan untuk diperiksa | PIC aktif dengan izin kerja dan scope fasilitas |
| Ajukan target/aturan/roster | Unit Admin dan Super Admin, sesuai scope |
| Periksa sumber dan kejadian | Pimpinan, Unit Admin, Super Admin dalam scope. Pemeriksa berbeda dari pengaju. |
| Rekam snapshot | Pimpinan dan Super Admin dalam scope |

Arahan berjalan dari **Draf → Diterbitkan → Diterima → Dikerjakan → Menunggu verifikasi → Selesai**. Menandai sudah dibaca menyimpan cap waktu per pengguna. Penerimaan dan pekerjaan tetap merupakan transisi terpisah. Reviewer selain PIC dapat mengembalikan pekerjaan atau membatalkan dengan alasan. Revisi memakai kontrol versi dan jejak audit. Unggah bukti tidak menyelesaikan arahan. Dokumen menggunakan penyimpanan privat yang sudah ada, dengan pemeriksaan scope saat unduh dan validasi PDF/JPEG/PNG. Notifikasi portal memakai teks generik. Modul ini tidak memanggil pengiriman WhatsApp atau email.

## Mode rapat dan shared hosting

- Preset bawaan dan preset pribadi menyimpan pilihan filter, layer, serta tampilan. Rekaman pribadi dibuka dengan akses pengguna dan data saat ini. Mode rapat menggunakan payload agregat server. Instruksi arahan, berkas, identitas personel, data Kasus dan klinis tidak disertakan dalam payload atau PDF. PIC ditampilkan dalam kapasitas sebagai penanggung jawab.
- Fullscreen mencakup kontrol, prioritas, detail, dan dialog perbandingan. Browser tanpa Fullscreen API memakai mode perluas. Rotasi preset dapat dijeda dan dilanjutkan, serta berhenti saat ada interaksi. Three.js dimuat ketika dipilih. Peta 2D dan tabel tetap tersedia jika WebGL gagal.
- Polling aktif berjalan tiap 25 detik. Cursor ditandatangani untuk cakupan, filter, tanggal, dan revisi sumber. Respons delta kosong hanya mengirim metadata. Polling berhenti saat tab tersembunyi, dan jeda kegagalan berlipat hingga tiga menit. Waktu sumber berasal dari catatan di fasilitas, penilaian, sertifikat, pensiun, kejadian atau arahan. Saat luring status menandai data lama. Polling pasif tidak memperpanjang idle timeout. Status sesi habis, logout, atau perubahan hak akses membersihkan tampilan.
- Deploy melalui document root `binprof/public` dengan konfigurasi PHP dan MariaDB/MySQL yang sama dengan instalasi utama. Folder `private` harus tetap privat dan dapat ditulis PHP pada lokasi storage yang ditetapkan. FPDF dan Natural Earth disajikan dari aset lokal. PDF A4 lanskap satu halaman dihitung kembali dari scope dan filter saat unduh, diberi `Cache-Control: no-store, private`, dan dicatat di audit. Tidak ada pekerjaan cron baru untuk Peta Kendali.
