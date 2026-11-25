<?php
/**
 * One tracking installation certificate.
 *
 * Goes in public/tracking_view.php
 *
 * Both roles open this page and each sees what belongs to them:
 *   accounts        - the payment, and approve or reject
 *   administrators  - print, download, renew, void
 *
 * There is no separate approvals page for this module. Accounts approve here, with
 * the details they are approving in front of them.
 */
require __DIR__ . '/../app/bootstrap.php';
require APP_DIR . '/tracking.php';
require_once APP_DIR . '/tracking_batches.php';   // a certificate raised as part of a fleet
require APP_DIR . '/views/layout.php';

$user  = require_staff();
$isAdm = $user['role'] === 'administrator';
$isAcc = $user['role'] === 'accounts';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $target = q('SELECT * FROM tracking_certificates WHERE id = ?', [$id])->fetch();
    $action = (string)($_POST['action'] ?? '');

    if (!$target) {
        $errors[] = 'Certificate not found.';
    } else {
        try {
            // Every action checks the role here, on the server. The buttons below are
            // hidden from the wrong role as a courtesy, not as the control.
            if (in_array($action, ['approve', 'approve_batch', 'reject', 'update_ref'], true)) {
                if (!$isAcc) {
                    throw new RuntimeException('Approving and recording payments is for accounts.');
                }
            } elseif (in_array($action, ['void', 'renew', 'remove_unit', 'fleet_edit'], true)) {
                if (!$isAdm) {
                    throw new RuntimeException('That action is for administrators.');
                }
            }

            if ($action === 'approve') {
                $done = approve_tracking_cert($target, $user,
                    (string)($_POST['payment_ref'] ?? ''),
                    trim((string)($_POST['payment_note'] ?? '')));
                flash('Approved. Certificate ' . tracking_label($done)
                    . ' can now be printed and downloaded.');
                redirect('tracking_view.php?id=' . $id);

            } elseif ($action === 'approve_batch') {
                // The same release, for every vehicle ticked in the fleet this certificate
                // came in with, on one payment. Anything left unticked stays waiting.
                if (empty($target['batch_id'])) {
                    throw new RuntimeException('This certificate was not raised as part of a fleet.');
                }
                $only = array_map('intval', (array)($_POST['cert'] ?? []));
                if (!$only) {
                    throw new RuntimeException('Tick at least one vehicle to release.');
                }
                $done = trk_batch_approve((int)$target['batch_id'], $user,
                    (string)($_POST['payment_ref'] ?? ''),
                    trim((string)($_POST['payment_note'] ?? '')), $only);
                flash($done['approved'] . ' certificate' . ($done['approved'] === 1 ? '' : 's')
                    . ' approved and numbered.'
                    . ($done['still_pending']
                        ? ' ' . $done['still_pending'] . ' still waiting - the fleet stays open.'
                        : ' The whole fleet is done.'));
                redirect('tracking_view.php?id=' . $id);

            } elseif ($action === 'reject') {
                reject_tracking_cert($target, $user, (string)($_POST['reason'] ?? ''));
                flash('Rejected and sent back to the administrator.');
                redirect('tracking_view.php?id=' . $id);

            } elseif ($action === 'update_ref') {
                // Accounts own the payment record, so they may correct a reference or
                // re-paste a message after approval. It is written to the audit trail.
                $note = trim((string)($_POST['payment_note'] ?? ''));
                $ref  = strtoupper(trim((string)($_POST['payment_ref'] ?? '')));
                if ($ref === '') {
                    throw new RuntimeException('The reference cannot be blank.');
                }
                $clash = q('SELECT id FROM tracking_certificates
                             WHERE upper(payment_ref) = ? AND id <> ?', [$ref, $id])->fetch();
                if ($clash) {
                    throw new RuntimeException('That reference is already on another certificate.');
                }
                q('UPDATE tracking_certificates SET payment_ref = ?, payment_note = ? WHERE id = ?',
                    [$ref, $note, $id]);
                audit('edit_tracking_payment', 'tracking_certificate', $id, [
                    'was' => $target['payment_ref'], 'now' => $ref,
                ]);
                flash('Payment details updated.');
                redirect('tracking_view.php?id=' . $id);

            } elseif ($action === 'fleet_edit') {
                // The ticked details, set to the same value on every vehicle in this fleet.
                if (empty($target['batch_id'])) {
                    throw new RuntimeException('This certificate was not raised as part of a fleet.');
                }
                $set = array_intersect_key((array)($_POST['set'] ?? []), (array)($_POST['apply'] ?? []));
                $done = trk_amend_fleet((int)$target['batch_id'], $set, $user, (string)($_POST['reason'] ?? ''));
                flash(implode(', ', array_map(fn($k) => TRK_EDITABLE[$k], array_keys($set))) . ' changed on '
                    . $done['changed'] . ' vehicle' . ($done['changed'] === 1 ? '' : 's') . '.'
                    . ($done['unchanged'] ? ' ' . $done['unchanged'] . ' already had those details.' : '')
                    . ($done['skipped'] ? ' ' . $done['skipped'] . ' voided or rejected left as they are.' : '')
                    . ' The reason is kept with each certificate.');
                redirect('tracking_view.php?id=' . $id);

            } elseif ($action === 'void') {
                void_tracking_cert($target, $user, (string)($_POST['reason'] ?? ''));
                flash('Certificate ' . tracking_label($target) . ' is now void.');
                redirect('tracking_view.php?id=' . $id);

            } elseif ($action === 'remove_unit') {
                $done = remove_tracking_unit($target, $user,
                    (string)($_POST['reason'] ?? ''),
                    (string)($_POST['removed_on'] ?? ''));
                flash('Unit ' . $target['imei'] . ' recorded as removed from ' . $target['reg_no']
                    . ' on ' . fmt_date((string)$done['removed_on'])
                    . '. The vehicle is no longer covered, and the unit can now be fitted'
                    . ' to another vehicle.');
                redirect('tracking_view.php?id=' . $id);

            } elseif ($action === 'renew') {
                $new = renew_tracking_cert($target, $user,
                    trim((string)($_POST['payment_note'] ?? '')),
                    (int)($_POST['term_months'] ?? 12));
                flash('Renewal created for ' . $new['reg_no'] . ', expiring '
                    . fmt_date($new['expiry_date']) . '. It is with accounts for approval.');
                redirect('tracking_view.php?id=' . $new['id']);

            } else {
                $errors[] = 'Nothing to do.';
            }
        } catch (Throwable $err) {
            $errors[] = $err->getMessage();
        }
    }
}

