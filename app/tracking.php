<?php
/**
 * Tracking installation certificates - the logic.
 *
 * Goes in app/tracking.php
 *
 * Mirrors app/certificates.php so the two modules behave the same way, but keeps its
 * own table, its own number series and its own records. Deliberately self-contained:
 * it does not require app/certificates.php, so one module can never break the other.
 */

// ---------------------------------------------------------------------------
// Shared helpers. Defined here only if certificates.php has not already done so,
// so whichever file loads first wins and the two never collide.
// ---------------------------------------------------------------------------

if (!function_exists('plate_key')) {
    /** A registration reduced to letters and digits, so KDF745B matches KDF 745B. */
    function plate_key(string $reg): string
    {
        return (string)preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($reg)));
    }
}

if (!function_exists('extract_payment_ref')) {
    /** Pull the M-Pesa or bank reference out of a pasted confirmation message. */
    function extract_payment_ref(string $text): string
    {
        $up = strtoupper($text);
        $patterns = [
            '/M[-\s]?PESA\s*REF[:.\s]*([A-Z0-9]{8,15})/',
            '/\bREF(?:ERENCE)?[:.\s]*([A-Z0-9]{8,15})/',
            '/\b([A-Z0-9]{10})\b/',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $up, $m)) {
                if (preg_match('/[0-9]/', $m[1]) && preg_match('/[A-Z]/', $m[1])) {
                    return $m[1];
                }
            }
        }
        return '';
    }
}

if (!function_exists('default_expiry')) {
    /** Twelve months from the start date, ending the day before the anniversary. */
    function default_expiry(string $startDate, int $months = 12): string
    {
        $date = new DateTime($startDate);
        $date->modify("+{$months} months")->modify('-1 day');
        return $date->format('Y-m-d');
    }
}

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

/** What state a tracking certificate is in. Approval comes before everything else. */
function tracking_status(array $cert): string
{
    $approval = $cert['approval_status'] ?? 'approved';
    if ($approval === 'pending') {
        return 'Pending approval';
    }
    if ($approval === 'rejected') {
        return 'Rejected';
    }
    if ($cert['status'] === 'voided') {
        return 'Voided';
    }
    if ($cert['status'] === 'superseded') {
        return 'Superseded';
    }
    return strtotime($cert['expiry_date']) < strtotime(date('Y-m-d')) ? 'Expired' : 'Valid';
}

/** Only an approved, current, unexpired certificate may be printed or downloaded. */
function tracking_is_releasable(array $cert): bool
{
    return tracking_status($cert) === 'Valid';
}

/** What a pending certificate is called before it has a number. */
function tracking_draft_ref(array $cert): string
{
    return 'TRK-' . str_pad((string)$cert['id'], 5, '0', STR_PAD_LEFT);
}

/** The number for display: the real one once approved, otherwise the draft reference. */
function tracking_label(array $cert): string
{
    return ($cert['number'] !== null && $cert['number'] !== '')
        ? str_pad((string)$cert['number'], 5, '0', STR_PAD_LEFT)
        : tracking_draft_ref($cert);
}

