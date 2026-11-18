# Chatbot WhatsApp BINPROFKES

Integrasi ini memakai WhatsApp Business Platform Cloud API Meta. Alur menggunakan PHP native dan database BINPROFKES yang sama. Setelan bawaan nonaktif.

## Persiapan Meta dan hosting

1. Backup database, lalu terapkan migrasi `004_whatsapp_cloud.sql` setelah migrasi 003. Pada instalasi baru, impor skema dasar dan seluruh migrasi secara berurutan.
2. Siapkan aplikasi Meta dengan produk WhatsApp, WhatsApp Business Account (WABA), dan nomor bisnis Cloud API. Catat WABA ID dan **Phone Number ID** dari dashboard. **Phone Number ID** berbeda dari nomor E.164 yang dapat dihubungi pengguna.
3. Gunakan HTTPS di subdomain BINPROFKES dengan document root `binprof/public`. Aktifkan ekstensi PHP `curl`, `sodium`, `mbstring`, serta `pdo_mysql`. Host worker perlu akses HTTPS ke `graph.facebook.com`. Gunakan versi Graph API yang masih didukung akun.
4. Di `private/config/config.local.php`, isi blok `whatsapp` sesuai contoh konfigurasi. Isi `business_account_id`, `phone_number_id`, dan `graph_version`. `display_phone` bersifat opsional dan hanya digunakan untuk tautan portal ke `wa.me`.
5. Simpan secret dalam konfigurasi privat atau injeksi lingkungan `BINPROF_WA_APP_SECRET`, `BINPROF_WA_VERIFY_TOKEN`, dan `BINPROF_WA_ACCESS_TOKEN` untuk proses PHP web dan CLI. Gunakan token Cloud API dengan `whatsapp_business_messaging` yang terikat ke WABA yang benar. `app_secret` adalah App Secret Meta. Buat `verify_token` acak yang sama pada portal Meta dan konfigurasi privat. Pisahkan token verifikasi dari access token. Jangan masukkan secret ke Git.
6. Aktifkan `enabled` dan daftarkan callback `https://binprof.puskesau.com/webhooks/whatsapp`, atau gunakan URL fallback `https://binprof.puskesau.com/index.php?r=/webhooks/whatsapp` pada instalasi tanpa rewrite. Aplikasi memverifikasi challenge GET dan HMAC SHA-256 atas body POST asli. Keluarkan query string webhook dari access log server agar token challenge tidak tercatat. Pilih field `messages` pada webhook dan pastikan aplikasi tersubskripsi ke WABA yang benar.
7. Tambahkan jadwal cPanel setiap menit sesuai lokasi PHP dan proyek:
   ```cron
   * * * * * /usr/local/bin/php /home/AKUN/binprof/tools/whatsapp-worker.php
   ```
   Worker memakai satu lock database, memproses hingga 40 pesan masuk dan enam upaya kirim per siklus. Pastikan CLI memakai konfigurasi privat serta injeksi lingkungan yang sama dengan proses PHP web. Portal menampilkan status konfigurasi dan riwayat untuk akun sendiri.

Dokumentasi resmi Meta [Cloud API](https://developers.facebook.com/docs/whatsapp/cloud-api/) dan [webhook Cloud API](https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks/).

## Penautan, izin, dan jenis jawaban

- Masuk ke **WhatsApp** di BINPROFKES, buat kode, lalu kirim `TAUT KODE` sebagai pesan WhatsApp ke nomor bisnis. Muat ulang portal, cocokkan petunjuk identitas yang disamarkan, dan pilih **Konfirmasi penautan**. Kode berlaku 10 menit, memiliki satu klaim, dan hanya dapat dikonfirmasi sekali oleh akun pembuat.
- Sistem memakai `messages[].from_user_id` (BSUID) bila disediakan, dan `messages[].from` untuk pesan yang hanya memiliki nomor. Bila BSUID hadir, nomor yang sama tidak dapat menggantikannya. Jika Meta mulai melaporkan BSUID untuk tautan yang sebelumnya memakai nomor, tautkan ulang. Penggantian WABA/Phone Number ID atau perubahan versi sesi akun memerlukan penautan baru.
- Chatbot memakai registry intent Bahasa Indonesia dan query PDO berparameter. Ketika data/permintaan ambigu, bot meminta rincian atau mengarahkan ke portal. Data administratif dihitung saat jawaban akan dikirim. Identitas personel, lampiran privat, rincian E-RM dan Rikkes tersedia melalui portal dengan otorisasi ulang. Nama profil WhatsApp tidak digunakan sebagai identitas BINPROFKES.
- Rekap diklat memakai tanggal mulai yang tercatat. “Belum mengikuti” menunjukkan personel aktif tanpa catatan selesai untuk jenis diklat tersebut. Ringkasan dan filter portal menjelaskan periode dan cakupan data.
- Uji coba di staging memakai akun dan kontak uji resmi Meta yang dikendalikan organisasi dan telah disetujui untuk pengiriman. Suite otomatis dalam repo memakai transport palsu lokal.

## Template dan preferensi pemberitahuan peta

Buat template **yang sudah disetujui Meta** tanpa variabel. Isi `map_template` dan `template_language` dengan nama dan bahasa yang persis sama. Contoh isi generik untuk pengajuan template:

> Ada pembaruan peta BINPROFKES. Buka portal untuk melihat kejadian dalam cakupan akun Anda. Balas STOP untuk menghentikan pemberitahuan.

Template boleh memuat tombol URL statis ke portal BINPROFKES. Bot tidak mengirim nama, nomor identitas, rincian kejadian, atau data medis pada template. Ikuti aturan kategori serta persetujuan template yang berlaku bagi akun Meta.

Pengguna mengaktifkan opt-in di portal. Kategori, bisu, dan jam tenang mengikuti preferensi notifikasi peta yang sama. Balas `STOP` untuk menonaktifkan pemberitahuan proaktif. Untuk mengakhiri akses chatbot, gunakan **Putuskan tautan** di portal. Antrean mengecek ulang izin, versi akun, scope, dan opt-in. Kejadian yang ditunda pada jam tenang dapat kedaluwarsa sebelum dikirim. Pencabutan akses mencegah pengiriman yang masih mengantre. Pesan yang sudah diajukan ke provider mengikuti status provider.

## Retry dan status

Jawaban bebas dikirim selama jendela layanan 24 jam sejak waktu pesan pengguna. Pemberitahuan peta memakai template. Penerimaan sementara webhook hanya berarti data masuk tersimpan. Penerimaan Cloud API dengan ID pesan berbeda dari status `sent`, `delivered`, atau `read` yang diterima melalui callback. Kegagalan provider dan hasil yang belum pasti disimpan sebagai status tersendiri.

Worker menjadwalkan ulang kegagalan sambungan sebelum pengiriman dan penolakan sementara yang jelas dari provider. Backoff dimulai dari satu menit, menghormati `Retry-After` numerik, dan berhenti pada lima upaya. Timeout setelah pengajuan, respons gateway ambigu, dan interupsi worker masuk status belum pasti. Status ini dapat direkonsiliasi melalui callback dengan ID pesan atau ID korelasi. Pengiriman dengan hasil belum pasti tidak diulang otomatis.

Inbox, outbox, dan callback menggunakan database untuk deduplikasi. Konten penautan dan pesan disimpan terenkripsi selama masih dibutuhkan dan dihapus setelah pemrosesan. Metadata inbox, outbox, dan callback dipertahankan sekitar 30 hari. Hak akses akun, tautan, dan scope divalidasi sebelum jawaban disusun atau template diajukan ke Meta.
