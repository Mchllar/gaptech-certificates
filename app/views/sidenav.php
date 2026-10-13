<?php
/**
 * GAPTECH side navigation - markup, icons and behaviour in one include.
 *
 * Save as app/views/sidenav.php, then in layout.php, immediately after the
 * <body> tag is printed, add:
 *
 *     require __DIR__ . '/sidenav.php';
 *
 * It needs nothing else: it draws its own menu button, its own links and its
 * own icons. Delete the old <aside class="sidebar">, the old <div id="scrim">
 * and the old menu button from layout.php, along with their script.
 */

if (!function_exists('gt_icon')) {
    /** One of the small line icons, as inline SVG. */
    function gt_icon(string $name): string
    {
        $paths = [
            'dashboard' => '<rect x="3" y="3" width="7.5" height="7.5" rx="1.6"/>'
                         . '<rect x="13.5" y="3" width="7.5" height="7.5" rx="1.6"/>'
                         . '<rect x="3" y="13.5" width="7.5" height="7.5" rx="1.6"/>'
                         . '<rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.6"/>',

            'new'       => '<rect x="4" y="3" width="16" height="18" rx="2.2"/>'
                         . '<path d="M12 8.5v7M8.5 12h7"/>',

            'certs'     => '<rect x="4" y="3" width="16" height="18" rx="2.2"/>'
                         . '<path d="M8 8h8M8 12h8M8 16h4.5"/>',

            'approvals' => '<rect x="4" y="3" width="16" height="18" rx="2.2"/>'
                         . '<path d="M8.5 12.5l2.4 2.4 4.6-5"/>',

            'payments'  => '<rect x="2.5" y="5" width="19" height="14" rx="2.2"/>'
                         . '<path d="M2.5 9.5h19M6 15h4"/>',

            'clients'   => '<circle cx="9.2" cy="8" r="3.3"/>'
                         . '<path d="M3.6 19.6a5.6 5.6 0 0 1 11.2 0"/>'
                         . '<path d="M16.2 5.1a3.3 3.3 0 0 1 0 5.8"/>'
                         . '<path d="M17.6 14.6a5.6 5.6 0 0 1 2.9 5"/>',

            'settings'  => '<circle cx="12" cy="12" r="3.1"/>'
                         . '<path d="M12 2.6v2.6M12 18.8v2.6M21.4 12h-2.6M5.2 12H2.6'
                         . 'M18.6 5.4l-1.8 1.8M7.2 16.8l-1.8 1.8M18.6 18.6l-1.8-1.8M7.2 7.2L5.4 5.4"/>',

            'password'  => '<rect x="4.5" y="10" width="15" height="10.5" rx="2.2"/>'
                         . '<path d="M8 10V7a4 4 0 0 1 8 0v3"/>',

            'portal'    => '<path d="M12 3.5v11"/><path d="M8 11l4 4 4-4"/>'
                         . '<path d="M4 19.5h16"/>',

            'signout'   => '<path d="M9.5 21H5.5a2.2 2.2 0 0 1-2.2-2.2V5.2A2.2 2.2 0 0 1 5.5 3h4"/>'
                         . '<path d="M16 16.5l4.5-4.5L16 7.5"/><path d="M20.5 12H9.5"/>',

            'verify'    => '<circle cx="11" cy="11" r="7"/><path d="M16.3 16.3L21 21"/>'
                         . '<path d="M8.3 11.1l2 2 3.5-3.7"/>',
        ];

        $d = $paths[$name] ?? $paths['certs'];

        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"'
             . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
    }
}

if (!function_exists('gt_link')) {
    /** One navigation link, marked active when it is the page being viewed. */
    function gt_link(string $href, string $icon, string $label): string
    {
        $here = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $target = strtok($href, '?');
        $active = ($here === $target) ? ' is-active' : '';

        return '<a class="gt-link' . $active . '" href="' . e($href) . '"'
             . ($active ? ' aria-current="page"' : '') . '>'
             . gt_icon($icon) . '<span>' . e($label) . '</span></a>';
    }
}

$gtUser   = function_exists('current_user') ? current_user() : null;
$gtClient = (!$gtUser && function_exists('current_client')) ? current_client() : null;

