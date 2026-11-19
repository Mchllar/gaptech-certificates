<?php
/**
 * Application settings. Passwords and anything that differs per machine come
 * from the .env file in the project root (copy .env.example to .env and fill
 * it in). Never put a password in this file - it is committed to git.
 */
require_once __DIR__ . '/env.php';

$smtpHost = (string)env('SMTP_HOST', '');

return [
    // PostgreSQL connection
    'db' => [
        'host' => (string)env('DB_HOST', 'localhost'),
        'port' => (int)env('DB_PORT', '5432'),
        'name' => (string)env('DB_NAME', 'gaptech_certs'),
        'user' => (string)env('DB_USER', 'gaptech'),
        'pass' => (string)env('DB_PASS', ''),
    ],

    // E-mail (password reset links). Left unset, mail is switched off.
    'smtp' => $smtpHost === '' ? [] : [
        'host'      => $smtpHost,
        'port'      => (int)env('SMTP_PORT', '587'),
        'security'  => (string)env('SMTP_SECURITY', 'tls'),
        'username'  => (string)env('SMTP_USERNAME', ''),
        'password'  => (string)env('SMTP_PASSWORD', ''),
        'from'      => (string)env('SMTP_FROM', ''),
        'from_name' => (string)env('SMTP_FROM_NAME', 'GAPTECH Certificates'),
        'helo'      => (string)env('SMTP_HELO', ''),
    ],

    // Where the app is reachable. Used for links in the QR code and e-mails.
    // Example on the office server: http://192.168.1.10/certificates/public
    'base_url' => (string)env('BASE_URL', 'http://localhost/gaptech-certificates/public'),

    // Optional address the PDF renderer uses to reach this app from the server itself.
    'internal_url' => (string)env('INTERNAL_URL', ''),

    // Signs the short-lived links the PDF renderer uses. Empty = derived automatically.
    'pdf_secret' => (string)env('PDF_SECRET', ''),

    // Path to wkhtmltopdf. Leave empty to disable it and use Chrome or the
    // browser's own "Save as PDF" from the print view instead.
    //   Windows: C:\Program Files\wkhtmltopdf\bin\wkhtmltopdf.exe
    //   Linux:   /usr/bin/wkhtmltopdf
    'wkhtmltopdf' => (string)env('WKHTMLTOPDF_PATH', ''),
    'chrome'      => (string)env('CHROME_PATH', ''),

    // Ubuntu/Debian ship an unpatched build that renders at ~77% and needs 1.299.
    // Use 1.0 for the official patched build from wkhtmltopdf.org (and on Windows).
    'render_zoom' => (float)env('RENDER_ZOOM', '1.299'),

    // Folder for generated PDFs. Must be writable by the web server and must NOT
    // be inside public/, so certificates can only be fetched through download.php.
    'storage' => __DIR__ . '/../storage',

    'session_name' => 'gaptech_certs',
];
