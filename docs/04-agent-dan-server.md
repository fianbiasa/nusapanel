# 4. Agent & Layout Server

## 4.1 Struktur kode agent (Go)

```
agent/
├── cmd/
│   ├── nusad/          # daemon agent
│   └── nusactl/        # CLI
├── internal/
│   ├── rpc/            # server JSON-RPC, cek SO_PEERCRED, dispatcher, kunci per akun
│   ├── validate/       # validasi username, domain, path, nilai config
│   ├── render/         # render template, tulis atomik, validasi, rollback
│   ├── runner/         # jalankan program tanpa shell, opsi turun ke UID user
│   ├── actions/        # satu paket per namespace: account, web, php, ssl, dns, db, mail, fs, ...
│   ├── daemons/        # brute force monitor, watchdog
│   └── services/       # wrapper systemd, nginx, apache, postfix, API PowerDNS, MariaDB
└── templates/          # template config default
```

## 4.2 Aturan validasi (berlaku di semua aksi)

| Input                         | Aturan                                                                                                                                                                                              |
| ----------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Username                      | `^[a-z][a-z0-9]{2,15}$`; bukan nama tercadang (`root`, `nusapanel`, `www-data`, `mysql`, `postfix`, `dovecot`, dll.); belum ada di `/etc/passwd`                                                    |
| Domain                        | Huruf kecil, punycode, maks 253 karakter, setiap label `^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`, minimal 2 label; bukan hostname server; tidak tumpang tindih dengan domain/subdomain milik akun lain |
| Path                          | Dibersihkan, symlink diresolve, harus di bawah `/home/<user>/`; operasi dijalankan sebagai UID user                                                                                                 |
| Nilai di config               | Tanpa newline, karakter kontrol, atau kutip; tipe & rentang dicek per kunci (contoh: `memory_limit` = angka + `M`/`G`, maksimal sesuai paket)                                                       |
| Perintah cron                 | Disimpan apa adanya, tapi selalu dijalankan sebagai user pemiliknya, tidak pernah sebagai root                                                                                                      |
| Nama service                  | Dari daftar putih                                                                                                                                                                                   |
| File sistem yang boleh diedit | Dari daftar putih                                                                                                                                                                                   |

## 4.3 Daftar aksi

Konvensi nama: `namespace.aksi`. "Sebagai user" = agent turun ke UID user sebelum menjalankan.

### account

| Aksi                            | Fungsi                                                                                                      | Fase    |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------- | ------- |
| `account.create`                | Buat user & grup Linux, home `0710 <user>:nusa-access`, struktur folder, quota, slice systemd               | P1      |
| `account.apply_limits`          | Quota disk/inode; batas CPU, RAM, proses, IO                                                                | P1 / P3 |
| `account.suspend` / `unsuspend` | Kunci login, vhost → halaman suspended, hentikan PHP-FPM & cron. Mail & FTP otomatis ditolak lewat view SQL | P1      |
| `account.delete`                | Hentikan proses; hapus config, database, mail, home, dan user Linux                                         | P1      |
| `account.set_password`          | Password Linux (untuk SFTP/SSH)                                                                             | P1      |
| `account.rename`                | Ganti username                                                                                              | P6      |

### web

| Aksi                | Fungsi                                                                                                                                                              | Fase |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---- |
| `web.apply_domain`  | Render ulang semua config satu domain dari state lengkap: vhost Nginx & Apache, subdomain, pointer, redirect, www/HTTPS, proteksi folder, error page, hotlink, MIME | P1   |
| `web.remove_domain` | Hapus config domain (file di `public_html` tidak dihapus kecuali diminta)                                                                                           | P1   |
| `web.rebuild_all`   | Render ulang semua domain                                                                                                                                           | P1   |
| `web.reload`        | Validasi lalu reload Nginx & Apache                                                                                                                                 | P1   |

### php

| Aksi                                                           | Fungsi                                             | Fase |
| -------------------------------------------------------------- | -------------------------------------------------- | ---- |
| `php.apply_pool`                                               | Pool PHP-FPM per user per versi + override php.ini | P1   |
| `php.remove_pool`                                              |                                                    | P1   |
| `php.versions`                                                 | Daftar versi PHP terpasang                         | P1   |
| `php.install_version` / `remove_version` / `install_extension` |                                                    | P6   |

### ssl

| Aksi                               | Fungsi                                                  | Fase    |
| ---------------------------------- | ------------------------------------------------------- | ------- |
| `ssl.issue`                        | Let's Encrypt via lego (HTTP-01; DNS-01 untuk wildcard) | P1 / P2 |
| `ssl.install_custom`               | Simpan cert & key, cek pasangan cocok dan masa berlaku  | P1      |
| `ssl.remove`                       |                                                         | P1      |
| `ssl.issue_hostname`               | SSL untuk hostname server: panel, mail, FTP             | P1      |
| `ssl.generate_csr` / `self_signed` |                                                         | P3      |

