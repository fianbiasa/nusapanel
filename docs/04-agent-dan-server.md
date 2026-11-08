# 4. Agent & Layout Server

## 4.1 Struktur kode agent (Go)

Tanda ✅ = sudah ada (P0); sisanya menyusul sesuai fase.

```
agent/
├── cmd/
│   ├── nusad/          ✅ daemon agent
│   └── nusactl/        ✅ CLI (ping, call <method> [params])
├── internal/
│   ├── rpc/            ✅ server JSON-RPC, socket, cek UID pemanggil, registry method, kunci per akun
│   ├── peercred/       ✅ UID pemanggil dari kernel (SO_PEERCRED di Linux, LOCAL_PEERCRED di Mac)
│   ├── client/         ✅ klien socket (dipakai nusactl dan test)
│   ├── actions/        ✅ daftar method; menyusul satu paket per namespace: account, web, php, ssl, dns, db, mail, fs, ...
│   ├── sysinfo/        ✅ data untuk system.info (Linux; Mac khusus pengembangan)
│   ├── validate/       ✅ username, domain, versi PHP; menyusul path dan nilai config
│   ├── version/        ✅ versi, diisi saat build
│   ├── runner/         ✅ jalankan program tanpa shell; menyusul: opsi turun ke UID user
│   ├── sysuser/        ✅ user/grup Linux, folder home & domain tanpa mengikuti symlink
│   ├── webserver/      ✅ vhost Nginx & Apache, pool PHP-FPM: render template, tulis atomik, validasi, reload, rollback
│   ├── daemons/        brute force monitor, watchdog
│   └── services/       wrapper postfix, API PowerDNS, MariaDB, ...
├── packaging/
│   └── nusad.service   ✅ unit systemd
└── Makefile            ✅ build (Mac), linux (amd64/arm64 static), test, vet, check
```

Template config (vhost, pool PHP-FPM, unit systemd, halaman default & suspended) ada di `internal/webserver/templates/` dan ditanam di binary `nusad`, jadi tidak bisa diubah tanpa root. Override template per server (`/etc/nusapanel/templates/custom/`) menyusul di P3.

```

```

### Menjalankan nusad

```
nusad --socket /run/nusapanel/agent.sock --allow-user nusapanel --socket-group nusapanel
```

| Flag             | Default                     | Fungsi                                                   |
| ---------------- | --------------------------- | -------------------------------------------------------- |
| `--socket`       | `/run/nusapanel/agent.sock` | Lokasi Unix socket                                       |
| `--allow-user`   | `nusapanel`                 | User yang boleh memanggil agent (selain root)            |
| `--socket-group` | `nusapanel`                 | Grup pemilik socket; kosong = tidak diubah               |
| `--call-timeout` | `10m`                       | Batas waktu satu panggilan, termasuk menunggu kunci akun |

Socket dibuat dengan izin `0660` (umask ketat saat dibuat, jadi tidak ada celah sebelum izin final). Saat menerima `SIGTERM`, nusad berhenti menerima koneksi, menunggu panggilan yang sedang berjalan selesai, lalu menghapus socket. Log berformat JSON ke stderr (dibaca journald).

### Protokol

JSON-RPC 2.0, satu koneksi = satu request dan satu response, masing-masing satu baris JSON diakhiri `\n` (maks 1 MiB). `params` selalu objek; field yang tidak dikenal ditolak.

| Kode     | Arti                                                                  |
| -------- | --------------------------------------------------------------------- |
| `-32700` | Request bukan JSON yang valid                                         |
| `-32600` | Request tidak valid (tanpa `jsonrpc`/`method`/`id`, terlalu besar)    |
| `-32601` | Method tidak ada                                                      |
| `-32602` | Params tidak valid                                                    |
| `-32603` | Error internal; detail hanya di log agent, tidak dikirim ke pemanggil |
| `-32000` | Aksi gagal; pesannya aman ditampilkan ke user panel                   |
| `-32003` | Pemanggil tidak diizinkan (dikirim dengan `id: null`)                 |
| `-32004` | Akun sedang dikunci task lain sampai batas waktu habis                |

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

| Aksi                            | Fungsi                                                                                                                                                                                                      | Fase  |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----- |
| `account.create`                | Buat user & grup Linux (penanda GECOS `nusapanel`), home `0710 <user>:nusa-access`, `domains`/`tmp`/`backups`/`.trash`; quota & slice systemd menyusul                                                      | P1 ✅ |
| `account.set_quota`             | ✅ `{username, disk_mb, inodes}` (0 = tanpa batas): kuota user filesystem lewat `setquota` (soft = hard). `{applied: false}` kalau kuota server belum aktif. `account.delete` membersihkan kuota UID-nya    | P1    |
| `quota.report`                  | ✅ Pemakaian & batas disk/inode semua akun nusapanel (`repquota`), dikumpulkan panel tiap 15 menit                                                                                                          | P1    |
| `account.apply_limits`          | Batas CPU, RAM, proses, IO (cgroups)                                                                                                                                                                        | P3    |
| `account.suspend` / `unsuspend` | ✅ kunci password + akun kedaluwarsa (blok SSH-key/SFTP), semua website menampilkan halaman 503, PHP-FPM dihentikan (tetap mati setelah reboot); menyusul: cron. Mail & FTP otomatis ditolak lewat view SQL | P1    |
| `account.delete`                | ✅ matikan & hapus pool PHP-FPM, hapus vhost & log domain, hentikan proses, hapus home & user Linux (akun sistem ditolak); menyusul: database, mail, dst.                                                   | P1    |
| `account.set_password`          | Password Linux (untuk SFTP/SSH)                                                                                                                                                                             | P1    |
| `account.rename`                | Ganti username                                                                                                                                                                                              | P6    |

