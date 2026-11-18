# Dependensi

Semua komponen runtime vendored dalam folder proyek. Aplikasi di hosting memakai PHP, MySQL/MariaDB, dan webserver. Tidak ada proses npm, Composer, CDN, atau layanan tile eksternal saat aplikasi berjalan.

| Komponen | Versi | Lisensi dan sumber |
| --- | --- | --- |
| Three.js | 0.184.0 | MIT, npm `three`, [proyek upstream](https://github.com/mrdoob/three.js) |
| Leaflet | 1.9.4 | BSD-2-Clause, npm `leaflet`, [proyek upstream](https://github.com/Leaflet/Leaflet) |
| Leaflet.markercluster | 1.5.3 | MIT, npm `leaflet.markercluster`, [proyek upstream](https://github.com/Leaflet/Leaflet.markercluster) |
| Chart.js | 4.5.1 | MIT, npm `chart.js`, [proyek upstream](https://github.com/chartjs/Chart.js) |
| Basemap land 1:110m | world-atlas 2.0.2 | Natural Earth domain publik. Distribusi [world-atlas](https://github.com/topojson/world-atlas) berlisensi ISC. |
| FPDF | 1.9.0 | Lisensi permisif FPDF disertakan. [Commit sumber](https://github.com/Setasign/FPDF/tree/051b70e4c57dedc88df41b1eff1c62894e5f9ed0) |
| SimpleXLSXGen | 1.5.17 | MIT. [Commit sumber](https://github.com/shuchkin/simplexlsxgen/tree/162a4a9b929611d69dbd6f1a7f42af483a87b537) |

Perbarui dependensi di mesin pengembangan dengan versi eksplisit. Gunakan npm untuk memverifikasi integritas paket JS; salin build serta lisensi ke folder `public/assets/vendor/`. Salin modul dan core Three.js dari rilis yang sama. Data daratan diubah dari TopoJSON menjadi GeoJSON menggunakan topojson-client 3.1.0. Basemap digeneralisasi untuk ikhtisar regional. Koordinat marker tetap berasal dari entri fasilitas. Jalankan pengujian visual setelah mengganti library.

Pustaka PHP ditempatkan di `private/vendor/` bersama lisensi. PDF administratif dan tabel memakai font inti FPDF dengan transliterasi untuk karakter yang berada di luar cakupan font. Pengujian Chromium memakai Playwright secara lokal.
