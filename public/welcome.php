<?php
/**
 * Front door. Administrators and clients sign in on different pages, so this asks
 * which you are first. Anyone already signed in is sent straight on.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/views/layout.php';

if (current_user()) {
    redirect('index.php');
}
if (current_client()) {
    redirect('client_portal.php');
}

layout_top('Welcome', 'public');
?>
<!-- <h1 class="auth-title">Speed Governor <span>Certificates System</span></h1> -->
   <h1 style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; font-size: 1.75rem; font-weight: 700; color: #0f172a; margin: 0 0 1rem 0; padding-left: 0.875rem; border-left: 4px solid #3b82f6; letter-spacing: -0.025em; line-height: 1.25;">Speed Governor Certificates System</h1>
    <p class="hint"style="color: navyblue; margin: 4px 0 10px 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">Choose how to Continue</p>
<div class="door-cards">
  <a class="door door-admin" href="login.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.0"
         stroke-linecap="round" stroke-linejoin="round">
      <circle cx="9" cy="7.5" r="3.8"/>
      <path d="M2.5 21c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5"/>
      <path d="M18.5 2.2l3.3 1.3v3c0 2.1-1.4 3.4-3.3 4-1.9-.6-3.3-1.9-3.3-4v-3z"/>
    </svg>
    <span class="door-title">Administrator</span>
    <span class="door-text">Issue, print and manage certificates</span>
  </a>

  <a class="door door-accounts" href="login.php?as=accounts">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.0"
        stroke-linecap="round" stroke-linejoin="round">
      <circle cx="9" cy="7.5" r="3.8"/>
      <path d="M2.5 21c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5"/>
      <circle cx="18.5" cy="6" r="3.5"/>
      <path d="M18.5 3.7v4.6M17 4.8h3M17 7.2h3"/> 
    </svg>
    <span class="door-title">Accounts</span>
    <span class="door-text">Confirm payments and approve certificates</span>
  </a>

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
<?php layout_bottom(); ?>