<p align="center">
  <img src="public/images/rayxo-logo.png" alt="RAYXO" width="360">
</p>

<p align="center">
  <a href="https://rayxopanel.com">Website</a> ·
  <a href="https://rayxopanel.com/docs/">Dokumentasi</a> ·
  <a href="https://rayxopanel.com/docs/installation/">Panduan instalasi</a> ·
  <a href="https://rayxopanel.com/changelog/">Changelog</a> ·
  <a href="https://github.com/fianbiasa/rayxo/discussions">Diskusi</a>
</p>

# RAYXO — Webhosting Manager

Panel hosting self-hosted untuk server Ubuntu, gratis dan open source, dengan tiga level akses (Admin, Reseller, User). Targetnya fitur **minimal setara DirectAdmin** tanpa biaya lisensi.

**Status:** beta, versi terbaru [v0.7.0](https://github.com/fianbiasa/rayxo/releases/latest). Fase P0–P3 di [roadmap](docs/05-roadmap.md) sudah selesai; backup dan migrasi (P4) belum ada.

## Yang sudah bisa dipakai

- **Web:** domain, subdomain, alias, pointer, redirect; Nginx → Apache → PHP-FPM per akun dengan PHP 7.4–8.5 per domain; pengaturan per website (halaman error, folder berpassword, proteksi hotlink, tipe MIME, CGI, batas bandwidth dan disk); log dan laporan GoAccess.
- **SSL:** Let's Encrypt otomatis termasuk wildcard dan `mail.<domain>`, sertifikat sendiri, CSR, dan self-signed.
- **Database:** MariaDB dengan phpMyAdmin sekali klik, import/export, periksa dan perbaiki tabel, akses dari luar server per IP.
- **Email:** Postfix, Dovecot, Rspamd, dan Roundcube; forwarder, catch-all, autoresponder, filter, filter spam, DKIM/SPF/DMARC otomatis, batas kirim, antrean email, dan konfigurasi otomatis aplikasi email.
- **DNS:** PowerDNS dengan template zone, editor record, dan nameserver sendiri per reseller.
- **File & akses:** file manager, FTP/FTPS, SFTP, SSH dalam jail dengan SSH key, cron job.
- **Reseller:** paket reseller dengan overselling, akun dan paket sendiri, masuk sebagai pelanggan, pindah pemilik, branding (nama, warna, logo), dan domain login panel sendiri.
- **Batas & statistik:** kuota disk dan inode, bandwidth dari log server dengan grafik harian, batas CPU/RAM/proses/IO per akun (cgroups), alamat IP sendiri per akun (shared atau dedicated).
- **Operasional:** REST API dengan login key, notifikasi email dan Telegram, tiket bantuan, pesan massal, monitor layanan dengan watchdog, process monitor, editor file konfigurasi, riwayat login, task, dan audit log.
- **Keamanan akun:** setiap akun hosting adalah user Linux sendiri; login panel dengan 2FA atau passkey.

Daftar lengkap per level ada di [docs/01-fitur.md](docs/01-fitur.md), dan panduan pemakaian di [rayxopanel.com/docs](https://rayxopanel.com/docs/).

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
| Pengembangan lokal | Herd (`rayxo.test`)                                                              | SSL & versi PHP diatur dari aplikasi Herd; agent disimulasikan (`AGENT_DRIVER=fake`) |
| Agent              | Go, satu binary, jalan sebagai root                                              | Eksekusi tanpa shell, mudah dipasang; SSL lewat certbot yang sudah ada di server     |
| Pemisahan hak      | Panel bukan root; semua perubahan sistem lewat agent dengan daftar aksi tertutup | Bug di panel tidak otomatis jadi akses root                                          |
| Web server         | Nginx → Apache → PHP-FPM per user                                                | Cepat, dan `.htaccess` tetap jalan untuk WordPress & pelanggan pindahan              |
| DNS                | PowerDNS                                                                         | Ada API, siap DNSSEC & cluster                                                       |
| Email              | Postfix + Dovecot + Rspamd + Roundcube                                           | Standar industri                                                                     |
| FTP                | Pure-FTPd (auth MySQL)                                                           | Akun FTP virtual per domain                                                          |
| Isolasi            | User Linux per akun, home `0710`, cgroups per user                               | Satu situs dibobol tidak menular ke akun lain                                        |
| Struktur folder    | Mengikuti DirectAdmin                                                            | Migrasi mudah, pelanggan familiar                                                    |
| Port panel         | 2222                                                                             | Sama dengan DirectAdmin                                                              |

## Berikutnya

| Fase | Isi                                                                                                                |
| ---- | ------------------------------------------------------------------------------------------------------------------ |
| P4   | Backup dan restore (lokal, FTP, SFTP, S3), impor backup cPanel dan DirectAdmin, transfer antar server, modul WHMCS |
| P5   | Brute force monitor, firewall, ModSecurity, kebijakan 2FA dan password, update panel sekali klik, UI dua bahasa    |
| P6   | App installer dan WordPress manager, Git deploy, Node.js/Python, DNSSEC, multi-server                              |

Panel dirancang untuk menjual shared hosting (Admin → Reseller → User) di satu server; desain agent sudah disiapkan untuk multi-server. Rinciannya di [docs/05-roadmap.md](docs/05-roadmap.md).

## Menjalankan secara lokal (Herd)

Panel dilayani Herd di `http://rayxo.test`. Di Mac, agent server disimulasikan (`AGENT_DRIVER=fake`) karena agent asli hanya jalan di Linux.

```bash
composer install && pnpm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed      # akun demo admin, reseller, dan user (lihat database/seeders/DatabaseSeeder.php)
pnpm run build                  # atau `composer run dev` untuk mode pengembangan
```

- **SSL & versi PHP:** atur dari aplikasi Herd (Sites → `rayxo`). Setelah mengaktifkan HTTPS, ubah `APP_URL` di `.env` menjadi `https://rayxo.test`.
- **Admin pertama di server:** dibuat otomatis oleh `setup.sh` (password acak ditampilkan sekali). Lupa password: `rayxo reset-password admin` sebagai root.
- **Test & pengecekan:** `php artisan test`, `vendor/bin/pint`, `vendor/bin/phpstan analyse`, `pnpm run check`, `pnpm run types:check`.

## Agent server (`agent/`, Go)

```bash
cd agent
make check     # go vet (Mac + Linux), test dengan race detector, gofmt
make build     # bin/rayxod dan bin/rayxo untuk Mac
make linux     # binary statis untuk server: bin/linux-amd64/ dan bin/linux-arm64/
```

**Uji panel dengan agent asli di Mac.** rayxod bisa jalan di macOS khusus untuk pengembangan, karena PHP-FPM Herd jalan sebagai user kamu:

```bash
agent/bin/rayxod --socket "$PWD/storage/app/rayxod.sock" --allow-user "$(whoami)" --socket-group ""
```

Lalu di `.env` set `AGENT_DRIVER=socket` dan `AGENT_SOCKET` ke path socket di atas. Kembalikan ke `AGENT_DRIVER=fake` setelah selesai. Cek cepat tanpa panel: `agent/bin/rayxo -socket storage/app/rayxod.sock ping`.

## Instalasi di server (Ubuntu 24.04)

Di server baru, sebagai root:

```bash
wget https://github.com/fianbiasa/rayxo/releases/latest/download/setup.sh
```

```bash
bash setup.sh --admin-email kamu@domainmu.com
```

`setup.sh` dari sebuah rilis otomatis mengunduh paket rilis yang sama dari GitHub dan memverifikasi checksum-nya. Versi tertentu: `--version 0.7.0`. Versi PHP untuk website (default 7.4 sampai 8.5): `--php-versions "8.2 8.3 8.4"`; versi yang sudah terpasang tidak dihapus.

**Syarat sebelum instalasi** (dicek `setup.sh` lewat DNS publik; instalasi berhenti kalau belum terpenuhi):

- Ubuntu 24.04 LTS yang masih bersih, akses root.
- Hostname berupa FQDN, misalnya `server1.domainmu.com`.
- **A record** hostname → IP publik server (kalau memakai Cloudflare: _DNS only_, bukan proxy). Tidak boleh ada AAAA record yang mengarah ke server lain.
- **PTR (reverse DNS)** IP publik → hostname, diatur lewat provider VPS.
- Port terbuka dari internet (firewall / security group provider VPS):

    | Port                             | Untuk                     |
    | -------------------------------- | ------------------------- |
    | 80, 443                          | Website dan Let's Encrypt |
    | 2222                             | Panel                     |
    | 22                               | SFTP dan SSH              |
    | 21, 35000–35999                  | FTP (passive)             |
    | 25, 110, 143, 465, 587, 993, 995 | Email (SMTP, POP3, IMAP)  |
    | 53 TCP dan UDP                   | DNS                       |

Dengan syarat itu terpenuhi, `setup.sh` otomatis memasang **SSL Let's Encrypt** untuk hostname (diperpanjang otomatis oleh `certbot.timer`), sehingga panel bisa dibuka normal di `https://<hostname>:2222`, dan `http://<hostname>` diarahkan ke sana.

Di akhir instalasi muncul alamat panel (`https://<hostname>:2222`), username, dan password admin (hanya ditampilkan sekali, tidak masuk log). `setup.sh` aman dijalankan ulang kalau berhenti di tengah. Pilihan lengkap: `bash setup.sh --help`.

**Email panel** (verifikasi, reset password) dikirim lewat Postfix di server itu sendiri (port 587 dengan login `rayxo@<hostname>`) dan ditandatangani DKIM oleh OpenDKIM. Di akhir instalasi, `setup.sh` menampilkan rekaman DNS yang perlu dipasang beserta statusnya (✔/✘), dan menyimpannya di `/etc/rayxo/dns-records.txt`:

| Rekaman | Nama                              | Nilai                                               |
| ------- | --------------------------------- | --------------------------------------------------- |
| SPF     | TXT `<hostname>`                  | `v=spf1 a ~all`                                     |
| DKIM    | TXT `rayxo._domainkey.<hostname>` | `v=DKIM1; k=rsa; p=…` (kunci dibuat saat instalasi) |
| DMARC   | TXT `_dmarc.<hostname>`           | `v=DMARC1; p=none; adkim=r; aspf=r`                 |

Jalankan `setup.sh` lagi kapan saja untuk mengecek ulang statusnya.

Lupa password admin? Di server, sebagai root:

```bash
rayxo reset-password admin
```

Password baru ditampilkan sekali, dan semua session lama akun itu dikeluarkan.

## Membuat rilis

Rilis dibuat otomatis oleh GitHub Actions (`.github/workflows/release.yml`) setiap kali tag versi di-push:

```bash
git tag v0.8.0 && git push origin v0.8.0
```

Workflow menjalankan `installer/build-release.sh`, lalu menerbitkan `setup.sh`, `rayxo-<versi>-linux-{amd64,arm64}.tar.gz`, dan file `.sha256`-nya di [Releases](https://github.com/fianbiasa/rayxo/releases). Server tidak butuh Composer, Node.js, atau Go.

Untuk uji tanpa rilis: jalankan `installer/build-release.sh 0.8.0-dev` di Mac, salin isi `dist/` ke server, lalu `bash setup.sh --package rayxo-0.8.0-dev-linux-amd64.tar.gz`.

## Lisensi

Copyright (C) 2026 Zulfianto

RAYXO adalah perangkat lunak bebas di bawah [GNU Affero General Public License versi 3](LICENSE) (AGPL-3.0). Kamu boleh memakai, menyebarkan, dan mengubahnya. Kalau kamu memodifikasi RAYXO lalu menyediakannya untuk orang lain lewat jaringan, misalnya sebagai panel untuk pelanggan hosting, kode sumber versi modifikasimu juga wajib tersedia untuk mereka.
