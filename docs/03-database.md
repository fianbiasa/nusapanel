# 3. Skema Database Panel

Database `nusapanel` (MariaDB). PowerDNS memakai database terpisah `pdns` dengan skema bawaan PowerDNS.

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

Private key **tidak** disimpan di database, hanya di `/etc/nusapanel/ssl/` dengan mode `0600 root`.

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

| Tabel           | Kolom penting                                                                                                         |
| --------------- | --------------------------------------------------------------------------------------------------------------------- |
| `dns_zones`     | user_id, domain_id (null; zone bisa dibuat admin tanpa domain web), name (unique), dnssec_enabled, serial             |
| `dns_records`   | zone_id, name, type, content, ttl, priority, disabled                                                                 |
| `dns_templates` | owner_id (null = default), name, type, content (placeholder `{domain}`, `{ip}`, `{ns1}`, `{ns2}`, ...), ttl, priority |

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

| Tabel                 | Kolom penting                                                                                                                                               |
| --------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `mail_domains`        | domain_id (unique), enabled, dkim_selector, dkim_public_key, catchall_mode `bounce`/`forward`/`discard`, catchall_target, daily_send_limit                  |
| `mailboxes`           | mail_domain_id, local_part, password_hash (SHA512-CRYPT), quota_mb, used_mb, daily_send_limit, status, last_login_at. Unique `(mail_domain_id, local_part)` |
| `mail_forwarders`     | mail_domain_id, local_part, destinations (json)                                                                                                             |
| `mail_autoresponders` | mail_domain_id, local_part, subject, body, content_type, cc, reply_interval_hours                                                                           |
| `mail_vacations`      | mailbox_id, subject, body, starts_at, ends_at, reply_interval_hours                                                                                         |
| `mail_filters`        | mailbox_id, name, sort, conditions (json), actions (json), enabled. Dikompilasi menjadi skrip Sieve                                                         |
| `spam_settings`       | scope_type `account`/`mailbox`, scope_id, enabled, threshold, action `move`/`delete`/`tag`, whitelist (json), blacklist (json)                              |
| `mailing_lists`       | P6, skema menyusul                                                                                                                                          |

### View SQL untuk layanan

Setiap layanan punya user MariaDB sendiri yang hanya boleh `SELECT` dari view-nya.

| View               | Dipakai oleh     | Isi                                                                                                                                                                                                                                 |
| ------------------ | ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `v_mail_domains`   | Postfix          | Domain aktif dengan mail lokal                                                                                                                                                                                                      |
| `v_mail_mailboxes` | Postfix, Dovecot | Alamat, password_hash, uid, gid, home, quota                                                                                                                                                                                        |
| `v_mail_aliases`   | Postfix          | Forwarder + catch-all                                                                                                                                                                                                               |
| `v_ftp_users`      | Pure-FTPd        | ✅ login, password, uid, gid, dir (`/home/<user>/<directory>`); hanya login `active` dari akun `active` ber-role user yang uid & gid-nya sudah diketahui. Dibaca user MariaDB `nusapanel-ftp` yang hanya punya `SELECT` di view ini |

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

| Tabel                   | Kolom penting                                                                                                                                                                                                             |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tasks`                 | id (uuid), user_id (null), actor_id (null), action, payload (terenkripsi), status `queued`/`running`/`success`/`failed`/`cancelled`, progress (0–100), attempts, output (json), error, parent_id, started_at, finished_at |
| `audit_logs`            | actor_id, impersonator_id (null), action, subject_type, subject_id, ip_address, user_agent, changes (json), created_at. Hanya ditambah, tidak pernah diubah                                                               |
| `login_history`         | user_id, ip, user_agent, method `password`/`login_key`/`sso`/`impersonate`, success, failure_reason, created_at                                                                                                           |
| `login_keys`            | user_id, name, key_hash, permissions (json), allowed_ips (json), expires_at, last_used_at                                                                                                                                 |
| `tickets`               | user_id (pembuat), recipient_level `reseller`/`admin`, subject, status `open`/`answered`/`closed`, priority                                                                                                               |
| `ticket_messages`       | ticket_id, author_id, body, attachments (json)                                                                                                                                                                            |
| `announcements`         | sender_id, audience (json), subject, body, sent_at                                                                                                                                                                        |
| `notification_channels` | user_id, type `email`/`telegram`, config 🔒, events (json)                                                                                                                                                                |
| `notifications`         | Bawaan Laravel                                                                                                                                                                                                            |
| `firewall_rules`        | action `allow`/`deny`, source (IP/CIDR, null), port, protocol, comment, expires_at                                                                                                                                        |
| `bfm_events`            | ip, service, username, attempts, first_seen_at, last_seen_at                                                                                                                                                              |
| `bfm_blocks`            | ip, reason, blocked_at, expires_at, unblocked_by                                                                                                                                                                          |
| `settings`              | key (unique), value (json)                                                                                                                                                                                                |
| `servers`               | hostname, address, role, agent_cert_fingerprint, status. P6 (multi-server)                                                                                                                                                |

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
