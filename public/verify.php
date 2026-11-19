<?php
// Where "Back" should go depends on who is looking. A verification page can be
// opened by an administrator, by accounts, by a client, or by nobody signed in
// at all - and welcome.php is administrators only.

/**
 * Public verification page. This is what the QR code on the certificate opens, and what
 * an inspector or client uses to check a certificate by hand.
 *
 * Built for a phone first: the verdict is the first thing on screen, in one column,
 * with no horizontal scrolling at any width.
 *
 * A lookup needs either the certificate number or the vehicle registration (or both).
 * A registration shows that vehicle's current certificate only - retired ones are not listed.
 * Certificates accounts has not approved do not exist as far as this page is concerned.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require APP_DIR . '/views/layout.php';
require APP_DIR . '/views/entry.php';

$vfyBack = '';
if (function_exists('current_user') && ($vfyWho = current_user())) {
    $vfyBack = in_array(strtolower((string)($vfyWho['role'] ?? '')), ['administrator', 'admin'], true)
        ? 'welcome.php'
        : 'index.php';
} elseif (function_exists('current_client') && current_client()) {
    $vfyBack = 'client_portal.php';
}

/** No more than $limit lookups an hour from one address, so the series cannot be read out. */
function verify_throttle(int $limit = 40): bool
{
    global $config;
    $dir = rtrim($config['storage'], '/\\') . '/throttle';
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        return true;
    }
    foreach (glob($dir . '/*.txt') ?: [] as $old) {
        if (filemtime($old) < time() - 7200) {
            @unlink($old);
        }
    }
    $file = $dir . '/' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'cli') . '.txt';
    $hits = array_filter(is_file($file) ? explode(',', (string)file_get_contents($file)) : [],
        fn($t) => (int)$t > time() - 3600);
    if (count($hits) >= $limit) {
        return false;
    }
    $hits[] = (string)time();
    @file_put_contents($file, implode(',', $hits), LOCK_EX);
    return true;
}

/** Show only the tail of the chassis number in public. */
function mask_chassis(string $chassis): string
{
    $len = strlen($chassis);
    return $len <= 4 ? e($chassis) : str_repeat('&bull;', $len - 4) . e(substr($chassis, -4));
}

$cert = null;
$searched = false;
$blocked = false;

$code   = trim((string)($_GET['c'] ?? ''));
$number = trim((string)($_GET['number'] ?? ''));
$reg    = trim((string)($_GET['reg'] ?? ''));

// A signed-in member of staff sees the real state, including drafts.
$staff = function_exists('current_user') ? current_user() : null;

// Staff may also paste an internal draft reference, e.g. DRAFT-00047.
$draftId = 0;
if ($staff && preg_match('/^draft-0*(\d+)$/i', $number, $m)) {
    $draftId = (int)$m[1];
}

// The public only ever sees approved certificates.
$onlyOk  = $staff ? '' : " AND approval_status = 'approved'";
$onlyOkC = $staff ? '' : " AND c.approval_status = 'approved'";

