<?php
// Copy this file to /home/<cpanel-user>/config.local.php and replace the placeholders.
// Keep the copy outside public_html and never commit it to version control.
return [
    'host' => 'localhost',
    'database' => '<cpanel-user>_bbhcapp',
    'username' => '<cpanel-user>_<database-user>',
    'password' => 'REPLACE_WITH_A_UNIQUE_DATABASE_PASSWORD',
    'charset' => 'utf8mb4',
    'smtp_host' => 'server415.web-hosting.com',
    'smtp_port' => 465,
    'smtp_encryption' => 'ssl',
    'smtp_username' => 'no-reply@barangaybalangan.site',
    'smtp_password' => 'SET_THIS_ONLY_IN_PRIVATE_CONFIG',
    'mail_from' => 'no-reply@barangaybalangan.site',
];

