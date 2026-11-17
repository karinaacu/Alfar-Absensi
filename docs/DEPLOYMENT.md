# Deployment Alfar People

Workflow `.github/workflows/tests.yml` menjalankan build, TypeScript, Pint, PHPStan, dan seluruh feature test. Push ke `main` hanya melakukan deployment jika CI berhasil dan `DEPLOY_ENABLED=true`. Pull request tidak melakukan deployment. Manual run tersedia melalui Actions.

## GitHub Actions → Settings → Secrets and variables

Isi pada **Repository variables** (atau environment `production`):

| Variable             | Nilai                                                                                                  |
| -------------------- | ------------------------------------------------------------------------------------------------------ |
| `DEPLOY_ENABLED`     | `true` untuk mengaktifkan, `false` untuk menghentikan deployment                                       |
| `DEPLOY_HOST`        | IP/hostname SSH server Anda; gunakan nilai aktual pada screenshot                                      |
| `DEPLOY_PORT`        | `22` atau port SSH aktual                                                                              |
| `DEPLOY_USER`        | `icad`                                                                                                 |
| `DEPLOY_PATH`        | `/home/icad/web/rdw.biz.id/public_html` (juga menjadi default script)                                  |
| `DEPLOY_KNOWN_HOSTS` | Baris **lengkap** public host key server dalam format OpenSSH `known_hosts`, bukan fingerprint singkat |

Isi pada **Repository secrets**:

| Secret           | Isi                                                                                                                                                                                         |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `DEPLOY_SSH_KEY` | Private key OpenSSH khusus deployment, tanpa passphrase untuk proses noninteraktif. Public key pasang pada `~icad/.ssh/authorized_keys` di server. Jangan unggah private key ke repository. |

Untuk port selain 22, entry known_hosts harus menggunakan `[hostname]:port`. Verifikasi host key melalui panel/console server atau saluran tepercaya sebelum menyimpannya. Workflow memakai StrictHostKeyChecking; tidak mengambil dan langsung mempercayai key dari jaringan. Screenshot Anda menunjukkan variabel yang sesuai; tidak perlu secret terpisah dengan nama yang sama.

Workflow menggunakan GitHub environment `production`; jika diaktifkan, aturan approval pada environment tersebut tetap berlaku. Koneksi server belum diuji karena kredensial belum diisi. Menyediakan workflow tidak menjalankan deployment sekarang.

## Persiapan server satu kali

1. PHP CLI dan PHP-FPM **8.3 atau lebih baru** dengan mbstring, XML/DOM/XMLReader/XMLWriter, zip, fileinfo, PDO dan driver database; Composer 2; Node.js **22.12+** dan npm; Git, Bash, serta `flock`.
2. Checkout repositori di `DEPLOY_PATH`, branch `main`. Gunakan read-only GitHub deploy key pada server agar `git fetch origin` bekerja tanpa prompt. Key ini berbeda dari key Actions → server. Tidak ada token GitHub yang disalin melalui workflow.
3. Siapkan `.env` production secara manual (jangan commit): `APP_NAME="Alfar People"`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://rdw.biz.id`, `APP_TIMEZONE=Asia/Jakarta`, `SESSION_SECURE_COOKIE=true`, `DB_*` dan mail transport perusahaan. Buat database dan user database dengan izin migrasi. SQLite dipakai untuk pengembangan/test; produksi dapat memakai PostgreSQL atau MySQL dengan driver PDO yang sesuai.
4. Dari direktori aplikasi:

    ```bash
    composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
    php artisan key:generate
    mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
    php artisan migrate --force
    npm ci
    npm run build
    php artisan storage:link
    php artisan alfar:admin --generate
    php artisan optimize
    ```

    `alfar:admin --generate` menghasilkan password sementara acak dan menampilkannya sekali pada console server (simpan aman, jangan jalankan melalui Actions). Tanpa `--generate`, perintah meminta password secara tersembunyi. Perintah ini membuat **superadmin@alfar.id**, dan mewajibkan perubahan password saat login pertama. Perintah berhenti jika akun sudah ada. Tidak ada password bawaan di kode atau workflow. Password minimal 12 karakter dengan huruf dan angka. Akun karyawan dibuat melalui panel internal.

5. Pastikan user `icad` dan PHP-FPM dapat membaca aplikasi dan menulis `storage/` serta `bootstrap/cache/`. Jangan memakai chmod 777. Atur pemilik/grup sesuai konfigurasi panel server.
6. **Document root domain harus `/home/icad/web/rdw.biz.id/public_html/public`.** Front controller adalah `public/index.php`; `.env`, `vendor/`, dan `storage/app/private` tidak boleh diakses melalui web. Pada Nginx gunakan `try_files $uri $uri/ /index.php?$query_string;`. Pastikan HTTPS aktif untuk kamera, sesi aman, dan API perangkat.
7. Verifikasi `https://rdw.biz.id/up`, login, penggantian password awal, dan akses dashboard. Siapkan backup database sebelum mengaktifkan migrasi otomatis.

## Yang dilakukan deployment

Script memakai lock untuk mencegah proses bersamaan; menolak perubahan tracked lokal atau branch selain main; fetch origin; memastikan commit sama dengan commit CI; maintenance; fast-forward commit; `composer install --no-dev`; `npm ci`; `npm run build`; `optimize:clear`; `migrate --force`; `storage:link`; `optimize`; `queue:restart`; kemudian `up`.

Ini deployment sederhana **di tempat**, dengan downtime singkat. `.env` dan database tidak ditimpa; tidak ada `migrate:fresh`, reset paksa, atau seeder otomatis. Jika langkah gagal setelah maintenance, aplikasi **tetap maintenance** agar perubahan parsial tidak disajikan. Periksa Actions dan log Laravel, perbaiki penyebab, ulangi deployment atau pulihkan backup; jalankan `php artisan up` setelah kondisi konsisten. Tidak ada rollback database otomatis.

`/up` membuktikan bootstrap Laravel, bukan kesehatan seluruh fitur. Setelah deploy periksa login/dashboard dan database. Jika menggunakan queue untuk email, jalankan worker melalui process manager server. Konfigurasi PHP-FPM/opcache mengikuti panel server; workflow tidak menjalankan sudo atau me-restart layanan lain.
