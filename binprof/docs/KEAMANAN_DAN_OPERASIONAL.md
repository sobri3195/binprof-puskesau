# Keamanan dan operasional

## Server dan sesi

Setel canonical origin sesuai subdomain. HTTPS wajib di produksi. Cookie `__Host-binprof_session` memiliki Path=/, Secure, HttpOnly, dan SameSite=Lax. Sesi diputar saat masuk, memiliki batas idle dan absolut, dan dicabut setelah perubahan hak/status/kata sandi. Operasi POST memerlukan CSRF token dan Origin kanonis. Saat Origin absen server memeriksa Referer berawalan origin kanonis diikuti garis miring. Jangan memercayai header forwarding tanpa konfigurasi webserver yang membatasi proxy.

CSP membatasi script dan koneksi ke origin sendiri. `style-src-attr` mengizinkan style dinamis untuk posisi marker Leaflet. Server mengirim no-store pada halaman terautentikasi. Aset geografis, Leaflet, Three.js, dan grafik dilayani lokal.

## Peta dan notifikasi

Muat snapshot awal dengan maksimum 200 kejadian, lalu poll setiap 30 detik. Poll mengembalikan perubahan revisi dalam cakupan atau snapshot bila delta terlalu besar atau versi akun berubah. Histori selesai pada snapshot mencakup 30 hari terakhir. Koneksi terputus menaikkan interval hingga dua menit. Saat tab tersembunyi polling berhenti kecuali pengguna mengaktifkan notifikasi browser di halaman itu. Filter kategori, bisu, dan jam tenang mengatur pemberitahuan sementara. Daftar kejadian tetap dapat disaring dan ditelusuri. Toast dan notifikasi browser menggunakan teks generik. Pengiriman ketika browser atau halaman ditutup memerlukan Web Push yang belum tersedia.

Peta, tabel, dan ekspor dibatasi 1.000 fasilitas per filter. Radius membandingkan lokasi berkoordinat dalam hasil tersebut dan menampilkan hingga 25 fasilitas terdekat. Daftar identitas personel diambil setelah pengguna meminta detail dan memerlukan izin terpisah.

## Berkas dan backup

Dokumen diklat memakai storage privat, envelope AEAD, dan checksum ciphertext. Unduh melewati pemeriksaan relasi dan cakupan. Lingkungan ini belum memiliki pemindai malware. File dengan struktur yang lolos pemeriksaan dasar tetap diberi status pemindaian tidak tersedia dan dipaksa sebagai attachment. Gunakan pemindai terkelola sebelum memproses unggahan nyata.

Simpan kunci per ID, pakai ID baru untuk enkripsi baru, dan pertahankan akses ke kunci lama selama ciphertext lama masih dipakai. Lakukan backup database, konfigurasi, dan dokumen privat secara terenkripsi dengan retensi dan kebijakan akses organisasi. Uji restore di lingkungan terisolasi dengan backup kunci sebelum pemakaian data nyata.

Pisahkan akun migrasi dan runtime. Akun runtime memerlukan operasi pada tabel aplikasi. Batasi UPDATE/DELETE untuk log audit bila hosting menyediakan privilege per tabel. Log aplikasi terikat request ID dan tidak memasukkan pesan mentah dari PDO. Apache access log umumnya memuat query string. Konfigurasikan format tanpa query string untuk pemasangan yang menangani pencarian identitas administratif.

## Contoh Nginx

Gunakan document root `public/`, tetapkan PHP-FPM socket yang sesuai dengan host, dan tambahkan pengaturan sertifikat serta server_name di vhost.

```nginx
root /home/AKUN/binprof/public;
location ~ (^|/)\. { deny all; }
location ~* \.(sql|bak|log|ini|zip|swp|old)$ { deny all; }
location ~ ^/(private|tools|docs)(/|$) { deny all; }
location /assets/ { try_files $uri =404; }
location / { try_files $uri /index.php?$query_string; }
location = /index.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_param HTTPS $https;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
}
location ~ \.php(?:/|$) { deny all; }
```