if ($code !== '' || $number !== '' || $reg !== '') {
    $searched = true;

    if (!verify_throttle()) {
        $blocked = true;

    } elseif ($draftId > 0) {
        $cert = q("SELECT * FROM certificates WHERE id = ?", [$draftId])->fetch() ?: null;

    } elseif ($code !== '' && strpos($code, '-') !== false) {
        list($n, $token) = explode('-', $code, 2);
        $cert = q("SELECT * FROM certificates
                    WHERE number = ? AND verify_token = ?" . $onlyOk,
            [(int)$n, $token])->fetch() ?: null;

    } elseif ($number !== '' && $reg !== '') {
        $cert = q("SELECT c.* FROM certificates c JOIN vehicles v ON v.id = c.vehicle_id
            WHERE c.number = ? AND regexp_replace(upper(v.reg_no), '[^A-Z0-9]', '', 'g') = ?" . $onlyOkC,
            [(int)$number, plate_key($reg)])->fetch() ?: null;

    } elseif ($number !== '') {
        $cert = q("SELECT * FROM certificates WHERE number = ?" . $onlyOk,
            [(int)$number])->fetch() ?: null;

    } else {
         // The certificate IN FORCE today first; only if there is none, one still to start.
         // Newest-number-first used to return a renewal that begins next year, so an
         // officer checking a properly covered vehicle was told "Not found".
         $cert = q("SELECT c.* FROM certificates c JOIN vehicles v ON v.id = c.vehicle_id
            WHERE regexp_replace(upper(v.reg_no), '[^A-Z0-9]', '', 'g') = ?
            AND c.status = 'issued'" . $onlyOkC . "
            ORDER BY (c.issue_date <= current_date) DESC, c.issue_date DESC, c.number DESC
            LIMIT 1", [plate_key($reg)])->fetch() ?: null;
    }
}

$status = '';
$d = [];
$inForceNow = null;    // for a renewal still to start: the certificate covering the vehicle today
$renewedBy  = null;    // for the certificate in force: a renewal already waiting to take over
if ($cert) {
    audit('verify', 'certificate', (int)$cert['id']);
    $d = json_decode((string)$cert['snapshot'], true) ?: [];
    $status = certificate_status($cert);
}

/**
 * The verdict, as a colour band, a headline and a sentence. Keeping this in one
 * place means the phone and the desktop can never disagree about what was decided.
 */
$tone = 'bad';
$headline = 'Not found';
$detail = 'No approved certificate matches those details. Check what you entered, or call '
        . setting('company_name') . ' on ' . setting('cell') . '.';

if ($blocked) {
    $tone = 'warn';
    $headline = 'Too many checks';
    $detail = 'This connection has made a lot of lookups in the last hour. Try again later, or call '
            . setting('company_name') . ' on ' . setting('cell') . '.';
} elseif ($cert) {
    switch ($status) {
        case 'Valid':
            $tone = 'good';
            $headline = 'Valid';
            $detail = 'This certificate is current and in force.';
            // Already renewed? Say so: it answers "what happens when this runs out".
            $renewedBy = q("SELECT * FROM certificates
                             WHERE vehicle_id = ? AND id <> ? AND approval_status = 'approved'
                               AND status = 'issued' AND issue_date > current_date
                             ORDER BY issue_date LIMIT 1",
                [(int)$cert['vehicle_id'], (int)$cert['id']])->fetch() ?: null;
            break;
        case 'Expired':
            $tone = 'warn';
            $headline = 'Expired';
            $detail = 'This certificate ran out on ' . fmt_date($cert['expiry_date'])
                    . '. The vehicle needs a renewal.';
            break;
        case 'Replaced':
            $tone = 'warn';
            $headline = 'Replaced';
            $detail = 'A newer certificate has been issued for this vehicle, so this one no longer '
                    . 'applies. Search the registration number to see the current one.';
            break;
        case 'Pending approval':
            $tone = 'warn';
            $headline = 'Not yet approved';
            $detail = 'Accounts has not confirmed payment for this certificate. '
                    . 'It has no number and must not be printed or issued.';
            break;
        case 'Rejected':
            $tone = 'bad';
            $headline = 'Rejected';
            $detail = 'Accounts rejected this certificate: '
                    . ((string)$cert['rejection_reason'] ?: 'no reason recorded') . '.';
            break;
        case 'Scheduled':
            // A renewal, paid and released, waiting for the current cover to run out.
            // Not "Not found" - it is genuine - but not in force yet either, and the
            // officer needs to know which certificate covers the vehicle TODAY.
            $tone = 'info';
            $headline = 'Starts ' . fmt_date((string)$cert['issue_date']);
            $detail = 'This is a genuine renewal, but its cover does not start until '
                    . fmt_date((string)$cert['issue_date']) . ', the day after the current certificate ends.'
                    . ' It does not cover the vehicle before then.';
            $inForceNow = certificate_current_for_plate((string)($d['vehicle']['reg_no'] ?? ''));
            break;
        default:
            $tone = 'bad';
            $headline = 'Voided';
            $detail = 'This certificate has been cancelled and is not valid.';
    }
}

layout_top('Verify a certificate', 'public');
echo entry_styles();
?>
<style>
  /* Verdict and details: verify-only, inside the shared entry card. */
  body.has-sidebar #wl { max-width: 560px; margin: 0 auto; }

  #wl .vfy-verdict { border-radius: 14px; padding: 20px 18px; margin: 0 0 6px; color: #fff; }
  #wl .vfy-good { background: linear-gradient(135deg, #3FA34D, #1E7B34); }
  #wl .vfy-warn { background: linear-gradient(135deg, #D8902A, #A86400); }
  #wl .vfy-bad  { background: linear-gradient(135deg, #D8343C, #A50F16); }
  /* Genuine but not started yet: blue, neither the green of "valid" nor the red of "not found". */
  #wl .vfy-info { background: linear-gradient(135deg, #2A86C8, #0D4F72); }

  /* The other half of a renewal, under the verdict. */
  #wl .vfy-link-note { display: flex; gap: 10px; align-items: flex-start; margin: 10px 0 0; padding: 12px 14px;
                       border-radius: 12px; font-size: 14px; line-height: 1.5; color: #1C2430; }
  #wl .vfy-link-note svg { width: 18px; height: 18px; flex: none; margin-top: 2px; }
  #wl .vfy-link-note a { display: inline-block; margin-left: 2px; font-weight: 700; color: #0D4F72;
                         text-decoration: none; border-bottom: 1.5px solid rgba(13,79,114,.3); }
  #wl .vfy-link-note a:hover { border-bottom-color: #0D4F72; }
  #wl .vfy-link-note.is-good { background: #EAF6EC; border: 1px solid #C7E6CD; } #wl .vfy-link-note.is-good svg { color: #1E7B34; }
  #wl .vfy-link-note.is-info { background: #EAF3FA; border: 1px solid #C9E0F1; } #wl .vfy-link-note.is-info svg { color: #0D4F72; }
  #wl .vfy-link-note.is-warn { background: #FFF6E5; border: 1px solid #F2DDB0; } #wl .vfy-link-note.is-warn svg { color: #A86400; }
  #wl .vfy-verdict-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
  #wl .vfy-verdict-ico { width: 34px; height: 34px; border-radius: 50%; background: rgba(255,255,255,.2);
                         display: grid; place-items: center; flex: none; }
  #wl .vfy-verdict-ico svg { width: 20px; height: 20px; }
  #wl .vfy-verdict h2::after { content: none; display: none; }
  #wl .vfy-verdict h2 { display: block; padding: 0; border: 0; background: none; margin: 0 0 6px; font-size: 28px; font-weight: 800; letter-spacing: -.5px;
                        line-height: 1.1; color: #fff; }
  #wl .vfy-verdict p { margin: 0; font-size: 14.5px; line-height: 1.5; color: rgba(255,255,255,.94); }
  #wl .vfy-plate { display: inline-block; padding: 5px 12px; background: rgba(255,255,255,.2);
                   border-radius: 8px; font-size: 16px; font-weight: 700; letter-spacing: 1px; }

  #wl .vfy-rows { margin: 0 0 18px; }
  #wl .vfy-row { display: flex; flex-wrap: wrap; gap: 2px 14px; padding: 12px 2px; border-top: 1px solid #EDF2F7; }
  #wl .vfy-row:first-child { border-top: 0; }
  #wl .vfy-key { flex: 0 0 100%; font-size: 11px; font-weight: 700; letter-spacing: .6px;
                 text-transform: uppercase; color: #8793A3; }
  #wl .vfy-val { flex: 1 1 100%; font-size: 15.5px; color: #1C2430; font-weight: 600;
                 word-break: break-word; font-variant-numeric: tabular-nums; }
  @media (min-width: 520px) {
    #wl .vfy-key { flex: 0 0 150px; padding-top: 3px; }
    #wl .vfy-val { flex: 1 1 0; }
  }

  #wl .vfy-note { margin: 16px 0 0; font-size: 12.5px; color: #8793A3; line-height: 1.6; text-align: center; }
  #wl .vfy-note strong { color: #5C6775; }
