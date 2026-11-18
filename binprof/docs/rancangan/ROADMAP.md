# Roadmap rancangan BINPROFKES

Urutan ini mengikuti prioritas mutu data, kebutuhan SDM, persetujuan, tindak lanjut, dan briefing. Daftar F01–F10 adalah usulan sementara sampai daftar rujukan dikonfirmasi. Fase memakai gerbang bukti dan penanggung jawab, tanpa estimasi kalender atau target operasional yang belum disepakati. [Analisis](ANALISIS_PROYEK.md) · [Spesifikasi](SPESIFIKASI.md) · [Backlog](BACKLOG.md)

| Fase | Cakupan | Gerbang keluar dan bukti tinjauan | Peran yang diusulkan untuk meninjau |
| --- | --- | --- | --- |
| P0 Mutu data | F01, inventaris sumber, katalog aturan, scan baca-saja, perbaikan terarah di modul sumber. | Pemilik per domain dan versi aturan disepakati. Fixture kosong, gagal, dan tidak berlaku dibedakan. Satu pemindaian ulang membuktikan deduplikasi. Operator dan peninjau melihat data sesuai scope. | Pemilik Personel, Diklat, master fasilitas, dan keamanan aplikasi. |
| P1a Kebutuhan SDM | F02, rencana dan pembandingan headcount tercatat untuk unit/fasilitas per periode. | Sumber kebutuhan, grain, periode efektif, arti selisih, dan otoritas pengesahan disetujui. Versi snapshot direkonstruksi setelah mutasi pada fixture. | Perencana SDM, pemilik data unit, dan pemilik indikator. |
| P1b Persetujuan berjenjang | F03, uji coba bertahap pada jenis Mutasi yang didefinisikan. Hubungkan rencana SDM setelah mandat tahap tersedia. | Matriks tahap disahkan, kasus lintas satuan dan pemohon sendiri diuji, keputusan bersamaan idempoten, dan strategi pengajuan lama bisa ditinjau. Penerapan mutasi konsisten dengan tanggal efektif. | Pemilik Mutasi, pejabat asal dan tujuan, dan pengelola akses. |
| P1c Tindak lanjut temuan | F04, pencatatan dan penutupan kasus dari mutu, audit, atau kejadian yang dipilih. | Pemilik, tenggat, alasan, verifikasi independen, dan penyimpanan bukti bertahan pada siklus buka-kembali. Status baca dan penyelesaian dibedakan pada uji portal. | Koordinator mutu, pelaksana, verifikator, dan pengelola bukti. |
| P1d Briefing pimpinan | F05, briefing dari data yang disahkan, dengan peninjauan narasi, audiens, dan distribusi. | Tiap indikator mempunyai definisi dan snapshot. Perbandingan sebanding, data parsial diberi label, dan akses versi sebelumnya tetap dikendalikan. | Sekretariat pimpinan, pemilik indikator, dan pengelola keamanan. |
| P2 Perluasan | F06–F10. Skenario memakai F02/F03, Diklat berbasis kesenjangan memakai F07, antrean memakai status sumber stabil, dan laporan memakai snapshot yang teruji. | Uji masing-masing fitur dan kebijakan saluran selesai. Ekspor, deep link, scope Maps, dan izin yang berubah diuji. Pekerjaan independen dapat dirancang paralel sesuai dependensi. | Pemilik proses masing-masing, operator, pengelola komunikasi, dan QA. |

## Keputusan yang perlu dicatat saat peninjauan

| Keputusan | Bukti yang dibutuhkan | Pekerjaan yang dapat berjalan sambil menunggu |
| --- | --- | --- |
| D1 Validasi nama dan lingkup sepuluh fitur | Daftar rujukan dan pemilik produk yang menyetujui urutan. | Telaah baseline, contoh kasus, dan kontrol lintas fitur. |
| D2 Pemilik data, atribut wajib, dan baseline coverage | Daftar sumber, sampel aman/sintetis, pemilik per kolom/entitas, aturan pengecualian. | Pemetaan master, format aturan, rencana scan baca-saja. |
| D3 Definisi kebutuhan dan ketersediaan SDM | Dokumen sah, tanggal efektif, grain, satuan ukur, dan mandat verifikasi. | Headcount tercatat dan rancangan snapshot tanpa target. |
| D4 Kewenangan alur, temuan, dan penggunaan evidence | Matriks tahap/source-destination, pembagian tugas, tanggung jawab retensi, aturan tenggat serta bukti. | Fixture status, transisi, dan percobaan cakupan di staging. |
| D5 Publikasi, penayangan agregat dan kanal | Daftar indikator/audiens, klasifikasi, kebijakan sel kecil dan opt-in, persetujuan penggunaan template Meta. | Pratinjau berbasis fixture dan tautan privat portal. |

## Urutan perubahan skema yang diusulkan

1. **Mutu dan katalog aturan.** Tambah tabel F01 dan registry korespondensi sumber. Mulai backlog scan dari fixture. Rencanakan verifikasi hitungan dan pemilik anomali sebelum pemindaian lingkungan nyata.
2. **Kebutuhan dan snapshot.** Tambah versi rencana dan snapshot F02. Gunakan grain yang disetujui, termasuk penanganan data kosong dan rentang efektif.
3. **Persetujuan dan penanganan kasus.** Tambah definisi tahap, relasi domain dan histori keputusan F03, lalu temuan dan bukti F04. Pertahankan riwayat mutasi lama. Terapkan strategi satu sumber kebenaran selama transisi.
4. **Briefing.** Tambah versi briefing, indikator, audiens, dan metadata artefak F05 sesudah sumber dapat direkonstruksi.
5. **Perluasan.** Ajukan migrasi F06–F10 per feature flag dan dependensi. Uji beban serta performa filter sebelum memilih batch atau worker.

Setiap proposal implementasi memerlukan uji migrasi pada salinan staging, pemeriksaan relasi dan jumlah record, rencana membatalkan pengaktifan, serta prosedur restore yang disepakati. Rancangan ini berisi kontrak SQL, tanpa perubahan skema yang dapat dieksekusi. [Aturan migrasi dan akses](ANALISIS_PROYEK.md#aturan-lintas-fitur-untuk-rancangan)
