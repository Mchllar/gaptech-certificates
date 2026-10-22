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

$vfyBack = '';
if (function_exists('current_user') && ($vfyWho = current_user())) {
    $vfyBack = in_array(strtolower((string)($vfyWho['role'] ?? '')), ['administrator', 'admin'], true)
        ? 'welcome.php'
        : 'payments.php';
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
         $cert = q("SELECT c.* FROM certificates c JOIN vehicles v ON v.id = c.vehicle_id
            WHERE regexp_replace(upper(v.reg_no), '[^A-Z0-9]', '', 'g') = ?
            AND c.status = 'issued'" . $onlyOkC . "
            ORDER BY c.number DESC LIMIT 1", [plate_key($reg)])->fetch() ?: null;
    }
}

$status = '';
$d = [];
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
          break;
        default:
            $tone = 'bad';
            $headline = 'Voided';
            $detail = 'This certificate has been cancelled and is not valid.';
    }
}

layout_top('Verify a SG. Certificate', 'public');
?>
<style>
  /* -------- verification page, phone first -------------------------------- */
  .vfy { max-width: 560px; margin: 0 auto; padding: 0 4px 40px; }
  .vfy * { box-sizing: border-box; }

  .vfy-title { margin: 0 0 6px; font-size: 22px; font-weight: 700;
              letter-spacing: -.4px; color: #1C2430; line-height: 1.25; }
  .vfy-sub { margin: 0 0 20px; font-size: 14px; color: #7A8696; line-height: 1.5; }

  /* ---- the verdict ---- */
  .vfy-verdict { border-radius: 16px; padding: 22px 20px; margin: 0 0 16px; color: #fff;
                box-shadow: 0 6px 20px rgba(13,79,114,.14); }
  .vfy-good { background: linear-gradient(135deg, #3FA34D, #1E7B34); }
  .vfy-warn { background: linear-gradient(135deg, #D8902A, #A86400); }
  .vfy-bad  { background: linear-gradient(135deg, #D8343C, #A50F16); }

  .vfy-verdict h2 { margin: 0 0 6px; font-size: 30px; font-weight: 800;
                    letter-spacing: -.5px; line-height: 1.1; color: #fff; }
  .vfy-verdict p { margin: 0; font-size: 14.5px; line-height: 1.5; color: rgba(255,255,255,.94); }
  .vfy-plate { display: inline-block; margin: 0 0 10px; padding: 5px 12px;
              background: rgba(255,255,255,.18); border-radius: 8px;
              font-size: 17px; font-weight: 700; letter-spacing: 1px; }

  /* ---- the details ---- */
  .vfy-card { background: #fff; border: 1px solid #E4EBF2; border-radius: 16px;
              padding: 6px 18px; margin: 0 0 16px;
              box-shadow: 0 1px 2px rgba(13,79,114,.04), 0 6px 20px rgba(13,79,114,.06); }
  .vfy-row { display: flex; flex-wrap: wrap; gap: 2px 14px;
            padding: 13px 0; border-top: 1px solid #EDF2F7; }
  .vfy-row:first-child { border-top: 0; }
  .vfy-key { flex: 0 0 100%; font-size: 10.5px; font-weight: 700; letter-spacing: .7px;
            text-transform: uppercase; color: #8793A3; }
  .vfy-val { flex: 1 1 100%; font-size: 16px; color: #1C2430; font-weight: 600;
            word-break: break-word; font-variant-numeric: tabular-nums; }

  /* ---- the form ---- */
  .vfy-form { background: #fff; border: 1px solid #E4EBF2; border-radius: 16px;
              padding: 20px 18px; margin: 0 0 16px;
              box-shadow: 0 1px 2px rgba(13,79,114,.04), 0 6px 20px rgba(13,79,114,.06); }
  .vfy-form label { display: block; margin: 0 0 14px; font-size: 10.5px; font-weight: 700;
                    letter-spacing: .7px; text-transform: uppercase; color: #8793A3; }
  .vfy-form input { display: block; width: 100%; margin-top: 7px; padding: 14px 15px;
                    border: 1px solid #D9E2EC; border-radius: 11px; background: #F8FBFD;
                    font: inherit; font-size: 17px; font-weight: 600; color: #1C2430;
                    text-transform: uppercase; letter-spacing: .5px; }
  .vfy-form input::placeholder { font-weight: 400; text-transform: none;
                                letter-spacing: 0; color: #A8B4C2; }
  .vfy-form input:focus { outline: none; background: #fff; border-color: #1890D0;
                          box-shadow: 0 0 0 3px rgba(24,144,208,.16); }

  .vfy-or { display: flex; align-items: center; gap: 12px; margin: 4px 0 14px;
            font-size: 11px; font-weight: 700; letter-spacing: 1px;
            text-transform: uppercase; color: #A8B4C2; }
  .vfy-or::before, .vfy-or::after { content: ""; flex: 1 1 auto; height: 1px; background: #E4EBF2; }

  .vfy-btn { display: block; width: 100%; padding: 15px; border: 0; border-radius: 11px;
            background: #0D4F72; color: #fff; font: inherit; font-size: 16px; font-weight: 700;
            text-align: center; text-decoration: none; cursor: pointer; }
  .vfy-btn:hover { background: #0A4260; }
  .vfy-btn-light { background: #fff; color: #0D4F72; border: 1px solid #D9E2EC; }
  .vfy-btn-light:hover { background: #F4F9FD; }

  .vfy-note { margin: 16px 2px 0; font-size: 12.5px; color: #8793A3; line-height: 1.6; }
  .vfy-stamp { margin: 10px 2px 0; font-size: 12px; color: #A8B4C2; }
  .vfy-back { display: inline-block; margin: 22px 2px 0; font-size: 14px;
              color: #0D4F72; text-decoration: none; }
  .vfy-back:hover { text-decoration: underline; }

  /* ---- a little wider once there is room ---- */
  .vfy h1.vfy-title {
    margin: 0 0 6px;
    font-size: 22px; font-weight: 700; letter-spacing: -.4px;
    line-height: 1.25; color: #0D4F72;
    padding-left: 12px; border-left: 4px solid #8CC63F;
  }
  @media (min-width: 520px) { .vfy h1.vfy-title { font-size: 26px; } }
</style>

<div class="vfy">

<?php if (!$searched): ?>

  <h1 class="vfy-title" >Verify a Speed Governor Certificate</h1>
  <p class="vfy-sub">Scan the QR code on the certificate, or enter <strong>either</strong> of the
    details printed on it.</p>

  <form method="get" class="vfy-form">
    <label>Certificate number
      <input name="number" value="<?= e($number) ?>" placeholder="e.g. 13621"
             inputmode="numeric" autocomplete="off">
    </label>

    <p class="vfy-or">or</p>

    <label>Vehicle registration
      <input name="reg" value="<?= e($reg) ?>" placeholder="e.g. KDK 017N"
             autocapitalize="characters" autocomplete="off">
    </label>

    <button type="submit" class="vfy-btn">Verify</button>
  </form>

  <p class="vfy-note">Searching by registration shows that vehicle's current certificate.</p>

  <?php if ($staff): ?>
    <p class="vfy-note"><strong>Staff view.</strong> You are signed in, so drafts and
      rejected certificates are shown. The public sees &ldquo;Not found&rdquo; for these.</p>
  <?php endif; ?>

<?php else: ?>

  <div class="vfy-verdict vfy-<?= e($tone) ?>">
    <?php if ($cert): ?>
      <span class="vfy-plate"><?= e($d['vehicle']['reg_no'] ?? '') ?></span>
    <?php endif; ?>
    <h2><?= e($headline) ?></h2>
    <p><?= e($detail) ?></p>
  </div>

  <?php if ($cert): ?>
    <div class="vfy-card">
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
  <?php endif; ?>

  <a class="vfy-btn vfy-btn-light" href="verify.php">Check another</a>

  <?php if ($cert): ?>
    <p class="vfy-note">This page is the authoritative record. A printed or forwarded copy is valid
      only if it matches what is shown here. The chassis number is shown in part only.</p>
    <p class="vfy-stamp">Checked <?= e(date('j M Y, H:i')) ?></p>
  <?php endif; ?>

<?php endif; ?>
<?php if ($vfyBack !== ''): ?>
  <a class="vfy-back" href="<?= e($vfyBack) ?>">&larr; Back</a>
<?php endif; ?>
</div>
<?php layout_bottom(); ?>