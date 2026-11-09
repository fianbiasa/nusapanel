# 5. Roadmap

Estimasi untuk **satu developer full-time dibantu AI**. Kalau dikerjakan paruh waktu, kalikan sekitar 2.

| Fase | Fokus                    | Estimasi      | Hasil                                                                           |
| ---- | ------------------------ | ------------- | ------------------------------------------------------------------------------- |
| P0   | Fondasi                  | 1–2 minggu    | Panel & agent tersambung, sistem task, audit log, installer awal                |
| P1   | Hosting inti             | 5–7 minggu    | Bisa hosting website: akun, domain, PHP, SSL, database, FTP, file manager, cron |
| P2   | DNS & email              | 5–7 minggu    | DNS & email lengkap                                                             |
| P3   | Reseller, statistik, API | 4–6 minggu    | Siap dipakai banyak user & reseller                                             |
| P4   | Backup, migrasi, WHMCS   | 4–6 minggu    | Bisa memindahkan pelanggan & jualan otomatis                                    |
| P5   | Keamanan & hardening     | 2–4 minggu    | **Siap produksi**                                                               |
| P6   | Fitur lanjutan           | Berkelanjutan | Software manager, WordPress, Node.js, multi-server, dll.                        |

**Total sampai siap produksi (P0–P5): sekitar 5–8 bulan full-time.**
Aturan main: tidak ada fitur P6 sebelum P5 selesai.

---

## P0 — Fondasi

Struktur repo (panel Laravel ada di root supaya Herd langsung melayaninya di `nusapanel.test`):

```
nusapanel/
├── app/, resources/, routes/, ...   panel Laravel + Inertia + React
├── agent/       Go (nusad, nusactl)
├── templates/   template config
├── installer/   setup.sh (installer server), build-release.sh (paket rilis)
├── whmcs/       modul WHMCS (P4)
└── docs/
```

Pekerjaan:

- Panel dikembangkan di Herd (`nusapanel.test`); SSL dan versi PHP diatur dari aplikasi Herd.
- VM pengembangan di Mac (OrbStack atau Multipass) dengan Ubuntu 24.04 untuk agent, plus snapshot "bersih" untuk menguji installer berulang kali.
- Agent: server JSON-RPC di Unix socket, cek `SO_PEERCRED`, kunci per akun, aksi pertama `system.info`.
- Panel: Laravel + Inertia + React (dari starter kit resmi), login username/email + 2FA, peran admin/reseller/user, tabel `tasks` & `audit_logs`, klien agent (driver `fake` di Mac, `socket` di server).
- Installer: `setup.sh` mewajibkan A record & PTR hostname yang benar, lalu memasang Nginx, MariaDB, PHP 8.4, Postfix kirim-saja + DKIM (email panel), SSL Let's Encrypt untuk hostname, user `nusapanel`, panel & agent dari paket rilis (`build-release.sh`), dan membuat admin pertama.
- CI: test Go & PHP; test integrasi agent di VM.

**Selesai kalau:** install di VM bersih → login admin → dashboard menampilkan info sistem dari agent.

## P1 — Hosting inti

**Status** (teruji di Ubuntu 24.04):

- akun hosting ✅: buat, suspend, unsuspend, hapus dari panel;
- domain & website ✅: tambah/hapus domain (halaman Domains user, domain utama saat admin membuat akun), Nginx → Apache → PHP-FPM 8.4 per user, `www.` otomatis, halaman 503 saat suspend, isolasi antar akun & proteksi symlink teruji;
- SSL Let's Encrypt ✅: pasang/lepas SSL dan Paksa HTTPS dari halaman Domains, cek dulu domain sampai ke server (A record atau proxy Cloudflare), diperpanjang otomatis `certbot.timer`; teruji dengan domain asli lewat Cloudflare;
- `nusapanel:rebuild-web` ✅: semua website ditulis ulang otomatis setiap upgrade (`setup.sh`), jadi perubahan template ikut terpasang;
- paket hosting ✅: admin membuat paket (kuota disk, bandwidth, domain, subdomain, email, database, FTP, cron; fitur SSL), memilihnya saat membuat akun dan menggantinya kapan saja; batas domain dan fitur SSL sudah diterapkan, batas lain berlaku begitu fiturnya ada; pemakaian tampil di dashboard customer;
- kuota disk & inode ✅: `setup.sh` mengaktifkan kuota user ext4 tanpa reboot (fstab dicadangkan, divalidasi, dikembalikan kalau gagal), agent memasang batas per akun dari paketnya, pemakaian diukur tiap 15 menit dan tampil di dashboard & daftar akun; teruji: menulis melebihi kuota ditolak "Disk quota exceeded".

