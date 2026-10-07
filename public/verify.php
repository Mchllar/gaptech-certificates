<?php
/**
 * Public verification page. This is what the QR code on the certificate opens, and what
 * an inspector or client uses to check a certificate by hand.
 *
 * A lookup needs either the certificate number or the vehicle registration (or both).
 * A registration shows that vehicle's current certificate only - retired ones are not listed.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require APP_DIR . '/views/layout.php';

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
    return $len <= 4 ? $chassis : str_repeat('•', $len - 4) . substr($chassis, -4);
}

$cert = null;
$searched = false;
$blocked = false;

$code = trim((string)($_GET['c'] ?? ''));
$number = trim((string)($_GET['number'] ?? ''));
$reg = trim((string)($_GET['reg'] ?? ''));

if ($code !== '' || $number !== '' || $reg !== '') {
    $searched = true;
    if (!verify_throttle()) {
        $blocked = true;
    } elseif ($code !== '' && strpos($code, '-') !== false) {
        // straight from the QR code: number plus its token, so any certificate can be checked
        list($n, $token) = explode('-', $code, 2);
        $cert = q('SELECT * FROM certificates WHERE number = ? AND verify_token = ?',
            [(int)$n, $token])->fetch() ?: null;
    } elseif ($number !== '' && $reg !== '') {
        $cert = q('SELECT c.* FROM certificates c JOIN vehicles v ON v.id = c.vehicle_id
                   WHERE c.number = ? AND upper(v.reg_no) = upper(?)', [(int)$number, $reg])->fetch() ?: null;
    } elseif ($number !== '') {
        $cert = q('SELECT * FROM certificates WHERE number = ?', [(int)$number])->fetch() ?: null;
    } else {
        // by registration: the vehicle's current certificate only
        $cert = q("SELECT c.* FROM certificates c JOIN vehicles v ON v.id = c.vehicle_id
                   WHERE upper(v.reg_no) = upper(?) AND c.status = 'issued'
                   ORDER BY c.number DESC LIMIT 1", [$reg])->fetch() ?: null;
    }
}

if ($cert) {
    audit('verify', 'certificate', (int)$cert['id']);
    $d = json_decode($cert['snapshot'], true);
    $status = certificate_status($cert);
}

layout_top('Verify a certificate', 'public');
?>

  <h1 style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; font-size: 1.75rem; font-weight: 700; 
  color: #0f172a; margin: 0 0 1rem 0; padding-left: 0.875rem; border-left: 4px solid #3b82f6; letter-spacing: -0.025em; line-height: 1.25;">Verify a Speed Governor Certificate</h1>

<form method="get" class="narrow">
  <p>Scan the QR code on the certificate, or enter <strong>either</strong> of the details printed on it:</p>
  <label>Certificate number <input name="number" value="<?= e($number) ?>" placeholder="e.g. 13621"></label>
  <p class="or-divider">or</p>
  <label>Vehicle registration <input name="reg" value="<?= e($reg) ?>" placeholder="e.g. KDK 017N"></label>
  <button type="submit">Verify</button>
  <p class="hint">Verifying using the registration number shows the vehicle's current certificate.</p>
</form>

<?php if ($searched): ?>
<div class="modal-backdrop" id="result"
     style="position:fixed; top:0; left:0; width:100%; height:100%; z-index:9999;
            background:rgba(12,20,40,0.55); display:flex; align-items:center;
            justify-content:center; padding:18px;">
  <div class="modal"
       style="background:#fff; border-radius:12px; padding:22px 24px; width:100%;
              max-width:470px; max-height:88vh; overflow-y:auto; position:relative;
              box-shadow:0 18px 50px rgba(10,18,40,0.32);">
    <a class="modal-close" href="verify.php" aria-label="Close">&times;</a>

    <?php if ($blocked): ?>
      <p class="verify-status status-voided">Too many lookups</p>
      <p>Please try again later, or call <?= e(setting('company_name')) ?> on <?= e(setting('cell')) ?>.</p>

    <?php elseif (!$cert): ?>
      <p class="verify-status status-voided">Not found</p>
      <p>No current certificate matches those details. Check what you entered, or contact
        <?= e(setting('company_name')) ?> on <?= e(setting('cell')) ?>.</p>

    <?php else: ?>
      <p class="verify-status status-<?= strtolower($status) ?>"><?= e($status) ?></p>
      <table class="details">
        <tr><th>Certificate no.</th><td><?= e($cert['number']) ?></td></tr>
        <tr><th>Vehicle</th><td><?= e($d['vehicle']['reg_no']) ?> (<?= e($d['vehicle']['make']) ?>)</td></tr>
        <tr><th>Chassis no.</th><td><?= e(mask_chassis($d['vehicle']['chassis_no'])) ?></td></tr>
        <tr><th>Governor Serial No</th><td><?= e($d['device']['serial_no']) ?></td></tr>
        <tr><th>Set speed</th><td><?= e($d['device']['set_speed_kmh']) ?> km/h</td></tr>
        <tr><th>Issued</th><td><?= e(fmt_date($cert['issue_date'])) ?></td></tr>
        <tr><th>Expires</th><td><?= e(fmt_date($cert['expiry_date'])) ?></td></tr>
        <tr><th>Issued by</th><td><?= e($d['company']['name']) ?>, Dealer: <?= e($d['company']['dealer_no']) ?></td></tr>
      </table>

      <?php if ($cert['status'] === 'voided'): ?>
        <p class="error">This certificate has been voided and is no longer valid.</p>
      <?php elseif ($cert['status'] === 'superseded'): ?>
        <p class="error">A newer certificate has been issued for this vehicle, so this one no longer applies.
          Search the registration number to see the current one.</p>
      <?php endif; ?>

      <p class="hint">This page is the authoritative record. A printed or forwarded copy is valid only if it
        matches what is shown here. The chassis number is shown in part only.</p>
    <?php endif; ?>

    <a class="button" href="verify.php">Check another</a>
  </div>
</div>
<?php endif; ?>

<script>
// Close the result with Escape or by clicking outside it.
(function () {
  var box = document.getElementById('result');
  if (!box) { return; }
  function close() { location.href = 'verify.php'; }
  box.addEventListener('click', function (e) { if (e.target === box) { close(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
})();
</script>
<p class="hint"><a href="welcome.php">&larr; Back</a></p>

<?php layout_bottom(); ?>