/** The vehicle's current approved certificate, if it has one. */
function tracking_current_for_plate(string $reg): ?array
{
    $row = q("SELECT * FROM tracking_certificates
               WHERE regexp_replace(upper(reg_no), '[^A-Z0-9]', '', 'g') = ?
                 AND approval_status = 'approved' AND status = 'issued'
               ORDER BY expiry_date DESC, number DESC LIMIT 1", [plate_key($reg)])->fetch();
    return $row ?: null;
}

// ---------------------------------------------------------------------------
// Numbering
// ---------------------------------------------------------------------------

/**
 * The next serial. The UPDATE ... RETURNING is one statement, so two approvals
 * landing at the same moment queue on the row lock instead of sharing a number.
 */
function next_tracking_number(): int
{
    return (int)q("UPDATE settings SET value = (CAST(value AS integer) + 1)::text
                    WHERE key = 'last_tracking_number' RETURNING value")->fetchColumn();
}

// ---------------------------------------------------------------------------
// Create, approve, reject, void, renew
// ---------------------------------------------------------------------------

/** The company block printed in the certificate footer. */
function tracking_company(): array
{
    return [
        'name'      => setting('company_name', 'GAPTECH Solutions Ltd'),
        'name_caps' => strtoupper((string)setting('company_name', 'GAPTECH Solutions Ltd')),
        'tagline'   => setting('tagline', ''),
        'address'   => setting('tracking_address', ''),
        'emergency' => setting('tracking_emergency', ''),
        'email'     => setting('tracking_email', ''),
        'website'   => setting('tracking_website', ''),
        'stamp'     => setting('stamp_image', ''),
        'signature' => setting('signature_image', ''),
    ];
}

/**
 * The IMEIs on a certificate, in the order they appear on the form.
 *
 * tracking_imeis is the truth. tracking_certificates.imei carries the printed
 * string alongside it, rewritten from these rows on every save, so the list page
 * and the search box still read one field.
 */
function tracking_imeis(int $certId): array
{
    return q('SELECT imei FROM tracking_imeis WHERE cert_id = ? ORDER BY position, id',
             [$certId])->fetchAll(PDO::FETCH_COLUMN);
}

/** What goes in the one box on the certificate: "865... / 866...". */
function tracking_imei_line(array $imeis): string
{
    return implode(' / ', array_values(array_filter(
        array_map('trim', $imeis), fn($x) => $x !== '')));
}

/** Tidy a set of IMEIs from a form: trimmed, blanks dropped, no duplicates. */
function tracking_imei_clean(array $imeis): array
{
    $out = [];
    foreach ($imeis as $one) {
        $one = trim((string)$one);
        if ($one !== '' && !in_array($one, $out, true)) {
            $out[] = $one;          // the same unit twice is a typo, not two units
        }
    }
    return $out;
}

/**
 * Replace a certificate's IMEIs.
 *
 * Wholesale rather than piecemeal: the form sends the complete set every time,
 * and delete-then-insert is the only version of this that cannot leave a unit
 * behind when somebody clears the second box.
 */
function set_tracking_imeis(int $certId, array $imeis): string
{
    $clean = tracking_imei_clean($imeis);

    q('DELETE FROM tracking_imeis WHERE cert_id = ?', [$certId]);

    foreach ($clean as $i => $one) {
        q('INSERT INTO tracking_imeis (cert_id, imei, position) VALUES (?, ?, ?)',
          [$certId, $one, $i + 1]);
    }

    $line = tracking_imei_line($clean);
    q('UPDATE tracking_certificates SET imei = ? WHERE id = ?', [$line, $certId]);

    return $line;
}

/**
 * Is this unit already on another VEHICLE'S live certificate?
 *
 * A warning, never a block. The same IMEI legitimately reappears on every
 * renewal of the same vehicle - what this catches is a mistyped digit, or a unit
 * recorded as fitted to two vehicles at once.
 */
function tracking_imei_clash(string $imei, string $regNo, ?int $ignoreCertId = null): array
{
    $imei = trim($imei);
    if ($imei === '') {
        return [];
    }

    return q("SELECT t.number, t.reg_no
              FROM tracking_imeis i
              JOIN tracking_certificates t ON t.id = i.cert_id
              WHERE i.imei = ?
                AND t.approval_status = 'approved' AND t.status = 'issued'
                AND regexp_replace(upper(t.reg_no), '[^A-Z0-9]', '', 'g')
                    <> regexp_replace(upper(?), '[^A-Z0-9]', '', 'g')
                AND (?::int IS NULL OR t.id <> ?::int)
              ORDER BY t.number DESC",
             [$imei, $regNo, $ignoreCertId, $ignoreCertId])->fetchAll();
}

/**
 * Create a tracking certificate. It is PENDING: no number, not printable, until
 * accounts records the payment.
 */
function create_tracking_cert(array $in, array $user, ?int $renewedFrom = null): array
{
    $token = bin2hex(random_bytes(8));

    // One box or two on the form; the certificate prints them in one box. Accepts
    // either 'imeis' (a list) or the old single 'imei', so nothing that still
    // passes one field breaks.
    $trkImeis = tracking_imei_clean((array)($in['imeis'] ?? [$in['imei'] ?? '']));
    $trkLine  = tracking_imei_line($trkImeis);

    // Everything that will be printed, frozen now, so a reprint years later is identical.
    $snapshot = [
        'client' => [
            'name'  => $in['client_name'],
            'phone' => $in['client_phone'],
        ],
        'vehicle' => [
            'reg_no'     => $in['reg_no'],
            'make_model' => $in['make_model'],
            'chassis_no' => $in['chassis_no'],
            'engine_no'  => $in['engine_no'],
            'colour'     => $in['colour'],
            'ownership'  => $in['ownership'],
            'financier'  => $in['financier'],
        ],
        'device' => [
            // imeis is the list; imei is the string that gets printed. Both are
            // frozen: the sheet reads whichever it finds, and every certificate
            // issued before two units were possible only has the second.
            'imeis' => $trkImeis,
            'imei'  => $trkLine,
        ],
        'cert' => [
            'number'                => '',            // filled in when accounts approves
            'installed_on'          => $in['installed_on'],
            'installed_on_fmt'      => fmt_date($in['installed_on']),
            'subscription_date'     => $in['subscription_date'],
            'subscription_date_fmt' => fmt_date($in['subscription_date']),
            'expiry_date'           => $in['expiry_date'],
            'expiry_date_fmt'       => fmt_date($in['expiry_date']),
            'signed_by'             => $in['signed_by'],
        ],
        'company' => tracking_company(),
    ];

    $id = (int)q("INSERT INTO tracking_certificates
          (client_name, client_phone, reg_no, make_model, chassis_no, engine_no, colour,
           ownership, financier, imei, installed_on, subscription_date, expiry_date,
           signed_by, verify_token, snapshot, issued_by, renewed_from, approval_status)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending') RETURNING id", [
        $in['client_name'], $in['client_phone'], $in['reg_no'], $in['make_model'],
        $in['chassis_no'], $in['engine_no'], $in['colour'], $in['ownership'],
        $in['financier'], $trkLine, $in['installed_on'], $in['subscription_date'],
        $in['expiry_date'], $in['signed_by'], $token, json_encode($snapshot), $user['id'],
        $renewedFrom,
    ])->fetchColumn();

    set_tracking_imeis($id, $trkImeis);

    // Nothing is superseded here. The vehicle's current certificate stays live until
    // accounts approves this one, so a renewing client is never left without one.

    audit('issue_tracking', 'tracking_certificate', $id, [
        'reg_no' => $in['reg_no'], 'status' => 'pending approval',
    ]);

    return q('SELECT * FROM tracking_certificates WHERE id = ?', [$id])->fetch();
}

/**
 * Accounts approves: the payment is on record, so the certificate gets its serial
 * and becomes printable.
 */
function approve_tracking_cert(array $cert, array $user, string $ref, string $note): array
{
    $ref = strtoupper(trim($ref));
    if ($ref === '') {
        throw new RuntimeException('A payment reference is required.');
    }
    if (($cert['approval_status'] ?? '') === 'approved') {
        throw new RuntimeException('That certificate has already been approved.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $used = q('SELECT id, number FROM tracking_certificates
                    WHERE upper(payment_ref) = ? AND id <> ?', [$ref, $cert['id']])->fetch();
        if ($used) {
            throw new RuntimeException('That payment reference was already used on certificate '
                . ($used['number']
                    ? str_pad((string)$used['number'], 5, '0', STR_PAD_LEFT)
                    : 'TRK-' . str_pad((string)$used['id'], 5, '0', STR_PAD_LEFT)) . '.');
        }

        $number = next_tracking_number();

        // the serial is printed on the certificate, so it goes into the snapshot too
        $snapshot = json_decode((string)$cert['snapshot'], true) ?: [];
        $snapshot['cert']['number'] = str_pad((string)$number, 5, '0', STR_PAD_LEFT);

        q("UPDATE tracking_certificates
              SET number = ?, snapshot = ?, approval_status = 'approved', status = 'issued',
                  payment_ref = ?, payment_note = ?, approved_by = ?, approved_at = now(),
                  rejection_reason = NULL
            WHERE id = ?",
            [$number, json_encode($snapshot), $ref, $note, $user['id'], $cert['id']]);

        // Now work out which of this vehicle's approved certificates is current and
        // retire the rest. Registrations match on letters and digits only.
        q("UPDATE tracking_certificates c
              SET status = CASE WHEN c.id = cur.id THEN 'issued' ELSE 'superseded' END
            FROM (SELECT id FROM tracking_certificates
                   WHERE regexp_replace(upper(reg_no), '[^A-Z0-9]', '', 'g') = ?
                     AND approval_status = 'approved' AND status <> 'voided'
                   ORDER BY expiry_date DESC, number DESC LIMIT 1) cur
            WHERE regexp_replace(upper(c.reg_no), '[^A-Z0-9]', '', 'g') = ?
              AND c.approval_status = 'approved' AND c.status <> 'voided'",
            [plate_key((string)$cert['reg_no']), plate_key((string)$cert['reg_no'])]);

        $pdo->commit();
    } catch (Throwable $err) {
        $pdo->rollBack();
        throw $err;
    }

    audit('approve_tracking', 'tracking_certificate', (int)$cert['id'],
        ['number' => $number, 'payment_ref' => $ref]);

    return q('SELECT * FROM tracking_certificates WHERE id = ?', [$cert['id']])->fetch();
}

/** Accounts rejects: no payment on record. It takes no number. */
function reject_tracking_cert(array $cert, array $user, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('Give a reason for rejecting it.');
    }
    if (($cert['approval_status'] ?? '') === 'approved') {
        throw new RuntimeException('That certificate is already approved. Void it instead.');
    }

    q("UPDATE tracking_certificates SET approval_status = 'rejected', rejection_reason = ?,
              approved_by = ?, approved_at = now() WHERE id = ?",
        [$reason, $user['id'], $cert['id']]);

    audit('reject_tracking', 'tracking_certificate', (int)$cert['id'], ['reason' => $reason]);

    return q('SELECT * FROM tracking_certificates WHERE id = ?', [$cert['id']])->fetch();
}

/**
 * Void an approved certificate: it was issued in error, or the subscription was
 * cancelled. The row stays, so the number is never reused and the history is intact.
 */
function void_tracking_cert(array $cert, array $user, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('Give a reason for voiding it.');
    }
    if ($cert['status'] === 'voided') {
        throw new RuntimeException('That certificate is already void.');
    }

    q("UPDATE tracking_certificates SET status = 'voided', void_reason = ? WHERE id = ?",
        [$reason, $cert['id']]);

    audit('void_tracking', 'tracking_certificate', (int)$cert['id'], ['reason' => $reason]);

    return q('SELECT * FROM tracking_certificates WHERE id = ?', [$cert['id']])->fetch();
}