- subdomain, pointer & redirect ✅: subdomain punya folder sendiri (`domains/blog.<domain>/public_html`, bukan di dalam `public_html` domain induk supaya `.htaccess`-nya tidak ikut berlaku) dan dihitung ke batas subdomain; pointer (batas baru di paket) menampilkan website domain induk (alias) atau mengalihkan pengunjungnya ke sana (301); halaman Redirects mengalihkan path (301/302, bisa termasuk semua halaman di bawahnya); SSL & Paksa HTTPS berlaku per nama; hapus domain ikut menghapus subdomain & pointer-nya (anak dulu, lalu domainnya); teruji di nginx sungguhan.

- pilihan versi PHP ✅: `setup.sh` memasang PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 dan 8.5 untuk website (beserta ekstensi umum WordPress; `--php-versions` untuk memilih), service PHP-FPM bawaan distro versi-versi itu dimatikan; customer mengganti versi per domain/subdomain dari kolom PHP di halaman Domains, pointer ikut domainnya, dan pool versi lama dihapus agent begitu tidak dipakai lagi.

- sertifikat kustom ✅: customer menempel sertifikat (beserta CA bundle) dan private key-nya dari menu domain; panel dan agent mengecek pasangan key, nama domain (wildcard boleh), masa berlaku, dan jenis key; sertifikat yang tidak dipercaya browser (Cloudflare Origin Certificate) boleh, dan ditandai; mengganti sertifikat (juga dari/ke Let's Encrypt) tidak mematikan HTTPS, dan sertifikat yang tidak dipakai lagi dihapus; private key hanya lewat payload task terenkripsi dan dihapus dari task begitu selesai.

- pengaturan PHP per website ✅: setiap website punya pool PHP-FPM sendiri (di dalam master per akun per versi), sehingga `memory_limit`, batas upload/POST, batas waktu, `max_input_vars`, `display_errors`, dan zona waktu bisa diatur per domain/subdomain tanpa bocor ke website lain; pointer alias ikut domainnya; Nginx mengikuti batas POST; akun lama berpindah ke pool per website tanpa jeda saat upgrade.

- MariaDB ✅: halaman Databases membuat database (sekaligus user-nya kalau mau, dengan pembuat password) dan user database; satu user bisa diberi akses ke beberapa database dan sebaliknya; nama selalu diawali `<username>_`; batas database dari paket; ukuran diukur tiap 15 menit; user ikut dikunci saat akun di-suspend; semua database & user ikut dihapus bersama akunnya; password tidak pernah dikirim ke agent maupun disimpan, hanya hash-nya.

- phpMyAdmin SSO ✅: tombol "Buka phpMyAdmin" di halaman Databases membuka phpMyAdmin 5.2 (dipaket bersama rilis, di `https://<hostname>/phpmyadmin/`, origin terpisah dari panel) dalam tab baru, langsung login ke semua database akun tanpa mengetik password; login memakai user pemilik MariaDB akun itu dengan password baru tiap sesi, lewat tiket terenkripsi yang kedaluwarsa dalam semenit dan hanya berlaku di browser yang memintanya; sesi berakhir setelah 60 menit (password diganti acak oleh scheduler); phpMyAdmin berjalan sebagai user `nusa-pma` yang tidak bisa membaca konfigurasi panel maupun file customer.

- FTP ✅: halaman FTP membuat login `nama@domain` (domain atau subdomain akun) yang hanya bisa membuka satu folder di home-nya (bawaan `domains/<domain>/public_html`, atau seluruh home), dengan password yang bisa diganti kapan saja dan batas jumlah dari paket; Pure-FTPd membaca login langsung dari database panel lewat view yang hanya memuat login siap dari akun aktif, sehingga login akun yang di-suspend langsung ditolak; FTP wajib TLS eksplisit (FTPS) dengan sertifikat hostname, yang ikut diperbarui saat Let's Encrypt memperpanjangnya; file yang diunggah milik akun itu dan terhitung di kuota disknya.

Berikutnya: cron jobs, SFTP, file manager. SSL mail menyusul bersama email (P2).

- Akun user & paket user, buat/suspend/hapus akun, quota disk & inode.
- Domain, subdomain, pointer, redirect, www/HTTPS.
- Nginx → Apache → PHP-FPM per user, banyak versi PHP, PHP settings.
- SSL Let's Encrypt + sertifikat kustom, SSL hostname/panel.
- MariaDB: database, user, hak akses, phpMyAdmin SSO.
- FTP + SFTP, file manager lengkap, cron jobs.
- Info sistem, service monitor + watchdog, pengaturan server.
- 2FA, riwayat login, `nusactl`, `nusactl rebuild`.

**Selesai kalau:**

- WordPress bisa di-host dengan HTTPS, database, FTP, dan file manager;
- suspend, unsuspend, dan hapus akun bersih tanpa sisa;
- `nusactl rebuild` menghasilkan config yang identik;
- user A terbukti tidak bisa membaca file user B (diuji otomatis).

## P2 — DNS & email

- PowerDNS, template DNS, DNS management, nameserver default, SSL wildcard.
- Postfix + Dovecot + Rspamd + Roundcube.
- Akun email, forwarder, catch-all, autoresponder, vacation, filter, spam filter.
- DKIM/SPF/DMARC otomatis, batas kirim, mail queue, SSL untuk mail & FTP.

**Selesai kalau:**

- zone DNS bisa di-resolve dari luar;
- email keluar lolos SPF, DKIM, dan DMARC (skor mail-tester minimal 9/10);
- email masuk diterima, dan spam tersaring.

## P3 — Reseller, statistik, API

- Level reseller lengkap, paket reseller, overselling, branding, nameserver kustom, login as.
- Manajemen IP, pindah pemilik, pesan massal, tiket.
- Bandwidth, statistik harian & grafik, GoAccess, log viewer, batas resource (cgroups).
- REST API + login keys, notifikasi email & Telegram.
- SSH jail & SSH keys, error page, protected directory, hotlink, MIME.
- Process monitor, file editor sistem, template config kustom.

**Selesai kalau:** reseller bisa membuat dan mengelola user dalam batasnya, dan terbukti tidak bisa menyentuh akun di luar miliknya (test otorisasi otomatis untuk setiap endpoint).

## P4 — Backup, migrasi, WHMCS

- Backup & restore (level user, reseller, admin), jadwal, retensi.
- Tujuan remote: FTP, SFTP, S3 (rclone).
- Import backup cPanel & DirectAdmin, transfer antar server.
- Modul WHMCS.

**Selesai kalau:**

- akun nyata dari cPanel dan DirectAdmin bisa di-import, lalu situs, email, dan database berjalan;
- restore dari backup sendiri lolos uji;
- WHMCS bisa membuat, suspend, dan menghapus akun.

## P5 — Keamanan & hardening

- Brute force monitor, firewall nftables, ModSecurity + OWASP CRS.
- Kebijakan 2FA & password, update panel satu klik, deteksi config drift.
- Audit keamanan: review kode + pentest internal (eskalasi hak antar user, injeksi config, path traversal, symlink, CSRF/XSS di panel).
- Uji beban dengan ratusan akun.

**Selesai kalau:** checklist keamanan lolos dan uji migrasi percobaan di server staging dengan beberapa akun nyata berjalan stabil 2 minggu.

## P6 — Fitur lanjutan

Software manager (setara CustomBuild), app installer & WordPress manager, Git deploy, Node.js/Python, Redis per user, DNSSEC, mailing list, multi-server & DNS cluster, plugin & skin manager, web terminal, malware scan, ganti username.

---

## Strategi pindah dari panel lama

1. Siapkan **server baru** untuk nusapanel. Jangan dipasang di server yang masih menjalankan cPanel/DirectAdmin.
2. Pindahkan akun milik sendiri atau akun percobaan lebih dulu.
3. Turunkan TTL DNS ke 300 detik sehari sebelum pindah.
4. Import backup → cek situs lewat file `hosts` → pindahkan DNS.
5. Pantau 1–2 minggu, lalu pindahkan gelombang berikutnya.
6. Hentikan lisensi lama setelah semua akun pindah.

## Risiko utama

| Risiko                                   | Mitigasi                                                                                                       |
| ---------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| Celah keamanan yang membuka semua akun   | Prinsip agent ketat, review keamanan setiap fase, pentest sebelum produksi                                     |
| Email masuk spam                         | rDNS/PTR benar di provider VPS, pastikan port 25 tidak diblokir provider, DKIM/SPF/DMARC otomatis, batas kirim |
| Import cPanel/DirectAdmin tidak sempurna | Uji dengan banyak contoh backup nyata; laporan item yang gagal per akun                                        |
| Beban perawatan jangka panjang           | Pakai paket distro, satu OS dulu, test integrasi otomatis                                                      |
| Scope melebar                            | Fase ketat; fitur P6 baru dikerjakan setelah P5                                                                |

## Langkah pertama

1. Tetapkan nama final (atau pakai codename `nusapanel` dulu).
2. Siapkan VM Ubuntu 24.04 di Mac (OrbStack/Multipass).
3. Scaffold panel Laravel + React (di Herd) dan agent Go.
4. Socket agent + `system.info`, tampil di dashboard admin.
5. Installer versi pertama.