### dns

| Aksi                            | Fungsi                                                     | Fase |
| ------------------------------- | ---------------------------------------------------------- | ---- |
| `dns.sync_zone`                 | Samakan seluruh isi zone di PowerDNS dengan database panel | P2   |
| `dns.delete_zone`               |                                                            | P2   |
| `dns.dnssec_enable` / `disable` |                                                            | P6   |

### db (MariaDB)

| Aksi                                             | Fungsi                                                            | Fase |
| ------------------------------------------------ | ----------------------------------------------------------------- | ---- |
| `db.create` / `drop`                             |                                                                   | P1   |
| `db.user_create` / `user_drop` / `user_password` |                                                                   | P1   |
| `db.grant` / `revoke`                            |                                                                   | P1   |
| `db.sizes`                                       | Ukuran semua database                                             | P1   |
| `db.sso_token`                                   | User MariaDB sementara (kedaluwarsa 1 jam) untuk login phpMyAdmin | P1   |
| `db.remote_hosts_apply`                          | Buat `user@host` sesuai daftar host                               | P3   |
| `db.dump` / `import`                             | Streaming, sebagai user                                           | P3   |
| `db.check` / `repair` / `optimize`               |                                                                   | P3   |

### ftp

| Aksi             | Fungsi                                                                                    | Fase |
| ---------------- | ----------------------------------------------------------------------------------------- | ---- |
| `ftp.ensure_dir` | Buat folder tujuan sebagai user. Akun FTP sendiri dibaca Pure-FTPd langsung dari view SQL | P1   |

### mail

| Aksi                                               | Fungsi                                                                 | Fase |
| -------------------------------------------------- | ---------------------------------------------------------------------- | ---- |
| `mail.domain_apply`                                | Folder mail, kunci DKIM (Rspamd), aktifkan domain                      | P2   |
| `mail.domain_remove`                               |                                                                        | P2   |
| `mail.mailbox_remove`                              | Hapus Maildir                                                          | P2   |
| `mail.sieve_apply`                                 | Tulis skrip Sieve (filter, vacation, autoresponder, spam) sebagai user | P2   |
| `mail.quota_usage`                                 | Lewat `doveadm quota get`                                              | P2   |
| `mail.queue_list` / `queue_flush` / `queue_delete` |                                                                        | P2   |
| `mail.ratelimit_apply`                             | Batas kirim per akun/domain di Rspamd                                  | P2   |
| `mail.webmail_sso`                                 | Token sekali pakai untuk Roundcube                                     | P2   |

### fs (file manager, semuanya sebagai user)

`fs.list`, `fs.stat`, `fs.read`, `fs.write`, `fs.mkdir`, `fs.rename`, `fs.copy`, `fs.move`, `fs.delete` (ke `.trash`), `fs.chmod`, `fs.compress`, `fs.extract`, `fs.upload_chunk`, `fs.upload_finalize`, `fs.search`, `fs.disk_usage`. Semua di P1.

### cron & ssh

| Aksi             | Fungsi                                                                                                        | Fase |
| ---------------- | ------------------------------------------------------------------------------------------------------------- | ---- |
| `cron.apply`     | Tulis ulang jadwal cron user dari database; perintah dijalankan sebagai user di dalam slice resource miliknya | P1   |
| `ssh.keys_apply` | Tulis `authorized_keys` user                                                                                  | P3   |
| `ssh.access_set` | Shell di dalam jail, atau `nologin`                                                                           | P3   |

### backup

| Aksi                                          | Fungsi                                                                                 | Fase |
| --------------------------------------------- | -------------------------------------------------------------------------------------- | ---- |
| `backup.create`                               | Arsip akun (file, database, mail, config) → `.tar.zst` + manifest JSON, dengan progres | P4   |
| `backup.restore`                              | Restore penuh atau sebagian                                                            | P4   |
| `backup.upload` / `download`                  | Ke/dari tujuan remote lewat rclone                                                     | P4   |
| `backup.import_cpanel` / `import_directadmin` | Baca format panel lain, ubah ke state panel, lalu restore                              | P4   |
| `backup.system`                               | Database panel + `/etc/nusapanel`                                                      | P4   |

### system