### web

| Aksi                | Fungsi                                                                                                                                                                                                                                                                                                                      | Fase |
| ------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---- |
| `web.apply_domain`  | Render ulang semua config satu domain dari state lengkap. ✅ `{username, domain, php_version}`: folder `domains/<domain>/public_html` (+ `index.html` awal), vhost Nginx (port 80, `www.` ikut) → Apache → pool PHP-FPM akun itu. Menyusul: subdomain, pointer, redirect, HTTPS, proteksi folder, error page, hotlink, MIME | P1   |
| `web.remove_domain` | ✅ Hapus vhost Nginx & Apache dan log domain itu (supaya pemilik domain berikutnya tidak mewarisi log lama); file di `domains/<domain>` tetap ada                                                                                                                                                                           | P1   |
| `web.rebuild_all`   | Render ulang semua domain                                                                                                                                                                                                                                                                                                   | P1   |
| `web.reload`        | Validasi lalu reload Nginx & Apache                                                                                                                                                                                                                                                                                         | P1   |

### php

| Aksi                                                           | Fungsi                                                                                                                                                                  | Fase |
| -------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---- |
| `php.apply_pool`                                               | Pool PHP-FPM per user per versi + override php.ini. Sekarang pool dibuat & dijalankan otomatis oleh `web.apply_domain`; aksi tersendiri menyusul bersama pengaturan PHP | P1   |
| `php.remove_pool`                                              | ✅ `{username, php_version}`: matikan & hapus pool; dilewati kalau masih ada vhost akun itu yang memakainya                                                             | P1   |
| `php.versions`                                                 | Daftar versi PHP terpasang                                                                                                                                              | P1   |
| `php.install_version` / `remove_version` / `install_extension` |                                                                                                                                                                         | P6   |

### ssl

| Aksi                               | Fungsi                                                                                                                                                                                                                                                                                                  | Fase    |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------- |
| `ssl.issue`                        | ✅ `{username, domain}`: cek dulu domain (dan `www.`) benar-benar sampai ke server ini lewat HTTP (file uji di webroot ACME, jadi A record langsung maupun proxy Cloudflare lolos), lalu Let's Encrypt via certbot (HTTP-01). `www.` ikut hanya kalau juga sampai. DNS-01 untuk wildcard menyusul di P2 | P1 / P2 |
| `ssl.install_custom`               | Simpan cert & key, cek pasangan cocok dan masa berlaku                                                                                                                                                                                                                                                  | P1      |
| `ssl.delete`                       | ✅ `{username, domain}`: hapus sertifikat (`certbot delete`); ditolak selama vhost masih memakai HTTPS. `web.remove_domain` dan `account.delete` juga menghapus sertifikat domainnya                                                                                                                    | P1      |
| `ssl.issue_hostname`               | SSL untuk hostname server: panel, mail, FTP                                                                                                                                                                                                                                                             | P1      |
| `ssl.generate_csr` / `self_signed` |                                                                                                                                                                                                                                                                                                         | P3      |

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

| Aksi                                | Fungsi                                                      | Fase  |
| ----------------------------------- | ----------------------------------------------------------- | ----- |
| `agent.ping`                        | Cek agent hidup + versi (dipakai installer & monitoring)    | P0 ✅ |
| `system.info`                       | CPU, RAM, disk, load, uptime, versi                         | P0 ✅ |
| `service.list` / `service.action`   | start/stop/restart/reload; nama dari daftar putih           | P1    |
| `system.set_hostname`               |                                                             | P1    |
| `system.processes` / `process_kill` |                                                             | P3    |
| `system.file_read` / `file_write`   | Hanya file di daftar putih; backup otomatis sebelum ditulis | P3    |
| `system.ip_add` / `ip_remove`       |                                                             | P3    |
| `system.update`                     | Update panel & agent                                        | P5    |

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

/usr/local/nusapanel/                   milik root, tidak bisa ditulis panel
├── releases/<versi>/                   satu folder per rilis (isi paket)
│   ├── panel/                          aplikasi Laravel (vendor & aset sudah jadi)
│   │   ├── .env -> /etc/nusapanel/panel.env
│   │   └── storage -> /var/lib/nusapanel/storage
│   ├── bin/nusad, bin/nusactl
│   ├── systemd/, config/               unit systemd & template config panel
│   └── VERSION
├── current -> releases/<versi>         rilis aktif; update = ganti symlink ini
└── bin -> current/bin

