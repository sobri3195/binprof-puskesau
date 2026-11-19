# Analisis dan pembaruan UI/UX BINPROFKES

## Cakupan dan metode

Tinjauan membandingkan `main` pada commit `ca71829` dengan tampilan baru di Chromium pada server lokal. Akun pengelola dan operator RSAU memakai data sintetis. Viewport pengukuran adalah 1440×900 dan 390×844 piksel CSS. Skenario mencakup masuk, pemulihan dari kata sandi salah, administrasi fasilitas, navigasi, dan tinjauan Peta Kendali. Data penggunaan lapangan dan telemetri produksi belum menjadi bagian pengukuran.

## Temuan dan perubahan

| Area | Temuan di baseline | Perubahan dan alasan |
| --- | --- | --- |
| Masuk | Kesalahan kredensial membawa petugas ke halaman galat terpisah. Email harus dimasukkan kembali. Globe dekoratif memuat Three.js pada layar masuk desktop. | Pesan galat tetap di formulir dan fokus menuju pesan. Email yang dimasukkan ditampilkan kembali dan kata sandi kosong. Kontrol tampil/sembunyi menggunakan tombol dengan status. Ilustrasi WebP lokal memberi identitas visual dengan pemuatan yang lebih sederhana. |
| Navigasi | Seluruh tautan ditampilkan sebagai satu daftar. Panel akun pengelola melebihi tinggi layar 900 px. Menu ponsel hanya memperlihatkan label navigasi pada keadaan tertutup. | Tautan dikelompokkan menurut pekerjaan dan tetap mengikuti izin. Di desktop daftar menu dapat digulir sendiri dengan akun/keluar di bagian bawah. Ponsel memakai kontrol Menu yang memperlihatkan merek. Escape menutup panel dan mengembalikan fokus ke pemicu. |
| Ikhtisar | Grafik dan tabel mengulang angka dari sumber yang sama. Sebaran fasilitas serta pengingat muncul setelah distribusi. | Urutkan akses cepat, angka aktif, peta, dan pengingat menurut aktivitas utama. Pengingat bisa membuka daftar terfilter. Distribusi memakai batang dengan angka yang tetap terbaca sebagai teks. Tabel lengkap tersedia ketika lebih dari enam kelompok. |
| Input RSAU/FKTP | Kelima tugas ditampilkan sebagai tombol dengan bobot visual serupa. Identitas fasilitas dan tindakan bercampur pada kotak yang panjang di ponsel. | Tampilkan identitas dan tindakan **Tambah personel** di bagian atas, lalu tugas lain sebagai tautan berlabel. Halaman data di bawah menu operator menampilkan nama fasilitas untuk menjaga konteks. |
| Diklat | Petugas yang sudah membuka profil harus berpindah ke Diklat dan mencari orang yang sama. Kondisi pencarian kosong dan tanpa hasil memakai umpan balik yang sama. | Tautan **Catat diklat** membawa peserta dari profil yang sudah dipilih. Server memeriksa scope dan status arsip lagi saat membuka formulir. Umpan balik membedakan pencarian awal, input terlalu pendek, dan hasil kosong. |
| Peta Kendali | Judul berulang dan sembilan filter tampil sebelum KPI. Tanggal historis dan periode proyeksi tampak bersamaan. Bagian awal peta berada di bawah viewport desktop. | Jadikan judul tunggal, lalu tampilkan tanggal acuan, status sumber, dan KPI. Pencarian dan mode waktu langsung tersedia. Filter rinci dapat dibuka dan menampilkan jumlah filter terpasang. Pilihan historis menampilkan tanggal snapshot, sementara proyeksi menampilkan horizon. Preset aktif dibedakan secara visual dan dengan `aria-pressed`. |
| Keterbacaan data | Warna angka KPI memberi penekanan sama pada angka dan status belum dapat dinilai. Konteks personel peta ringkasan bisa menampilkan `null` bila sumber kosong. | Nilai yang belum dapat dinilai memakai teks dan warna netral. Peta ringkasan memberi pesan bahwa data personel belum tersedia. Legenda Peta Kendali tetap menunjukkan teks kondisi bersama warna. |

## Pengukuran sebelum dan sesudah

Posisi diukur dari puncak viewport dengan `getBoundingClientRect()` setelah halaman selesai merender. Ukuran dibulatkan.

| Elemen | Viewport | Baseline | Tampilan baru |
| --- | --- | ---: | ---: |
| Awal kanvas Peta Kendali | 1440×900 | 1.257 px | 695 px |
| Tepi bawah panel akun pengelola | 1440×900 | 1.001 px | 890 px |
| Awal tombol **Tambah personel** operator | 390×844 | 279 px | 224 px |
| Awal tombol **Masuk** | 390×844 | 597 px | 457 px |

Angka menunjukkan posisi dalam fixture tersebut. Banyaknya grup menu, kondisi KPI, ukuran layar, atau filter yang dibuka dapat mengubah posisi.

## Keputusan desain

- Gunakan warna dasar hijau gelap, permukaan terang, dan aksen hijau untuk navigasi aktif serta tindakan utama. Pertahankan merah, kuning, hijau, dan abu-abu sebagai penanda kondisi fasilitas. Nilai inti, nama, dan aksi ditempatkan sebelum keterangan.
- DM Sans disajikan dari WOFF2 lokal. Teks putih pada tombol utama `#0c6655` memiliki rasio kontras 6,89:1. Teks deskripsi `#475e65` pada putih memiliki rasio 6,87:1. Pemeriksaan ini mencakup pasangan warna terpilih.
- Gunakan elemen HTML standar untuk daftar, tautan, kontrol formulir, filter lanjutan, dan navigasi ponsel. Status aktif dapat dibaca teknologi bantu. Tidak ada kebutuhan WebGL di layar masuk, dan visualisasi peta tetap memakai aset lokal aplikasi.
- Ilustrasi garis pantai dibuat untuk layar masuk dan tidak mewakili lokasi fasilitas. Salinan WebP 61 KiB dan font sekitar 37 KiB disajikan dari origin yang sama.
- Pembaruan berlaku pada layar masuk, kerangka seluruh portal, Ikhtisar/Input Fasilitas, Peta Kendali, serta pintasan diklat. Perilaku otorisasi tetap diperiksa melalui server. Uji akses menolak ID peserta fasilitas lain meski dikirim langsung.

## Validasi

Skenario Chromium memeriksa pemulihan login 401, tampil/sembunyi kata sandi, pengguliran panel menu, Escape dan fokus, pola tampilan 390 px, kondisi filter historis/proyeksi, preset aktif, dan formulir diklat dengan peserta terpilih. Pemeriksaan menangkap error JavaScript dan permintaan aset di luar origin. Suite regresi Peta Kendali juga memeriksa fullscreen, mode perluas, perbandingan fasilitas, WebGL, dan pembersihan sesi.

Lingkungan produksi, Safari, dan teknologi bantu selain pemeriksaan keyboard serta semantik HTML pada Chromium belum diuji. [Hasil pengujian lengkap](HASIL_PENGUJIAN.md)