| Aksi                                | Fungsi                                                      | Fase |
| ----------------------------------- | ----------------------------------------------------------- | ---- |
| `system.info`                       | CPU, RAM, disk, load, uptime, versi                         | P0   |
| `service.list` / `service.action`   | start/stop/restart/reload; nama dari daftar putih           | P1   |
| `system.set_hostname`               |                                                             | P1   |
| `system.processes` / `process_kill` |                                                             | P3   |
| `system.file_read` / `file_write`   | Hanya file di daftar putih; backup otomatis sebelum ditulis | P3   |
| `system.ip_add` / `ip_remove`       |                                                             | P3   |
| `system.update`                     | Update panel & agent                                        | P5   |

### security

| Aksi                               | Fungsi                                | Fase |
| ---------------------------------- | ------------------------------------- | ---- |
| `firewall.apply`                   | Render ruleset nftables dari database | P5   |
| `firewall.block_ip` / `unblock_ip` |                                       | P5   |
| `modsec.apply`                     | On/off ModSecurity global/per domain  | P5   |

### stats

| Aksi              | Fungsi                                     | Fase |
| ----------------- | ------------------------------------------ | ---- |
| `stats.disk`      | Quota & inode semua akun                   | P1   |
| `stats.bandwidth` | Hitung byte web/mail/FTP per akun dari log | P3   |
| `stats.resources` | CPU & RAM per akun dari cgroups            | P3   |
| `stats.goaccess`  | Laporan GoAccess per domain                | P3   |

### app (P6)

`app.wordpress_install`, `app.wordpress_list`, `app.wordpress_update`, `app.wordpress_reset_password` (WP-CLI sebagai user), `app.node_apply`, `app.redis_apply`, `app.git_deploy`.

### Daemon di dalam agent

| Daemon   | Fungsi                                                                                        | Fase |
| -------- | --------------------------------------------------------------------------------------------- | ---- |
| watchdog | Cek service tiap menit, restart kalau mati, lapor ke panel                                    | P1   |
| bfm      | Baca log SSH, Dovecot, Postfix, Pure-FTPd, panel; blokir IP lewat nftables kalau lewat ambang | P5   |

---

## 4.4 Layout folder server

```
/home/<user>/                           0710  <user>:nusa-access
├── domains/<domain>/
│   ├── public_html/                    document root
│   ├── private_html -> public_html     kompatibel DirectAdmin
│   └── stats/                          laporan GoAccess
├── imap/<domain>/<mailbox>/Maildir/    email
├── backups/                            backup milik user
├── tmp/                                tmp & session PHP pribadi
└── .trash/                             tempat sampah file manager

/usr/local/nusapanel/
├── panel/                              aplikasi Laravel
├── bin/nusad, bin/nusactl
└── templates/                          template default (ikut ter-update)

/etc/nusapanel/
├── nusad.yaml                          config agent
├── templates/custom/                   override template (tidak tertimpa update)
├── php-fpm/<versi>/<user>.conf         pool PHP-FPM per user
└── ssl/<domain>/{cert,key,chain}.pem   0600 root

/etc/nginx/nusapanel/<user>/<domain>.conf
/etc/apache2/nusapanel/<user>/<domain>.conf

/var/log/nusapanel/                     log panel & agent
/var/log/nusapanel/domains/<domain>.{access,error}.log
/var/lib/nusapanel/                     state agent, backup admin
/run/nusapanel/agent.sock
```

## 4.5 Port

| Port            | Layanan        | Terbuka untuk                   |
| --------------- | -------------- | ------------------------------- |
| 22              | SSH/SFTP       | Publik (port bisa diganti)      |
| 21, 35000–35999 | FTP + passive  | Publik                          |
| 25, 465, 587    | SMTP           | Publik                          |
| 110, 995        | POP3           | Publik                          |
| 143, 993        | IMAP           | Publik                          |
| 4190            | ManageSieve    | Publik (opsional)               |
| 53 tcp/udp      | DNS            | Publik                          |
| 80, 443         | Web            | Publik                          |
| 2222            | Panel          | Publik (bisa dibatasi per IP)   |
| 8080            | Apache backend | 127.0.0.1                       |
| 3306            | MariaDB        | Lokal (remote kalau diaktifkan) |
| 6379            | Redis          | Lokal                           |
| 8081            | API PowerDNS   | 127.0.0.1                       |
| 11332–11334     | Rspamd         | 127.0.0.1                       |

## 4.6 Service systemd milik panel

| Unit                               | Fungsi                                               |
| ---------------------------------- | ---------------------------------------------------- |
| `nusad.service`                    | Agent                                                |
| `nusapanel-queue.service`          | Queue worker Laravel                                 |
| `nusapanel-scheduler.service`      | Scheduler Laravel                                    |
| `nusa-php<versi>@<user>.service`   | PHP-FPM per user (contoh: `nusa-php84@budi.service`) |
| `nusa.slice` → `nusa-<user>.slice` | Batas resource per user                              |
