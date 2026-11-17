# Alfar People — Manajemen Kinerja

Laravel 13 + Vue 3 + Inertia 3 + TypeScript + Tailwind 4. Bahasa antarmuka utama Indonesia, zona waktu Asia/Jakarta, tampilan lavender/ungu dengan sidebar putih dan kartu responsif.

## Pengembangan

PHP 8.4.1+, Composer 2, Node 22.12+, ekstensi mbstring, DOM/XML/XMLReader/XMLWriter, zip, fileinfo, PDO SQLite (atau driver database pilihan).

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm ci
npm run build
php artisan alfar:admin --generate
php artisan serve
# terminal lain untuk hot reload:
npm run dev
```

Gunakan checkout yang sudah ada dalam lingkungan cloud; setiap task sudah terisolasi, tidak perlu membuat worktree. Composer lock memakai URL arsip resmi codeload GitHub agar tidak bergantung pada API GitHub. Font dibundel dari npm; build tidak meminta font dari layanan eksternal.

Pemeriksaan:

```bash
npm run build
npm run types:check
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress
php artisan test
```

## Mulai dari panel

1. Login superadmin, ganti password sementara.
2. Buat divisi, jabatan, shift, akun @alfar.id dan profil karyawan; hubungkan supervisor dan tetapkan akses WFO/WFH.
3. Buat role; pilih permission dan cakupan. Administrasi akun/master memakai `all`. Akses akun tidak otomatis memberi akses laporan. Tetapkan role ke akun.
4. Tetapkan jadwal per karyawan/tanggal. Shift lintas tengah malam didukung; jadwal menyimpan jam dan toleransi agar aturan lama tidak berubah saat shift baru dibuat.
5. Buat template KPI per jabatan dan periode (planning). Tetapkan target individu dengan total bobot 100%, atau target tim per departemen/shift. Aktifkan periode untuk mengunci definisi/target/bobot.
6. Input realisasi dan unggah bukti privat. Supervisor memvalidasi atau mengembalikan. Mulai tahap review, catat analisis, tindak lanjut dan tanggal peninjauan. Terbitkan setelah KPI lengkap.
7. Karyawan membaca laporan yang terbit dan memberi tanggapan. Revisi laporan terbit harus disertai alasan; snapshot sebelum/sesudah disimpan dalam audit. Tutup periode setelah semua review individu terbit.

Role rekomendasi (dibuat melalui panel, bukan dikunci dalam kode):

| Role              | Izin dan cakupan                                                                                                                                                      |
| ----------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Karyawan          | `kpi.submit:self`, `reports.read:self`, `attendance.read:self`                                                                                                        |
| Supervisor        | `kpi.validate:reports`, `reviews.publish:reports`, `reports.read:reports`, `attendance.read:reports`, `attendance.approve:reports`                                    |
| HR                | `organization.manage:all`, `accounts.manage:all`, `attendance.manage:all`, `attendance.read:all`, `kpi.manage:all`; akses laporan diberikan eksplisit jika diperlukan |
| Kepala Bagian     | `reports.read:department`, `attendance.read:department`; validasi KPI tim melalui `kpi.validate:department` jika ditugaskan                                           |
| Display QR kantor | `qr.display:all`, tanpa akses laporan                                                                                                                                 |

`reports` berarti bawahan langsung, bukan seluruh pohon organisasi. Karyawan selalu dapat mengakses formulir absensi/pengajuan dirinya sendiri. Workspace realisasi sendiri dapat terlihat sebelum publikasi; catatan review draft hanya untuk evaluator. Pembatasan cakupan diberlakukan di server. Akun superadmin tidak dapat diberikan melalui form role.

## Aturan KPI dan laporan

- Lebih tinggi lebih baik: realisasi / target × 100. Lebih rendah lebih baik: target / realisasi × 100; realisasi nol mendapat batas nilai. Target wajib > 0; realisasi >= 0. Batas default 120%, dapat ditetapkan per template.
- Nilai individu = Σ(nilai KPI × bobot) / 100; dihitung hanya jika seluruh KPI individu tervalidasi dan bobot 100%. Data kosong/belum tervalidasi adalah **Belum Lengkap**, bukan nol.
- KPI tim ditampilkan terpisah dan tidak dibagikan menjadi nilai individu. Publikasi juga memeriksa kelengkapan KPI tim yang terkait.
- Kehadiran terpisah: hari terjadwal, hadir, cuti, sakit, izin, terlambat, pulang awal, tanpa keterangan pada hari lampau, dan absensi tanpa jam keluar. Tidak ada skor gabungan otomatis.
- Hasil terbit menyimpan snapshot. Koreksi angka KPI memerlukan izin validasi dan publikasi dalam cakupan yang sesuai, bukti baru, dan alasan. Koreksi disimpan pada data sumber; laporan terbit baru berubah setelah supervisor menerbitkan revisi beralasan. Koreksi absensi berikutnya tidak diam-diam mengubah snapshot terbit; supervisor menerbitkan revisi beralasan jika laporan perlu diperbarui.
- Saat ini tren mencakup nilai periode terbit sebelumnya pada laporan individu; rekap jumlah karyawan, nilai rata-rata, kehadiran, dan publikasi review dapat difilter menurut divisi, shift, dan kelengkapan/KPI meleset. Dashboard mengikuti cakupan akses.

## Absensi QR, WFH, dan Excel

QR ditampilkan oleh akun dengan `qr.display`, diperbarui tiap 15 detik, dan diverifikasi waktu kedaluwarsanya di server. Token disimpan sebagai hash; tiap token hanya boleh dipakai sekali per karyawan. Karyawan harus login, membuka QR melalui kamera ponsel atau pemindai kamera dalam aplikasi, memilih tanggal kerja dan masuk/keluar. Timestamp berasal dari server; tanpa jadwal absensi ditolak. QR dinamis mengurangi replay, tetapi tidak membuktikan lokasi fisik atau mencegah foto QR dibagikan selama masih valid; pembatasan lokasi tambahan dapat ditambahkan kemudian.

Tombol **Saya WFH** tetap mengecek server: akun `allow_wfh` bisa langsung absen, akun lain harus memiliki pengajuan WFH yang disetujui pada tanggal kerja tersebut. Supervisor tidak dapat menyetujui pengajuan sendiri (kecuali superadmin). Cuti/sakit/izin juga melalui pengajuan. Koreksi menyimpan data lama, alasan, dan aktor dalam audit.

Unduh [template Excel](public/template-absensi.xlsx). Format **.xlsx**, sheet pertama, header persis:

| nik    | work_date  | clock_in            | clock_out           | mode |
| ------ | ---------- | ------------------- | ------------------- | ---- |
| NIK001 | 2026-11-17 | 2026-11-17 08:00:00 | 2026-11-17 17:00:00 | wfo  |

Gunakan sel berformat teks, tanggal `YYYY-MM-DD`, waktu `YYYY-MM-DD HH:mm:ss` dalam Asia/Jakarta. `clock_out` boleh kosong; mode `wfo` atau `wfh`. Maksimal 2000 baris/5 MB. Impor bersifat atomik: satu baris invalid membatalkan seluruh berkas. NIK, akses, jadwal, izin WFH dan duplikasi diperiksa; catatan lama tidak ditimpa. Format mesin yang berbeda memerlukan pemetaan sebelum impor.

## Fondasi fingerprint

Daftarkan perangkat dan token acak minimal 40 karakter, lalu petakan ID pengguna alat ke karyawan. Token asli hanya disimpan oleh adapter; aplikasi menyimpan hash. Kirim lewat HTTPS:

```http
POST /api/fingerprint/events
Authorization: Bearer <token-perangkat>
Content-Type: application/json

