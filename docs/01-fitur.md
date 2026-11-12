# 1. Daftar Fitur

Target: **minimal setara DirectAdmin**. Setiap fitur diberi fase pengerjaan (lihat [Roadmap](05-roadmap.md)); PB adalah fase UI dua bahasa, dikerjakan bersama P5.
Tanda ★ = fitur tambahan yang tidak ada di DirectAdmin standar.

Pembagian level sama seperti DirectAdmin:

- **Admin** mengelola server, reseller, dan semua user. Admin juga punya akun user sendiri.
- **Reseller** mengelola user di bawahnya dalam batas paket reseller. Reseller juga punya akun user sendiri.
- **User** mengelola domain, email, database, dan file miliknya.

---

## 1.1 Level User

### Domain & web

| Fitur                          | Keterangan                                                                                                                                             | Fase |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------ | ---- |
| Domain setup                   | ✅ Tambah/ubah/hapus domain; on/off PHP dan SSL                                                                                                        | P1   |
| CGI                            | Skrip CGI di `cgi-bin`, on/off per domain                                                                                                              | P3   |
| Batas per domain               | Batas disk & bandwidth per domain                                                                                                                      | P3   |
| Subdomain                      | ✅ Buat/hapus, document root kustom, versi PHP sendiri                                                                                                 | P1   |
| Domain pointer                 | ✅ Alias (konten sama) dan pointer (redirect ke domain utama)                                                                                          | P1   |
| Pengaturan www                 | ✅ Layani www & non-www, atau paksa salah satu                                                                                                         | P1   |
| Paksa HTTPS                    | ✅ Redirect otomatis ke HTTPS                                                                                                                          | P1   |
| Site redirection               | ✅ Redirect 301/302 per path                                                                                                                           | P1   |
| PHP selector                   | ✅ Versi PHP per domain/subdomain                                                                                                                      | P1   |
| PHP settings                   | ✅ `memory_limit`, `upload_max_filesize`, `post_max_size`, `max_execution_time`, `max_input_vars`, `display_errors`, dll. (hanya kunci yang diizinkan) | P1   |
| Custom error pages             | 400, 401, 403, 404, 500, 503                                                                                                                           | P3   |
| Password protected directories | htpasswd + realm, kelola user per folder                                                                                                               | P3   |
| Hotlink protection             | Daftar domain diizinkan, ekstensi, URL pengalihan                                                                                                      | P3   |
| MIME types & handlers          | Per domain                                                                                                                                             | P3   |

### SSL

| Fitur                | Keterangan                                                                                                             | Fase |
| -------------------- | ---------------------------------------------------------------------------------------------------------------------- | ---- |
| Let's Encrypt        | ✅ Otomatis, termasuk www, mail, dan alias; perpanjangan otomatis                                                      | P1   |
| Sertifikat kustom    | ✅ Upload cert + private key + CA bundle, dicek kecocokannya                                                           | P1   |
| Wildcard SSL         | ✅ Lewat DNS-01 (kalau DNS domain di server ini)                                                                       | P2   |
| SSL untuk mail & FTP | ✅ mail.<domain> dengan SNI di Postfix & Dovecot; FTP dengan sertifikat hostname (login FTP sudah dibatasi per folder) | P2   |
| CSR & self-signed    | Generator CSR dan sertifikat self-signed                                                                               | P3   |

### DNS

| Fitur             | Keterangan                                               | Fase |
| ----------------- | -------------------------------------------------------- | ---- |
| DNS management    | ✅ A, AAAA, CNAME, MX, TXT, SRV, CAA, NS; TTL per record | P2   |
| Reset ke template | ✅ Kembalikan zone ke template default                   | P2   |
| DNSSEC ★          | On/off per zone, tampilkan DS record                     | P6   |
| Cek propagasi ★   | Bandingkan jawaban beberapa resolver publik              | P6   |

### Database

