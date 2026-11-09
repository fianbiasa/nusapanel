<?php

/**
 * phpMyAdmin configuration for nusapanel. setup.sh fills in the
 * double-brace placeholders and writes it to /etc/nusa-pma/config.inc.php
 * on every run, so changes made there are lost.
 *
 * Customers never type a password: the panel gives the account's MariaDB
 * owner user a fresh password, and nusapanel-sso.php puts it in the signon
 * session below. Without that session phpMyAdmin sends the browser to the
 * panel's Databases page.
 */

declare(strict_types=1);

$cfg['blowfish_secret'] = (string) base64_decode(trim((string) file_get_contents('/etc/nusa-pma/blowfish.key')), true);

$i = 1;
$cfg['Servers'][$i]['auth_type'] = 'signon';
$cfg['Servers'][$i]['SignonSession'] = 'NusaPanelSignon';
$cfg['Servers'][$i]['SignonCookieParams'] = [
    'lifetime' => 0,
    'path' => '/phpmyadmin/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
];
$cfg['Servers'][$i]['SignonURL'] = 'https://{{HOSTNAME}}:{{PANEL_PORT}}/databases';
$cfg['Servers'][$i]['LogoutURL'] = 'https://{{HOSTNAME}}/phpmyadmin/nusapanel-sso.php?logout=1';
$cfg['Servers'][$i]['host'] = 'localhost';
$cfg['Servers'][$i]['socket'] = '/run/mysqld/mysqld.sock';
$cfg['Servers'][$i]['AllowRoot'] = false;
$cfg['Servers'][$i]['AllowNoPassword'] = false;
$cfg['Servers'][$i]['hide_db'] = '^(information_schema|performance_schema|mysql|sys)$';

$cfg['TempDir'] = '/var/lib/nusa-pma/tmp';
$cfg['UploadDir'] = '';
$cfg['SaveDir'] = '';
$cfg['ExecTimeLimit'] = 600;

$cfg['DefaultLang'] = 'id';
$cfg['VersionCheck'] = false;
$cfg['SendErrorReports'] = 'never';
$cfg['PmaNoRelation_DisableWarning'] = true;

// Databases are created in the panel, which counts them against the
// package; the owner user's password is the panel's to set.
$cfg['ShowCreateDb'] = false;
$cfg['ShowChgPassword'] = false;
