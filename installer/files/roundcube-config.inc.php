<?php

/**
 * Roundcube configuration for rayxo. setup.sh writes it to
 * /etc/rayxo-webmail/config.inc.php on every run, so changes made there are
 * lost; the keys next to it (db.key, des.key, sso.key) are kept.
 *
 * Mailboxes sign in with their full address and password, or from the
 * panel's Email page without one: the rayxo_sso plugin opens the panel's
 * ticket and signs in with the webmail session's token, which Dovecot only
 * accepts from this server. Roundcube talks to Dovecot and Postfix on
 * 127.0.0.1, so the sign-in is checked by the same database views as a
 * mail app's.
 */

declare(strict_types=1);

$rayxoKey = static fn (string $name): string => trim((string) file_get_contents('/etc/rayxo-webmail/'.$name.'.key'));

$config['db_dsnw'] = 'mysql://rayxo-webmail:'.rawurlencode($rayxoKey('db')).'@unix(/run/mysqld/mysqld.sock)/roundcube';
$config['des_key'] = $rayxoKey('des');

// Dovecot counts loopback connections as secure, so IMAP needs no TLS
// here; Postfix's submission port insists on STARTTLS, with the server's
// own certificate on 127.0.0.1.
$config['imap_host'] = '127.0.0.1:143';
$config['smtp_host'] = 'tls://127.0.0.1:587';
$config['smtp_user'] = '%u';
$config['smtp_pass'] = '%p';
$config['smtp_conn_options'] = [
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ],
];

$config['plugins'] = ['rayxo_sso', 'archive', 'zipdownload'];
$config['product_name'] = 'Webmail';
$config['support_url'] = '';
$config['language'] = 'id_ID';
$config['skin'] = 'elastic';

// Postfix only sends as the address that signed in, so identities may
// have another name or signature but not another address.
$config['identities_level'] = 1;
$config['max_message_size'] = '45M';

$config['use_https'] = true;
$config['session_path'] = '/webmail/';
$config['session_lifetime'] = 60;
$config['ip_check'] = true;
$config['login_rate_limit'] = 5;
$config['enable_installer'] = false;

$config['log_driver'] = 'syslog';
$config['syslog_id'] = 'roundcube';
$config['syslog_facility'] = LOG_MAIL;
$config['log_dir'] = '/var/lib/rayxo-webmail/log/';
$config['temp_dir'] = '/var/lib/rayxo-webmail/tmp/';