| Fitur                 | Keterangan                                            | Fase |
| --------------------- | ----------------------------------------------------- | ---- |
| Database MariaDB      | ✅ Buat/hapus (nama berawalan `username_`)            | P1   |
| User database         | ✅ Buat/hapus, ganti password, hak akses per database | P1   |
| phpMyAdmin SSO        | ✅ Login sekali klik, tanpa ketik password            | P1   |
| Remote access         | Daftar host/IP yang boleh konek dari luar             | P3   |
| Import/export         | Upload `.sql`/`.sql.gz`, download dump                | P3   |
| Check/repair/optimize |                                                       | P3   |

### File & FTP

| Fitur        | Keterangan                                                                                                                                                                      | Fase |
| ------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---- |
| File manager | ✅ Telusur, upload (drag & drop, chunked untuk file besar), download, buat, rename, copy, move, hapus (ada trash), chmod, zip/unzip/tar, editor kode, preview gambar, cari file | P1   |
| Akun FTP     | ✅ Buat/hapus, folder akses, quota, ganti password                                                                                                                              | P1   |
| SFTP         | ✅ Login SFTP memakai akun utama                                                                                                                                                | P1   |

### Email

| Fitur              | Keterangan                                                                                             | Fase |
| ------------------ | ------------------------------------------------------------------------------------------------------ | ---- |
| Akun email         | ✅ Buat/hapus, password, quota, batas kirim per hari (≤ batas akun), suspend login (email tetap masuk) | P2   |
| Forwarder          | ✅ Satu alamat ke banyak tujuan, dengan SRS supaya lolos SPF di tujuan                                 | P2   |
| Catch-all          | ✅ Tolak (bawaan) atau teruskan ke satu alamat                                                         | P2   |
| Autoresponder      | ✅ Balasan otomatis                                                                                    | P2   |
| Vacation message   | ✅ Dengan tanggal mulai dan selesai                                                                    | P2   |
| Spam filter        | ✅ Per akun email: ambang skor, aksi (Junk/hapus/tandai saja), whitelist & blacklist                   | P2   |
| Email filter       | ✅ Aturan per akun (Sieve)                                                                             | P2   |
| DKIM / SPF / DMARC | ✅ Dibuat otomatis + indikator status ("Cek DNS" di halaman Email, lewat DNS publik)                   | P2   |
| MX & mode email    | ✅ Per domain: server ini, Google Workspace, Zoho Mail (MX & SPF diatur otomatis), atau layanan lain   | P2   |
| Webmail            | ✅ Roundcube dengan SSO dari panel                                                                     | P2   |
| Pemakaian email    | ✅ Ukuran dan jumlah pesan per akun email, diukur tiap 15 menit                                        | P2   |
| Konfigurasi klien  | Autoconfig (Thunderbird) & autodiscover (Outlook)                                                      | P3   |
| Mailing list       |                                                                                                        | P6   |
| Lacak pengiriman ★ | Cari log mail untuk domain sendiri                                                                     | P6   |

### Fitur lanjutan

| Fitur                       | Keterangan                                                               | Fase |
| --------------------------- | ------------------------------------------------------------------------ | ---- |
| Cron jobs                   | ✅ Preset jadwal, email hasil output                                     | P1   |
| 2FA                         | ✅ TOTP + kode cadangan                                                  | P1   |
| Riwayat login               | ✅ IP, waktu, berhasil/gagal                                             | P1   |
| Tema                        | ✅ Mode terang/gelap                                                     | P1   |
| Bahasa                      | Indonesia & English, pilihan per user                                    | PB   |
| SSH access                  | Shell terkurung (jail), on/off sesuai paket                              | P3   |
| SSH keys                    | Tambah/hapus public key                                                  | P3   |
| Site statistics             | Laporan GoAccess per domain                                              | P3   |
| Log viewer                  | Access & error log per domain (tail, download)                           | P3   |
| Pemakaian resource          | Disk, inode, bandwidth (grafik); CPU & RAM ★                             | P3   |
| Disk usage explorer         | Folder/file terbesar                                                     | P3   |
| Login keys (API key)        | Izin per aksi, batasi IP, masa berlaku                                   | P3   |
| Pesan/tiket                 | Ke reseller atau admin                                                   | P3   |
| Backup & restore            | Pilih item, download, restore sebagian                                   | P4   |
| App installer ★             | WordPress satu klik                                                      | P6   |
| WordPress manager ★         | Update core/plugin/tema, reset password admin, mode maintenance, staging | P6   |
| Git deploy                  | Clone repo, deploy otomatis via webhook                                  | P6   |
| Aplikasi Node.js / Python ★ | Proses dikelola systemd + reverse proxy                                  | P6   |
| Redis per user ★            |                                                                          | P6   |
| Web terminal ★              |                                                                          | P6   |

