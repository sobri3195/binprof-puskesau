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

Paket ini belum menjalankan modul klinis. Peran administratif saat ini tidak memiliki hak akses klinis.
