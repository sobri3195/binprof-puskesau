# Matriks hak akses peta

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
