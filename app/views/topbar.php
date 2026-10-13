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
        <img class="gt-top-logo" src="assets/logo_header.png" alt="GAPTECH Solutions Ltd">
    </span>
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

    <div class="gt-top-user">
      <span class="gt-avatar" aria-hidden="true"><?= e($gtInitials) ?></span>
      <span class="gt-who">
        <span class="gt-who-name"><?= e($gtTopName) ?></span>
        <span class="gt-role gt-role-<?= e(strtolower($gtTopRole) ?: 'client') ?>">
          <?= e($gtTopRole ?: 'client') ?>
        </span>
      </span>
    </div>
  </div>
</header>