{
  "external_event_id": "gate-a-000001",
  "external_id": "0042",
  "event": "in",
  "occurred_at": "2026-11-17 08:00:00",
  "work_date": "2026-11-17"
}
```

Untuk keluar gunakan `event: out`. Event yang sama idempotent; event baru tidak boleh menggandakan punch yang sudah ada. Fingerprint selalu WFO dan mengikuti jadwal/toleransi yang sama. Event ditolak jika akun tidak aktif, identitas belum dipetakan, di luar jadwal, atau jam perangkat di masa depan. Vendor adapter harus menentukan jenis event dan tanggal kerja untuk shift malam. **Driver/protokol alat fisik belum dihubungkan**, sesuai keputusan menunda konfigurasi alat.

## Batas MVP

Master divisi/jabatan/template/shift dapat dibuat dan diubah melalui panel; riwayat perubahan penempatan karyawan disimpan. Jadwal dibuat per hari. Kalender libur massal, integrasi vendor otomatis, coaching terstruktur, notifikasi reminder, ekspor laporan PDF, gabungan skor kinerja-kehadiran memerlukan tahap berikutnya. Bukti KPI disimpan privat dan diunduh melalui pemeriksaan izin. Backup, retensi audit/bukti, worker email serta layanan database produksi dikelola pada server.

Panduan CI/CD dan semua GitHub Secrets/Variables: **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)**.