</style>

<div id="wl" class="wl-wide">
  <section class="wl-card" aria-labelledby="wl-title">
    <?php if (!$staff && !current_client()): ?><?= entry_logo() ?><?php endif; ?>

<?php if (!$searched): ?>

    <header class="wl-head">
      <p class="wl-eyebrow"><?= entry_icon('shield') ?>Verification</p>
      <h1 id="wl-title">Verify a speed governor certificate</h1>
      <p>Scan the QR code on the certificate, or enter <strong>either</strong> of the details printed on it.</p>
    </header>

    <form method="get" class="wl-form">
      <label class="wl-field">
        <span>Certificate number</span>
        <input name="number" value="<?= e($number) ?>" placeholder="e.g. 13621"
               inputmode="numeric" autocomplete="off" autofocus>
      </label>

      <p class="wl-or">or</p>

      <label class="wl-field">
        <span>Vehicle registration</span>
        <input class="is-plate" name="reg" value="<?= e($reg) ?>" placeholder="e.g. KDK 017N"
               autocapitalize="characters" autocomplete="off" spellcheck="false">
      </label>

      <button type="submit" class="wl-btn" data-busy="Checking…" data-idle="Verify certificate">
        <span class="wl-spin" aria-hidden="true"></span><span class="wl-btn-label">Verify certificate</span>
      </button>
    </form>

    <p class="wl-foot"><span>Searching by registration shows that vehicle's current certificate.</span></p>

    <?php if ($staff): ?>
      <p class="vfy-note"><strong>Staff view.</strong> You are signed in, so drafts and rejected
        certificates are shown. The public sees &ldquo;Not found&rdquo; for these.</p>
    <?php endif; ?>

