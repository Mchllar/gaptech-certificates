<?php
/** Shared page chrome: side navigation, top bar, content area. */

/** Small inline icons, so the menu needs no image files. */
function nav_icon(string $name): string
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>'
            . '<rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'new' => '<path d="M12 5v14M5 12h14"/>',
        'clients' => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0"/>'
            . '<path d="M16 8.5a3 3 0 0 1 0 5"/><path d="M17.5 19a5.4 5.4 0 0 0-2-4"/>',
        'settings' => '<path d="M22.07 10.40 L22.07 13.60 L19.20 13.73 L18.31 15.87 L20.25 18.00 '
        . 'L18.00 20.25 L15.87 18.31 L13.73 19.20 L13.60 22.07 L10.40 22.07 L10.27 19.20 '
        . 'L8.13 18.31 L6.00 20.25 L3.75 18.00 L5.69 15.87 L4.80 13.73 L1.93 13.60 '
        . 'L1.93 10.40 L4.80 10.27 L5.69 8.13 L3.75 6.00 L6.00 3.75 L8.13 5.69 L10.27 4.80 '
        . 'L10.40 1.93 L13.60 1.93 L13.73 4.80 L15.87 5.69 L18.00 3.75 L20.25 6.00 '
        . 'L19.20 8.13 L19.20 10.27 Z"/><circle cx="12" cy="12" r="3.2"/>',
        'certs' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'signout' => '<path d="M15 12H4M8 8l-4 4 4 4"/><path d="M12 4h6a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-6"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
            stroke-linecap="round" stroke-linejoin="round">' . ($paths[$name] ?? '') . '</svg>';
}

function nav_item(string $href, string $icon, string $label): string
{
    $current = basename((string)parse_url($_SERVER['PHP_SELF'], PHP_URL_PATH));
    $on = ($current === $href) ? ' class="on"' : '';
    return '<a href="' . e($href) . '"' . $on . '>' . nav_icon($icon)
        . '<span>' . e($label) . '</span></a>';
}

function layout_top(string $title, string $nav = 'staff'): void
{
    $user = $nav === 'staff' ? current_user() : null;
    $client = $nav === 'client' ? current_client() : null;
    $signedIn = $user || $client;
    ?><!DOCTYPE html>
      <html lang="en">
      <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="assets/style.css">
        <link rel="stylesheet" href="assets/sidenav.css">
        <link rel="stylesheet" href="assets/topbar.css">
        <link rel="stylesheet" href="assets/content.css">
        <link rel="stylesheet" href="assets/ui.css">
      </head>
      <body class="<?= $signedIn ? 'has-sidebar' : 'plain' ?>">
      <?php require __DIR__ . '/sidenav.php'; ?>
      <?php require __DIR__ . '/topbar.php'; ?>

  <div class="shell">
    <?php if (!$signedIn): ?>
      <div class="auth-brand">
        <img src="assets/logo_header.png" alt="">
      </div>
  <?php endif; ?>

  <main>
    <?php
        $message = flash();
        if ($message) {
            echo '<p class="flash">' . e($message) . '</p>';
        }
    }
    /** A back link above the page heading. Call it immediately after layout_top(). */
    function layout_back(string $href, string $label = 'Back'): void
    {
        echo '<a class="back-link" href="' . e($href) . '">'
          . '<span aria-hidden="true">&larr;</span> ' . e($label) . '</a>';
    }
    function layout_bottom(): void
    {
    ?>

    <script>
      (function () {
        var ICON = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor"'
                + ' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                + '<path d="M2.2 12S6 5.6 12 5.6 21.8 12 21.8 12 18 18.4 12 18.4 2.2 12 2.2 12z"/>'
                + '<circle cx="12" cy="12" r="3.1"/></svg>';        
        var ICON_OFF = ICON.replace('</svg>', '<path d="M3.5 3.5l17 17"/></svg>');

        document.querySelectorAll('input[type="password"]').forEach(function (input) {
          if (input.dataset.pwWired) { return; }
          input.dataset.pwWired = '1';

          var wrap = document.createElement('span');
          wrap.className = 'pw-wrap';
          input.parentNode.insertBefore(wrap, input);
          wrap.appendChild(input);

          var btn = document.createElement('button');
          btn.type = 'button';                 // never submits the form
          btn.className = 'pw-eye';
          btn.innerHTML = ICON;
          btn.setAttribute('aria-label', 'Show password');
          wrap.appendChild(btn);

          btn.addEventListener('click', function () {
            var showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            btn.innerHTML = showing ? ICON : ICON_OFF;
            btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            input.focus();
          });
        });
      })();
    </script>
  </main>
  <footer>GAPTECH Solutions Ltd &middot; SG Certificate System</footer>
  </div>
  </body>
  </html>
  <?php
}
