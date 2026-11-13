# 3. Skema Database Panel

Database `rayxo` (MariaDB). PowerDNS memakai database terpisah `pdns` dengan skema bawaan PowerDNS.

Konvensi:

- Setiap tabel punya `id`, `created_at`, `updated_at`, kecuali disebut lain.
- `→ tabel` = foreign key.
- 🔒 = dienkripsi dengan `APP_KEY` Laravel.
- Limit bernilai `null` = tanpa batas.

---

## 3.1 Akun & paket

### `users` (akun)

Satu tabel untuk admin, reseller, dan user (sama seperti DirectAdmin: setiap level juga user Linux). Memakai tabel `users` bawaan Laravel/Fortify; kolom yang baru dibutuhkan di fase berikutnya ditambahkan saat fase itu dikerjakan.

| Kolom                        | Tipe                                                        | Keterangan                                                                                                          |
| ---------------------------- | ----------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| username                     | varchar(16), unique                                         | Juga nama user Linux. Pola `^[a-z][a-z0-9]{2,15}$`, bukan nama tercadang                                            |
| name                         | varchar                                                     | Nama tampilan                                                                                                       |
| email                        | varchar                                                     |                                                                                                                     |
| password                     | varchar                                                     | bcrypt, untuk login panel                                                                                           |
| role                         | enum `admin`, `reseller`, `user`                            |                                                                                                                     |
| owner_id                     | → users, null                                               | Pembuat akun (admin/reseller). Admin pertama `null`                                                                 |
| package_id                   | → packages, null                                            |                                                                                                                     |
| custom_limits                | json, null                                                  | Override limit paket khusus akun ini                                                                                |
| status                       | enum `pending`, `active`, `suspended`, `deleting`, `failed` |                                                                                                                     |
| suspend_reason               | varchar, null                                               |                                                                                                                     |
| phpmyadmin_expires_at        | timestamp, null                                             | kapan sesi phpMyAdmin yang terbuka berakhir; scheduler lalu memberi user pemilik MariaDB password acak              |
| sftp_password_set_at         | timestamp, null                                             | kapan password SFTP terakhir diatur; null kalau belum ada. Password-nya hanya ada di server (hash di `/etc/shadow`) |
| uid                          | int, unique                                                 | UID Linux                                                                                                           |
| gid                          | int, null                                                   | GID grup milik akun (dari agent, seperti uid); login FTP berjalan sebagai uid:gid ini                               |
| ip_address_id                | → ip_addresses                                              | IP utama                                                                                                            |
| language, timezone, theme    | varchar                                                     |                                                                                                                     |
| cron_email                   | varchar, null                                               | Tujuan email output cron                                                                                            |
| two_factor_secret 🔒         | text, null                                                  |                                                                                                                     |
| two_factor_recovery_codes 🔒 | text, null                                                  |                                                                                                                     |
| two_factor_confirmed_at      | timestamp, null                                             |                                                                                                                     |
| branding                     | json, null                                                  | Khusus reseller: logo, nama, warna, domain panel                                                                    |
| last_login_at                | timestamp, null                                             |                                                                                                                     |

### `packages`

| Kolom               | Keterangan                                                                                                                                                                                                       |
| ------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| owner_id → users    | Admin atau reseller pemilik paket                                                                                                                                                                                |
| type                | `user` atau `reseller`                                                                                                                                                                                           |
| name                |                                                                                                                                                                                                                  |
| **Kuantitas**       | `disk_mb`, `inodes`, `bandwidth_mb`, `domains`, `subdomains`, `pointers`, `email_accounts`, `email_forwarders`, `autoresponders`, `mailing_lists`, `databases`, `ftp_accounts`, `cron_jobs`, `email_daily_limit` |
| **Resource**        | `cpu_percent`, `memory_mb`, `max_processes`, `io_weight`                                                                                                                                                         |
| **Fitur (bool)**    | `php`, `cgi`, `ssl`, `ssh`, `dns_control`, `cron`, `spam_filter`, `catchall`, `git`, `nodejs`, `wordpress`, `login_keys`, `suspend_on_exceed`                                                                    |
| **Khusus reseller** | `max_users`, `allow_oversell`, `ip_mode` (`shared`/`assigned`)                                                                                                                                                   |