<?php else: ?>

    <div class="vfy-verdict vfy-<?= e($tone) ?>" role="status">
      <div class="vfy-verdict-top">
        <?php if ($cert): ?><span class="vfy-plate"><?= e($d['vehicle']['reg_no'] ?? '') ?></span><?php else: ?><span></span><?php endif; ?>
        <span class="vfy-verdict-ico"><?= entry_icon($tone === 'good' ? 'shield' : (in_array($tone, ['warn', 'info'], true) ? 'info' : 'error')) ?></span>
      </div>
      <h2 id="wl-title"><?= e($headline) ?></h2>
      <p><?= e($detail) ?></p>
    </div>

    <?php
      // The other half of a renewal, so nobody has to work it out. Links use the
      // certificate's own code, the same as its QR, so they open without a search.
      $vfyLink = static fn(array $c): string => 'verify.php?c=' . (int)$c['number'] . '-' . rawurlencode((string)$c['verify_token']);
    ?>
    <?php if ($inForceNow): ?>
      <div class="vfy-link-note is-good">
        <?= entry_icon('shield') ?>
        <span><b>Covered today by certificate No. <?= e((string)$inForceNow['number']) ?></b>, valid until
          <?= e(fmt_date((string)$inForceNow['expiry_date'])) ?>.
          <a href="<?= e($vfyLink($inForceNow)) ?>">Check No. <?= e((string)$inForceNow['number']) ?></a></span>
      </div>
    <?php elseif ($status === 'Scheduled'): ?>
      <div class="vfy-link-note is-warn">
        <?= entry_icon('info') ?>
        <span><b>No certificate covers this vehicle today.</b> Its cover starts on
          <?= e(fmt_date((string)$cert['issue_date'])) ?>.</span>
      </div>
    <?php endif; ?>
    <?php if ($renewedBy): ?>
      <div class="vfy-link-note is-info">
        <?= entry_icon('info') ?>
        <span><b>Already renewed.</b> Certificate No. <?= e((string)$renewedBy['number']) ?> takes over on
          <?= e(fmt_date((string)$renewedBy['issue_date'])) ?> and runs to <?= e(fmt_date((string)$renewedBy['expiry_date'])) ?>.
          <a href="<?= e($vfyLink($renewedBy)) ?>">Check No. <?= e((string)$renewedBy['number']) ?></a></span>
      </div>
    <?php endif; ?>

    <?php if ($cert): ?>
      <div class="vfy-rows">
        <div class="vfy-row"><span class="vfy-key">Certificate no.</span>
          <span class="vfy-val"><?= e((string)$cert['number']) ?></span></div>

        <div class="vfy-row"><span class="vfy-key">Vehicle</span>
          <span class="vfy-val"><?= e($d['vehicle']['reg_no'] ?? '') ?><?=
            isset($d['vehicle']['make']) ? ' (' . e($d['vehicle']['make']) . ')' : '' ?></span></div>

        <div class="vfy-row"><span class="vfy-key">Chassis no.</span>
          <span class="vfy-val"><?= mask_chassis((string)($d['vehicle']['chassis_no'] ?? '')) ?></span></div>

        <div class="vfy-row"><span class="vfy-key">Governor serial no.</span>
          <span class="vfy-val"><?= e($d['device']['serial_no'] ?? '') ?></span></div>

        <div class="vfy-row"><span class="vfy-key">Set speed</span>
          <span class="vfy-val"><?= e((string)($d['device']['set_speed_kmh'] ?? '')) ?> km/h</span></div>

        <div class="vfy-row"><span class="vfy-key">Issued</span>
          <span class="vfy-val"><?= e(fmt_date($cert['issue_date'])) ?></span></div>

        <div class="vfy-row"><span class="vfy-key">Expires</span>
          <span class="vfy-val"><?= e(fmt_date($cert['expiry_date'])) ?></span></div>

        <div class="vfy-row"><span class="vfy-key">Issued by</span>
          <span class="vfy-val"><?= e($d['company']['name'] ?? setting('company_name')) ?><?php
            if (!empty($d['company']['dealer_no'])): ?><br>Dealer <?= e($d['company']['dealer_no']) ?><?php
            endif; ?></span></div>
      </div>
    <?php else: ?>
      <div style="height:12px"></div>
    <?php endif; ?>

    <a class="wl-btn is-light" href="verify.php">Check another certificate</a>

    <?php if ($cert): ?>
      <p class="vfy-note">This page is the authoritative record. A printed or forwarded copy is valid
        only if it matches what is shown here. The chassis number is shown in part only.<br>
        Checked <?= e(date('j M Y, H:i')) ?></p>
    <?php endif; ?>

<?php endif; ?>
  </section>

  <?= entry_back($vfyBack !== '' ? $vfyBack : 'welcome.php', $vfyBack !== '' ? 'Back' : 'Back to start') ?>
</div>
<?= entry_script() ?>
<?php layout_bottom(); ?>