/**
 * Renew a subscription. Everything carries over from the old certificate's snapshot,
 * so nothing can be retyped wrongly; only the dates move. The installation date does
 * not change, because the unit was only ever fitted once.
 *
 * Created as PENDING with the payment message attached, so accounts still approves it.
 */
function renew_tracking_cert(array $old, array $user, string $note, int $months = 12): array
{
    if (!in_array($months, [12, 24, 36, 48, 60], true)) {
        $months = 12;
    }
    $ref = extract_payment_ref($note);
    if ($ref === '') {
        throw new RuntimeException('No payment reference could be found in that message. '
            . 'Check the whole confirmation was pasted.');
    }

    $s = json_decode((string)$old['snapshot'], true) ?: [];
    $start = date('Y-m-d');

    $in = [
        'client_name'       => $s['client']['name']       ?? $old['client_name'],
        'client_phone'      => $s['client']['phone']      ?? $old['client_phone'],
        'reg_no'            => $s['vehicle']['reg_no']    ?? $old['reg_no'],
        'make_model'        => $s['vehicle']['make_model'] ?? $old['make_model'],
        'chassis_no'        => $s['vehicle']['chassis_no'] ?? $old['chassis_no'],
        'engine_no'         => $s['vehicle']['engine_no']  ?? $old['engine_no'],
        'colour'            => $s['vehicle']['colour']     ?? $old['colour'],
        'ownership'         => $s['vehicle']['ownership']   ?? $old['ownership'],
        'financier'         => $s['vehicle']['financier']   ?? $old['financier'],
        // Both units carry forward. The live rows are the truth; the snapshot is
        // the fallback for a certificate issued before tracking_imeis existed,
        // and $old['imei'] the fallback for one whose snapshot predates even that.
        'imeis'             => tracking_imeis((int)$old['id'])
                                 ?: ($s['device']['imeis']
                                     ?? array_filter([$s['device']['imei'] ?? $old['imei']])),
        'installed_on'      => $old['installed_on'],          // historic, never moves
        // The signatory is whoever Settings names today, not whoever signed the
        // certificate being renewed - this is a new certificate, freshly signed.
        'signed_by'         => trim((string)setting('tracking_signatory', '')) ?: 'MOSES CLAYTONE',
        'subscription_date' => $start,
        'expiry_date'       => default_expiry($start, $months),
    ];

    $new = create_tracking_cert($in, $user, (int)$old['id']);

    q('UPDATE tracking_certificates SET payment_ref = ?, payment_note = ? WHERE id = ?',
        [$ref, $note, $new['id']]);

    audit('renew_tracking', 'tracking_certificate', (int)$new['id'],
        ['renewed_from' => (int)$old['id'], 'payment_ref' => $ref]);

    return q('SELECT * FROM tracking_certificates WHERE id = ?', [$new['id']])->fetch();
}

