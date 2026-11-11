<?php
/**
 * public/batch_view.php
 *
 * With no id: the batches, newest first.
 * With ?id=: one batch - its vehicles, its payments, and, for accounts, the
 * form that releases it.
 *
 * Accounts can untick vehicles that were not paid for. Those stay pending and
 * the batch stays open until they are settled, so two unpaid lorries never hold
 * up the other thirty-eight.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/certificates.php';
require_once APP_DIR . '/batches.php';   // the bulk functions - harmless if
                                         // certificates.php already pulled it in
require APP_DIR . '/views/layout.php';

/**
 * Any staff member may LOOK at a batch - an administrator needs to see whether
 * theirs has been released. Only accounts may release one, which is checked
 * again further down before anything happens.
 *
 * Which "any staff" guard exists depends on your bootstrap.php, so this takes
 * whichever is there rather than assuming. require_admin() is not used: it
 * would shut accounts out of the one page they need.
 */
if (function_exists('require_staff')) {
    $user = require_staff();
} elseif (function_exists('staff_guard')) {
    $user = staff_guard([]);
} elseif (function_exists('current_user')) {
    $user = current_user();
    if (!$user) { redirect('login.php'); }
} else {
    exit('app/bootstrap.php has no require_staff(), staff_guard() or current_user(). '
       . 'Send me the top of that file and I will match it.');
}

$isAccts  = ($user['role'] ?? '') === 'accounts';
$errors   = [];
$notes    = [];

