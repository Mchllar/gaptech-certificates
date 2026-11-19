<?php
/**
 * Front door. Staff and clients sign in on different pages, so this asks
 * which you are first. Anyone already signed in is sent straight on.
 * Look and feel shared with the other entry pages: app/views/entry.php.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/views/layout.php';
require APP_DIR . '/views/entry.php';

if ($signedIn = current_user()) {
    redirect(staff_home($signedIn));
}
if (current_client()) {
    redirect('client_portal.php');
}

layout_top('Welcome', 'public');
echo entry_styles();
?>
<div id="wl">
  <section class="wl-card" aria-labelledby="wl-title">
    <?= entry_logo() ?>

    <header class="wl-head">
      <h1 id="wl-title">Certificates System</h1>
      <p>Speed governor and tracking certificates. How would you like to continue?</p>
    </header>

    <ul class="wl-list">
      <li>
        <a class="wl-opt" href="login.php">
          <span class="wl-ico"><?= entry_icon('staff') ?></span>
          <span class="wl-txt">
            <b>Staff sign in</b>
            <span>Issue certificates, approvals and reports</span>
          </span>
          <span class="wl-go"><?= entry_icon('next') ?></span>
        </a>
      </li>
      <li>
        <a class="wl-opt is-client" href="client_login.php">
          <span class="wl-ico"><?= entry_icon('client') ?></span>
          <span class="wl-txt">
            <b>Client portal</b>
            <span>View and download your vehicles' certificates</span>
          </span>
          <span class="wl-go"><?= entry_icon('next') ?></span>
        </a>
      </li>
    </ul>

    <p class="wl-foot">
      <?= entry_icon('shield') ?>
      <span>Checking a certificate is genuine?</span>
      <a href="verify.php">Verify it, no sign in needed</a>
    </p>
  </section>
</div>
<?php layout_bottom(); ?>