// Nothing to show on the sign-in pages.
if (!$gtUser && !$gtClient) {
    return;
}

$gtRole = $gtUser ? (string)($gtUser['role'] ?? '') : 'client';
$gtName = $gtUser ? (string)($gtUser['name'] ?? '') : (string)($gtClient['name'] ?? '');
?>
<button type="button" class="gt-menu-btn" id="gt-menu-btn"
        aria-expanded="false" aria-controls="gt-sidebar" aria-label="Menu">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
       stroke-linecap="round" aria-hidden="true">
    <line class="gt-bar gt-bar-1" x1="4" y1="6"  x2="20" y2="6"/>
    <line class="gt-bar gt-bar-2" x1="4" y1="12" x2="20" y2="12"/>
    <line class="gt-bar gt-bar-3" x1="4" y1="18" x2="20" y2="18"/>
  </svg>
</button>

<aside class="gt-sidebar" id="gt-sidebar" aria-label="Main navigation">
  <?php if ($gtName !== ''): ?>
    <p class="gt-whoami">Signed in as<strong><?= e($gtName) ?></strong></p>
  <?php endif; ?>

  <p class="gt-label">Menu</p>

  <nav class="gt-nav">
    <?php if ($gtRole === 'accounts'): ?>
      <?= gt_link('approvals.php', 'approvals', 'Approvals') ?>
      <?= gt_link('payments.php', 'payments', 'Payments') ?>
      <?= gt_link('verify.php', 'verify', 'Verify a certificate') ?>
      <?= gt_link('change_password.php', 'password', 'Change password') ?>

    <?php elseif ($gtRole === 'client'): ?>
      <?= gt_link('client_portal.php', 'portal', 'My certificates') ?>
      <?= gt_link('verify.php', 'verify', 'Verify a certificate') ?>

    <?php else: ?>
      <?= gt_link('dashboard.php', 'dashboard', 'Dashboard') ?>
      <?= gt_link('index.php', 'certs', 'Certificates') ?>
      <?= gt_link('certificate_new.php', 'new', 'New certificate') ?>
      <?= gt_link('verify.php', 'verify', 'Verify a certificate') ?>
      <?= gt_link('client_access.php', 'clients', 'Client portal access') ?>
      <?= gt_link('user_admin.php', 'clients', 'Staff accounts') ?>
      <?= gt_link('settings.php', 'settings', 'Settings') ?>
      <?= gt_link('change_password.php', 'password', 'Change password') ?>
    <?php endif; ?>
  </nav>

  <div class="gt-foot">
    <a class="gt-signout" href="logout.php"><?= gt_icon('signout') ?><span>Sign out</span></a>
  </div>
</aside>

<div class="gt-scrim" id="gt-scrim"></div>

<script>
(function () {
  var btn   = document.getElementById('gt-menu-btn');
  var panel = document.getElementById('gt-sidebar');
  var scrim = document.getElementById('gt-scrim');
  if (!btn || !panel || !scrim) { return; }

  function openNav() {
    document.body.classList.add('gt-open');
    btn.setAttribute('aria-expanded', 'true');
    var first = panel.querySelector('.gt-link');
    if (first) { first.focus(); }
  }
  function closeNav() {
    document.body.classList.remove('gt-open');
    btn.setAttribute('aria-expanded', 'false');
  }

  btn.addEventListener('click', function () {
    document.body.classList.contains('gt-open') ? closeNav() : openNav();
  });
  scrim.addEventListener('click', closeNav);

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && document.body.classList.contains('gt-open')) {
      closeNav();
      btn.focus();
    }
  });

  // Following a link should put the panel away rather than leave it open
  // over the next page for the moment before it loads.
  panel.addEventListener('click', function (ev) {
    if (ev.target.closest('a')) { closeNav(); }
  });

  // Keep the keyboard inside the panel while it is open.
  panel.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Tab') { return; }
    var items = panel.querySelectorAll('a[href], button');
    if (!items.length) { return; }
    var first = items[0], last = items[items.length - 1];
    if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
    else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
  });
})();
</script>
