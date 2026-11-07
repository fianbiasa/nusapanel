# nusapanel — Panel Hosting Mandiri

> `nusapanel` hanya codename sementara; nama final bisa diganti kapan saja.

Panel hosting self-hosted dengan fitur **minimal setara DirectAdmin**: tiga level akses (Admin, Reseller, User), web, DNS, email, database, FTP, SSL, backup, migrasi, dan keamanan, tanpa biaya lisensi.

## Dokumen spesifikasi

| #   | Dokumen                                       | Isi                                                                  |
| --- | --------------------------------------------- | -------------------------------------------------------------------- |
| 1   | [Daftar fitur](docs/01-fitur.md)              | Semua fitur per level (User, Reseller, Admin) dan fase pengerjaannya |
| 2   | [Arsitektur](docs/02-arsitektur.md)           | Komponen, stack layanan, isolasi user, model keamanan, alur task     |
| 3   | [Database](docs/03-database.md)               | Skema tabel panel dan view SQL untuk layanan                         |
| 4   | [Agent & server](docs/04-agent-dan-server.md) | Daftar aksi agent, aturan validasi, layout folder, port              |
| 5   | [Roadmap](docs/05-roadmap.md)                 | Fase P0–P6, estimasi, kriteria selesai, strategi migrasi, risiko     |

## Keputusan utama

| Topik              | Pilihan                                                                          | Alasan                                                                               |
| ------------------ | -------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| OS                 | Ubuntu 24.04 LTS dulu, Debian menyusul                                           | Paling umum di provider VPS; satu OS mengurangi beban perawatan                      |
| Panel web          | Laravel + Inertia + React (TypeScript, Tailwind, shadcn/ui)                      | UI dibangun sendiri; interaktif untuk file manager, editor, dan progres task         |
| Login              | Laravel Fortify                                                                  | Username/email, 2FA, passkey; pendaftaran publik dimatikan                           |
| Pengembangan lokal | Herd (`nusapanel.test`)                                                          | SSL & versi PHP diatur dari aplikasi Herd; agent disimulasikan (`AGENT_DRIVER=fake`) |
| Agent              | Go, satu binary, jalan sebagai root                                              | Eksekusi tanpa shell, mudah dipasang, ada library ACME (lego)                        |
| Pemisahan hak      | Panel bukan root; semua perubahan sistem lewat agent dengan daftar aksi tertutup | Bug di panel tidak otomatis jadi akses root                                          |
| Web server         | Nginx → Apache → PHP-FPM per user                                                | Cepat, dan `.htaccess` tetap jalan untuk WordPress & pelanggan pindahan              |
| DNS                | PowerDNS                                                                         | Ada API, siap DNSSEC & cluster                                                       |
| Email              | Postfix + Dovecot + Rspamd + Roundcube                                           | Standar industri                                                                     |
| FTP                | Pure-FTPd (auth MySQL)                                                           | Akun FTP virtual per domain                                                          |
| Isolasi            | User Linux per akun, home `0710`, cgroups per user                               | Satu situs dibobol tidak menular ke akun lain                                        |
| Struktur folder    | Mengikuti DirectAdmin                                                            | Migrasi mudah, pelanggan familiar                                                    |
| Port panel         | 2222                                                                             | Sama dengan DirectAdmin                                                              |

## Asumsi yang dipakai (belum dikonfirmasi)

- Panel dipakai untuk **menjual shared hosting** (Admin → Reseller → User).
- Dimulai dari **satu server**; desain agent sudah disiapkan untuk multi-server.
- Ada akun di cPanel/DirectAdmin yang nanti perlu **dipindahkan**.

## Ekspektasi yang realistis

DirectAdmin dikembangkan lebih dari 20 tahun. Mengejar fitur selengkap itu bisa, tapi butuh waktu: perkiraan **5–8 bulan full-time** sampai siap produksi (fase P0–P5). Urutannya fondasi yang aman dulu, lalu fitur dibangun per fase. Fitur tambahan di luar DirectAdmin (P6) dikerjakan setelah panel stabil.

## Menjalankan secara lokal (Herd)

Panel dilayani Herd di `http://nusapanel.test`. Di Mac, agent server disimulasikan (`AGENT_DRIVER=fake`) karena agent asli hanya jalan di Linux.

```bash
composer install && pnpm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed      # akun demo admin, reseller, dan user (lihat database/seeders/DatabaseSeeder.php)
pnpm run build                  # atau `composer run dev` untuk mode pengembangan
```