// ---------------------------------------------------------------------------
// Printing
// ---------------------------------------------------------------------------

/**
 * Render the certificate.
 *
 * $mode 'screen' produces a page for the browser, which is also what printing uses;
 * 'pdf' switches to file:// asset paths so wkhtmltopdf can find the images.
 */
function render_tracking_sheet(array $cert, string $mode = 'screen'): string
{
    // Globals because the view reads them.
    global $d, $assets, $trackingMode;

    $d = json_decode((string)$cert['snapshot'], true) ?: [];

    // Settings may have changed since the certificate was issued, but the footer is
    // contact detail rather than certified fact, so take the current values if the
    // snapshot predates them.
    $d['company'] = array_merge(tracking_company(), array_filter($d['company'] ?? []));

    $d['cert']['number'] = $d['cert']['number'] ?: tracking_label($cert);
    $d['cert']['status'] = tracking_status($cert);

    $assets = ($mode === 'pdf')
        ? 'file://' . str_replace('\\', '/', ROOT_DIR . '/public/assets')
        : 'assets';
    $trackingMode = $mode;

    ob_start();
    include APP_DIR . '/views/tracking_sheet.php';
    return (string)ob_get_clean();
}

function tracking_pdf(array $cert, bool $force = false): ?string
{
    global $config;
 
    if (pdf_engine() === null) {
        return null;                       // same fallback as certificate_pdf()
    }
 
    $dir = rtrim((string)$config['storage'], '/\\') . '/tracking';
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create storage folder: ' . $dir);
    }
 
    $pdfPath = $dir . '/installation-' . tracking_label($cert) . '.pdf';
    if (!$force && is_file($pdfPath) && !empty($cert['pdf_path'])) {
        return $pdfPath;
    }
 
    pdf_render_certificate('tracking', (int)$cert['id'], $pdfPath, 'portrait');
 
    q('UPDATE tracking_certificates SET pdf_path = ?, pdf_sha256 = ? WHERE id = ?',
        [$pdfPath, hash_file('sha256', $pdfPath), $cert['id']]);
 
    return $pdfPath;
}
 