$cert = q("SELECT t.*, iss.name AS issued_by_name, dec.name AS decided_by_name,
                  old.number AS renewed_from_number, old.id AS renewed_from_id
           FROM tracking_certificates t
           LEFT JOIN users iss ON iss.id = t.issued_by
           LEFT JOIN users dec ON dec.id = t.approved_by
           LEFT JOIN tracking_certificates old ON old.id = t.renewed_from
           WHERE t.id = ?", [$id])->fetch();

if (!$cert) {
    layout_top('Installation certificate');
    echo '<h1>Not found</h1><p class="error">There is no installation certificate with that '
       . 'reference. It may have been opened from an old link.</p>'
       . '<p><a class="button" href="tracking_list.php">Back to installations</a></p>';
    layout_bottom();
    exit;
}

$d        = json_decode((string)$cert['snapshot'], true) ?: [];
$status   = tracking_status($cert);
$pending  = $cert['approval_status'] === 'pending';
$rejected = $cert['approval_status'] === 'rejected';
$approved = $cert['approval_status'] === 'approved';
$live     = tracking_is_releasable($cert);

// A certificate that is not the current one: offer the one that is, rather than
// leaving someone to go and look for it.
$replacement = null;
if ($approved && !$live) {
    $cur = tracking_current_for_plate((string)$cert['reg_no']);
    if ($cur && (int)$cur['id'] !== (int)$cert['id']) {
        $replacement = $cur;
    }
}

// The fleet this certificate was raised with, if any. A fleet of one is not worth
// mentioning, so a single certificate looks exactly as it always did.
$batch    = !empty($cert['batch_id']) ? trk_batch_get((int)$cert['batch_id']) : null;
$siblings = $batch ? $batch['certificates'] : [];
$waiting  = array_values(array_filter($siblings, fn($c) => $c['approval_status'] === 'pending'));
if ($batch && count($siblings) < 2) {
    $batch = null;
    $siblings = $waiting = [];
}

layout_top('');
layout_back('tracking_list.php', 'Back to Tracking Installations');
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
  .vfy-sub { font-size: 14px; color: #7A8696; line-height: 1.5; text-align: justified; }

</style>
<div class="vfy">
<h1 class="vfy-title"><?= e(tracking_label($cert)) ?>
  <span class="status-pill status-<?= e(strtolower(str_replace(' ', '-', $status))) ?>">
    <?= e($status) ?></span></h1>
<p class="vfy-sub">Tracking installation certificate for
  <?= e($cert['reg_no']) ?> &mdash; <?= e($cert['client_name']) ?>.</p></div>

<?php foreach ($errors as $msg): ?><p class="error"><?= e($msg) ?></p><?php endforeach; ?>

<?php if ($pending): ?>
  <p class="hint" style="text-align:left; color:#8A5A00">
    This certificate has no serial number yet and cannot be printed or downloaded.
    It takes its number when accounts confirm the payment.</p>
<?php endif; ?>

<?php if ($batch):
  // The fleet: 20 vehicles shown, the rest one click away. Clicking a vehicle shows its
  // details in a pop-up; administrators can also change shared details for all of them.
  $fleetLimit    = 20;
  $fleetEditable = array_values(array_filter($siblings, fn($s) =>
      !in_array((string)$s['status'], ['voided', 'superseded'], true) && $s['approval_status'] !== 'rejected'));
  $popup = [];
  foreach ($siblings as $s) {
      $popup[(int)$s['id']] = [
          'reg' => (string)$s['reg_no'], 'serial' => $s['number'] ? str_pad((string)$s['number'], 5, '0', STR_PAD_LEFT) : '',
          'state' => ucfirst((string)$s['approval_status']), 'make' => (string)$s['make_model'],
          'chassis' => (string)$s['chassis_no'], 'engine' => (string)$s['engine_no'], 'colour' => (string)$s['colour'],
          'imei' => (string)$s['imei'], 'civ' => (string)$s['civilian_plate'], 'sim' => (string)$s['sim_card'],
          'owner' => (string)$s['ownership'], 'contact' => (string)$s['client_phone'],
          'fin' => (string)$s['financier'] !== '' ? (string)$s['financier'] : 'N/A',
          'installed' => fmt_date((string)$s['installed_on']), 'sub' => fmt_date((string)$s['subscription_date']),
          'exp' => fmt_date((string)$s['expiry_date']),
          'editable' => !in_array((string)$s['status'], ['voided', 'superseded'], true) && $s['approval_status'] !== 'rejected',
      ];
  }
  // For the "edit all" form: a detail's value if every vehicle shares it, otherwise blank.
  $common = [];
  foreach (TRK_FLEET_EDITABLE as $k) {
      $vals = array_unique(array_map(fn($s) => (string)$s[$k], $fleetEditable));
      $common[$k] = count($vals) === 1 ? (string)reset($vals) : null;
  }
?>
  <details class="trk-fleet"<?= $pending ? ' open' : '' ?>>
    <summary><?= count($siblings) ?> vehicles raised together for <?= e((string)$batch['client_name']) ?>
      <?= $batch['label'] ? ' &middot; ' . e((string)$batch['label']) : '' ?>
      &mdash; <?= $waiting ? count($waiting) . ' still waiting' : 'all approved' ?></summary>

    <div class="trk-fleet-tools">
      <span class="field-hint">Click a vehicle to see its details.</span>
      <?php if ($isAdm && $fleetEditable): ?>
        <button type="button" class="button secondary" data-modal="#m-fleet-edit">
          Edit details for all <?= count($fleetEditable) ?> vehicles</button>
      <?php endif; ?>
    </div>

    <div class="trk-fleet-wrap">
      <table class="trk-fleet-table">
        <thead><tr><th>Vehicle</th><th>Track IMEI</th><th>Serial</th><th>Status</th><th>Expires</th></tr></thead>
        <tbody>
        <?php foreach ($siblings as $i => $sib): $me = (int)$sib['id'] === (int)$cert['id']; ?>
          <tr class="trk-fleet-row<?= $me ? ' is-me' : '' ?>"
              data-id="<?= (int)$sib['id'] ?>" tabindex="0" role="button"
              aria-label="Show details for <?= e((string)$sib['reg_no']) ?>">
            <td><strong><?= e((string)$sib['reg_no']) ?></strong><?= $me ? ' <span class="field-hint">(this one)</span>' : '' ?></td>
            <td><?= e((string)$sib['imei']) ?></td>
            <td><?= $sib['number'] ? 'No. ' . e(str_pad((string)$sib['number'], 5, '0', STR_PAD_LEFT))
                                   : '<span class="field-hint">on approval</span>' ?></td>
            <td><?= e(ucfirst((string)$sib['approval_status'])) ?></td>
            <td><?= e(fmt_date((string)$sib['expiry_date'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php /* The same pager as the rest of the system. Every vehicle is already on the page,
             so it turns pages instantly, without reloading. */ ?>
    <div class="pager trk-fleet-pager" id="fleetPager">
      <form class="per-page" onsubmit="return false">
        <label>Rows per page
          <select id="fleetPer">
            <?php foreach ([10, 20, 50, 100] as $pp): ?>
              <option value="<?= $pp ?>" <?= $pp === $fleetLimit ? 'selected' : '' ?>><?= $pp ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </form>
      <span class="pager-info" id="fleetInfo"></span>
      <span class="pager-buttons">
        <button type="button" class="button secondary" data-go="first">&laquo; First</button>
        <button type="button" class="button secondary" data-go="prev">Previous</button>
        <span class="pager-page" id="fleetPage"></span>
        <button type="button" class="button secondary" data-go="next">Next</button>
        <button type="button" class="button secondary" data-go="last">Last &raquo;</button>
      </span>
    </div>

    <?php if ($batch['payments']): ?>
      <p class="hint" style="text-align:left; margin:8px 0 0">Paid:
        <?= e(implode(', ', array_map(fn($p) => $p['payment_ref'] . ' (' . $p['certificates'] . ')', $batch['payments']))) ?></p>
    <?php endif; ?>
  </details>

  <!-- One vehicle's details, filled in from the row that was clicked. -->
  <div class="gt-modal" id="m-vehicle" role="dialog" aria-modal="true" aria-labelledby="mv-title">
    <div class="gt-box">
      <h3 id="mv-title">Vehicle</h3>
      <p class="gt-hint" id="mv-sub"></p>
      <table class="details" id="mv-table"></table>
      <div class="gt-foot">
        <button type="button" class="button secondary gt-close">Close</button>
        <a class="button secondary" id="mv-open" href="#">Open its page</a>
        <?php if ($isAdm): ?><a class="button" id="mv-edit" href="#">Edit details</a><?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($isAdm && $fleetEditable): ?>
  <!-- The same details for every vehicle in the fleet. -->
  <div class="gt-modal" id="m-fleet-edit" role="dialog" aria-modal="true" aria-labelledby="mf-title">
    <form class="gt-box" method="post" id="mfForm">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">
      <input type="hidden" name="action" value="fleet_edit">
      <h3 id="mf-title">Edit all <?= count($fleetEditable) ?> vehicles</h3>
      <p class="gt-hint">Tick each detail to change, and it is set to the same value on every vehicle in
        this fleet. Anything not ticked stays as it is. To change one vehicle only, click it in the list instead.</p>
      <table class="details mf-table">
        <?php
          $mfFields = ['ownership' => ['text', true], 'financier' => ['text', true], 'client_name' => ['text', true],
                       'client_phone' => ['tel', false], 'installed_on' => ['date', false],
                       'subscription_date' => ['date', false], 'expiry_date' => ['date', false]];
          foreach ($mfFields as $k => [$type, $caps]): ?>
          <tr>
            <td style="width:30px"><input type="checkbox" name="apply[<?= e($k) ?>]" value="1" id="mfa-<?= e($k) ?>"
                     class="mf-tick" style="width:16px; margin:0"></td>
            <th><label for="mfa-<?= e($k) ?>" style="margin:0; cursor:pointer"><?= e(TRK_EDITABLE[$k]) ?></label></th>
            <td><input name="set[<?= e($k) ?>]" type="<?= e($type) ?>" class="mf-input<?= $caps ? ' caps' : '' ?>"
                       value="<?= e((string)($common[$k] ?? '')) ?>" disabled
                       <?= $k === 'financier' ? 'list="mf-banks"' : '' ?>
                       placeholder="<?= $common[$k] === null ? 'Differs between vehicles' : '' ?>"></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <datalist id="mf-banks">
        <?php foreach (array_unique(array_values(trk_financier_names())) as $bank): ?><option value="<?= e($bank) ?>"><?php endforeach; ?>
      </datalist>
      <label class="gt-f">Reason for the change <span class="req">*</span>
        <textarea name="reason" rows="2" required minlength="5" maxlength="300"
                  placeholder="e.g. Fleet financed by Equity Bank; owner per the lease agreement"></textarea>
      </label>
      <p class="gt-hint">Each vehicle keeps this change and the reason in its own history. If any vehicle
        would end up invalid (for example an expiry before its subscription date), nothing is saved.</p>
      <div class="gt-foot">
        <button type="button" class="button secondary gt-close">Cancel</button>
        <button type="submit" class="button" id="mfSave" disabled>Tick a detail to change</button>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <?php require_once APP_DIR . '/views/modal_base.php'; ?>
  <style>
    .trk-fleet { margin: 0 0 22px; }
    .trk-fleet summary { cursor: pointer; font-weight: 600; color: #0D4F72; padding: 10px 14px;
                         background: #fff; border: 1px solid #E4EBF2; border-radius: 10px; }
    .trk-fleet[open] summary { margin-bottom: 10px; }
    .trk-fleet-tools { display: flex; justify-content: space-between; align-items: center; gap: 12px;
                       flex-wrap: wrap; margin: 0 0 10px; }
    .trk-fleet-wrap { overflow-x: auto; border-radius: 12px; }
    .trk-fleet-table { width: 100%; }
    .trk-fleet-row { cursor: pointer; }
    .trk-fleet-row:hover td, .trk-fleet-row:focus td { background: #F2F7FB; }
    .trk-fleet-row:focus { outline: 2px solid rgba(24,144,208,.45); outline-offset: -2px; }
    .trk-fleet-row.is-me td { background: #F6FAF0; }
    .trk-fleet-row.is-off-page { display: none; }
    .trk-fleet-pager { margin-top: 10px; }
    .trk-fleet-pager .pager-buttons button[hidden] { display: none; }
    #m-vehicle .gt-box { width: min(760px, calc(100vw - 32px)); }
    #m-fleet-edit .gt-box { width: min(640px, calc(100vw - 32px)); }
    #m-fleet-edit .mf-table th { width: 150px; white-space: nowrap; }
    #m-fleet-edit .mf-input { width: 100%; padding: 8px 10px; border: 1px solid #D9E2EC; border-radius: 8px;
                              font: inherit; font-size: 14px; }
    #m-fleet-edit .mf-input:disabled { background: #F4F6F8; color: #8793A3; }
    #m-fleet-edit textarea { width: 100%; }
  </style>
  <script>
  (function () {
    var data = <?= json_encode($popup, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var isAdm = <?= $isAdm ? 'true' : 'false' ?>;

    // Pages of vehicles, turned without reloading. Starts on the page with this certificate.
    var rows = Array.prototype.slice.call(document.querySelectorAll('.trk-fleet-row'));
    var perSel = document.getElementById('fleetPer'), info = document.getElementById('fleetInfo');
    var pageLbl = document.getElementById('fleetPage'), pager = document.getElementById('fleetPager');
    var per = parseInt(perSel.value, 10), page = 1;
    var mine = rows.findIndex(function (r) { return r.classList.contains('is-me'); });
    if (mine > -1) { page = Math.floor(mine / per) + 1; }
    function pages() { return Math.max(1, Math.ceil(rows.length / per)); }
    function draw() {
      page = Math.min(Math.max(1, page), pages());
      var from = (page - 1) * per, to = Math.min(from + per, rows.length);
      rows.forEach(function (r, i) { r.classList.toggle('is-off-page', i < from || i >= to); });
      info.textContent = (rows.length ? (from + 1) + '\u2013' + to : 0) + ' of ' + rows.length;
      pageLbl.textContent = 'Page ' + page + ' of ' + pages();
      pager.querySelector('[data-go=first]').hidden = pager.querySelector('[data-go=prev]').hidden = page <= 1;
      pager.querySelector('[data-go=next]').hidden = pager.querySelector('[data-go=last]').hidden = page >= pages();
    }
    pager.querySelectorAll('[data-go]').forEach(function (b) {
      b.addEventListener('click', function () {
        var go = b.dataset.go;
        page = go === 'first' ? 1 : go === 'last' ? pages() : go === 'prev' ? page - 1 : page + 1;
        draw();
      });
    });
    perSel.addEventListener('change', function () {
      var first = (page - 1) * per;          // keep the same vehicles in view
      per = parseInt(perSel.value, 10); page = Math.floor(first / per) + 1; draw();
    });
    draw();

    // A vehicle's details in a pop-up.
    var modal = document.getElementById('m-vehicle');
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function row(a, av, b, bv) { return '<tr><th>' + a + '</th><td>' + esc(av || '—') + '</td><th>' + b + '</th><td>' + esc(bv || '—') + '</td></tr>'; }
    function show(id) {
      var v = data[id]; if (!v) { return; }
      document.getElementById('mv-title').textContent = v.reg;
      document.getElementById('mv-sub').textContent = (v.serial ? 'No. ' + v.serial + ' · ' : 'No serial yet · ') + v.state;
      var html = row('Vehicle', v.reg, 'Make / model', v.make) + row('Chassis no.', v.chassis, 'Engine no.', v.engine)
        + row('Colour', v.colour, 'Track IMEI', v.imei);
      if (v.civ || v.sim) { html += row('Civilian plate', v.civ, 'SIM card', v.sim); }
      html += row('Ownership', v.owner, 'Contact', v.contact) + row('Financier', v.fin, 'Installed on', v.installed)
        + row('Subscription', v.sub, 'Expires', v.exp);
      document.getElementById('mv-table').innerHTML = html;
      document.getElementById('mv-open').href = 'tracking_view.php?id=' + id;
      var edit = document.getElementById('mv-edit');
      if (edit) { edit.href = 'tracking_edit.php?id=' + id; edit.hidden = !v.editable; }
      modal.classList.add('on');
    }
    document.querySelectorAll('.trk-fleet-row').forEach(function (tr) {
      tr.addEventListener('click', function () { show(tr.dataset.id); });
      tr.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); show(tr.dataset.id); } });
    });

    // Edit all: a detail's box comes alive only when ticked; the button says what will happen.
    var form = document.getElementById('mfForm');
    if (form) {
      var save = document.getElementById('mfSave');
      var ticks = form.querySelectorAll('.mf-tick');
      function sync() {
        var n = 0;
        ticks.forEach(function (t) {
          var input = form.querySelector('[name="set[' + t.name.slice(6, -1) + ']"]');
          input.disabled = !t.checked;
          if (t.checked) { n++; }
        });
        save.disabled = n === 0;
        save.textContent = n ? 'Change ' + n + ' detail' + (n === 1 ? '' : 's') + ' on <?= count($fleetEditable) ?> vehicles' : 'Tick a detail to change';
      }
      ticks.forEach(function (t) { t.addEventListener('change', sync); });
      form.querySelectorAll('.mf-input.caps').forEach(function (i) {
        i.addEventListener('input', function () { i.value = i.value.toUpperCase(); });
      });
      sync();
    }
  })();
  </script>
<?php endif; ?>

<?php if ($rejected): ?>
  <p class="error">Rejected by <?= e((string)$cert['decided_by_name']) ?>
    on <?= e(fmt_date(substr((string)$cert['approved_at'], 0, 10))) ?>:
    <?= e((string)$cert['rejection_reason']) ?></p>
<?php endif; ?>

<?php if ($cert['status'] === 'voided'): ?>
  <p class="error">Voided: <?= e((string)$cert['void_reason']) ?></p>
<?php endif; ?>

<?php if (($cert['status'] ?? '') === 'removed'): ?>
  <?php /*
    Deliberately not styled as an error. Nothing went wrong here: the certificate
    did its job for the months it covered, and the unit has since come off.
  */ ?>
  <p class="hint" style="text-align:left">
    <strong>Unit removed on <?= e(fmt_date((string)$cert['removed_on'])) ?></strong>
    &mdash; <?= e((string)$cert['removed_reason']) ?>.
    The vehicle is no longer covered. Cover would otherwise have run to
    <?= e(fmt_date($cert['expiry_date'])) ?>.
  </p>
<?php endif; ?>

<?php if ($replacement): ?>
  <p class="hint" style="text-align:left">
    <?= $cert['status'] === 'superseded' ? 'Replaced by' : 'The current certificate for this vehicle is' ?>
    <a href="tracking_view.php?id=<?= (int)$replacement['id'] ?>"><?= e(tracking_label($replacement)) ?></a>,
    expiring <?= e(fmt_date($replacement['expiry_date'])) ?>.</p>
<?php endif; ?>

<!-- ------------------------------------------------------------------ -->
<!-- Details. A rejected certificate shows only what was asked for, not -->
<!-- dates and serials that never came into effect.                     -->
<!-- ------------------------------------------------------------------ -->
<table class="details">
  <tr><th>Vehicle</th><td><?= e($cert['reg_no']) ?></td>
      <th>Make / model</th><td><?= e($cert['make_model']) ?></td></tr>
  <tr><th>Chassis no.</th><td><?= e($cert['chassis_no']) ?></td>
      <th>Engine no.</th><td><?= e($cert['engine_no']) ?></td></tr>
  <tr><th>Colour</th><td><?= e($cert['colour']) ?></td>
      <?php $vwImeis = tracking_imeis((int)$cert['id']); ?>
      <th>Track IMEI<?= count($vwImeis) > 1 ? 's' : '' ?></th>
      <td><?= e($vwImeis ? implode(' / ', $vwImeis) : (string)$cert['imei']) ?></td></tr>
  <?php /* Government fleets: kept on the record, never printed on the certificate. */ ?>
  <?php if (trim((string)($cert['civilian_plate'] ?? '')) !== '' || trim((string)($cert['sim_card'] ?? '')) !== ''): ?>
  <tr><th>Civilian plate</th><td><?= e((string)($cert['civilian_plate'] ?: '—')) ?></td>
      <th>SIM card</th><td><?= e((string)($cert['sim_card'] ?: '—')) ?></td></tr>
  <?php endif; ?>
  <tr><th>Ownership</th><td><?= e($cert['ownership']) ?></td>
      <th>Contact</th><td><?= e($cert['client_phone']) ?></td></tr>
  <tr>
      <th>Financier</th><td><?= e($cert['financier'] !== '' ? $cert['financier'] : '—') ?></td>
    <th>Installed on</th><td colspan="3"><?= e(fmt_date($cert['installed_on'])) ?></td></tr>
  <?php /*
    "Signed by" is who signs the issued certificate. A certificate with no serial
    number has not been issued - it takes its number when accounts confirm the
    payment - so nobody has signed anything yet, and printing a signatory beside a
    pending or rejected one claims something that has not happened.
 
    The cell is not simply dropped: this table is four columns wide, and a row
    with two cells in it comes out short with the column rule bending round it.
    The installed date spans the row instead.
  */ ?>
  <?php if ($approved): ?>
  <tr><th>Installed on</th><td><?= e(fmt_date($cert['installed_on'])) ?></td>
      <th>Signed by</th><td><?= e($cert['signed_by']) ?></td></tr>
  <?php else: ?>

  <?php endif; ?>
  <?php if (!$rejected): ?>
  <tr><th>Subscription</th><td><?= e(fmt_date($cert['subscription_date'])) ?></td>
      <th>Expires</th><td><?= e(fmt_date($cert['expiry_date'])) ?></td></tr>
  <?php endif; ?>
  <tr><th>Raised by</th><td><?= e((string)$cert['issued_by_name']) ?></td>
      <th>Raised on</th><td><?= e(fmt_date(substr((string)$cert['issued_at'], 0, 10))) ?></td></tr>
  <?php if ($approved): ?>
  <tr><th>Approved by</th><td><?= e((string)$cert['decided_by_name']) ?></td>
      <th>Approved on</th><td><?= e(fmt_date(substr((string)$cert['approved_at'], 0, 10))) ?></td></tr>
  <?php endif; ?>
  <?php if ($cert['renewed_from']): ?>
  <tr><th>Renewal of</th><td colspan="3">
      <a href="tracking_view.php?id=<?= (int)$cert['renewed_from_id'] ?>">
        <?= e($cert['renewed_from_number']
              ? str_pad((string)$cert['renewed_from_number'], 5, '0', STR_PAD_LEFT)
              : 'TRK-' . str_pad((string)$cert['renewed_from_id'], 5, '0', STR_PAD_LEFT)) ?></a></td></tr>
  <?php endif; ?>
</table>

<!-- ------------------------------------------------------------------ -->
<!-- Payment                                                            -->
<!-- ------------------------------------------------------------------ -->
<?php
  // Nothing recorded yet means nothing to show. Two empty dashes under a heading
  // called Payment read as a fault on the page, and they sit directly above the
  // form that is there to fill them in.
  $trkHasPayment = trim((string)$cert['payment_ref']) !== ''
                || trim((string)$cert['payment_note']) !== '';
?>
<?php if (!$rejected && $trkHasPayment): ?>
<h2>Payment</h2>
<table class="details">
  <tr><th>Reference</th>
      <td><?= $cert['payment_ref'] !== ''
            ? e($cert['payment_ref'])
            : '<span style="display:block; text-align:center">&mdash;</span>' ?></td>
      <th>Message</th>
      <td><?php if (trim((string)$cert['payment_note']) !== ''): ?>
            <a href="#" class="pay-view">View</a>
          <?php else: ?>
            <span style="display:block; text-align:center">&mdash;</span>
          <?php endif; ?></td></tr>
</table>
<?php if ($isAcc && $approved): ?>
  <p><button type="button" class="button secondary pay-edit">Correct the payment details</button></p>
<?php endif; ?>
<?php endif; ?>

<!-- ------------------------------------------------------------------ -->
<!-- Accounts: approve or reject                                        -->
<!-- ------------------------------------------------------------------ -->
<?php if ($isAcc && !$approved): ?>
  <div class="approve-panel">
    <h2 style="margin-top:0">Record the payment</h2>

    <?php if (trim((string)$cert['payment_ref']) !== ''): ?>
      <p class="hint" style="text-align:left; margin:0 0 10px; color:#8A5A00">
        The administrator who raised this recorded a payment. Check it against the
        statement before approving &mdash; it has not been verified by accounts.</p>
    <?php endif; ?>

    <p><a class="button secondary" href="tracking_print.php?id=<?= (int)$cert['id'] ?>"
          target="_blank">Preview the certificate</a></p>

    <form method="post" class="card-form" style="margin-top:16px">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">
      <label class="field" style="width:100%">Payment message
        <span class="field-row">
          <textarea name="payment_note" id="note" rows="3"
            placeholder="Paste the full M-Pesa or bank message here"
            style="width:100%; padding:9px 11px; border:1px solid var(--line); border-radius:6px;
                   font-family:inherit; font-size:14px; resize:vertical"><?= e((string)($_POST['payment_note'] ?? $cert['payment_note'])) ?></textarea>
        </span>
      </label>
      <label class="field">Reference code <span class="req">*</span>
        <span class="field-row">
          <input name="payment_ref" id="ref" required maxlength="40"
                 value="<?= e((string)($_POST['payment_ref'] ?? $cert['payment_ref'])) ?>"
                 style="text-transform:uppercase">
        </span>
      </label>
      <?php if ($batch && count($waiting) > 1): ?>
        <?php /* The fleet's waiting certificates, ticked by default: one payment covers them
                 all, and unticking the ones not paid for leaves them waiting without holding
                 up the rest. The button below changes with the ticks. */ ?>
        <p class="hint" style="text-align:left; margin:0 0 10px">
          <?= e((string)$batch['client_name']) ?> raised <?= count($siblings) ?> vehicles together and
          pays once. Untick anything that was not paid for &mdash; it stays waiting.</p>
        <table class="details trk-fleet-pick">
          <?php foreach ($waiting as $w): ?>
            <tr>
              <td style="width:28px"><input type="checkbox" name="cert[]" value="<?= (int)$w['id'] ?>" checked
                     class="fleet-tick" id="fx<?= (int)$w['id'] ?>" style="width:16px; margin:0"></td>
              <th style="white-space:nowrap"><label for="fx<?= (int)$w['id'] ?>" style="margin:0; cursor:pointer">
                <?= e((string)$w['reg_no']) ?></label></th>
              <td><?= e((string)$w['imei']) ?></td>
              <td><?= $w['renewed_from'] ? 'Renewal' : 'New' ?></td>
              <td><a href="tracking_print.php?id=<?= (int)$w['id'] ?>" target="_blank">Preview</a></td>
            </tr>
          <?php endforeach; ?>
        </table>
        <p class="hint" style="text-align:left; margin:10px 0 0">The reference is checked against
           every other certificate and fleet, so one payment cannot be used twice.</p>
        <button type="submit" name="action" value="approve_batch" id="release-fleet">
          Approve all <?= count($waiting) ?> certificates</button>
        <p class="hint" style="text-align:left; margin:10px 0 0">
          Or <button type="submit" name="action" value="approve"
              style="background:none; border:0; padding:0; color:#1890D0; cursor:pointer;
                     font:inherit; text-decoration:underline; box-shadow:none">approve only
          <?= e((string)$cert['reg_no']) ?></button> on this payment.</p>
      <?php else: ?>
        <p class="hint" style="text-align:left; margin:0">The reference is checked against every
           other installation certificate, so one payment cannot approve two.</p>
        <button type="submit" name="action" value="approve">Approve and issue the serial number</button>
      <?php endif; ?>
    </form>
    <script>
    // "Approve all 12" follows the ticks, so the button says exactly what will happen.
    (function () {
      var btn = document.getElementById('release-fleet');
      if (!btn) { return; }
      var ticks = document.querySelectorAll('.fleet-tick');
      function count() {
        var n = 0; ticks.forEach(function (t) { if (t.checked) { n++; } });
        btn.textContent = n === ticks.length ? 'Approve all ' + n + ' certificates'
                        : 'Approve ' + n + ' of ' + ticks.length + ' certificates';
        btn.disabled = n === 0;
      }
      ticks.forEach(function (t) { t.addEventListener('change', count); });
      count();
    })();
    </script>

    <form method="post" class="reject-form"
          onsubmit="return confirm('Reject this certificate and send it back?');">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">
      <label>Or reject it <input name="reason" placeholder="Reason, e.g. no payment received"></label>
      <button type="submit" name="action" value="reject" class="danger">Reject</button>
    </form>
  </div>
<?php endif; ?>

<!-- ------------------------------------------------------------------ -->
<!-- Accounts: download only - printing is for administrators            -->
<!-- ------------------------------------------------------------------ -->
<?php if ($isAcc && $live): ?>
  <div class="row-actions" style="margin-top:22px; display:flex; flex-wrap:wrap; gap:10px">
    <a class="button" href="tracking_print.php?id=<?= (int)$cert['id'] ?>&amp;download=1">Download PDF</a>
  </div>
<?php endif; ?>

<!-- ------------------------------------------------------------------ -->
<!-- Administrators: print, download, renew, void                       -->
<!-- ------------------------------------------------------------------ -->
<?php if ($isAdm): ?>
  <div class="row-actions" style="margin-top:22px; display:flex; flex-wrap:wrap; gap:10px">
    <?php if ($live): ?>
      <?php /* Plain paper only: pre-printed tracking blanks cannot carry the certificate's own QR code. */ ?>
      <a class="button" href="tracking_print.php?id=<?= (int)$cert['id'] ?>&amp;print=1" target="_blank">Print certificate</a>
      <a class="button secondary" href="tracking_print.php?id=<?= (int)$cert['id'] ?>&amp;download=1">Download PDF</a>
    <?php elseif ($approved): ?>
      <a class="button secondary" href="tracking_print.php?id=<?= (int)$cert['id'] ?>" target="_blank">
        View (<?= e(strtolower($status)) ?>)</a>
    <?php endif; ?>

    <?php /*
      A removed certificate offers neither renewal nor removal: there is no unit
      in the vehicle to renew a subscription for, and nothing left to take out.
    */ ?>
    <?php $liveUnit = $approved && !in_array($cert['status'], ['voided', 'removed'], true); ?>

    <?php if ($liveUnit): ?>
      <button class="button renew-open" type="button">Renew subscription</button>
      <button class="button secondary remove-open" type="button">Record unit removal</button>
      <button class="button danger void-open" type="button">Void</button>
    <?php endif; ?>

    <?php if (!$rejected && !in_array($cert['status'], ['voided', 'superseded'], true)): ?>
      <a class="button secondary" href="tracking_edit.php?id=<?= (int)$cert['id'] ?>">Edit details</a>
    <?php endif; ?>

    <?php if ($rejected): ?>
      <a class="button" href="tracking_new.php?reg=<?= urlencode((string)$cert['reg_no']) ?>">
        Raise it again</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php $amends = trk_amendments((int)$cert['id']); ?>
<?php if ($amends): ?>
  <?php /* Every correction: when, who, why, and each field from what to what. */ ?>
  <details class="trk-history" style="margin-top:24px">
    <summary>Edited <?= count($amends) ?> time<?= count($amends) === 1 ? '' : 's' ?> &mdash; see what changed and why</summary>
    <?php foreach ($amends as $a): $d = json_decode((string)$a['details'], true) ?: []; ?>
      <div class="trk-history-item">
        <p><strong><?= e(fmt_date(substr((string)$a['created_at'], 0, 10))) ?> <?= e(substr((string)$a['created_at'], 11, 5)) ?></strong>
           &middot; <?= e((string)($a['by_name'] ?: 'Unknown')) ?> &middot; <em><?= e((string)($d['reason'] ?? '')) ?></em></p>
        <table class="details">
          <?php foreach ((array)($d['changes'] ?? []) as $k => [$from, $to]): ?>
            <tr><th><?= e(TRK_EDITABLE[$k] ?? $k) ?></th>
                <?php $isDate = in_array($k, ['installed_on', 'subscription_date', 'expiry_date'], true);
                      $show = fn($v) => $v === '' ? '(blank)' : ($isDate ? fmt_date((string)$v) : (string)$v); ?>
                <td><?= e($show($from)) ?></td>
                <td>&rarr; <?= e($show($to)) ?></td></tr>
          <?php endforeach; ?>
        </table>
      </div>
    <?php endforeach; ?>
  </details>
  <style>
    .trk-history summary { cursor: pointer; font-weight: 600; color: #0D4F72; padding: 10px 14px;
                           background: #fff; border: 1px solid #E4EBF2; border-radius: 10px; }
    .trk-history-item { margin: 12px 0 0; }
    .trk-history-item p { margin: 0 0 6px; font-size: 13.5px; color: #3D4756; }
  </style>
<?php endif; ?>

<!-- ================= dialogs ================= -->

<div class="trk-modal" id="m-note">
  <div class="trk-box">
    <h3>Payment message</h3>
    <p class="trk-msg"><?= nl2br(e((string)$cert['payment_note'])) ?></p>
    <div class="trk-foot"><button type="button" class="button secondary trk-close">Close</button></div>
  </div>
</div>

<?php if ($isAcc && $approved): ?>
<div class="trk-modal" id="m-pay">
  <form class="trk-box" method="post">
    <h3>Correct the payment details</h3>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">
    <label class="trk-f">Payment message
      <textarea name="payment_note" rows="4"><?= e((string)$cert['payment_note']) ?></textarea>
    </label>
    <label class="trk-f">Reference code <span class="req">*</span>
      <input name="payment_ref" required maxlength="40" style="text-transform:uppercase"
             value="<?= e((string)$cert['payment_ref']) ?>">
    </label>
    <p class="trk-hint">Correcting this is recorded against your name in the audit trail.</p>
    <div class="trk-foot">
      <button type="button" class="button secondary trk-close">Cancel</button>
      <button type="submit" name="action" value="update_ref" class="button">Save</button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if ($isAdm && $approved && $cert['status'] !== 'voided'): ?>
<div class="trk-modal" id="m-renew">
  <form class="trk-box" method="post">
    <h3>Renew the subscription</h3>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">
    <p class="trk-hint">Everything carries over from this certificate &mdash; vehicle, unit,
      customer and the original installation date. Only the subscription dates move.</p>
    <table class="details" style="margin:0 0 14px">
      <tr><th>Vehicle</th><td><?= e($cert['reg_no']) ?></td>
          <th>IMEI<?= count(tracking_imeis((int)$cert['id'])) > 1 ? 's' : '' ?></th>
          <td><?= e((string)$cert['imei']) ?></td></tr>
      <tr><th>New subscription</th><td><?= e(fmt_date(date('Y-m-d'))) ?></td>
          <th>New expiry</th><td id="renew-expiry"><?= e(fmt_date(default_expiry(date('Y-m-d'), 12))) ?></td></tr>
    </table>
    <label class="trk-f">Subscription period
      <select name="term_months" id="renew-term">
        <?php foreach (TRACKING_TERMS as $m => $text): ?>
          <option value="<?= $m ?>"
                  data-expiry="<?= e(fmt_date(default_expiry(date('Y-m-d'), $m))) ?>">
            <?= e($text) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="trk-f">Payment message <span class="req">*</span>
      <textarea name="payment_note" rows="4" required
        placeholder="Paste the full M-Pesa or bank message here"></textarea>
    </label>
    <p class="trk-hint">The reference is read out of the message, so it cannot disagree with
      the confirmation it came from. The renewal goes to accounts for approval.</p>
    <div class="trk-foot">
      <button type="button" class="button secondary trk-close">Cancel</button>
      <button type="submit" name="action" value="renew" class="button">Create the renewal</button>
    </div>
  </form>
</div>

<div class="trk-modal" id="m-remove">
  <form class="trk-box" method="post">
    <h3>Record unit removal</h3>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">

    <p class="trk-hint">For a tracker that has come off the vehicle &mdash; usually
      recovered by GAPTECH after the vehicle was repossessed. The certificate stays
      on record and says the unit was removed, rather than pretending it was issued
      in error.</p>

    <table class="details" style="margin:0 0 14px">
      <tr><th>Vehicle</th><td><?= e($cert['reg_no']) ?></td>
          <th>Unit</th><td><?= e((string)$cert['imei']) ?></td></tr>
      <tr><th>Customer</th><td><?= e($cert['client_name']) ?></td>
          <th>Cover ran to</th><td><?= e(fmt_date($cert['expiry_date'])) ?></td></tr>
    </table>

    <label class="trk-f">Date removed <span class="req">*</span>
      <input type="date" name="removed_on" required
             value="<?= e(date('Y-m-d')) ?>"
             min="<?= e((string)$cert['installed_on']) ?>" max="<?= e(date('Y-m-d')) ?>">
    </label>

    <label class="trk-f">Reason <span class="req">*</span>
      <input name="reason" required
             placeholder="e.g. vehicle repossessed by financier, unit recovered">
    </label>

    <p class="trk-hint"><strong>The vehicle stops being covered from that date</strong>,
      and drops out of the renewal lists and the dashboard. Any subscription time left
      ends here &mdash; it belonged to this customer, and the next one buys their own.
      Once recorded, the unit can be fitted to another vehicle.</p>

    <div class="trk-foot">
      <button type="button" class="button secondary trk-close">Cancel</button>
      <button type="submit" name="action" value="remove_unit" class="button">Record the removal</button>
    </div>
  </form>
</div>

<div class="trk-modal" id="m-void">
  <form class="trk-box" method="post">
    <h3>Void <?= e(tracking_label($cert)) ?></h3>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$cert['id'] ?>">
    <p class="trk-hint">The certificate stays on record so its serial number is never reused,
      but it stops being valid and cannot be printed.</p>
    <label class="trk-f">Reason <span class="req">*</span>
      <input name="reason" required placeholder="e.g. unit removed, subscription cancelled">
    </label>
    <div class="trk-foot">
      <button type="button" class="button secondary trk-close">Cancel</button>
      <button type="submit" name="action" value="void" class="button danger">Void it</button>
    </div>
  </form>
</div>
<?php endif; ?>

<style>
/* Dialogs: centred, wide enough not to scroll, no backdrop. */
.trk-modal { display: none; position: fixed; inset: 0; z-index: 2000; }
.trk-modal.on { display: block; }
.trk-box {
  position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%);
  width: min(720px, calc(100vw - 32px));
  background: #fff; border: 1px solid #D7DEE5; border-radius: 12px;
  box-shadow: 0 18px 50px rgba(13, 79, 114, 0.28);
  padding: 22px 24px 26px;
}
.trk-box h3 { margin: 0 0 14px; font-size: 19px; color: #0D4F72; }
.trk-f { display: block; margin: 0 0 14px; font-size: 13px; font-weight: 600; color: #44505E; }
.trk-f textarea, .trk-f input {
  display: block; width: 100%; margin-top: 5px; box-sizing: border-box;
  padding: 9px 11px; border: 1px solid #C9D3DC; border-radius: 6px;
  font-family: inherit; font-size: 14px; font-weight: 400; resize: vertical;
}
.trk-hint { margin: 0 0 14px; font-size: 13px; color: #6B7786; line-height: 1.5; }
.trk-msg {
  margin: 0 0 18px; padding: 14px 16px; background: #F2F7FB;
  border-left: 4px solid #1890D0; border-radius: 6px;
  font-size: 14px; line-height: 1.6; color: #1D2530; word-break: break-word;
}
.trk-foot { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; }
.status-pill {
  display: inline-block; margin-left: 10px; padding: 3px 11px; border-radius: 999px;
  font-size: 13px; font-weight: 600; vertical-align: middle;
  background: #EEF2F6; color: #44505E;
}
.status-pill.status-valid { background: #E8F6E0; color: #4A7A18; }
.status-pill.status-expired, .status-pill.status-voided,
.status-pill.status-rejected { background: #FBE9E9; color: #A4121A; }
.status-pill.status-pending-approval { background: #FDF3DF; color: #8A5A00; }
</style>

<script>
(function () {
  function open(sel) { var m = document.querySelector(sel); if (m) { m.classList.add('on'); } }
  function closeAll() {
    document.querySelectorAll('.trk-modal.on').forEach(function (m) { m.classList.remove('on'); });
  }

  var opens = [['.pay-view', '#m-note'], ['.pay-edit', '#m-pay'],
               ['.renew-open', '#m-renew'], ['.void-open', '#m-void'],
               ['.remove-open', '#m-remove']];
  opens.forEach(function (pair) {
    document.querySelectorAll(pair[0]).forEach(function (btn) {
      btn.addEventListener('click', function (ev) { ev.preventDefault(); open(pair[1]); });
    });
  });

  // Show the expiry the chosen period produces. The server works it out again.
  var term = document.getElementById('renew-term');
  var cell = document.getElementById('renew-expiry');
  if (term && cell) {
    term.addEventListener('change', function () {
      cell.textContent = this.options[this.selectedIndex].getAttribute('data-expiry');
    });
  }

  document.querySelectorAll('.trk-close').forEach(function (btn) {
    btn.addEventListener('click', closeAll);
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') { closeAll(); }
  });
})();

// Suggest the reference from a pasted message, without ever overwriting a typed one.
(function () {
  var note = document.getElementById('note'), ref = document.getElementById('ref');
  if (!note || !ref) { return; }
  function suggest() {
    if (ref.value.trim() !== '') { return; }
    var r = window.gtPaymentRef ? window.gtPaymentRef(note.value) : '';   // app/views/layout.php
    if (r) { ref.value = r; }
  }
  note.addEventListener('input', suggest);
  note.addEventListener('paste', function () { setTimeout(suggest, 50); });
  ref.addEventListener('input', function () { this.value = this.value.toUpperCase(); });
})();
</script>
<?php layout_bottom(); ?>