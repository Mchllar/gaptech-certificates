<?php
/**
 * Edit this file after installing. Nothing else needs changing to get running.
 */
return [
    // PostgreSQL connection
    'db' => [
        'host' => 'localhost',
        'port' => 5432,
        'name' => 'gaptech_certs',
        'user' => 'gaptech',
        'pass' => 'Admin@907',
    ],

    // Where the app is reachable. Used for links in the QR code and e-mails.
    // Example on the office server: http://192.168.1.10/certificates/public
    'base_url' => 'http://192.168.0.109/gaptech-certificates/public',

    // Path to wkhtmltopdf. Leave empty to disable server-side PDFs and use the
    // browser's own "Save as PDF" from the print view instead.
    //   Windows: C:\Program Files\wkhtmltopdf\bin\wkhtmltopdf.exe
    //   Linux:   /usr/bin/wkhtmltopdf
    'wkhtmltopdf' => '',

    // Ubuntu/Debian ship an unpatched build that renders at ~77% and needs 1.299.
    // Use 1.0 for the official patched build from wkhtmltopdf.org (and on Windows).
    'render_zoom' => 1.299,

    // Folder for generated PDFs. Must be writable by the web server and must NOT
    // be inside public/, so certificates can only be fetched through download.php.
    'storage' => __DIR__ . '/../storage',

    'session_name' => 'gaptech_certs',
];