---

## 1.2 Level Reseller

| Fitur                   | Keterangan                                       | Fase |
| ----------------------- | ------------------------------------------------ | ---- |
| Buat/ubah/hapus user    | Dalam batas paket reseller                       | P3   |
| Suspend/unsuspend user  | Dengan alasan                                    | P3   |
| Paket user              | Buat paket sendiri                               | P3   |
| Daftar user + pemakaian | Cari, filter, urutkan, aksi massal               | P3   |
| Login as user           | Tercatat di audit log                            | P3   |
| Statistik reseller      | Total pemakaian vs jatah                         | P3   |
| IP                      | Berikan IP dari pool reseller (shared/dedicated) | P3   |
| Nameserver kustom       | `ns1/ns2.domain-reseller.com`                    | P3   |
| Branding                | Logo, nama, warna, domain login panel sendiri    | P3   |
| Overselling             | On/off (diatur admin)                            | P3   |
| Pesan massal ke user    |                                                  | P3   |
| Tiket dari user         |                                                  | P3   |
| Backup & restore user   |                                                  | P4   |

---

## 1.3 Level Admin

### Akun

| Fitur                      | Keterangan                                                 | Fase |
| -------------------------- | ---------------------------------------------------------- | ---- |
| Buat user                  | ✅                                                         | P1   |
| Buat reseller & admin lain |                                                            | P3   |
| Semua akun                 | ✅ Cari, filter, aksi massal (suspend, hapus, ganti paket) | P1   |
| Paket user                 | ✅                                                         | P1   |
| Paket reseller             |                                                            | P3   |
| Pindah pemilik             | Pindahkan user antar reseller                              | P3   |
| Login as                   | Masuk sebagai reseller/user, tercatat di audit log         | P3   |
| Pesan massal               | Ke semua reseller/user                                     | P3   |
| Ganti username             |                                                            | P6   |

### Server

| Fitur                                 | Keterangan                                                                              | Fase |
| ------------------------------------- | --------------------------------------------------------------------------------------- | ---- |
| Info sistem                           | ✅ CPU, RAM, disk, load, uptime, versi software                                         | P1   |
| Service monitor                       | ✅ Status, start/stop/restart; restart otomatis kalau mati                              | P1   |
| Pengaturan server                     | ✅ Hostname, timezone, port panel, SSL panel, batas upload; bahasa default ikut fase PB | P1   |
| Rebuild config                        | ✅ Tulis ulang semua config dari database (setara `rewrite_confs`)                      | P1   |
| Nameserver default                    | ✅                                                                                      | P2   |
| DNS administration                    | ✅ Semua zone, template DNS default                                                     | P2   |
| Mail queue                            | ✅ Lihat (dengan alasan tertunda), kirim ulang, hapus (admin, menu Antrean Email)       | P2   |
| Batas kirim email                     | ✅ Per akun (bawaan 300) dan seluruh server per 24 jam, diatur di Pengaturan Server     | P2   |
| Process monitor                       | Lihat & hentikan proses                                                                 | P3   |
| Statistik pemakaian                   | Ranking akun per disk & bandwidth                                                       | P3   |
| Manajemen IP                          | Tambah/hapus IPv4/IPv6, berikan ke reseller                                             | P3   |
| File editor sistem                    | Hanya file config yang diizinkan                                                        | P3   |
| Template config kustom                | Override template vhost, PHP-FPM, DNS                                                   | P3   |
| Update panel                          | Satu klik, kanal stable/beta                                                            | P5   |
| Software manager (setara CustomBuild) | Pasang/hapus versi PHP & ekstensi, update phpMyAdmin/Roundcube, versi MariaDB           | P6   |
| Multi-server & DNS cluster            |                                                                                         | P6   |
| Plugin manager                        |                                                                                         | P6   |
| Skin manager                          |                                                                                         | P6   |

