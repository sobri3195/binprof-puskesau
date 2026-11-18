# Backlog pengembangan BINPROFKES

Gunakan ID untuk diskusi dan pelacakan. Prioritas dan dependensi mengikuti [roadmap](ROADMAP.md). Item ini **menunggu peninjauan**, termasuk konfirmasi daftar fitur asli. Kompleksitas relatif tercatat per fitur di [spesifikasi](SPESIFIKASI.md).

| ID | Pekerjaan yang dapat ditinjau | Dependensi | Bukti selesai yang diminta |
| --- | --- | --- | --- |
| F01a | Definisikan katalog aturan mutu, sumber yang dapat diperiksa, pemilik, dan status pemeriksaan. | D1, D2 | Daftar aturan berversi dan fixture dengan status lulus, gagal, belum dinilai dan tidak berlaku. |
| F01b | Rancang penyimpanan hasil, scan terukur, deduplikasi, dan penyelesaian melalui modul sumber. | F01a | Ulang scan tanpa duplikasi, verifikasi ulang menutup kasus, dua unit tidak bisa melihat kasus satu sama lain. |
| F01c | Rancang layar steward responsif, indikator Dashboard/Maps, dan pengingat generik. | F01b, D5 untuk kanal tambahan | Hasil kosong/parsial terlihat, detail dicek ulang, dan pesan tidak berisi penanda individu. |
| F02a | Validasi headcount, grain, periode, dokumen kebutuhan dan mandat pemilik target. | F01a, D3 | Matriks definisi diketahui, termasuk contoh target kosong dan target resmi nol. |
| F02b | Rancang versi kebutuhan dan snapshot dengan jejak sumber serta pemeriksaan periode tumpang tindih. | F01b, F02a | Fixture snapshot ulang menghasilkan nilai sama, termasuk perubahan status dan spesialisasi ganda. |
| F02c | Rancang matriks SDM dan kartu mobile, link Dashboard/Maps, dan review versi sah. | F02b | Cakupan dan periode terlihat, selisih `null` saat basis tidak tersedia, laporan versi tertentu bisa ditelusuri. |
| F03a | Petakan urutan tahap, cakupan asal/tujuan, mandat, delegasi dan versi kebijakan. | F02a, D4 | Contoh jejak mutasi sah, kasus penolakan diri sendiri, route lintas unit, serta pilihan transisi pengajuan lama. |
| F03b | Rancang engine persetujuan administratif transaksional dan relasi ke Mutasi/SDM. | F02b, F03a | Klik dua kali atau keputusan paralel tidak menggandakan hasil. Objek berubah memerlukan versi baru. |
| F03c | Rancang antrean peninjau desktop/mobile, diff, notifikasi dan penerapan efektif. | F03b | Setiap transisi dan penolakan scope terlacak. Cron dan retry tidak menggandakan pemindahan atau riwayat. |
| F04a | Sepakati asal temuan, klasifikasi, pemilik, tenggat, izin bukti dan verifikator. | F01a, D4 | Daftar sumber dan alasan penutupan/pembukaan ulang, tanpa duplikasi antar sumber. |
| F04b | Rancang kasus, tindakan, FK bukti privat, dan pengujian verifikasi independen. | F03b, F04a | Pemohon/pelaksana tanpa mandat tidak menutup kasus. Unduhan privat ditolak di luar scope. |
| F04c | Rancang tampilan tindak lanjut, indikator Dashboard/Maps, dan pengingat. | F04b | Umur/ketertinggalan hanya bila tenggat ada, tampilan mobile dapat membuka detail, notifikasi baca tidak menutup kasus. |
| F05a | Validasi indikator, periode, audiens, klasifikasi, dan pengesahan rujukan briefing. | F01–F04, D5 | Kartu definisi menunjukkan pemilik, versi, cakupan dan alasan status tidak tersedia. |
| F05b | Rancang versi briefing, pratinjau, reviewer, publikasi, dan artefak privat. | F05a | Indikator direkonstruksi dari snapshot, revisi menyisakan histori, dan paket tidak bocor saat scope berubah. |
| F05c | Rancang akses mobile pimpinan, tautan Dashboard/Maps, pengumuman portal, dan opsi WhatsApp. | F05b, D5 | Dokumen dan deep link diperiksa ulang. Uji periode tidak sebanding tidak membentuk tren. |
| F06a | Tentukan ruang skenario, data kelayakan, batas penempatan dan cara serah ke Mutasi. | F02c, F03a, D3 | Perencana dan pemilik asal/tujuan menyetujui grain dan aturan overlap. |
| F06b | Rancang simulasi bertanggal, validasi konflik, serta layer Maps dan fallback tabel mobile. | F03c, F06a | Proyeksi perpindahan seimbang asal/tujuan dan tidak mengubah aktual. Hasil parsial jelas. |
| F06c | Rancang penyerahan skenario ke Mutasi yang idempoten dengan audit dan scope. | F06b | Duplikasi atau objek berubah ditolak, pemindahan aktual hanya mengikuti mekanisme Mutasi yang sah. |
| F07a | Tetapkan jenis bukti kompetensi, katalog persyaratan, penerbit, dan tanggal berlaku. | F01a, D2 | Otoritas meninjau contoh bukti sah, tanggal kosong, tidak berlaku, dicabut, dan kedaluwarsa. |
| F07b | Rancang relasi personel–persyaratan–bukti dan penilaian berversi. | F07a | Skema dan pengujian unduhan menunjukkan metadata minimum serta akses silang yang ditolak. |
| F07c | Rancang matriks responsif, peninjauan dokumen, pengingat, dan agregat berizin. | F07b, D5 | Card dan ekspor mengikuti periode, scope dan penyamaran sel kecil sesuai kebijakan. |
| F08a | Definisikan sumber jadwal, kuota, nominasi, bukti, dan arti realisasi diklat. | F07a, D4 | Daftar status program/peserta terpisah dan definisi penyebut disepakati. |
| F08b | Rancang program, daftar nominasi, validasi kuota, dan pencatatan hasil idempoten. | F03b bila ada review, F08a | Nominasi ganda ditolak, hasil tercatat sekali, dan kegiatan tanpa bukti tidak dihitung. |
| F08c | Rancang kalender desktop, daftar mobile, ringkasan periode, dan pengingat. | F08b | Dataset yang belum lengkap dan penyebut kosong tetap dapat dibaca. Data individu terjaga. |
| F09a | Tetapkan registry sumber tugas, tenggat, penerima, kategori, dan kebijakan eskalasi. | F03c, F04c, D4, D5 | Contoh tugas berasal dari objek yang sah. Status baca, delivery dan selesai berbeda. |
| F09b | Rancang proyeksi tugas serta pencatatan pengingat dengan kunci deduplikasi. | F09a | Pengulangan cron atau perubahan scope tidak menghasilkan tugas/kiriman ganda. |
| F09c | Rancang dashboard/mobile, tautan ke sumber, dan opt-in WhatsApp yang terpisah per kategori. | F09b | Uji STOP, preferensi, recall dari antrean karena izin, serta hasil provider yang belum pasti. |
| F10a | Definisikan laporan terdaftar, parameter, versi formula, retensi dan aturan artefak. | F02b, F05b, D5 | Ada manifest laporan dan fixture periode, baris yang belum tersedia, dan parameter ditolak. |
| F10b | Rancang run idempoten, penyimpanan privat, dan pemeriksaan ulang izin saat ekspor. | F10a | Eksekusi berulang pada input berversi menghasilkan manifest setara, scope dicabut menolak file lama. |
| F10c | Rancang pratinjau responsif, daftar hasil, tautan Maps/Dashboard, dan pengumuman generik. | F10b | Uji formula spreadsheet, status gagal, tautan privat, dan volume sesuai data staging yang disetujui. |

## Format keputusan untuk tinjauan

- Tandai setiap fitur atau tiket sebagai disetujui, perlu revisi, atau menunggu bukti.
- Catat penanggung jawab keputusan, rujukan data atau kebijakan, dan batas fase. Ganti judul atau lingkup usulan sementara bila daftar resmi berbeda.
- Untuk tiket implementasi, tambahkan fixture yang disetujui, perubahan permission, strategi migrasi, dan pengujian regresi sebelum mulai kode.