### `account_usage`

Satu baris per akun, diperbarui berkala.

| Kolom                   | Keterangan            |
| ----------------------- | --------------------- |
| user_id → users, unique |                       |
| disk_mb, inodes         | Dari quota filesystem |
| bandwidth_mb_month      | Bulan berjalan        |
| emails_sent_today       |                       |
| measured_at             |                       |

### `usage_daily`

Untuk grafik. Unique `(user_id, date)`.

| Kolom                            | Keterangan   |
| -------------------------------- | ------------ |
| user_id → users                  |              |
| date                             |              |
| web_bytes, mail_bytes, ftp_bytes |              |
| disk_mb                          |              |
| cpu_seconds, memory_peak_mb      | Dari cgroups |

### `ip_addresses`

| Kolom                     | Keterangan                      |
| ------------------------- | ------------------------------- |
| address                   | unique, IPv4/IPv6               |
| version                   | 4 atau 6                        |
| netmask                   |                                 |
| is_server_ip              | IP utama server                 |
| status                    | `free`, `shared`, `dedicated`   |
| reseller_id → users, null | Pool milik reseller             |
| user_id → users, null     | Kalau dedicated untuk satu user |
| ptr                       | Reverse DNS (info)              |

### `nameservers`

| Kolom                  | Keterangan                                                |
| ---------------------- | --------------------------------------------------------- |
| owner_id → users, null | `null` = default server; isi = nameserver kustom reseller |
| hostname               | `ns1.contoh.com`                                          |
| ip_address             |                                                           |
| sort                   | Urutan                                                    |

---

## 3.2 Web

### `domains`

| Kolom                             | Keterangan                                       |
| --------------------------------- | ------------------------------------------------ |
| user_id → users                   |                                                  |
| parent_id → domains               | Domain induk subdomain & pointer (null = domain) |
| name                              | unique, dalam bentuk punycode                    |
| type                              | `domain`, `subdomain`, `pointer`                 |
| pointer_mode                      | `alias` atau `redirect` (hanya pointer)          |
| name_unicode                      | Untuk tampilan (domain IDN)                      |
| is_primary                        | Domain utama akun                                |
| document_root                     | Default `domains/<domain>/public_html`           |
| php_enabled, php_version          |                                                  |
| cgi_enabled                       |                                                  |
| ssl_enabled, force_https          |                                                  |
| www_mode                          | `both`, `redirect_to_www`, `redirect_to_bare`    |
| ip_address_id → ip_addresses      |                                                  |
| bandwidth_limit_mb, disk_limit_mb | null                                             |
| mail_mode                         | `local` atau `remote`                            |
| status                            | `pending`, `active`, `suspended`, `failed`       |

Subdomain dan pointer adalah baris `domains` juga, karena masing-masing punya vhost, log, dan sertifikat SSL sendiri. Subdomain punya folder sendiri seperti domain; pointer alias memakai folder domain induknya, pointer redirect tidak punya folder. Menghapus domain menghapus anak-anaknya dulu, lalu domainnya.

### Tabel pendukung web

| Tabel                       | Kolom penting                                                                                                          |
| --------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `url_redirects`             | domain_id, path, destination, status_code `301`/`302`, wildcard (ikut semua di bawah path). Unique `(domain_id, path)` |
| `php_settings`              | domain_id, key, value. Key dari daftar putih                                                                           |
| `protected_directories`     | domain_id, path, realm                                                                                                 |
| `protected_directory_users` | protected_directory_id, username, password_hash                                                                        |
| `error_pages`               | domain_id, status_code, content                                                                                        |
| `hotlink_protections`       | domain_id (unique), enabled, allowed_hosts (json), extensions (json), redirect_url, allow_empty_referer                |
| `mime_types`                | domain_id, extension, kind `mime`/`handler`, value                                                                     |
| `custom_httpd_configs`      | domain_id, server `nginx`/`apache`, content. Hanya admin                                                               |