/usr/local/bin/nusactl -> /usr/local/nusapanel/bin/nusactl

/etc/nusapanel/                         0750 root:nusapanel
├── panel.env                           0640 root:nusapanel (APP_KEY, password DB panel)
├── ssl/panel.{crt,key}                 symlink ke sertifikat Let's Encrypt hostname (self-signed kalau gagal)
├── dns-records.txt                     rekaman DNS (A, PTR, SPF, DKIM, DMARC) + statusnya
├── installed, admin-created            penanda instalasi (dipakai setup.sh saat diulang)
├── templates/custom/                   override template (tidak tertimpa update)
├── php-fpm/<versi>/<user>.conf         pool PHP-FPM per user
└── ssl/<domain>/{cert,key,chain}.pem   0600 root (sertifikat kustom, menyusul)

/etc/nginx/nusapanel/<user>/<domain>.conf        vhost Nginx (port 80, plus 443 kalau SSL aktif), ditulis nusad
/etc/nginx/nusapanel/<user>/suspended.inc        hanya ada selama akun di-suspend (halaman 503)
/etc/nginx/conf.d/nusapanel-sites.conf           include semua vhost di atas
/etc/nginx/sites-enabled/nusapanel-default.conf  default server: port 80 → 404, port 443 → handshake ditolak (host tak dikenal & IP)
/etc/apache2/nusapanel/<user>/<domain>.conf      vhost Apache (127.0.0.1:8080), ditulis nusad
/etc/apache2/conf-available/nusapanel.conf       mod_remoteip, HTTPS dari proxy, aturan /home
/etc/systemd/system/nusa-php<versi>@.service     unit template pool PHP-FPM, ditulis nusad
/run/nusa-php/php<versi>-<user>.sock             socket pool PHP-FPM (0660 <user>:www-data)

/var/log/nusapanel/                     log nginx panel (access/error)
/var/log/nusapanel/domains/<domain>.{access,error,apache-error}.log
/var/log/nusapanel-install.log          log setup.sh (0600, tanpa password)
/etc/letsencrypt/live/<hostname>/       sertifikat Let's Encrypt hostname (certbot)
/etc/letsencrypt/live/<domain>/         sertifikat Let's Encrypt domain customer (certbot, nama sertifikat = domain)
/var/www/nusapanel-acme/                webroot verifikasi Let's Encrypt (port 80), dipakai hostname dan semua domain
/aquota.user                            data kuota user (journaled, vfsv1); fstab filesystem /home mendapat usrjquota=aquota.user,jqfmt=vfsv1
/etc/fstab.nusapanel-<waktu>            cadangan fstab sebelum setup.sh mengaktifkan kuota
/etc/opendkim/keys/<hostname>/          kunci DKIM email panel (0600 opendkim)
/var/lib/nusapanel/                     home user nusapanel; state agent, backup admin
/var/lib/nusapanel/storage/             storage Laravel (log, cache view), dipakai semua rilis
/run/nusapanel/agent.sock
```

## 4.5 Port

| Port            | Layanan            | Terbuka untuk                                                                    |
| --------------- | ------------------ | -------------------------------------------------------------------------------- |
| 22              | SSH/SFTP           | Publik (port bisa diganti)                                                       |
| 21, 35000–35999 | FTP + passive      | Publik                                                                           |
| 25, 465, 587    | SMTP               | Publik mulai P2; sampai itu Postfix hanya mendengar di 127.0.0.1:25 (kirim-saja) |
| 110, 995        | POP3               | Publik                                                                           |
| 143, 993        | IMAP               | Publik                                                                           |
| 4190            | ManageSieve        | Publik (opsional)                                                                |
| 53 tcp/udp      | DNS                | Publik                                                                           |
| 80, 443         | Web                | Publik                                                                           |
| 2222            | Panel              | Publik (bisa dibatasi per IP)                                                    |
| 8080            | Apache backend     | 127.0.0.1                                                                        |
| 3306            | MariaDB            | Lokal (remote kalau diaktifkan)                                                  |
| 6379            | Redis (Rspamd, P2) | Lokal, wajib berpassword/socket terkunci                                         |
| 8081            | API PowerDNS       | 127.0.0.1                                                                        |
| 11332–11334     | Rspamd             | 127.0.0.1                                                                        |

## 4.6 Service systemd milik panel

| Unit                               | Fungsi                                               |
| ---------------------------------- | ---------------------------------------------------- |
| `nusad.service`                    | Agent                                                |
| `nusapanel-queue.service`          | Queue worker Laravel                                 |
| `nusapanel-scheduler.timer`        | Menjalankan `schedule:run` Laravel tiap menit        |
| `nusa-php<versi>@<user>.service`   | PHP-FPM per user (contoh: `nusa-php84@budi.service`) |
| `nusa.slice` → `nusa-<user>.slice` | Batas resource per user                              |
