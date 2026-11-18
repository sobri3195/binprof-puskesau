# Peta database

Impor `private/database/binprofkes.sql` lalu jalankan migrasi `002_interactive_maps.sql`, `003_clinical_records.sql`, `004_whatsapp_cloud.sql`, `005_personnel_records.sql`, dan `006_personnel_retirements.sql`. SQL awal dibuat dari spesifikasi lampiran. Daftar skema lampiran menjadi referensi desain karena berkas SQL asal tidak tersedia.

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


## Migrasi 003

- `user_clinical_privileges` mengikat akun dan fasilitas ke kemampuan `can_read`, `can_write`, `can_export`, dan `can_approve`. Supervisor mengesahkan kewenangan akun lain. `patient_care_assignments` mengikat penugasan dan masa aktif pada pasien tertentu.
- `clinical_patients` menyimpan identitas klinis stabil yang ditautkan ke `personnel`. Mutasi mengubah satuan/fasilitas personel dan mempertahankan histori kunjungan.
- `clinical_access_requests` dan `clinical_access_grants` merekam sumber, tempat pemohon bertugas, tujuan, lingkup, pemohon/peninjau, waktu keputusan, kedaluwarsa, dan pencabutan. Justifikasi dienkripsi.
- `clinical_encounters` menyimpan fasilitas, jenis, tanggal, dan nomor versi terkini. `clinical_record_versions` menyimpan payload terenkripsi dan status `draft`, `signed`, atau `amended`. Perubahan memperoleh nomor versi baru dan menyimpan penulis serta waktu.
- `clinical_documents` mengikat versi klinis ke metadata `file_objects` dan berkas terenkripsi di direktori privat. Penandatanganan dan amendemen mempertahankan tautan lampiran. Unduhan memerlukan otorisasi pembacaan ulang.
- `clinical_access_logs` mencatat aktor, fasilitas yang diizinkan, pasien bila sah, aksi, alasan terbungkus, hasil, dan ID permintaan. Layar supervisor menampilkan metadata tanpa membuka uraian klinis.

## Migrasi 004

- `whatsapp_link_challenges` menyimpan hash kode sekali pakai, klaim pengirim terenkripsi, versi sesi, dan kedaluwarsa. `whatsapp_links` mengikat akun, saluran WABA, hash BSUID/nomor, identitas tujuan yang terenkripsi, versi sesi, dan persetujuan pemberitahuan.
- `whatsapp_inbox` menyimpan data input minimum yang terenkripsi sampai diproses. ID provider unik dan urutan antrean mendukung pengulangan callback.
- `whatsapp_outbox` menyimpan intent/argumen yang terenkripsi sampai pengiriman selesai, status, jumlah upaya, ID provider, dan batas waktu. Tautan dan kejadian peta menjadi referensi untuk pemeriksaan ulang.
- `whatsapp_receipts` menyimpan callback terdeduplikasi dan waktu status. `whatsapp_map_cursor` mengikuti revisi `map_events` untuk mengantrekan pemberitahuan dari kejadian yang sama.

## Migrasi 005

- `personnel_record_categories` menyediakan jenis Kasus, Sertifikat, Petikan dan jenjang pendidikan. Ijazah memakai jenjang dari kelompok pendidikan. `personnel_case_permissions` mengikat izin Kasus yang ditetapkan khusus ke akun.
- `personnel_cases` mengikat personel, unit penanganan, petugas, tahap dan keputusan. `personnel_case_updates` mencatat timeline tahap dan tindak lanjut terpisah.
- `personnel_certificates` menyimpan sertifikat tersendiri atau metadata yang berelasi unik dengan `training_records`. FK gabungan `(training_id,personnel_id)` mengikat pemilik yang sama. Tanggal akhir dan lampiran sumber tetap pada Diklat. Metadata verifikasi mencatat versi sumber yang diperiksa.
- `personnel_decree_extracts` menyimpan petikan, referensi ke mutasi/riwayat jika ada, serta tautan ke dokumen pengganti. `personnel_education` membedakan `military` dan `general` dan merelasikan ijazah atau sertifikat milik personel yang sama.
- `personnel_diplomas` menyimpan informasi ijazah. `personnel_record_attachments` mengaitkan `file_objects` ke satu induk bertipe yang memiliki FK. Penayangan ijazah melalui riwayat pendidikan menggunakan file sumber itu juga.
- `personnel_record_history` menyimpan versi dan perubahan per kategori, waktu, aktor, dan request ID. Endpoint audit administratif umum tidak membaca riwayat Kasus.

## Migrasi 006

- `personnel.retired_on` menyimpan TMT yang sudah diterapkan. `notifications.cancelled_at` memisahkan pembatalan pengingat dari status baca.
- `retirement_rules` menyimpan versi dan validitas aturan yang bersumber. `personnel_retirements` menyimpan pengajuan, status, TMT, dan snapshot kedinasan. Satu pengajuan aktif per personel.
- `personnel_retirement_versions` menyimpan riwayat versi dan keputusan. `retirement_document_links` merekam hubungan dengan Petikan. File tetap dikelola pada modul Petikan.
- `retirement_checklist_templates` menyediakan master. `personnel_retirement_checklists` menyimpan salinan persyaratan dan hasil pengurusan per pengajuan.
- `personnel_account_links` menyimpan bukti identitas akun-personel terverifikasi. `retirement_assignments` menyimpan penugasan dengan satu cakupan dan tanggal kedaluwarsa.
- `retirement_reminder_preferences` menyimpan opt-in dan periode per pengguna.
