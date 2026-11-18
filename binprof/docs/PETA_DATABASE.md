# Peta database

Impor `private/database/binprofkes.sql` lalu jalankan migrasi `002_interactive_maps.sql`. SQL awal dibuat dari spesifikasi lampiran. Daftar skema lampiran menjadi referensi desain karena berkas SQL asal tidak tersedia.

- `commands` mengelompokkan `units`. `facilities` terhubung ke satuan dan `facility_types`. Koordinat harus sepasang dan berada dalam rentang lintang/bujur yang berlaku.
- `personnel` berelasi dengan satuan, fasilitas, pangkat, korps, profesi, spesialisasi, riwayat, penghargaan, dan karya. Foreign key gabungan memeriksa satuan fasilitas. NIK dan kontak menggunakan envelope AEAD. NIK memiliki indeks HMAC pencarian tepat.
- `training_records` mengaitkan peserta dan jenis diklat; `training_documents` mengaitkan `file_objects`. Simpan file terenkripsi di storage privat.
- `users`, `roles`, `role_permissions`, `user_unit_scopes`, `user_facility_scopes`, dan `auth_sessions` menjadi sumber izin dan isolasi.
- `unit_transfers` menyimpan asal, tujuan, pemohon, peninjau, status, dan tanggal efektif. Penerapan mengunci personel dan mengubah satuan serta riwayat dalam transaksi.
- `map_events` menyimpan kejadian operasional tanpa isi bebas. `active_slot=1` menetapkan satu kejadian aktif per fasilitas dan kategori; penyelesaian mengosongkan slot dan mempertahankan histori. Perubahan status dan audit berlangsung dalam transaksi.
- `map_event_reads` menyimpan waktu baca dan memakai PK pasangan akun/kejadian. Pengakuan petugas dan penyelesaian memakai kolom aktor dan waktu tersendiri di `map_events`.
- `map_revision` memberi urutan delta yang terkomit. Perubahan master fasilitas menandai snapshot perlu diperbarui. `map_preferences` memberi versi per pengguna agar baca dan preferensi mengikuti sesi lain.
- `map_saved_views` menyimpan nama, filter, dan layer per akun. Perbandingan disimpan selama halaman terbuka.
- Tanggal kalender memakai `DATE`. Stempel waktu tersimpan dalam UTC dan ditampilkan sesuai konfigurasi. Interval tenang mengikuti zona waktu aplikasi.

Kategori kejadian memakai `operations` (Operasional), `data` (Pembaruan data), dan `training` (Diklat). Severity memakai `info`, `warning`, atau `critical`. Penghitungan hanya memakai catatan personel aktif dan nonarsip. Ketiadaan data personel/diklat dilaporkan sebagai `null`.
