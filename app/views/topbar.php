<?php
/**
 * GAPTECH top bar.
 *
 * Save as app/views/topbar.php, then in layout.php require it on the line
 * immediately after sidenav.php:
 *
 *     require __DIR__ . '/sidenav.php';
 *     require __DIR__ . '/topbar.php';
 *
 * Delete the old <header class="topbar"> block from layout.php first.
 *
 * It picks up the page title from whatever layout_top() called its first
 * argument. If that is not $title in your file, change the line marked below.
 */

$gtTitle = $title ?? $pageTitle ?? '';          // <- the page title variable

$gtTopUser   = function_exists('current_user') ? current_user() : null;
$gtTopClient = (!$gtTopUser && function_exists('current_client')) ? current_client() : null;

if (!$gtTopUser && !$gtTopClient) {
    return;                                      // no bar on the sign-in pages
}

$gtTopName = $gtTopUser
    ? (string)($gtTopUser['name'] ?? '')
    : (string)($gtTopClient['name'] ?? '');
$gtTopRole = $gtTopUser ? (string)($gtTopUser['role'] ?? '') : 'client';

// Only staff have a username. A client's e-mail is optional and is not what they
// sign in with, so the menu shows a client their name and nothing else. If clients
// sign in with something else you want shown, put that column here in place of ''.
$gtTopHandle = $gtTopUser ? trim((string)($gtTopUser['username'] ?? '')) : '';

/** Up to two initials from a name: "Michelle Guya" -> MG */
$gtInitials = '';
foreach (preg_split('/\s+/', trim($gtTopName)) as $word) {
    if ($word !== '' && strlen($gtInitials) < 2) {
        $gtInitials .= strtoupper(substr($word, 0, 1));
    }
}
if ($gtInitials === '') {
    $gtInitials = '?';
}
?>
<header class="gt-topbar">
  <div class="gt-top-left">
    <span class="gt-top-mark">
      <span class="gt-m1">GAP</span><span class="gt-m2">TECH</span>
      <small>Certificates</small>
    </span>

    <?php if ($gtTitle !== ''): ?>
      <span class="gt-top-rule"></span>
      <h1 style="margin: 0; font-size: 16px; font-weight: 650; color: #1d5a16"; class="gt-top-title"><?= e($gtTitle) ?></h1>
    <?php endif; ?>
  </div>

  <div class="gt-top-right">
    <span class="gt-top-date">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <rect x="3.5" y="5" width="17" height="16" rx="2.2"/>
        <path d="M3.5 10h17M8 3v4M16 3v4"/>
      </svg>
      <?= e(date('D, j M Y')) ?>
    </span>

    <div class="gt-top-user" id="gt-account" role="button" tabindex="0"
         aria-haspopup="true" aria-expanded="false" aria-controls="gt-acct-menu">
      <span class="gt-avatar" aria-hidden="true"><?= e($gtInitials) ?></span>
      <span class="gt-who">
        <span class="gt-who-name"><?= e($gtTopName) ?></span>
        <span class="gt-role gt-role-<?= e(strtolower($gtTopRole) ?: 'client') ?>">
          <?= e($gtTopRole ?: 'client') ?>
        </span>
      </span>

      <div class="gt-acct-menu" id="gt-acct-menu" role="menu" hidden>
        <p class="gt-acct-head">Signed in as</p>
        <p class="gt-acct-name"><?= e($gtTopName) ?></p>
        <?php if ($gtTopHandle !== ''): ?>
          <p class="gt-acct-handle"><?= e($gtTopHandle) ?></p>
        <?php endif; ?>

        <div class="gt-acct-sep"></div>

        <?php if ($gtTopUser): ?>
          <a class="gt-acct-item" role="menuitem" href="change_password.php">Change password</a>
        <?php endif; ?>
        <a class="gt-acct-item gt-acct-out" role="menuitem" href="logout.php">Sign out</a>
      </div>
    </div>
  </div>
</header>

<style>
  /*
   * The bar itself is untouched: nothing here changes the avatar, the name or
   * the role pill, and topbar.css still owns all three. The only additions are
   * a positioning context, a pointer cursor, and the panel that drops out of it.
   */
  /*
   * The brand mark, pinned. topbar.css paints it with var(--gt-green) and
   * var(--gt-navy); something else on the page defines those as a dark colour,
   * so the fallbacks never get used and GAPTECH comes out all one shade. Two
   * classes beat one, and there is no variable left to override.
   */
  .gt-topbar .gt-top-mark .gt-m1 { color: #8CC63F; }
  .gt-topbar .gt-top-mark .gt-m2 { color: #0D4F72; }
 
  #gt-account { position: relative; cursor: pointer; }
  #gt-account:focus-visible { outline: 2px solid #1890D0; outline-offset: 3px; border-radius: 10px; }
 
  #gt-account .gt-acct-menu {
    position: absolute; top: calc(100% + 10px); right: 0; z-index: 2100;
    min-width: 230px; padding: 14px 0 6px;
    background: #fff; border: 1px solid #D7DEE5; border-radius: 12px;
    box-shadow: 0 14px 38px rgba(13, 79, 114, .22);
    text-align: left; cursor: default;
  }
  #gt-account .gt-acct-menu[hidden] { display: none; }
 
  #gt-account .gt-acct-head {
    margin: 0 16px 4px; font-size: 11.5px; font-weight: 700; letter-spacing: .5px;
    text-transform: uppercase; color: #8793A3;
  }
  #gt-account .gt-acct-name {
    margin: 0 16px; font-size: 15px; font-weight: 700; color: #1C2430;
  }
  #gt-account .gt-acct-handle {
    margin: 2px 16px 0; font-size: 13.5px; color: #5C6775; word-break: break-all;
  }
 
  #gt-account .gt-acct-sep { height: 1px; margin: 12px 0 6px; background: #EDF2F7; }
 
  #gt-account .gt-acct-item {
    display: block; padding: 9px 16px;
    font-size: 14.5px; color: #1C2430; text-decoration: none;
  }
  #gt-account .gt-acct-item:hover { background: #F2F7FB; color: #0D4F72; }
  #gt-account .gt-acct-out { color: #C3121A; }
  #gt-account .gt-acct-out:hover { background: #FBE9E9; color: #A50F16; }
 
  @media (max-width: 640px) {
    #gt-account .gt-acct-menu { min-width: 200px; }
  }
</style>
 
<script>
(function () {
  var box  = document.getElementById('gt-account');
  var menu = document.getElementById('gt-acct-menu');
  if (!box || !menu) { return; }

  function open(yes) {
    menu.hidden = !yes;
    box.setAttribute('aria-expanded', yes ? 'true' : 'false');
  }

  box.addEventListener('click', function (ev) {
    // A click on a menu item is the item's own business, not a toggle.
    if (menu.contains(ev.target)) { return; }
    ev.stopPropagation();
    open(menu.hidden);
  });

  box.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' || ev.key === ' ') {
      ev.preventDefault();
      open(menu.hidden);
    }
  });

  // A click anywhere else on the page closes it.
  document.addEventListener('click', function (ev) {
    if (!menu.hidden && !box.contains(ev.target)) { open(false); }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && !menu.hidden) { open(false); box.focus(); }
  });
})();
</script>