$id    = (int)($_GET['id'] ?? 0);
$batch = $id ? batch_get($id) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $batchId = (int)($_POST['id'] ?? 0);
    $action  = (string)($_POST['action'] ?? '');

    if (!$isAccts) {
        $errors[] = 'Only accounts can release or reject a batch.';
    } else {
        try {
            if ($action === 'approve') {
                $only = isset($_POST['cert']) ? array_map('intval', (array)$_POST['cert']) : null;
                $done = batch_approve($batchId, $user,
                            (string)($_POST['payment_ref'] ?? ''),
                            trim((string)($_POST['payment_note'] ?? '')),
                            trim((string)($_POST['lpo_no'] ?? '')),
                            $only);
                $notes[] = $done['approved'] . ' certificate'
                    . ($done['approved'] === 1 ? '' : 's') . ' released and numbered.'
                    . ($done['still_pending']
                        ? ' ' . $done['still_pending'] . ' still waiting - the batch stays open.'
                        : ' The batch is complete.');
            } elseif ($action === 'reject') {
                $n = batch_reject($batchId, $user, (string)($_POST['reason'] ?? ''));
                $notes[] = $n . ' certificate' . ($n === 1 ? '' : 's') . ' rejected.';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
    $batch = batch_get($batchId);
    $id    = $batchId;
}

if (!empty($_GET['created'])) {
    $notes[] = 'Batch created. Every certificate is waiting for accounts - none has a number yet.';
}

layout_top($batch ? 'Batch ' . $batch['id'] : 'Batches');
layout_back($batch ? 'batch_view.php' : 'index.php',
            $batch ? 'All batches' : 'Back to certificates');

foreach ($errors as $m) { echo '<p class="error">' . e($m) . '</p>'; }
foreach ($notes  as $m) { echo '<p class="hint">'  . e($m) . '</p>'; }


// ---------------------------------------------------------------- the list
if (!$batch):
    $all = batch_list();
?>
<h1>Batches</h1>
<p class="hint">A fleet coming on board or renewing, raised in one go and paid for once.</p>

<p><a class="button" href="batch_new.php">Upload a fleet</a></p>

<?php if (!$all): ?>
  <p class="hint">Nothing here yet.</p>
<?php else: ?>
  <table class="list">
    <thead><tr><th>#</th><th>Customer</th><th>Vehicles</th><th>State</th>
               <th>Payment</th><th>Raised</th></tr></thead>
    <tbody>
    <?php foreach ($all as $b): ?>
      <tr>
        <td><a href="batch_view.php?id=<?= (int)$b['id'] ?>"><?= (int)$b['id'] ?></a></td>
        <td><?= e($b['client_name']) ?><br><span class="field-hint"><?= e($b['label']) ?></span></td>
        <td><?= (int)$b['vehicles'] ?></td>
        <td>
          <?php if ($b['approval_status'] === 'rejected'): ?>
            <span class="status status-rejected">Rejected</span>
          <?php elseif ((int)$b['pending'] === 0): ?>
            <span class="status status-approved">Approved</span>
          <?php else: ?>
            <span class="status status-pending"><?= (int)$b['pending'] ?> waiting</span>
          <?php endif; ?>
        </td>
        <td><?= e((string)($b['payment_refs'] ?? '')) ?></td>
        <td><?= e(fmt_date((string)$b['created_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php
// ---------------------------------------------------------------- one batch
else:
    $waiting  = array_values(array_filter($batch['certificates'],
                   fn($c) => $c['approval_status'] === 'pending'));
    $released = array_values(array_filter($batch['certificates'],
                   fn($c) => $c['approval_status'] === 'approved'));
?>
<h1><?= e($batch['client_name']) ?>
    <span class="chip-count"><?= count($batch['certificates']) ?></span></h1>
<p class="hint">Batch <?= (int)$batch['id'] ?><?= $batch['label'] ? ' &middot; ' . e($batch['label']) : '' ?>
   &middot; raised by <?= e((string)$batch['created_by_name']) ?>
   on <?= e(fmt_date((string)$batch['created_at'])) ?></p>

<?php if ($batch['approval_status'] === 'rejected'): ?>
  <p class="error">Rejected: <?= e((string)$batch['rejection_reason']) ?></p>
<?php endif; ?>

<table class="list">
  <thead><tr><?= $isAccts && $waiting ? '<th></th>' : '' ?>
             <th>Registration</th><th>Governor</th><th>Type</th>
             <th>Number</th><th>State</th><th>Expires</th></tr></thead>
  <tbody>
  <?php foreach ($batch['certificates'] as $c):
      $pending = $c['approval_status'] === 'pending'; ?>
    <tr>
      <?php if ($isAccts && $waiting): ?>
        <td><?php if ($pending): ?>
          <input type="checkbox" form="release" name="cert[]" value="<?= (int)$c['id'] ?>" checked
                 style="width:16px; flex:0 0 16px; margin:0">
        <?php endif; ?></td>
      <?php endif; ?>
      <td><strong><?= e((string)$c['reg_no']) ?></strong></td>
      <td><?= e((string)$c['serial_no']) ?></td>
      <td><?= e((string)$c['type']) ?></td>
      <td><?= $c['number'] !== null ? e((string)$c['number']) : '<span class="field-hint">on approval</span>' ?></td>
      <td><span class="status status-<?= e($c['approval_status']) ?>">
            <?= e(ucfirst($c['approval_status'])) ?></span></td>
      <td><?= e(fmt_date((string)$c['expiry_date'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php if ($batch['payments']): ?>
  <h2 style="margin-top:28px">Payments</h2>
  <table class="list">
    <thead><tr><th>Reference</th><th>LPO</th><th>Certificates</th><th>Taken by</th><th>When</th></tr></thead>
    <tbody>
    <?php foreach ($batch['payments'] as $p): ?>
      <tr>
        <td><?= e($p['payment_ref'] ?: '-') ?><?php if ($p['note']): ?>
            <br><span class="field-hint"><?= e($p['note']) ?></span><?php endif; ?></td>
        <td><?= e($p['lpo_no'] ?: '-') ?></td>
        <td><?= (int)$p['certificates'] ?></td>
        <td><?= e((string)$p['received_by_name']) ?></td>
        <td><?= e(fmt_date((string)$p['received_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php if ($isAccts && $waiting): ?>
  <h2 style="margin-top:28px">Release <?= count($waiting) ?>
      certificate<?= count($waiting) === 1 ? '' : 's' ?></h2>
  <p class="hint">Untick any vehicle that was not paid for. Those stay waiting and the
     batch stays open, so the rest are not held up. Numbers are allocated now, in
     registration order.</p>

  <form method="post" id="release" class="card-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$batch['id'] ?>">

    <fieldset>
      <legend>The payment</legend>
      <label class="field">Payment reference
        <span class="field-row">
          <input name="payment_ref" maxlength="40" value="<?= e($_POST['payment_ref'] ?? '') ?>">
        </span>
        <span class="field-hint">One reference for everything ticked above. It cannot be
          used again on another batch or certificate.</span>
      </label>

      <label class="field">or LPO number
        <span class="field-row">
          <input name="lpo_no" maxlength="60" value="<?= e($_POST['lpo_no'] ?? '') ?>">
        </span>
        <span class="field-hint">When the client is paying later. An LPO may cover more
          than one batch, so it is not checked for reuse.</span>
      </label>

      <label class="field">Note
        <span class="field-row">
          <input name="payment_note" maxlength="160" value="<?= e($_POST['payment_note'] ?? '') ?>"
                 placeholder="e.g. Invoice INV-4471">
        </span>
      </label>

      <button class="button" type="submit" name="action" value="approve">Release them</button>
    </fieldset>
  </form>

  <form method="post" class="card-form" style="margin-top:16px"
        onsubmit="return confirm('Reject every waiting certificate in this batch?')">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$batch['id'] ?>">
    <fieldset>
      <legend>Or send it back</legend>
      <label class="field">Reason <span class="req">*</span>
        <span class="field-row"><input name="reason" required maxlength="200"
               placeholder="What has to be fixed before this can be released"></span>
        <span class="field-hint">Goes to whoever raised it. Nothing was superseded, so no
          lorry loses the certificate it already has.</span>
      </label>
      <button class="button secondary" type="submit" name="action" value="reject">Reject the batch</button>
    </fieldset>
  </form>
<?php elseif ($waiting): ?>
  <p class="hint"><?= count($waiting) ?> certificate<?= count($waiting) === 1 ? ' is' : 's are' ?>
     waiting for accounts. They take their numbers then.</p>
<?php endif; ?>

<?php if ($released): ?>
  <p class="hint" style="margin-top:20px"><?= count($released) ?> released.
     Open any of them from the register to print it; printing the whole batch in
     one go is the next piece.</p>
<?php endif; ?>

<?php endif; ?>
<?php layout_bottom(); ?>