### Keamanan

| Fitur                     | Keterangan                                                                              | Fase |
| ------------------------- | --------------------------------------------------------------------------------------- | ---- |
| Audit log                 | ✅ Siapa melakukan apa, kapan, dari IP mana                                             | P0   |
| 2FA                       | ✅ Untuk semua level                                                                    | P1   |
| Riwayat login semua akun  |                                                                                         | P3   |
| Batas resource per user ★ | CPU, RAM, jumlah proses, IO (cgroups v2)                                                | P3   |
| Brute force monitor       | Deteksi login gagal di SSH, FTP, email, panel, WordPress; blokir otomatis; daftar putih | P5   |
| Firewall                  | Buka/tutup port, allow/deny IP (nftables)                                               | P5   |
| ModSecurity + OWASP CRS   | On/off global & per domain                                                              | P5   |
| Kebijakan keamanan        | Wajib 2FA untuk admin/reseller, aturan password                                         | P5   |
| Malware scan ★            | ClamAV/maldet                                                                           | P6   |

### Backup & migrasi

| Fitur                   | Keterangan                                         | Fase |
| ----------------------- | -------------------------------------------------- | ---- |
| Admin backup            | Backup user terpilih/semua ke lokal, FTP, SFTP, S3 | P4   |
| Jadwal & retensi        |                                                    | P4   |
| Restore                 | Pilih user & item                                  | P4   |
| Import dari cPanel      | Dari file backup cPanel (`cpmove-*`, `backup-*`)   | P4   |
| Import dari DirectAdmin | Dari file backup DirectAdmin                       | P4   |
| Transfer antar server   |                                                    | P4   |
| Backup sistem panel     | Database panel + config                            | P4   |

### Notifikasi & integrasi

| Fitur       | Keterangan                                                                                 | Fase |
| ----------- | ------------------------------------------------------------------------------------------ | ---- |
| CLI `rayxo` | ✅ Operasi lewat terminal                                                                  | P1   |
| Notifikasi  | Email & Telegram ★: service mati, disk penuh, user lewat quota, backup gagal, SSL gagal    | P3   |
| REST API    | Semua aksi panel tersedia lewat API                                                        | P3   |
| Modul WHMCS | Create, suspend, unsuspend, terminate, ganti paket, ganti password, SSO, sinkron pemakaian | P4   |

---

## 1.4 Proses latar belakang (tanpa UI)

| Proses                    | Keterangan                                                              | Fase |
| ------------------------- | ----------------------------------------------------------------------- | ---- |
| Quota disk & inode        | ✅ Filesystem quota                                                     | P1   |
| Rotasi log per domain     | ✅                                                                      | P1   |
| Perpanjangan SSL          | ✅                                                                      | P1   |
| Watchdog service          | ✅                                                                      | P1   |
| Hitung bandwidth          | Dari log web, email, FTP; suspend otomatis kalau lewat batas (opsional) | P3   |
| Snapshot pemakaian harian | Untuk grafik                                                            | P3   |
| Backup terjadwal          |                                                                         | P4   |
| Deteksi config drift ★    | Bandingkan config di disk dengan database                               | P5   |
