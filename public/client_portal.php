<?php
/** What a client sees: their vehicles and a download for each certificate. */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require APP_DIR . '/views/layout.php';

$client = require_client();
$rows = q("SELECT c.*, v.reg_no FROM certificates c JOIN vehicles v ON v.id = c.vehicle_id
           WHERE c.client_id = ? ORDER BY c.number DESC", [$client['id']])->fetchAll();
// the first number from Settings, digits only, so it can be dialled
$officePhone = preg_replace('/[^0-9+]/', '', strtok((string)setting('cell'), '/'));

// A vehicle needs renewing when it has nothing valid and nothing already waiting
// with accounts. Matched on letters and digits only, so a plate stored two ways
// counts once.
$needsRenewal = [];
$covered = [];
foreach ($rows as $row) {
    $key = plate_key((string)$row['reg_no']);
    $st  = certificate_status($row);
    if ($st === 'Valid' || $st === 'Pending approval') {
        $covered[$key] = true;
    }
}
foreach ($rows as $row) {
    $key = plate_key((string)$row['reg_no']);
    if (empty($covered[$key])) {
        $needsRenewal[$key] = true;
    }
}

layout_top('My certificates', 'client');
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
  .vfy-sub { margin: 0 0 20px; font-size: 14px; color: #7A8696; line-height: 1.5; }
</style>
<div class="vfy">
  <h1 class="vfy-title">My Certificates</h1>
  <p class="vfy-sub">Valid certificates can be downloaded. Expired, replaced and cancelled ones can be
    viewed but not downloaded &mdash; call the office to renew.</p>
    <?php require APP_DIR . '/views/client_renew_panel.php'; ?>

  <table>
    <tr><th>Vehicle</th><th>Certificate no.</th><th>Issued</th><th>Expiry</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $row): ?>
    <tr>
      <td><?= e($row['reg_no']) ?></td>
      <td>
        <?php if ($row['number'] !== null && $row['number'] !== ''): ?>
          <?= e((string)$row['number']) ?>
        <?php else: ?>
          <span class="muted">Assigned after approval</span>
        <?php endif; ?>
      </td>    
      <td><?= e(fmt_date($row['issue_date'])) ?></td>
      <td><?= e(fmt_date($row['expiry_date'])) ?></td>
      <td class="status-<?= strtolower(certificate_status($row)) ?>"><?= e(certificate_status($row)) ?></td>
      <td>
        <?php $state = certificate_status($row); ?>
        <?php if ($state === 'Valid'): ?>
          <a class="button" href="download.php?id=<?= (int)$row['id'] ?>">Download</a>
          <a class="button secondary" href="sheet.php?id=<?= (int)$row['id'] ?>" target="_blank">Preview</a>

        <?php elseif ($state === 'Pending approval'): ?>
          <span class="muted">Awaiting approval</span>

        <?php elseif ($state === 'Expired'): ?>
          <a class="button" href="tel:<?= e($officePhone) ?>">Renew</a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6">No certificates yet.</td></tr><?php endif; ?>
    </table>
      <?php if (!empty($needsRenewal[plate_key((string)$row['reg_no'])])): ?>
        <button type="button" class="button renew-open"
                data-id="<?= (int)$row['id'] ?>"
                data-reg="<?= e($row['reg_no']) ?>">Renew</button>
      <?php endif; ?>
  </div>
  <?php require APP_DIR . '/views/client_renew_modal.php'; ?>
<?php layout_bottom(); ?>