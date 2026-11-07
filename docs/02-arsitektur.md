# 2. Arsitektur

## 2.1 Gambaran umum

```
                         ┌──────────────────────────────────────────────┐
 Browser / WHMCS / API ──▶  Panel web (Laravel + React)     :2222        │
                         │  user Linux: nusapanel (BUKAN root)           │
                         │  • login, 2FA, otorisasi, cek limit paket     │
                         │  • setiap perubahan = 1 task di database      │
                         └───────────────┬──────────────────────────────┘
                                         │ queue worker (antrean di MariaDB)
                                         ▼
                         /run/nusapanel/agent.sock  (JSON-RPC, cek UID pemanggil)
                                         │
                         ┌───────────────▼──────────────────────────────┐
                         │  Agent nusad (Go, root)                       │
                         │  • hanya aksi terdaftar, input divalidasi     │
                         │  • render config dari template, tulis atomik  │
                         │  • operasi file dijalankan sebagai UID user   │
                         └───────────────┬──────────────────────────────┘
        ┌──────────┬──────────┬──────────┼──────────┬──────────┬──────────┐
     Nginx →    PHP-FPM    MariaDB   PowerDNS   Postfix +   Pure-FTPd  nftables
     Apache    per user                         Dovecot +
                                                Rspamd
```

## 2.2 Komponen

| Komponen      | Teknologi                                                                                | Jalan sebagai | Tugas                                                         |
| ------------- | ---------------------------------------------------------------------------------------- | ------------- | ------------------------------------------------------------- |
| Panel web     | Laravel + Inertia + React (TypeScript, Tailwind, shadcn/ui), login lewat Laravel Fortify | `nusapanel`   | UI, login, otorisasi, validasi, cek limit paket, membuat task |
| Queue worker  | Laravel queue (driver database, MariaDB)                                                 | `nusapanel`   | Mengambil task dan memanggil agent                            |
| Scheduler     | Laravel scheduler                                                                        | `nusapanel`   | Jadwal backup, perpanjang SSL, kumpulkan statistik            |
| Agent `nusad` | Go, satu binary                                                                          | root          | **Satu-satunya** komponen yang mengubah sistem                |
| CLI `nusactl` | Go                                                                                       | root          | Operasi darurat & otomasi lewat terminal                      |

## 2.3 Prinsip desain

1. **Panel tidak pernah root.** PHP milik panel jalan dengan fungsi `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen` dimatikan. Satu-satunya jalan ke sistem adalah agent.
2. **Agent hanya kenal aksi yang terdaftar.** Tidak ada aksi "jalankan perintah bebas". Setiap aksi punya parameter bertipe dan aturan validasi sendiri ([daftar aksi](04-agent-dan-server.md)).
3. **Database panel = sumber kebenaran.** Agent menerima _state lengkap_ (misalnya seluruh konfigurasi satu domain), lalu merender config dari template. Hasilnya idempoten: aksi yang sama dijalankan dua kali hasilnya tetap sama.
4. **Semua bisa dibangun ulang.** `nusactl rebuild` menulis ulang semua config dari database (setara `rewrite_confs` di DirectAdmin). Penting saat template di-update atau server dipulihkan.
5. **Operasi file atas nama user.** File manager, cron, backup user, dan WP-CLI dijalankan agent setelah turun hak ke UID user tersebut. Kalau ada bug di panel yang meloloskan path yang salah, izin OS tetap membatasi kerusakan.
6. **Tulis config dengan aman.** Tulis ke file sementara → validasi (`nginx -t`, `apachectl configtest`, `postfix check`, `php-fpm -t`) → rename atomik → reload. Kalau gagal, versi lama dikembalikan dan error dilaporkan.
7. **Pakai paket distro, bukan compile.** PHP dari repo ondrej/sury, sisanya dari repo resmi Ubuntu. Update keamanan cukup lewat `apt`.

## 2.4 Komunikasi panel ↔ agent

- Lokal: Unix socket `/run/nusapanel/agent.sock`, pemilik `root:nusapanel`, mode `0660`.
- Agent memeriksa UID pemanggil lewat `SO_PEERCRED`; hanya `nusapanel` dan root yang diterima.
- Format JSON-RPC 2.0, satu request dan satu response per baris (diakhiri `\n`):

    ```json
    {"jsonrpc":"2.0","id":"<task-uuid>","method":"web.apply_domain","params":{"account":"budi","domain":{...}}}
    ```

- Aksi panjang (backup, restore, import) mengirim progres bertahap; panel menyimpannya ke tabel `tasks`.
- Multi-server (P6): agent di server lain didengarkan lewat TCP + mTLS dengan sertifikat per server. Daftar aksinya sama persis.

## 2.5 Alur satu aksi (contoh: tambah domain)