### `ssl_certificates`

Private key **tidak** disimpan di database, hanya di `/etc/rayxo/ssl/` dengan mode `0600 root`.

| Kolom                  | Keterangan                               |
| ---------------------- | ---------------------------------------- |
| domain_id → domains    |                                          |
| type                   | `letsencrypt`, `custom`, `selfsigned`    |
| names                  | json, daftar nama (SAN)                  |
| is_wildcard            |                                          |
| issuer                 |                                          |
| not_before, expires_at |                                          |
| auto_renew             |                                          |
| status                 | `pending`, `active`, `failed`, `expired` |
| last_error             |                                          |

---

## 3.3 DNS

Record disimpan di panel lalu disinkronkan ke PowerDNS oleh agent (sesuai prinsip "database panel = sumber kebenaran").

| Tabel                  | Kolom penting                                                                                                                                                                                                                                             |
| ---------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `dns_zones`            | user_id, domain_id (domain atau pointer-nya; null kalau domainnya terhapus), name (unique). Serial dikelola PowerDNS; DNSSEC menyusul (P6)                                                                                                                |
| `dns_records`          | dns_zone_id, name (absolut tanpa titik di akhir), type, content (bentuk biasa, TXT tanpa kutip), ttl, priority (MX/SRV)                                                                                                                                   |
| `dns_template_records` | name (relatif terhadap zone, `@` = zone itu sendiri), type, content (placeholder `{domain}` dan `{ip}`), ttl, priority: record yang dimiliki zone baru dan zone yang di-reset; admin mengubahnya di halaman DNS. Template reseller (owner) menyusul di P3 |

---

## 3.4 Database milik customer

User pemilik (`<username>`, dipakai phpMyAdmin) tidak punya baris di tabel ini; kapan sesi phpMyAdmin-nya berakhir ada di `users.phpmyadmin_expires_at`.

Password user database **tidak disimpan** dan tidak pernah dikirim ke agent. Panel hanya menyimpan hash `mysql_native_password`-nya (`*` + SHA1(SHA1(password))), yang dikirim ke agent setiap kali hak akses user diubah, supaya customer tidak perlu mengetik password lagi. Hash itu disembunyikan dari serialisasi model.

| Tabel                    | Kolom penting                                                                                                                     |
| ------------------------ | --------------------------------------------------------------------------------------------------------------------------------- |
| `databases`              | user_id, name (unique, `<user>_<nama>`, ≤ 64), status (pending/active/failed/deleting), size_bytes, measured_at                   |
| `database_users`         | user_id, name (unique, `<user>_<nama>`, ≤ 32), password_hash (41 karakter), status                                                |
| `database_database_user` | database_id, database_user_id (primary key gabungan, cascade on delete): user mana boleh memakai database mana (`ALL PRIVILEGES`) |
| `database_remote_hosts`  | user_id, host (IP, CIDR, hostname, atau `%`)                                                                                      |

---

## 3.5 FTP