- **SSL & versi PHP:** atur dari aplikasi Herd (Sites → `nusapanel`). Setelah mengaktifkan HTTPS, ubah `APP_URL` di `.env` menjadi `https://nusapanel.test`.
- **Admin pertama di server:** `php artisan nusapanel:create-admin <username> <email>` (password acak ditampilkan sekali).
- **Test & pengecekan:** `php artisan test`, `vendor/bin/pint`, `vendor/bin/phpstan analyse`, `pnpm run check`, `pnpm run types:check`.

## Agent server (`agent/`, Go)

```bash
cd agent
make check     # go vet (Mac + Linux), test dengan race detector, gofmt
make build     # bin/nusad dan bin/nusactl untuk Mac
make linux     # binary statis untuk server: bin/linux-amd64/ dan bin/linux-arm64/
```

**Uji panel dengan agent asli di Mac.** nusad bisa jalan di macOS khusus untuk pengembangan, karena PHP-FPM Herd jalan sebagai user kamu:

```bash
agent/bin/nusad --socket "$PWD/storage/app/nusad.sock" --allow-user "$(whoami)" --socket-group ""
```

Lalu di `.env` set `AGENT_DRIVER=socket` dan `AGENT_SOCKET` ke path socket di atas. Kembalikan ke `AGENT_DRIVER=fake` setelah selesai. Cek cepat tanpa panel: `agent/bin/nusactl -socket storage/app/nusad.sock ping`.

## Instalasi di server (Ubuntu 24.04)

**1. Buat paket rilis** (di Mac atau CI). Server tidak butuh Composer, Node.js, atau Go:

```bash
installer/build-release.sh 0.1.0
```

Hasilnya di `dist/`: `setup.sh` dan `nusapanel-0.1.0-linux-{amd64,arm64}.tar.gz` beserta file `.sha256`.

**Syarat sebelum instalasi** (dicek `setup.sh` lewat DNS publik; instalasi berhenti kalau belum terpenuhi):

- Ubuntu 24.04 LTS yang masih bersih, akses root.
- Hostname berupa FQDN, misalnya `server1.domainmu.com`.
- **A record** hostname → IP publik server (kalau memakai Cloudflare: _DNS only_, bukan proxy). Tidak boleh ada AAAA record yang mengarah ke server lain.
- **PTR (reverse DNS)** IP publik → hostname, diatur lewat provider VPS.
- Port 80 dan 2222 terbuka dari internet.

Dengan syarat itu terpenuhi, `setup.sh` otomatis memasang **SSL Let's Encrypt** untuk hostname (diperpanjang otomatis oleh `certbot.timer`), sehingga panel bisa dibuka normal di `https://<hostname>:2222`, dan `http://<hostname>` diarahkan ke sana.

**2. Pasang di server baru**, sebagai root:

```bash
scp dist/setup.sh dist/nusapanel-0.1.0-linux-amd64.tar.gz* root@SERVER:/root/
```

```bash
bash setup.sh --package nusapanel-0.1.0-linux-amd64.tar.gz --admin-email kamu@domainmu.com
```

Kalau paketnya sudah di-host di HTTPS, cukup `wget https://<host>/setup.sh` lalu `bash setup.sh --release-url https://<host>/nusapanel-0.1.0-linux-amd64.tar.gz --admin-email ...` (checksum diambil dari `<url>.sha256`).

Di akhir instalasi muncul alamat panel (`https://<hostname>:2222`), username, dan password admin (hanya ditampilkan sekali, tidak masuk log). `setup.sh` aman dijalankan ulang kalau berhenti di tengah. Pilihan lengkap: `bash setup.sh --help`.

**Email panel** (verifikasi, reset password) dikirim lewat Postfix kirim-saja (hanya mendengar di `127.0.0.1`) dan ditandatangani DKIM oleh OpenDKIM. Di akhir instalasi, `setup.sh` menampilkan rekaman DNS yang perlu dipasang beserta statusnya (✔/✘), dan menyimpannya di `/etc/nusapanel/dns-records.txt`:

| Rekaman | Nama                                  | Nilai                                               |
| ------- | ------------------------------------- | --------------------------------------------------- |
| SPF     | TXT `<hostname>`                      | `v=spf1 a ~all`                                     |
| DKIM    | TXT `nusapanel._domainkey.<hostname>` | `v=DKIM1; k=rsa; p=…` (kunci dibuat saat instalasi) |
| DMARC   | TXT `_dmarc.<hostname>`               | `v=DMARC1; p=none; adkim=r; aspf=r`                 |

Jalankan `setup.sh` lagi kapan saja untuk mengecek ulang statusnya.

Lupa password admin? Di server, sebagai root:

```bash
runuser -u nusapanel -- php8.4 /usr/local/nusapanel/current/panel/artisan nusapanel:reset-password admin
```

Password baru ditampilkan sekali, dan semua session lama akun itu dikeluarkan.