1. User klik **Tambah domain**.
2. Panel memeriksa izin (policy), validasi input, limit paket (jumlah domain), dan memastikan domain belum dipakai akun lain.
3. Panel menyimpan domain (status `pending`) dan membuat task `web.apply_domain`. Kalau akunnya sendiri masih dibuat, task ini baru dikirim setelah `account.create` berhasil.
4. Queue worker mengirim task ke agent. Ada kunci per akun, jadi tidak ada dua task yang mengubah akun yang sama secara bersamaan.
5. Agent membuat folder, merender vhost Nginx & Apache dan pool PHP-FPM, lalu reload service.
6. Panel mengubah status domain menjadi `active`, atau `failed` beserta pesan error.
7. Panel memicu task lanjutan: zone DNS, domain email, lalu SSL Let's Encrypt.

UI menampilkan progres task lewat polling Inertia dulu; websocket (Laravel Reverb) menyusul.

## 2.6 Stack layanan

| Fungsi                      | Software                                  | Catatan                                                                                                                                                          |
| --------------------------- | ----------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Web (depan)                 | Nginx                                     | Port 80/443, terminasi SSL, file statis                                                                                                                          |
| Web (belakang)              | Apache 2.4 (event MPM)                    | `127.0.0.1:8080`, supaya `.htaccess` jalan                                                                                                                       |
| PHP                         | PHP-FPM, banyak versi                     | Satu proses PHP-FPM per user per versi, jalan sebagai user itu, `pm = ondemand`                                                                                  |
| Database                    | MariaDB LTS                               | + phpMyAdmin                                                                                                                                                     |
| DNS                         | PowerDNS Authoritative (backend MySQL)    | Dikelola lewat API PowerDNS; siap DNSSEC & cluster                                                                                                               |
| Email (kirim/terima)        | Postfix                                   | Sejak P0: kirim-saja untuk email panel (hanya 127.0.0.1, IPv4). P2: lengkap untuk customer, domain/akun/alias dibaca dari view SQL                               |
| Email (IMAP/POP3)           | Dovecot                                   | + LMTP, SASL, Sieve, quota                                                                                                                                       |
| Antispam & DKIM             | Rspamd + Redis                            | P2, sekaligus pembatas jumlah kirim. Sebelum itu, DKIM email panel ditangani OpenDKIM (selector `nusapanel`)                                                     |
| SSL hostname/panel          | certbot (Let's Encrypt)                   | Dipasang `setup.sh` lewat webroot di port 80, diperpanjang `certbot.timer`                                                                                       |
| Webmail                     | Roundcube                                 |                                                                                                                                                                  |
| FTP                         | Pure-FTPd (auth MySQL)                    | TLS wajib, passive port 35000–35999                                                                                                                              |
| SSL domain customer         | certbot (Let's Encrypt), dijalankan agent | HTTP-01 lewat webroot bersama `/var/www/nusapanel-acme`; diperpanjang `certbot.timer` (nginx di-reload otomatis). DNS-01/wildcard menyusul bersama PowerDNS (P2) |
| Firewall                    | nftables                                  | Set IP terblokir dengan timeout                                                                                                                                  |
| Statistik situs             | GoAccess                                  |                                                                                                                                                                  |
| Backup remote               | rclone                                    | FTP, SFTP, S3, Google Drive, dll.                                                                                                                                |
| Cache, queue, session panel | MariaDB (driver database)                 | Bukan Redis: PHP customer di server yang sama bisa menyambung ke Redis lokal tanpa password dan membaca session admin                                            |
| Batas resource              | systemd slice (cgroups v2)                | CPU, RAM, jumlah proses, IO per user                                                                                                                             |

### Mode web server

Default: **Nginx → Apache → PHP-FPM**. Alasannya: `.htaccess` tetap jalan (penting untuk WordPress dan pelanggan pindahan dari cPanel/DirectAdmin), sementara file statis tetap cepat lewat Nginx.

Karena Apache ada di belakang proxy, perlu:

- `mod_remoteip` supaya log dan aplikasi melihat IP asli pengunjung;
- `SetEnvIf X-Forwarded-Proto https HTTPS=on` supaya WordPress tahu sedang di HTTPS.

Mode lain (Nginx saja, OpenLiteSpeed) bisa ditambah di P6, karena semua config dirender dari template.

## 2.7 Isolasi antar user

| Lapisan   | Cara                                                                                                                                                                 |
| --------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Akun      | Setiap akun = user Linux sendiri                                                                                                                                     |
| Home      | `/home/<user>` milik `<user>:nusa-access`, mode `0710`. Hanya pemiliknya dan web server (anggota grup `nusa-access`) yang bisa masuk; PHP milik user lain tidak bisa |
| PHP       | PHP-FPM per user sebagai service systemd sendiri, jalan sebagai user itu; `open_basedir` ke home + tmp pribadi; fungsi berbahaya dimatikan (bisa diatur admin)       |
| Symlink   | Nginx `disable_symlinks if_not_owner`, Apache `SymLinksIfOwnerMatch`, supaya user tidak bisa membuat symlink ke `wp-config.php` milik orang lain                     |
| Resource  | PHP-FPM, cron, dan sesi SSH user ditempatkan di slice systemd `nusa-<user>.slice` dengan batas CPU, RAM, proses, IO                                                  |
| Email     | Disimpan di `/home/<user>/imap/...` milik user itu, jadi ikut terhitung di quota disk user                                                                           |
| SFTP      | Tanpa chroot, dibatasi oleh permission home `0710`                                                                                                                   |
| SSH shell | Hanya kalau paket mengizinkan, dan jalan di dalam jail                                                                                                               |

## 2.8 Panel (Laravel)

- Satu aplikasi Laravel + Inertia + React. UI dibangun sendiri di atas starter kit resmi Laravel, jadi semua komponen (shadcn/ui) adalah kode kita, bukan framework admin siap pakai.
- Area dipisah lewat middleware peran: `/admin/*` hanya admin, `/reseller/*` admin & reseller, sisanya halaman level user. Menu sidebar menyesuaikan peran.
- Login satu pintu (username atau email) dengan 2FA dan passkey dari Laravel Fortify. Pendaftaran publik dimatikan; akun hanya dibuat admin/reseller.
- Verifikasi email hanya diwajibkan kalau panel benar-benar bisa mengirim email (mailer bukan `log`). Selama belum ada SMTP/server email, ganti email tidak mengunci akun; email baru tetap tercatat "belum terverifikasi" dan akan diminta verifikasi begitu email aktif.
- Port 2222 (sama seperti DirectAdmin, familiar bagi pelanggan lama) dengan SSL milik hostname server.
- **Props Inertia terlihat di source halaman** (atribut `data-page`), begitu juga bundle JavaScript. Jadi tidak ada data rahasia yang boleh dikirim sebagai props, dan semua izin dicek di server.
- Lapisan kode:
    - **Policies**: siapa boleh apa. Admin: semua. Reseller: akun miliknya. User: resource miliknya.
    - **Services**: logika bisnis + cek limit (`DomainService::create()`, dll.). UI, REST API, dan modul WHMCS memanggil service yang sama.
    - **Agent\AgentClient**: klien JSON-RPC ke socket agent (driver `socket` di server, `fake` untuk pengembangan di Mac).
    - **Jobs**: satu job per jenis task.
- Login as (impersonation) selalu dicatat dengan `impersonator_id`.

## 2.9 Ringkasan kontrol keamanan

| Ancaman                | Kontrol                                                                                                                                                                                                                                                                     |
| ---------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Injeksi perintah       | Agent menjalankan program tanpa shell, argumen terpisah; tidak ada aksi perintah bebas                                                                                                                                                                                      |
| Injeksi config         | Nilai yang masuk template divalidasi ketat (tanpa newline, karakter kontrol, kutip); kunci php.ini dari daftar putih                                                                                                                                                        |
| Path traversal         | Path dibersihkan, harus di bawah home user, operasi file dijalankan sebagai UID user                                                                                                                                                                                        |
| Membaca file user lain | Home `0710` + grup `nusa-access`, PHP-FPM per user, `open_basedir`, proteksi symlink                                                                                                                                                                                        |
| Pengambilalihan domain | Domain tidak bisa ditambahkan kalau domain itu, atau domain induknya, milik akun lain                                                                                                                                                                                       |
| Akun panel dibobol     | 2FA, rate limit login, brute force monitor, riwayat login, notifikasi login dari IP baru                                                                                                                                                                                    |
| Panel dibobol          | Batas utamanya: panel jalan sebagai user biasa dan agent hanya menerima aksi terdaftar yang memeriksa ulang kepemilikan resource. Fungsi exec/putenv/mail dimatikan di PHP panel hanya sebagai lapisan tambahan. Cache config (berisi APP_KEY) hanya bisa dibaca user panel |
| Penyalahgunaan email   | Batas kirim per akun per hari (Rspamd), antrean mail dipantau                                                                                                                                                                                                               |
| Secret bocor           | Password DB customer tidak disimpan; kredensial backup remote dienkripsi; private key SSL hanya di disk (`0600 root`)                                                                                                                                                       |

## 2.10 Kompatibilitas DirectAdmin

Struktur folder user mengikuti DirectAdmin (`domains/<domain>/public_html`, `imap/<domain>/<mailbox>/Maildir`). Hasilnya:

- migrasi dari DirectAdmin hampir tanpa mengubah path di aplikasi customer;
- pelanggan lama tidak bingung;
- port panel tetap 2222.

## 2.11 Pengembangan lokal (Herd)

- Panel dilayani Herd di `nusapanel.test`. SSL, versi PHP, dan pengaturan situs lainnya diatur dari aplikasi Herd.
- Agent hanya bisa jalan di Linux (butuh systemd, `useradd`, `SO_PEERCRED`, dll.). Di Mac, panel memakai `AGENT_DRIVER=fake`, yang mensimulasikan jawaban agent supaya UI bisa dikembangkan tanpa server.
- Agent Go dikembangkan dan diuji di VM Ubuntu (OrbStack/Multipass), lalu panel di server memakai `AGENT_DRIVER=socket`.
