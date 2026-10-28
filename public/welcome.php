<?php
/**
 * Front door. Administrators and clients sign in on different pages, so this asks
 * which you are first. Anyone already signed in is sent straight on.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/views/layout.php';

if ($signedIn = current_user()) {
    redirect(staff_home($signedIn));
}
if (current_client()) {
    redirect('client_portal.php');
}
 
layout_top('Welcome', 'public');
?>
<style>
  .vfy-title { margin: 0 0 6px; font-size: 22px; font-weight: 700; letter-spacing: -.4px; color: #1C2430; line-height: 1.25; }
     /* ---- a little wider once there is room ---- */
  .vfy h1.vfy-title {
    margin: 0 0 6px;
    font-size: 22px; font-weight: 700; letter-spacing: -.4px;
    line-height: 1.25; color: #0D4F72;
    padding-left: 12px; border-left: 4px solid #8CC63F;
  }
  @media (min-width: 520px) { .vfy h1.vfy-title { font-size: 26px; } }
  .vfy-sub { font-size: 14px; color: #7A8696; line-height: 1.5; text-align: center; }

</style>
<!-- <h1 class="auth-title">Speed Governor <span>Certificates System</span></h1> -->
<div class="vfy">
  <h1 class="vfy-title">Certificates System</h1>
  <p class="vfy-sub">Choose how to Continue</p>
<div class="door-cards">
  <!-- <a class="door door-admin" href="login.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.0"
         stroke-linecap="round" stroke-linejoin="round">
      <circle cx="9" cy="7.5" r="3.8"/>
      <path d="M2.5 21c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5"/>
      <path d="M18.5 2.2l3.3 1.3v3c0 2.1-1.4 3.4-3.3 4-1.9-.6-3.3-1.9-3.3-4v-3z"/>
    </svg>
    <span class="door-title">Administrator</span>
    <span class="door-text">Issue, print and manage certificates</span>
  </a> -->

  <a class="door" href="login.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.0"
         stroke-linecap="round" stroke-linejoin="round">
      <rect x="3" y="4" width="18" height="14" rx="2"/><path d="M3 9h18M8 18v2h8v-2"/>
    </svg>
    <span class="door-title">Staff</span>
    <span class="door-text">Certificates, Approvals and Dashboards</span>
  </a>

  <!-- <a class="door door-accounts" href="login.php?as=accounts">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.0"
        stroke-linecap="round" stroke-linejoin="round">
      <circle cx="9" cy="7.5" r="3.8"/>
      <path d="M2.5 21c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5"/>
      <circle cx="18.5" cy="6" r="3.5"/>
      <path d="M18.5 3.7v4.6M17 4.8h3M17 7.2h3"/> 
    </svg>
    <span class="door-title">Accounts</span>
    <span class="door-text">Confirm payments and approve certificates</span>
  </a> -->

  <a class="door door-client" href="client_login.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.0"
         stroke-linecap="round" stroke-linejoin="round">
      <circle cx="9" cy="7.5" r="3.8"/>
      <path d="M2.5 21c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5"/>
      <path d="M16.5 4.2a3.6 3.6 0 0 1 0 6.6"/>
      <path d="M21.5 21v-.5c0-2.7-1.7-5-4.2-5.9"/>
    </svg>
    <span class="door-title">Client</span>
    <span class="door-text">Download your own certificates</span>
  </a>

  <a class="door door-verify" href="verify.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.0"
         stroke-linecap="round" stroke-linejoin="round">
      <circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6M8.5 11l1.8 1.8 3.4-3.4"/>
    </svg>
    <span class="door-title">Verify a certificate</span>
    <span class="door-text">No sign in needed</span>
  </a>
</div>
</div>
<?php layout_bottom(); ?>