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
| PB   | Dua bahasa               | 1–2 minggu    | UI Indonesia & English, bahasa default server; dikerjakan bersama P5            |
| P6   | Fitur lanjutan           | Berkelanjutan | Software manager, WordPress, Node.js, multi-server, dll.                        |

**Total sampai siap produksi (P0–P5, termasuk PB): sekitar 5–8 bulan full-time.**
Aturan main: tidak ada fitur P6 sebelum P5 selesai.

---

## P0 — Fondasi

Struktur repo (panel Laravel ada di root supaya Herd langsung melayaninya di `rayxo.test`):

```
rayxo/
├── app/, resources/, routes/, ...   panel Laravel + Inertia + React
├── agent/       Go (rayxod, rayxo)
├── templates/   template config
├── installer/   setup.sh (installer server), build-release.sh (paket rilis)
├── whmcs/       modul WHMCS (P4)
└── docs/
```

**Status** ✅ (diperiksa ulang 11–12 November 2026):

- Panel dikembangkan di Herd (`rayxo.test`); SSL dan versi PHP diatur dari aplikasi Herd. ✅
- Uji di server bersih ✅, menggantikan rencana VM OrbStack/Multipass dengan snapshot. `installer/test-in-vm.sh` memasang RAYXO di server yang masih bersih lalu memeriksanya dari ujung ke ujung. Servernya VPS uji yang masih bersih (`--ssh`, dengan pengecekan DNS A/PTR dan Let's Encrypt sungguhan; OS-nya di-reinstall sebelum uji bersih berikutnya), dan di CI VM Lima baru di mesin GitHub (selalu dari image bersih, jadi tidak perlu snapshot). Uji pertamanya menemukan tiga bug instalasi baru yang tidak terlihat di VPS yang hanya di-upgrade, dan semuanya sudah diperbaiki:
    - PHP gagal dipasang kalau API Launchpad bermasalah. PHP kini diambil dari `packages.sury.org`, dengan PPA `ondrej/php` sebagai cadangan; kunci keduanya dipatok.
    - Pengecekan paket PHP selalu gagal (SIGPIPE dengan `pipefail`).
    - Ringkasan instalasi yang usang.
- Agent: server JSON-RPC di Unix socket, cek `SO_PEERCRED`, kunci per akun, aksi pertama `system.info`. ✅
- Panel: Laravel + Inertia + React (dari starter kit resmi), login username/email + 2FA, peran admin/reseller/user, tabel `tasks` & `audit_logs`, klien agent (driver `fake` di Mac, `socket` di server). ✅
- Installer: `setup.sh` mewajibkan A record & PTR hostname yang benar, lalu memasang Nginx, MariaDB, PHP 8.4, Postfix + DKIM (email panel; sejak P2 email lengkap), SSL Let's Encrypt untuk hostname, user `rayxo`, panel & agent dari paket rilis (`build-release.sh`), dan membuat admin pertama. ✅
- CI: test Go & PHP di setiap push (workflow `tests`) ✅; test integrasi agent di VM (workflow `clean-install`: `test-in-vm.sh` di VM Lima dengan KVM, setiap push ke main) ✅ hijau di GitHub (12 November 2026, sekitar 8 menit: instalasi di VM bersih, login admin, info sistem dari agent, tes isolasi).

**Selesai kalau:** install di VM bersih → login admin → dashboard menampilkan info sistem dari agent. ✅ Teruji 11 November 2026 dengan `test-in-vm.sh`:

- VPS uji (Ubuntu 24.04 amd64, DNS sungguhan): instalasi lolos, sertifikat Let's Encrypt valid, semua pemeriksaan `verify_install` lolos, admin login, dashboard menampilkan info sistem dari agent, dan tes isolasi lolos.
- VM Lima baru (Ubuntu 24.04 arm64): hasil yang sama, dengan sertifikat self-signed.

## P1 — Hosting inti

**Status** (teruji di Ubuntu 24.04):

- akun hosting ✅: buat, suspend, unsuspend, hapus dari panel;
- domain & website ✅: tambah/hapus domain (halaman Domains user, domain utama saat admin membuat akun), Nginx → Apache → PHP-FPM 8.4 per user, `www.` otomatis, halaman 503 saat suspend, isolasi antar akun & proteksi symlink teruji;
- SSL Let's Encrypt ✅: pasang/lepas SSL dan Paksa HTTPS dari halaman Domains, cek dulu domain sampai ke server (A record atau proxy Cloudflare), diperpanjang otomatis `certbot.timer`; teruji dengan domain asli lewat Cloudflare;
- `rayxo:rebuild-web` ✅: semua website ditulis ulang otomatis setiap upgrade (`setup.sh`), jadi perubahan template ikut terpasang;
- paket hosting ✅: admin membuat paket (kuota disk, bandwidth, domain, subdomain, email, database, FTP, cron; fitur SSL), memilihnya saat membuat akun dan menggantinya kapan saja; batas domain, subdomain, pointer, database, FTP, cron, fitur SSL, dan sejak P2 akun email dan forwarder diterapkan; batas bandwidth menyusul bersama statistiknya (P3); pemakaian tampil di dashboard customer;
- kuota disk & inode ✅: `setup.sh` mengaktifkan kuota user ext4 tanpa reboot (fstab dicadangkan, divalidasi, dikembalikan kalau gagal), agent memasang batas per akun dari paketnya, pemakaian diukur tiap 15 menit dan tampil di dashboard & daftar akun; teruji: menulis melebihi kuota ditolak "Disk quota exceeded".

- subdomain, pointer & redirect ✅: subdomain punya folder sendiri (`domains/blog.<domain>/public_html`, bukan di dalam `public_html` domain induk supaya `.htaccess`-nya tidak ikut berlaku) dan dihitung ke batas subdomain; pointer (batas baru di paket) menampilkan website domain induk (alias) atau mengalihkan pengunjungnya ke sana (301); halaman Redirects mengalihkan path (301/302, bisa termasuk semua halaman di bawahnya); SSL & Paksa HTTPS berlaku per nama; hapus domain ikut menghapus subdomain & pointer-nya (anak dulu, lalu domainnya); teruji di nginx sungguhan.

- pilihan versi PHP ✅: `setup.sh` memasang PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4 dan 8.5 untuk website (beserta ekstensi umum WordPress; `--php-versions` untuk memilih), service PHP-FPM bawaan distro versi-versi itu dimatikan; customer mengganti versi per domain/subdomain dari kolom PHP di halaman Domains, pointer ikut domainnya, dan pool versi lama dihapus agent begitu tidak dipakai lagi.

- sertifikat kustom ✅: customer menempel sertifikat (beserta CA bundle) dan private key-nya dari menu domain; panel dan agent mengecek pasangan key, nama domain (wildcard boleh), masa berlaku, dan jenis key; sertifikat yang tidak dipercaya browser (Cloudflare Origin Certificate) boleh, dan ditandai; mengganti sertifikat (juga dari/ke Let's Encrypt) tidak mematikan HTTPS, dan sertifikat yang tidak dipakai lagi dihapus; private key hanya lewat payload task terenkripsi dan dihapus dari task begitu selesai.

- pengaturan PHP per website ✅: setiap website punya pool PHP-FPM sendiri (di dalam master per akun per versi), sehingga `memory_limit`, batas upload/POST, batas waktu, `max_input_vars`, `display_errors`, dan zona waktu bisa diatur per domain/subdomain tanpa bocor ke website lain; pointer alias ikut domainnya; Nginx mengikuti batas POST; akun lama berpindah ke pool per website tanpa jeda saat upgrade.

- MariaDB ✅: halaman Databases membuat database (sekaligus user-nya kalau mau, dengan pembuat password) dan user database; satu user bisa diberi akses ke beberapa database dan sebaliknya; nama selalu diawali `<username>_`; batas database dari paket; ukuran diukur tiap 15 menit; user ikut dikunci saat akun di-suspend; semua database & user ikut dihapus bersama akunnya; password tidak pernah dikirim ke agent maupun disimpan, hanya hash-nya.

- phpMyAdmin SSO ✅: tombol "Buka phpMyAdmin" di halaman Databases membuka phpMyAdmin 5.2 (dipaket bersama rilis, di `https://<hostname>/phpmyadmin/`, origin terpisah dari panel) dalam tab baru, langsung login ke semua database akun tanpa mengetik password; login memakai user pemilik MariaDB akun itu dengan password baru tiap sesi, lewat tiket terenkripsi yang kedaluwarsa dalam semenit dan hanya berlaku di browser yang memintanya; sesi berakhir setelah 60 menit (password diganti acak oleh scheduler); phpMyAdmin berjalan sebagai user `rayxo-pma` yang tidak bisa membaca konfigurasi panel maupun file customer.

- FTP ✅: halaman FTP membuat login `nama@domain` (domain atau subdomain akun) yang hanya bisa membuka satu folder di home-nya (bawaan `domains/<domain>/public_html`, atau seluruh home), dengan password yang bisa diganti kapan saja dan batas jumlah dari paket; Pure-FTPd membaca login langsung dari database panel lewat view yang hanya memuat login siap dari akun aktif, sehingga login akun yang di-suspend langsung ditolak; FTP wajib TLS eksplisit (FTPS) dengan sertifikat hostname, yang ikut diperbarui saat Let's Encrypt memperpanjangnya; file yang diunggah milik akun itu dan terhitung di kuota disknya.

- cron jobs ✅: halaman Cron Jobs menambah, mengubah, menjeda dan menghapus job (jadwal siap pakai seperti setiap 5 menit atau harian, atau lima kolom sendiri; perintah satu baris) dengan batas jumlah dari paket; agent menulis crontab akun dari semua job aktif, dijalankan daemon cron sebagai user akun itu; job akun yang di-suspend tidak berjalan (cron menolak akun kedaluwarsa lewat PAM) dan jalan lagi setelah unsuspend; crontab ikut dihapus bersama akun.

- SFTP ✅: akun utama login SFTP (port 22) dengan username akunnya dan password khusus SFTP yang diatur di halaman FTP & SFTP (terpisah dari password panel; panel hanya mengirim hash SHA512-CRYPT ke server); sshd memberi akun hosting SFTP saja, tanpa shell, forwarding, atau tunnel, sementara root dan user lain tidak berubah; file yang diunggah milik akun itu (644/755); suspend memblokir login, termasuk dengan SSH key.

- file manager tahap 1 ✅: halaman File Manager untuk menelusuri home, mengunggah (drag & drop, per bagian 8 MB, sampai 10 GB, dengan konfirmasi timpa), download, membuat file/folder, ganti nama, salin, pindah, mengatur izin (centang atau angka), hapus ke Trash lalu pulihkan atau hapus permanen, preview gambar (SVG dalam sandbox), dan editor kode (CodeMirror 6, highlight sesuai jenis file, Ctrl+S, menolak menimpa perubahan yang dibuat orang lain sejak file dibuka); semua dijalankan agent sebagai user Linux akun itu di dalam home-nya.

- file manager tahap 2 ✅: kompres ke ZIP atau TAR.GZ, ekstrak ZIP/TAR/TAR.GZ/TAR.BZ2 ke folder ini atau folder baru (dengan atau tanpa menimpa; entri berbahaya ditolak), cari file berdasarkan nama di folder dan subfoldernya, dan hitung ukuran folder.

- service monitor + watchdog ✅: halaman admin Services menampilkan status service sistem (berjalan/berhenti/gagal, start saat boot, sejak kapan, memori), pool PHP website dan kejadian watchdog, dengan start/stop/restart/reload yang dikonfirmasi dulu; konfigurasi Nginx/Apache dites sebelum restart, service yang dibutuhkan panel tidak bisa dihentikan, dan watchdog di agent menyalakan lagi service yang mati tiap menit.

- riwayat login ✅: Settings > Security menampilkan 20 login terakhir akun itu, berhasil maupun gagal, dengan waktu, IP, browser dan sistem operasinya (dari audit log yang sudah mencatat setiap login).

- `rayxo rebuild` ✅: menulis ulang semua konfigurasi dari database panel (Nginx, Apache dan pool PHP setiap website, kuota disk dan crontab setiap akun, folder setiap login FTP; sejak P2 juga zone DNS, domain email, filter email dan batas kirim), lewat `rayxo rebuild` di server, `php artisan rayxo:rebuild`, atau tombol "Tulis ulang konfigurasi" di halaman Services; aman diulang karena setiap tugas menulis keadaan lengkapnya.

- SSL hostname/panel ✅: `setup.sh` mengambil sertifikat Let's Encrypt untuk hostname setiap kali dijalankan (kalau belum ada atau sisa masa berlakunya kurang dari 30 hari), dipakai panel (port 2222) dan FTP, lalu diperpanjang `certbot.timer`; kalau gagal, panel memakai sertifikat self-signed sampai `setup.sh` dijalankan lagi. Menerbitkan ulang dari panel ada di Pengaturan Server (lihat pengaturan server tahap 1).

- info sistem ✅ (sejak P0): dashboard admin menampilkan hostname, sistem operasi, CPU, load, memori, disk, uptime dan versi agent dari `system.info`, juga versi software server (Nginx, Apache, MariaDB, Postfix, Dovecot, Rspamd, Redis, PowerDNS, Pure-FTPd dan setiap versi PHP, dari `dpkg-query`). Teruji di VPS uji: semua versi terbaca.

- 2FA ✅ (sejak P0): Settings > Security mengaktifkan 2FA lewat aplikasi authenticator (dengan konfirmasi kode dan recovery code) serta passkey; keduanya meminta konfirmasi password dulu.

- CLI `rayxo` ✅: `rayxo ping`, `rayxo call <method> [params-json]` (memanggil aksi agent apa pun dari terminal server), `rayxo rebuild` dan `rayxo version`; sejak P2 juga `rayxo reset-password <username>` dan `rayxo acme-dns-hook` (hook certbot untuk SSL wildcard).

- tes isolasi akun ✅: `agent/internal/isolation` membuktikan di server asli bahwa akun A tidak bisa membaca file akun B. Jalur yang diserang: user Linux (SFTP, cron, file manager), PHP website, symlink lewat Nginx dan Apache, `.htaccess`, socket PHP-FPM, dan file manager agent. Setiap jalur punya kontrol, dan tes memakai dua akun sementara yang dihapus lagi. Teruji di VPS.

- uji WordPress ✅: WordPress dipasang di website asli lewat Cloudflare memakai alat panel. Database dibuat dari halaman Databases, file diunggah lewat FTPS dari internet, lalu diekstrak dan dipindah di File Manager. Wizard WordPress menulis `wp-config.php` sendiri, dan instalasi selesai. Yang terbukti jalan: HTTPS terdeteksi di belakang proxy (tanpa tautan `http://`), login wp-admin, permalink cantik (`.htaccess` lewat Apache), unggah media, dan pasang plugin dari wordpress.org tanpa diminta kredensial FTP. Semua file tetap milik akun. Setelah itu semuanya dihapus lagi lewat panel dan situs kembali persis seperti semula. Uji ini juga menemukan bahwa paket rilis belum menyertakan `lang/`, sehingga pesan dari server tampil dalam bahasa Inggris; sudah diperbaiki.

- pengaturan server tahap 1 ✅: halaman admin Pengaturan Server menampilkan hostname dan sertifikat panel (Let's Encrypt atau self-signed, masa berlakunya) dengan tombol terbitkan ulang SSL hostname (panel, phpMyAdmin dan FTP ikut memakainya), mengganti zona waktu server (cron ikut), dan mengatur batas upload PHP: ukuran maksimum yang boleh dipilih website dan ukuran bawaan untuk website yang belum memilih sendiri; menyimpan batas upload menerapkan ulang semua website. Bahasa default menyusul bersama UI dua bahasa.

- pengaturan server tahap 2 ✅: hostname bisa diganti dari Pengaturan Server. Agent menjalankan ulang `setup.sh --reconfigure --hostname <baru>` (rilis yang terpasang, tanpa unduh paket atau apt) di unit systemd sendiri; setup.sh mengecek A record & PTR dulu dan tidak mengubah apa pun kalau belum benar, lalu mengganti hostname sistem, `/etc/hosts`, cloud-init, SSL, Postfix, DKIM, phpMyAdmin dan alamat panel, dan menghapus sertifikat hostname lama; halaman menampilkan proses, hasil dan log-nya.

- pengaturan server tahap 3 ✅: port panel bisa diganti dari Pengaturan Server tanpa risiko terkunci. Port baru dibuka dulu berdampingan dengan port lama selama 5 menit; admin membuka panel lewat port baru dan mengonfirmasi di sana (bukti port itu tembus firewall), lalu `setup.sh --reconfigure --panel-port <port>` menulis semua config untuk port itu dan mengingatnya. Port yang tidak dikonfirmasi tepat waktu, atau yang setup.sh-nya gagal, ditutup lagi otomatis.

- pilihan www ✅: setiap domain bisa dibuka dengan dan tanpa `www.` (bawaan), atau dipaksa ke salah satunya (301, path dan query ikut), dari menu domain di halaman Domains; pointer redirect tidak punya pilihan ini karena sudah mengalihkan semuanya. Teruji di VPS.

- PHP aktif/mati per website ✅: website tanpa PHP tidak punya pool PHP-FPM (pool lamanya dihapus), Apache melayani `index.html` dan menolak file `.php`/`.phtml`/`.phar` dengan 403 alih-alih menampilkan kodenya; pointer alias ikut domainnya. Teruji di VPS.

- folder website subdomain ✅: subdomain bisa dilayani dari folder lain di dalam `domains/` (misalnya `domains/<domain>/public_html/blog`), dicek di panel dan agent (tanpa `..`, tanpa folder tersembunyi, bukan `tmp/` atau `.trash/`), dan folder yang belum ada dibuat milik akun. Teruji di VPS.

- kuota per login FTP ✅: login FTP bisa diberi batas MB (kosong berarti tanpa batas); Pure-FTPd membacanya dari view `v_ftp_users` dan menyimpan pemakaian di `.ftpquota` folder login, yang tidak bisa dihapus lewat FTP dan ditolak Nginx (403). Saat kuota dipasang, agent menghitung isi folder dulu dengan `pure-quotacheck`, dan setiap malam semua folder berkuota dihitung ulang supaya file dari file manager, SFTP atau website ikut terhitung. Teruji di VPS: upload melewati kuota ditolak, login tanpa kuota tidak terpengaruh, dan setelah file dihapus di luar FTP, hitung ulang membuka ruangnya lagi.

- email output cron ✅: halaman Cron Jobs punya satu alamat email per akun (`MAILTO`), yang dicek ketat di panel dan agent (alamat biasa saja, tanpa tanda kutip, spasi, `%`, atau tanda minus di depan). Cron hanya mengirim email kalau job menghasilkan output; `>/dev/null 2>&1` membuat satu job diam. Teruji di VPS: email dikirim lewat Postfix lokal dan ditandatangani DKIM hostname.

- cari dan filter akun ✅: daftar akun admin bisa dicari (username, nama, email) dan disaring per status (aktif, suspend, disiapkan, gagal, dihapus) dan per paket.

- aksi massal akun ✅: admin mencentang akun di daftar akun lalu suspend (dengan alasan), buka suspend, hapus (konfirmasi ketik HAPUS) atau ganti paket sekaligus, paling banyak 100 akun. Setiap akun melewati pemeriksaan yang sama seperti aksi satu akun; akun yang tidak cocok (admin, akun sendiri, status yang salah) dilewati dan disebut beserta alasannya. Teruji di VPS dengan dua akun sementara: suspend massal lalu hapus massal tanpa sisa.

Semua fitur P1 sudah ada. Yang dipindah ke fase lain: CGI dan batas disk & bandwidth per domain (P3), UI dua bahasa beserta bahasa default (PB). SSL mail sudah ada sejak P2.

**Cakupan:**

- Akun user & paket user, buat/suspend/hapus akun, quota disk & inode.
- Domain, subdomain, pointer, redirect, www/HTTPS.
- Nginx → Apache → PHP-FPM per user, banyak versi PHP, PHP settings.
- SSL Let's Encrypt + sertifikat kustom, SSL hostname/panel.
- MariaDB: database, user, hak akses, phpMyAdmin SSO.
- FTP + SFTP, file manager lengkap, cron jobs.
- Info sistem, service monitor + watchdog, pengaturan server.
- 2FA, riwayat login, `rayxo`, `rayxo rebuild`.

**Selesai kalau:**

- WordPress bisa di-host dengan HTTPS, database, FTP, dan file manager; ✅ teruji di VPS (dashbot.web.id, lewat Cloudflare);
- suspend, unsuspend, dan hapus akun bersih tanpa sisa; ✅ teruji di VPS: tidak ada sisa home, vhost, pool PHP, unit systemd, crontab, maupun user/database MariaDB dari 19 akun yang sudah dihapus;
- `rayxo rebuild` menghasilkan config yang identik; ✅ teruji di VPS: dua kali berturut-turut, checksum config, crontab, kuota dan izin folder sama persis;
- user A terbukti tidak bisa membaca file user B (diuji otomatis). ✅ `make isolation`, dijalankan di VPS: semua jalur ditolak.

## P2 — DNS & email

**Status:**

- DNS tahap 1 ✅: `setup.sh` memasang PowerDNS Authoritative dengan database `pdns` sendiri (login unix_socket tanpa password), API hanya di 127.0.0.1 dengan kunci root saja, menjawab di IP server dan 127.0.0.1 saja (tidak bentrok dengan systemd-resolved) dan menolak domain yang tidak dikelolanya. Setiap domain dan pointer otomatis mendapat zone dari template (A `@`/`www`/`mail`/`ftp`, MX, SPF), subdomain menambah record ke zone induknya, zone ikut terhapus bersama domain atau akunnya, dan `rayxo rebuild` serta setiap upgrade menulis ulang semua zone. Nameserver diatur admin di Pengaturan Server (bawaan `ns1`/`ns2` dari domain hostname). Teruji dari internet: subdomain yang didelegasikan dijawab server lewat Google dan Cloudflare, termasuk lewat UDP.
- DNS tahap 2 ✅: halaman DNS untuk customer: lihat record setiap zone, tambah/ubah/hapus record A, AAAA, CNAME, MX, TXT, SRV, CAA dan NS (untuk subdomain), dengan TTL dan prioritas; validasi sama dengan agent, CNAME tidak boleh bersama record lain, paling banyak 1000 record per zone; reset zone ke record bawaan. Teruji di VPS: setiap jenis record, wildcard, ubah, hapus dan reset dijawab benar oleh server dan Google.
- DNS tahap 3a ✅: halaman DNS admin menampilkan semua zone (cari nama zone atau username) dan membuka record zone mana pun untuk diubah, termasuk milik akun yang di-suspend. Template zone baru bisa diubah admin (record dengan `{domain}` dan `{ip}`, dicek seperti record biasa) dan dikembalikan ke bawaan; zone yang sudah ada tidak ikut berubah. Teruji di VPS: record template baru terisi dan dijawab Google. dashbot.web.id kini memakai nameserver server ini.
- DNS tahap 3b ✅: SSL wildcard. Domain yang nameservernya mengarah ke server ini bisa meminta sertifikat untuk `domain` dan `*.domain` lewat DNS-01. Hook certbot (`rayxo acme-dns-hook`) memasang dan membersihkan `_acme-challenge` di PowerDNS, juga saat perpanjangan otomatis, dan agent menolak lebih dulu kalau nameserver domain bukan milik server ini. Subdomain dari domain berwildcard langsung memakai sertifikat induknya tanpa menerbitkan yang baru (`www.<subdomain>` tidak tercakup); melepas SSL induk ikut melepas subdomain itu lebih dulu, dan agent menolak menghapus sertifikat yang masih dipakai vhost lain. Teruji di VPS dengan Let's Encrypt sungguhan: sertifikat wildcard terbit, subdomain tersaji HTTPS dengannya, dry-run perpanjangan lolos, dan pelepasan bersih.
- Email tahap 1 ✅: `setup.sh` membuka Postfix untuk email customer (domain dan mailbox dibaca dari view SQL lewat user MariaDB `rayxo-mail` yang hanya boleh `SELECT`), memasang Dovecot (IMAP dan POP3 wajib TLS dengan sertifikat hostname, LMTP, kuota per mailbox) dan Rspamd + Redis (DKIM per domain dan filter spam, tanpa greylisting). Kirim lewat 587 (STARTTLS) atau 465 (TLS) wajib login dan hanya sebagai alamat sendiri; email dari pengirim yang login mendapat `Message-ID` dan `Date` kalau aplikasinya tidak menulisnya, dan `verify_install` memastikan server bukan open relay. Halaman Email untuk customer: buat mailbox (password minimal 12 karakter, kuota opsional), ganti password atau kuota, hapus, dengan batas jumlah dari paket dan info pengaturan aplikasi email. Mailbox pertama sebuah domain membuat kunci DKIM (selector `rayxo`) dan menambah record DKIM, DMARC `p=none` (kalau belum ada), serta MX dan A `mail` (kalau belum ada MX) ke zone-nya. Email disimpan di `/home/<user>/imap/<domain>/<nama>/Maildir` milik akun itu; menghapus domain atau akun ikut menghapus kunci DKIM dan email-nya, dan `rayxo rebuild` menyiapkan ulang semua domain mail. Teruji dari internet di dashbot.web.id: email dari Gmail masuk, dan email ke Gmail lolos SPF, DKIM, dan DMARC.
- Email tahap 2 ✅: webmail Roundcube 1.7 (versi & SHA-256 dipatok, tanda tangan rilis dicek) di `https://<hostname>/webmail/`, dengan pool PHP, user sistem, database dan user MariaDB sendiri; Roundcube bicara dengan Dovecot dan Postfix di 127.0.0.1. Login dengan alamat dan password, atau tanpa password lewat tombol Webmail di halaman Email: panel membuat sesi webmail (token acak, panel hanya menyimpan SHA-256-nya, berlaku 12 jam, paling banyak 10 per mailbox) dan mengirim tiket bersegel yang hanya berlaku satu menit di browser yang memintanya, persis seperti phpMyAdmin. Dovecot menerima token itu sebagai password hanya dari server ini. Ganti password, hapus mailbox, suspend, atau sesi habis membuat Roundcube kembali ke halaman login; menghapus mailbox, domain atau akun ikut menghapus pengaturan, identitas dan kontak Roundcube-nya. Teruji di VPS: SSO, kirim dari Roundcube (DKIM, tersimpan di Sent), token ditolak dari IP publik, sesi putus setelah ganti password, data Roundcube bersih setelah mailbox dihapus; juga dibuka dari panel untuk `hi@dashbot.web.id`.
- Email tahap 3 ✅: forwarder dan catch-all. Forwarder meneruskan email sebuah alamat ke 1–20 alamat lain (di server ini atau di luar), dihitung dengan batas paket baru `email_forwarders`; kalau alamatnya sama dengan akun email, akun itu tetap menerima salinan. Forwarder pertama sebuah domain menyiapkan email domain itu seperti akun email pertama. Catch-all per domain meneruskan email ke alamat yang tidak ada ke satu alamat, atau menolaknya seperti biasa; alamat tujuan di domain yang sama harus ada, dan akun email atau forwarder yang dituju catch-all tidak bisa dihapus sebelum catch-all-nya diubah. Postfix membaca semuanya dari view SQL (`virtual_alias_maps`: forwarder, akun email, lalu catch-all), jadi perubahan langsung berlaku. Email yang diteruskan ke luar tetap membawa pengirim aslinya (belum SRS), jadi lolos DMARC di tujuan lewat DKIM pengirimnya. Teruji di VPS: forwarder, salinan di akun email, catch-all, dan penolakan alamat yang tidak ada setelah catch-all dimatikan.
- Email tahap 4 ✅: Sieve (Dovecot Pigeonhole). Email yang ditandai Rspamd sebagai spam (`X-Spam: Yes`, skor ≥ 6; header palsu dari pengirim dibuang) otomatis masuk folder Junk lewat skrip server. Setiap akun email punya halaman "Filter dan balasan otomatis": balasan otomatis/cuti (subjek, isi, tanggal mulai/selesai opsional, jeda per pengirim 1–30 hari) dan filter berurutan (Dari, Kepada/Cc atau Subjek berisi/sama dengan teks → pindah ke folder, teruskan salinan, atau hapus; paling banyak 50). Panel menyusun skrip Sieve-nya (setiap nilai di-escape) dan agent menulisnya ke folder akun email sebagai user akun (`mail.sieve_apply`); `rayxo rebuild` menulis ulang semuanya. Salinan yang diteruskan dikirim dari alamat akun email (SPF lolos). Teruji di VPS: filter pindah folder, salinan, hapus, balasan otomatis sekali per pengirim (bertanda tangan DKIM, `Auto-Submitted`), dan spam asli dari Rspamd (pola GTUBE) masuk Junk.
- Pengamanan layanan email ✅: dashboard Rspamd, Redis, dan milter Rspamd/OpenDKIM tadinya mendengar di 127.0.0.1 tanpa password, sehingga website atau cron akun hosting bisa membaca riwayat email semua customer, mengubah data Rspamd, atau meminta tanda tangan DKIM. Sekarang semuanya hanya lewat unix socket yang terkunci (milter untuk Postfix, scanner dan UI untuk root, Redis untuk Rspamd), `verify_install` gagal kalau ada yang kembali ke TCP, dan pengirim yang login hanya ditandatangani DKIM untuk domain alamatnya sendiri. Teruji di VPS: akun hosting tidak bisa membuka satu pun port atau socket-nya; email masuk dipindai, email keluar dan balasan otomatis bertanda tangan DKIM, From domain lain tidak ditandatangani, email panel tetap ditandatangani OpenDKIM, dan spam tetap masuk Junk. Siapa yang boleh mendapat tanda tangan DKIM juga dibatasi: email yang masuk lewat port 25 (termasuk dari 127.0.0.1, yang bisa dibuka akun hosting) tidak pernah ditandatangani, baik oleh Rspamd maupun OpenDKIM; yang ditandatangani hanya email yang login (587/465, hanya untuk domain alamat login-nya) dan email dari `sendmail` lokal (PHP `mail()`, cron), yang alamat pengirimnya dibatasi Postfix per user Linux (`local_login_sender_maps` dari view `v_mail_local_senders`: hanya `@<domain>` milik akun itu, nama user-nya, atau `<>`; root dan postfix bebas). Email panel kini dikirim lewat port 587 dengan login `rayxo@<hostname>` (password acak di `.env`, hanya diterima Dovecot dari server ini), jadi tetap ditandatangani OpenDKIM untuk hostname. Teruji di VPS dengan akun uji: port 25 untuk domain sendiri maupun domain lain tidak ditandatangani, login dengan From domain sendiri ditandatangani dan From domain lain tidak, `sendmail` dengan alamat domain sendiri ditandatangani, `sendmail` dengan alamat domain lain atau alamat panel ditolak ("not authorized to use sender"), balasan otomatis dan email panel tetap bertanda tangan.
- Batas kirim dan antrean email ✅: setiap akun hosting paling banyak mengirim ke 300 penerima per 24 jam (bawaan, diubah admin di Pengaturan Server; bisa juga batas total seluruh server, kosong = tanpa batas). Rspamd menghitungnya lewat modul ratelimit dengan `ratelimit.lua` sendiri: email yang login di 587/465 (termasuk webmail) dihitung untuk akun pemilik domain login-nya, email dari sendmail lokal (PHP `mail()`, cron, balasan otomatis) untuk akun pemilik alamat pengirimnya. Pengirim kosong (`<>`) tidak lagi diterima dari akun hosting, dan balasan otomatis kini dikirim dari alamat mailbox, jadi tidak ada email akun yang lolos dari hitungan. Email di atas batas ditolak sementara (451): klien SMTP mencoba lagi nanti, dan email dari sendmail lokal menunggu di maildrop lalu berangkat sendiri begitu batasnya longgar. Panel mengirim batas dan domain tiap akun ke server saat pengaturan berubah, saat `rayxo rebuild`, dan setiap 5 menit kalau ada yang berubah; Rspamd membacanya dalam 30 detik. Halaman admin Antrean Email menampilkan email yang tertunda (dengan alasannya), ditahan, atau menunggu, dan bisa mengirim ulang satu atau semua email serta menghapus yang dipilih. Teruji di VPS uji: dengan batas 3, balasan otomatis dan 2 email login lolos, email berikutnya ditolak 451 dan sendmail lokal tertahan, `sendmail -f ""` ditolak, email masuk tetap diterima; daftar, kirim ulang, dan hapus antrean (tertunda, ditahan, ID yang sudah tidak ada) di Postfix sungguhan.
- Akun email: suspend dan batas kirim sendiri ✅: akun email bisa dinonaktifkan dari formulir ubah ("Nonaktifkan login"): tidak bisa login di IMAP, POP3, SMTP maupun webmail (sesi webmail-nya diakhiri), jadi tidak bisa dipakai mengirim, tapi email untuknya tetap masuk (`v_mail_mailboxes` memuatnya dengan `can_login = 0`, dan Dovecot hanya menerima login dengan `can_login = 1`). Setiap akun email juga bisa diberi batas kirim harian sendiri untuk email yang dikirim dengan login-nya, paling tinggi batas akun hosting (`mailbox:<alamat>` di map batas kirim). Teruji di VPS uji: dengan batas 2, email ketiga ditolak 451; setelah dinonaktifkan, login IMAP dan SMTP ditolak sementara email masuk tetap terkirim; setelah diaktifkan lagi, login berhasil.
- Pemakaian email ✅: ukuran dan jumlah pesan setiap akun email tampil di halaman Email ("Terpakai / kuota"), dari catatan kuota Dovecot (`doveadm quota get -A`, lewat agent `mail.usage`) yang dikumpulkan `rayxo:collect-usage` tiap 15 menit, juga untuk akun email tanpa kuota. Teruji di VPS uji: setelah 3 email masuk, panel menyimpan 61 KB dan 3 pesan, sama dengan Dovecot.
- Status DNS email ✅: halaman Email punya panel "Status DNS email". Tombol "Cek DNS" per domain memeriksa DNS publik lewat DNS over HTTPS (Cloudflare, cadangan Google), dan hasilnya disimpan 5 menit: MX mengarah ke server ini, satu SPF yang mengizinkan server ini, kunci DKIM sama dengan kunci server, dan ada DMARC. Untuk record yang belum ada atau tidak cocok, nilai yang seharusnya ditampilkan supaya bisa disalin ke DNS luar (misalnya Cloudflare). Teruji dengan DNS sungguhan: semua record dashbot.web.id terbaca benar, dan kunci DKIM yang salah terdeteksi.
- Filter spam per akun email ✅: di halaman "Filter dan balasan otomatis", setiap akun email bisa punya filter spam sendiri: ambang skor (1–30, bawaan 6, dibaca dari bintang `X-Spam-Level` Rspamd), aksi (pindah ke Junk, hapus, atau tandai saja tetap di Inbox), daftar pengirim yang bukan spam (whitelist) dan yang selalu spam (blacklist), berupa alamat atau @domain. Skrip Sieve akun itu menangani spam lebih dulu, sebelum filter dan balasan otomatis, dan Dovecot melewati skrip spam server (`sieve_before` per akun dari userdb: skrip kosong untuk akun dengan filter sendiri). Akun lain tetap memakai bawaan server (`X-Spam: Yes` ke Junk). `setup.sh` menulis ulang semua skrip Sieve setiap upgrade (`rayxo:apply-mail-rules`). Teruji di VPS uji dengan Rspamd dan Dovecot sungguhan: bawaan memindah GTUBE ke Junk; dengan ambang 30, GTUBE tetap di Inbox; ambang 6 memindah email 10 bintang ke Junk; blacklist menghapus atau hanya menandai; whitelist meloloskan.
- Penerima email per domain ✅: panel "Penerima email" di halaman Email memilih siapa yang menerima email tiap domain: server ini, Google Workspace, Zoho Mail, atau layanan lain. Domain yang emailnya di luar keluar dari `v_mail_domains`, `v_mail_forwarders` dan `v_mail_catchalls`, jadi Postfix mengirim email untuknya (juga dari website, cron dan akun lain di server ini) ke MX layanan itu; akun email domain itu tetap bisa login (IMAP, webmail) untuk memindahkan email lama, dan email website-nya tetap ditandatangani DKIM server ini. Untuk Google Workspace dan Zoho, record MX zone diganti milik layanan itu (`smtp.google.com`; `mx`/`mx2`/`mx3.zoho.com`) dan SPF ditambah include-nya; saat kembali ke server ini atau pindah layanan, hanya record yang dipasang panel yang dilepas, dan domain tanpa MX kembali mendapat `mail.<domain>`. Layanan lain: record diatur customer sendiri. "Cek DNS" untuk domain eksternal memeriksa MX ke layanan itu (bukan ke server ini) dan include SPF-nya. Sekalian diperbaiki: forwarder di alamat akun email yang di-suspend tetap menyimpan salinan di akun itu. Teruji di VPS uji: Postfix melepas domain, forwarder dan catch-all, email lokal diarahkan ke MX luar, login akun email tetap berhasil, PowerDNS menjawab MX dan SPF Google lalu Zoho, kembali ke server ini memulihkan MX `mail.<domain>` dan SPF, dan email kembali masuk.
- SRS untuk forwarder ✅: email yang diteruskan server ini ke luar (forwarder, catch-all, alamat tujuan di domain lain) dikirim dengan envelope sender SRS hostname server (`SRS0=<hash>=<hari>=<domain>=<nama>@<hostname>`), jadi lolos SPF di tujuan; header tidak diubah, jadi tanda tangan DKIM pengirim asli tetap berlaku. Pengirim di domain yang di-host server ini tidak ditulis ulang (`v_mail_hosted_domains`), jadi email website tetap lolos SPF domainnya sendiri. Bounce ke alamat SRS diterima lagi dan diteruskan ke pengirim asli; alamat yang hash-nya salah atau umurnya lebih dari 21 hari ditolak, jadi tidak bisa dipakai untuk relay. Alamat dibuat dan dicek `rayxod srs` (HMAC-SHA1 seperti libsrs2, termasuk SRS1 untuk email yang sudah diteruskan server lain), berjalan sebagai user `rayxo-srs` tanpa hak istimewa dan hanya bisa dihubungi Postfix lewat unix socket; paket `postsrsd` Ubuntu tidak dipakai karena mendengar di TCP 127.0.0.1 tanpa autentikasi, sehingga akun hosting bisa membuat alamat relay sendiri. Kunci rahasianya `/etc/rayxo/srs.secret`, tetap sama saat upgrade. Akibat sampingan: email masuk dari luar ke akun email di server ini kini ber-`Return-Path` SRS (balasan otomatis dan bounce tetap sampai ke pengirim asli lewat alamat itu). SPF untuk hostname server (`v=spf1 a ~all`, sudah ada di daftar DNS setup.sh) kini juga dipakai untuk email yang diteruskan. Teruji di VPS uji: akun hosting tidak bisa membuka socket, email yang diteruskan ke luar berangkat dengan pengirim SRS dan bounce-nya kembali ke pengirim asli, email dari domain sendiri tetap dengan pengirimnya, dan lewat IP publik alamat SRS yang sah diterima sementara yang dipalsukan ditolak (550).
- SSL per domain email (SNI) ✅: aplikasi email bisa memakai `mail.<domain>` sebagai server masuk dan keluar dengan sertifikatnya sendiri. Begitu domain menerima email, panel meminta sertifikat untuk `mail.<domain>`: sertifikat domain yang sudah mencakupnya (wildcard atau custom) dipakai, kalau tidak Let's Encrypt menerbitkannya lewat HTTP-01 (situs default Nginx kini melayani tantangan ACME untuk nama tanpa website). Dovecot (IMAP, POP3) dan Postfix (25, 465, 587) menyajikannya lewat SNI dari salinan sertifikat; nama lain tetap mendapat sertifikat hostname. Halaman Email punya panel "Nama server email" (status SSL, alasan gagal, tombol "Pasang SSL"/"Coba lagi"), dan panel "Cara terhubung" menampilkan `mail.<domain>` untuk domain yang dipilih begitu SSL-nya aktif. Perpanjangan: hook certbot kini juga me-reload Dovecot dan Postfix (sebelumnya Dovecot terus menyajikan sertifikat hostname lama setelah diperpanjang) dan menyegarkan salinan; `rayxo:sync-mail-ssl` setiap hari dan setiap upgrade meminta sertifikat untuk domain yang belum pernah dan menyegarkan semuanya. Teruji di VPS uji: tanpa DNS `mail.<domain>`, Let's Encrypt tidak dihubungi dan alasannya tampil; dengan sertifikat domain yang mencakupnya, `mail.<domain>` mendapat sertifikat itu di 993, 995, 465, 587 dan 25 sementara hostname tetap; Dovecot tetap bisa restart; setelah sertifikat dicabut dan refresh, kembali ke sertifikat hostname; menghapus domain melepas `mail.<domain>`. Penerbitan Let's Encrypt sungguhan untuk `mail.<domain>` diuji saat upgrade server produksi.

**Cakupan:**

- PowerDNS, template DNS, DNS management, nameserver default, SSL wildcard.
- Postfix + Dovecot + Rspamd + Roundcube.
- Akun email, forwarder, catch-all, autoresponder, vacation, filter, spam filter.
- DKIM/SPF/DMARC otomatis, batas kirim, mail queue, SSL untuk mail & FTP.

Semua fitur P2 sudah ada, terpasang di server produksi dan dirilis sebagai v0.6.0 (12 November 2026). SSL FTP sengaja tetap memakai sertifikat hostname (sejak P1): setiap login FTP sudah terikat ke foldernya sendiri, jadi sertifikat per domain tidak dibutuhkan; SNI per domain hanya untuk email.

**Selesai kalau:**

- zone DNS bisa di-resolve dari luar; ✅ dashbot.web.id dilayani ns1/ns2.rayxopanel.com dan dijawab Google serta Cloudflare; "Cek DNS" panel untuk dashbot.web.id: MX, SPF, DKIM dan DMARC semuanya ok;
- email keluar lolos SPF, DKIM, dan DMARC (skor mail-tester minimal 9/10); ✅ skor mail-tester 10/10 untuk email dari hi@dashbot.web.id (12 November 2026), dan email ke Gmail lolos SPF, DKIM dan DMARC;
- email masuk diterima, dan spam tersaring. ✅ email dari Gmail masuk ke dashbot.web.id; GTUBE masuk Junk, filter spam per akun teruji; `mail.dashbot.web.id` menyajikan sertifikat Let's Encrypt-nya sendiri di 993, 995, 465, 587 dan 25.

## P3 — Reseller, statistik, API

**Status:**

- Reseller inti ✅: admin membuat reseller dan admin lain (akun panel saja, tanpa user Linux), dan mengatur paket reseller: jumlah akun, kuota yang boleh dibagikan (disk, inode, bandwidth, domain, subdomain, pointer, email, forwarder, database, FTP, cron), SSL, dan overselling. Reseller punya area sendiri (`/reseller`): dashboard dengan pemakaian dan alokasi dibanding jatahnya, daftar akun hosting miliknya (cari, filter, urutkan, aksi massal), buat/suspend/hapus akun, dan paket miliknya sendiri yang tidak boleh melebihi jatahnya. Jumlah akun selalu dibatasi; tanpa overselling, paket akun-akunnya dijumlah tidak boleh melebihi jatah, jadi setiap akun wajib punya paket yang membatasi kuota yang dibatasi jatah. Nama paket unik per pemilik. Admin memindahkan akun ke pemilik lain (paket harus milik pemilik baru, jatah reseller tujuan berlaku) dan mengganti paket reseller. Reseller yang masih punya akun tidak bisa dihapus; suspend reseller hanya memblokir login-nya. Login as: admin ke reseller atau akun hosting, reseller ke akunnya sendiri, tercatat di audit log (`impersonator_id` di setiap entri selama sesi itu), tidak tercatat sebagai login akun itu, dan konfirmasi password tidak terbawa, jadi password, email, 2FA dan passkey akun itu tidak bisa diubah. Tes otorisasi menelusuri setiap rute reseller: akun dan paket milik orang lain dijawab 404, dan rute admin serta akun hosting tertutup untuk reseller. Teruji di VPS (MariaDB dan agent sungguhan): akun buatan reseller dibuat agent, akun kedua di atas jatah ditolak, hapus akun menghapus user Linux-nya.

- Level reseller lengkap, paket reseller, overselling, branding, nameserver kustom, login as.
- Manajemen IP, pindah pemilik, pesan massal, tiket.
- Bandwidth, statistik harian & grafik, GoAccess, log viewer, batas resource (cgroups).
- REST API + login keys, notifikasi email & Telegram.
- SSH jail & SSH keys, error page, protected directory, hotlink, MIME, CGI per domain.
- Batas disk & bandwidth per domain.
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
- UI dua bahasa (PB, di bawah).

**Selesai kalau:** checklist keamanan lolos dan uji migrasi percobaan di server staging dengan beberapa akun nyata berjalan stabil 2 minggu.

## PB — Dua bahasa

Dikerjakan bersama P5 (diputuskan 12 November 2026), supaya halaman baru dari P3 dan P4 ikut diterjemahkan sekali jalan, saat tampilan sudah stabil. Sampai saat itu, teks tampilan React tetap ditulis dalam bahasa Indonesia, dan pesan dari server memakai kunci bahasa Inggris lewat `__()` dengan terjemahan Indonesia di `lang/id.json`.

- Semua teks UI panel lewat terjemahan (Indonesia & English), pilihan bahasa per user, bahasa default server di Pengaturan Server.
- Pesan dari server (validasi, toast) mengikuti bahasa user.

**Selesai kalau:** setiap halaman bisa dipakai penuh dalam kedua bahasa, dan tes otomatis menolak teks UI tanpa terjemahan.

## P6 — Fitur lanjutan

Software manager (setara CustomBuild), app installer & WordPress manager, Git deploy, Node.js/Python, Redis per user, DNSSEC, mailing list, multi-server & DNS cluster, plugin & skin manager, web terminal, malware scan, ganti username.

---

## Strategi pindah dari panel lama

1. Siapkan **server baru** untuk rayxo. Jangan dipasang di server yang masih menjalankan cPanel/DirectAdmin.
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

1. Tetapkan nama final: **RAYXO**. ✅
2. Siapkan server Ubuntu 24.04 bersih untuk uji (`installer/test-in-vm.sh`: VPS uji, dan VM Lima di CI). ✅
3. Scaffold panel Laravel + React (di Herd) dan agent Go. ✅
4. Socket agent + `system.info`, tampil di dashboard admin. ✅
5. Installer versi pertama. ✅