| Tabel          | Kolom penting                                                                                                                                                                                                                                                                                                                                 |
| -------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ftp_accounts` | user_id, domain_id (domain atau subdomain akun itu; login ikut terhapus bersama domainnya), login (unique, `nama@domain`, ≤ 128), password_hash (SHA512-CRYPT, dicek Pure-FTPd dengan `crypt()`), directory (relatif terhadap home, `''` = seluruh home), status (pending/active/failed). Tanpa kuota per login: kuota disk akun yang berlaku |

---

## 3.6 Email

| Tabel                | Kolom penting                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `mail_domains`       | ✅ domain_id (unique), dkim_selector (`rayxo`), dkim_public_key, catchall_target (null = email ke alamat yang tidak ada ditolak; selain itu diteruskan ke alamat itu, yang kalau di domain yang sama harus berupa akun email atau forwarder), status `pending`/`active`/`failed`, mode `local`/`external` (siapa yang menerima email domain itu), provider `google`/`zoho`/`other` (null untuk `local`), ssl_status `pending`/`active`/`failed` (sertifikat mail.<domain>, null kalau belum pernah diminta) dan ssl_error; dibuat bersama akun email atau forwarder pertama domain itu, atau saat emailnya dialihkan ke layanan luar |
| `mailboxes`          | ✅ mail_domain_id, local_part (≤ 64), password_hash (SHA512-CRYPT), quota_mb (null = tanpa kuota), status. Unique `(mail_domain_id, local_part)`. daily_send_limit (penerima per 24 jam untuk email yang dikirim dengan login-nya, null = ikut batas akun), status `active`/`suspended` (suspended: tidak bisa login, email tetap masuk). used_kb dan message_count (ukuran dan jumlah pesan menurut catatan kuota Dovecot, diukur `rayxo:collect-usage` tiap 15 menit; null sampai terukur). Menyusul: last_login_at                                                                                                                |
| `mail_forwarders`    | ✅ mail_domain_id, local_part (≤ 64), destinations (alamat dipisah koma, huruf kecil, 1–20, tidak ke dirinya sendiri). Unique `(mail_domain_id, local_part)`; boleh sama dengan alamat akun email, yang lalu tetap menerima salinan                                                                                                                                                                                                                                                                                                                                                                                                  |
| `mail_autoreplies`   | ✅ mailbox_id (unique), enabled, subject (≤ 150, satu baris), body (≤ 5000), starts_on & ends_on (tanggal, opsional), interval_days (1–30): balasan otomatis/cuti sebuah akun email, jadi `vacation` di skrip Sieve-nya                                                                                                                                                                                                                                                                                                                                                                                                              |
| `mail_filters`       | ✅ mailbox_id, field `from`/`to`/`subject`, operator `contains`/`is`, value (≤ 200, satu baris), action `move`/`forward`/`discard`, target (nama folder atau alamat; null untuk `discard`). Berjalan sesuai urutan dibuat, paling banyak 50 per akun email                                                                                                                                                                                                                                                                                                                                                                           |
| `webmail_sessions`   | ✅ mailbox_id, token_hash (SHA-256 hex dari token acak, unique), expires_at (UTC). Sesi webmail yang dibuka dari panel; paling banyak 10 per mailbox, terhapus saat password mailbox diganti, dan yang kedaluwarsa dibuang tiap jam (`model:prune`)                                                                                                                                                                                                                                                                                                                                                                                  |
| `mail_spam_settings` | ✅ mailbox_id (unique), threshold (1–30, bawaan 6), action `junk`/`delete`/`tag`, whitelist (json), blacklist (json): filter spam sendiri sebuah akun email (tanpa baris = bawaan server)                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| `mailing_lists`      | P6, skema menyusul                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |

### View SQL untuk layanan

Setiap layanan punya user MariaDB sendiri yang hanya boleh `SELECT` dari view-nya.

| View                      | Dipakai oleh     | Isi                                                                                                                                                                                                                                                                                                                                                                                                                |
| ------------------------- | ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `v_mail_domains`          | Postfix          | ✅ name: domain mail `active` ber-mode `local` dari akun `active` ber-role user yang uid & gid-nya sudah diketahui                                                                                                                                                                                                                                                                                                 |
| `v_mail_mailboxes`        | Postfix, Dovecot | ✅ address, password, uid, gid, home (`/home/<user>/imap/<domain>/<local_part>`), quota_rule (`*:storage=<n>M`, null tanpa kuota); mailbox `active` dan `suspended` dari domain di `v_mail_domains`, dengan can_login (1 hanya untuk `active`; Dovecot hanya menerima login yang can_login-nya 1, Postfix tetap mengantar ke keduanya). Dibaca user MariaDB `rayxo-mail` yang hanya punya `SELECT` di dua view ini |
| `v_mail_forwarders`       | Postfix          | ✅ source (alamat forwarder), destination (tujuannya, didahului alamat akun email yang sama kalau ada, juga yang di-suspend); hanya domain ber-mode `local`                                                                                                                                                                                                                                                        |
| `v_mail_catchalls`        | Postfix          | ✅ source (`@<domain>`), destination (catchall_target); hanya domain ber-mode `local`. Postfix mencari alamat lengkap di forwarder, lalu di akun email (alamat ke dirinya sendiri), baru `@<domain>`, jadi catch-all tidak pernah mengambil alamat yang ada                                                                                                                                                        |
| `v_mail_webmail_sessions` | Dovecot          | ✅ address, token_hash: sesi webmail yang belum kedaluwarsa (dibanding `UTC_TIMESTAMP()`) dari mailbox yang ada di `v_mail_mailboxes`. Dovecot membandingkan `SHA2(password, 256)` dan hanya menerimanya dari 127.0.0.1/::1                                                                                                                                                                                        |
| `v_mail_hosted_domains`   | Postfix          | ✅ name: semua domain akun ber-role user (status apa pun, dengan atau tanpa email). Pengirim di domain ini tidak ditulis ulang SRS (`sender_canonical_maps`), jadi email website tetap lolos SPF domainnya sendiri                                                                                                                                                                                                 |
| `v_mail_local_senders`    | Postfix          | ✅ login (username), senders: `<username> <> @<domain> ...` untuk setiap akun ber-role user, dari semua domainnya. Postfix (`local_login_sender_maps`) hanya mengizinkan alamat pengirim ini untuk `sendmail` lokal user Linux itu                                                                                                                                                                                 |
| `v_ftp_users`             | Pure-FTPd        | ✅ login, password, uid, gid, dir (`/home/<user>/<directory>`); hanya login `active` dari akun `active` ber-role user yang uid & gid-nya sudah diketahui. Dibaca user MariaDB `rayxo-ftp` yang hanya punya `SELECT` di view ini                                                                                                                                                                                    |

Akun, domain, atau mailbox yang di-suspend otomatis tersaring dari view, jadi suspend berlaku seketika tanpa reload service.

---

## 3.7 Fitur lanjutan

| Tabel                                              | Kolom penting                                                                                                                                                                                        |
| -------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `cron_jobs`                                        | ✅ user_id, schedule (lima kolom atau `@daily` dst., huruf kecil), command (satu baris, ≤ 1024), description (null), enabled. Setiap perubahan mengirim semua job aktif akun ke agent (`cron.apply`) |
| `ssh_keys`                                         | user_id, name, public_key, fingerprint. Unique `(user_id, fingerprint)`                                                                                                                              |
| `app_installs`                                     | user_id, domain_id, subdomain_id (null), app, path, version, database_id (null), admin_username, auto_update                                                                                         |
| `git_repositories`, `node_apps`, `redis_instances` | P6, skema menyusul                                                                                                                                                                                   |

---

## 3.8 Backup & migrasi

| Tabel                 | Kolom penting                                                                                                                                                     |
| --------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `backup_destinations` | owner_id, name, type `local`/`ftp`/`sftp`/`s3`/`rclone`, config 🔒 (json)                                                                                         |
| `backup_schedules`    | owner_id, name, scope (json: semua / akun tertentu / user milik reseller X), items (json), destination_id, cron_expression, retention_count, enabled, last_run_at |
| `backups`             | user_id, backup_schedule_id (null), destination_id, file_path, size_bytes, items (json), status, started_at, finished_at, error, task_id                          |
| `imports`             | owner_id, source `cpanel`/`directadmin`, file_path, target_username, target_owner_id, package_id, status, report (json: item berhasil/gagal), task_id             |

---

## 3.9 Sistem & audit

| Tabel                   | Kolom penting                                                                                                                                                                                                                                                                                                                                                                                                |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `tasks`                 | id (uuid), user_id (null), actor_id (null), action, payload (terenkripsi), status `queued`/`running`/`success`/`failed`/`cancelled`, progress (0–100), attempts, output (json), error, parent_id, started_at, finished_at                                                                                                                                                                                    |
| `audit_logs`            | actor_id, impersonator_id (null), action, subject_type, subject_id, ip_address, user_agent, changes (json), created_at. Hanya ditambah, tidak pernah diubah. ✅ login_key_id dan login_key_name: key API yang membuat perubahan (tanpa foreign key, jadi tetap tercatat setelah key dihapus)                                                                                                                 |
| `login_history`         | user_id, ip, user_agent, method `password`/`login_key`/`sso`/`impersonate`, success, failure_reason, created_at                                                                                                                                                                                                                                                                                              |
| `login_keys`            | ✅ user_id, name, key_hash (SHA-256 hex dari key, unique), key_prefix (awal key untuk membedakan), permissions (json: `accounts:read`, `domains:write`, …), allowed_ips (json, null = dari mana pun), expires_at, last_used_at, last_used_ip                                                                                                                                                                 |
| `tickets`               | user_id (pembuat), recipient_level `reseller`/`admin`, subject, status `open`/`answered`/`closed`, priority                                                                                                                                                                                                                                                                                                  |
| `ticket_messages`       | ticket_id, author_id, body, attachments (json)                                                                                                                                                                                                                                                                                                                                                               |
| `announcements`         | sender_id, audience (json), subject, body, sent_at                                                                                                                                                                                                                                                                                                                                                           |
| `notification_channels` | user_id, type `email`/`telegram`, config 🔒, events (json)                                                                                                                                                                                                                                                                                                                                                   |
| `notifications`         | Bawaan Laravel                                                                                                                                                                                                                                                                                                                                                                                               |
| `firewall_rules`        | action `allow`/`deny`, source (IP/CIDR, null), port, protocol, comment, expires_at                                                                                                                                                                                                                                                                                                                           |
| `bfm_events`            | ip, service, username, attempts, first_seen_at, last_seen_at                                                                                                                                                                                                                                                                                                                                                 |
| `bfm_blocks`            | ip, reason, blocked_at, expires_at, unblocked_by                                                                                                                                                                                                                                                                                                                                                             |
| `settings`              | ✅ key (unique), value (json). Dipakai pengaturan server: `php.upload_limit` (ukuran upload/POST maksimum yang boleh dipilih website) dan `php.upload_default` (ukuran website yang belum memilih sendiri), `mail.send_limit_account` dan `mail.send_limit_server` (batas kirim harian per akun dan seluruh server, penerima per 24 jam; 0 = tanpa batas, belum diisi = 300 per akun dan tanpa batas server) |
| `servers`               | hostname, address, role, agent_cert_fingerprint, status. P6 (multi-server)                                                                                                                                                                                                                                                                                                                                   |

---

## 3.10 Relasi utama

```
users (admin)
 ├─< users (reseller)
 │    └─< users (user)
 └─< packages

users (user)
 ├─< domains
 │    ├─< subdomains
 │    ├─< domain_pointers
 │    ├─< ssl_certificates
 │    ├── mail_domains ─< mailboxes
 │    └── dns_zones ─< dns_records
 ├─< databases ─< database_database_user >─ database_users
 ├─< ftp_accounts
 ├─< cron_jobs
 ├─< backups
 └─< tasks
```
