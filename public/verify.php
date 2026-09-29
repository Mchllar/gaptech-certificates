<?php
/**
 * Public verification page. This is what the QR code on the certificate opens, and what
 * an inspector or client uses to check a certificate by hand.
 *
 * A lookup needs either the certificate number or the vehicle registration (or both).
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require APP_DIR . '/views/layout.php';

/**
 * Crude but effective throttle: no more than $limit lookups from one address per hour.
 * Stops anyone reading out the whole certificate series by trying numbers in order.
 */
function verify_throttle(int $limit = 40): bool
{
    global $config;
    $dir = rtrim($config['storage'], '/\\') . '/throttle';
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        return true;                                   // cannot throttle; let it through
    }
    foreach (glob($dir . '/*.txt') ?: [] as $old) {     // tidy up old files
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
$others = [];

$code = trim((string)($_GET['c'] ?? ''));
$number = trim((string)($_GET['number'] ?? ''));
$reg = trim((string)($_GET['reg'] ?? ''));

if ($code !== '' || $number !== '' || $reg !== '') {
    $searched = true;
    if (!verify_throttle()) {
        $blocked = true;
    } elseif ($code !== '' && strpos($code, '-') !== false) {
        // straight from the QR code: number plus its token
        list($n, $token) = explode('-', $code, 2);
        $cert = q('SELECT * FROM certificates WHERE number = ? AND verify_token = ?',
            [(int)$n, $token])->fetch() ?: null;
    } elseif ($number !== '' && $reg !== '') {
        $cert = q('SELECT c.* FROM certificates c JOIN vehicles v ON v.id = c.vehicle_id
                   WHERE c.number = ? AND upper(v.reg_no) = upper(?)', [(int)$number, $reg])->fetch() ?: null;
    } elseif ($number !== '') {
        $cert = q('SELECT * FROM certificates WHERE number = ?', [(int)$number])->fetch() ?: null;
    } else {
        // by registration: the most recent certificate for that vehicle
        $cert = q('SELECT c.* FROM certificates c JOIN vehicles v ON v.id = c.vehicle_id
                   WHERE upper(v.reg_no) = upper(?) ORDER BY c.number DESC LIMIT 1', [$reg])->fetch() ?: null;
        if ($cert) {
            $others = q('SELECT number, issue_date, expiry_date, status FROM certificates
                         WHERE vehicle_id = ? AND id <> ? ORDER BY number DESC LIMIT 5',
                [$cert['vehicle_id'], $cert['id']])->fetchAll();
        }
    }
}

if ($cert) {
    audit('verify', 'certificate', (int)$cert['id']);
    $d = json_decode($cert['snapshot'], true);
    $status = certificate_status($cert);
}

layout_top('Verify a certificate', 'public');
?>
<h1>Verify Speed Governor Certificate</h1>

<?php if ($blocked): ?>
  <p class="error">Too many lookups from this connection. Please try again later, or call
    <?= e(setting('company_name')) ?> on <?= e(setting('cell')) ?>.</p>
<?php elseif ($cert): ?>
  <div class="verify-result status-box-<?= strtolower($status) ?>">
    <p class="verify-status"><?= e($status) ?></p>
    <table class="details">
      <tr><th>Certificate no.</th><td><?= e($cert['number']) ?></td></tr>
      <tr><th>Vehicle</th><td><?= e($d['vehicle']['reg_no']) ?> (<?= e($d['vehicle']['make']) ?>)</td></tr>
      <tr><th>Chassis no.</th><td><?= e(mask_chassis($d['vehicle']['chassis_no'])) ?></td></tr>
      <tr><th>Governor</th><td><?= e($d['device']['model']) ?></td></tr>
      <tr><th>Set speed</th><td><?= e($d['device']['set_speed_kmh']) ?> km/h</td></tr>
      <tr><th>Issued</th><td><?= e(fmt_date($cert['issue_date'])) ?></td></tr>
      <tr><th>Expires</th><td><?= e(fmt_date($cert['expiry_date'])) ?></td></tr>
      <tr><th>Issued by</th><td><?= e($d['company']['name']) ?>, Dealer <?= e($d['company']['dealer_no']) ?></td></tr>
    </table>
    <?php if ($cert['status'] === 'voided'): ?>
      <p class="error">This certificate has been voided and is no longer valid.</p>
    <?php endif; ?>
    <?php if ($others): ?>
      <p class="hint">Earlier certificates for this vehicle:
        <?php foreach ($others as $i => $o): ?>
          <?= $i ? ', ' : '' ?>no. <?= e($o['number']) ?> (<?= e(fmt_date($o['expiry_date'])) ?>)
        <?php endforeach; ?>
      </p>
    <?php endif; ?>
    <p class="hint">This page is the authoritative record. A printed or forwarded copy is valid only if it
      matches what is shown here. The chassis number is shown in part only; the full number is on the
      certificate itself.</p>
  </div>
<?php elseif ($searched): ?>
  <p class="error">No certificate matches those details. Check what you entered, or contact
    <?= e(setting('company_name')) ?> on <?= e(setting('cell')) ?>.</p>
<?php endif; ?>

<form method="get" class="narrow">
  <p>Scan the QR code on the certificate, or enter <strong>EITHER</strong> of the details printed on it:</p>
  <label>Certificate number <input name="number" value="<?= e($number) ?>" placeholder="e.g. 13621"></label>
  <p class="or-divider">or</p>
  <label>Vehicle registration <input name="reg" value="<?= e($reg) ?>" placeholder="e.g. KDK 017N"></label>
  <button type="submit">Verify</button>
  <p class="hint">Searching by registration shows that vehicle's most recent certificate.
    Entering both narrows it to one exact certificate.</p>
</form>
<?php layout_bottom(); ?>
