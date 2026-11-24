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

/**
 * The subscription lengths on offer, in months. One list for the new-installation form,
 * the renewal dialog and the server checks: when they were separate, the renewal dialog
 * offered 6 years while the server only accepted 5, and quietly saved such a renewal as 1.
 */
const TRACKING_TERMS = [12 => '1 year', 24 => '2 years', 36 => '3 years',
                        48 => '4 years', 60 => '5 years', 72 => '6 years'];

// ---------------------------------------------------------------------------
// Shared helpers. Defined here only if certificates.php has not already done so,
// so whichever file loads first wins and the two never collide.
// ---------------------------------------------------------------------------

if (!function_exists('plate_display')) {
    /**
     * A registration as it is stored and printed: "KDK 259X", not "KDK259X". The same
     * function as app/clients.php (which this module does not load). Anything typed WITH
     * a space is left alone - the typist knows where a "GK A123A" space goes.
     */
    function plate_display(string $reg): string
    {
        $clean = strtoupper(trim((string)preg_replace('/\s+/', ' ', $reg)));
        if (strpos($clean, ' ') === false
            && preg_match('/^(K[A-Z]{2}|KM[A-Z]{2})([0-9]{3}[A-Z]?)$/', $clean, $m)) {
            return $m[1] . ' ' . $m[2];
        }
        return $clean;
    }
}

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
    // Before the expiry test, deliberately. A tracker taken off in November is
    // not "expired" in December - it is gone, and calling it expired invites
    // somebody to renew cover for a vehicle with no unit in it.
    if (($cert['status'] ?? '') === 'removed') {
        return 'Unit removed';
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
        'stamp'     => setting('tracking_stamp_image', ''),   // tracking only; governors use stamp_image
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

    // Stored and printed as "KDK 259X", whichever way it was typed.
    $in['reg_no'] = plate_display((string)$in['reg_no']);
    // And the financier as one name per bank: "KCB" or "Kenya Commercial Bank Ltd" both
    // print as KCB BANK KENYA LTD, HFC as HFCB BANK, none as N/A.
    $in['financier'] = trk_financier_canonical((string)($in['financier'] ?? ''));

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
            // Kept, not printed: a government vehicle's civilian plate.
            'civilian_plate' => (string)($in['civilian_plate'] ?? ''),
        ],
        'device' => [
            // Kept, not printed: the SIM card in the tracker.
            'sim_card' => (string)($in['sim_card'] ?? ''),
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
           signed_by, verify_token, snapshot, issued_by, renewed_from, approval_status,
           civilian_plate, sim_card)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',?,?) RETURNING id", [
        $in['client_name'], $in['client_phone'], $in['reg_no'], $in['make_model'],
        $in['chassis_no'], $in['engine_no'], $in['colour'], $in['ownership'],
        $in['financier'], $trkLine, $in['installed_on'], $in['subscription_date'],
        $in['expiry_date'], $in['signed_by'], $token, json_encode($snapshot), $user['id'],
        $renewedFrom,
        mb_strtoupper(trim((string)($in['civilian_plate'] ?? '')), 'UTF-8'),
        trim((string)($in['sim_card'] ?? '')),
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

        // A reference that released a fleet cannot release a single certificate too.
        $fleetPaid = q('SELECT batch_id FROM tracking_batch_payments WHERE upper(payment_ref) = ?',
                       [$ref])->fetch();
        if ($fleetPaid) {
            throw new RuntimeException('That payment reference was already used to release fleet '
                . $fleetPaid['batch_id'] . '.');
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
    if (!isset(TRACKING_TERMS[$months])) {
        $months = 12;
    }
    $ref = extract_payment_ref($note);
    if ($ref === '') {
        throw new RuntimeException('No payment reference could be found in that message. '
            . 'Check the whole confirmation was pasted.');
    }

    $s = json_decode((string)$old['snapshot'], true) ?: [];

    // The new subscription picks up the day after the current one ends, so the
    // client keeps every day they paid for. This used to start from today, which
    // meant renewing a subscription with months left quietly threw those months
    // away - and made renewing late the safest thing to do.
    //
    // A subscription that has ALREADY lapsed starts today instead. The rule lives
    // in renewal_start_date(), in app/certificates.php, so both modules agree on
    // it; the guard is for a page that loads tracking without it.
    $start = function_exists('renewal_start_date')
        ? renewal_start_date((string)$old['expiry_date'])
        : date('Y-m-d');

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
        // Carried forward, never printed: a government vehicle's civilian plate and the SIM.
        'civilian_plate'    => (string)($old['civilian_plate'] ?? ''),
        'sim_card'          => (string)($old['sim_card'] ?? ''),
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
    //
    // This line fills GAPS only: array_merge lets the second array win, so any
    // value the snapshot already holds keeps winning. That is right for the
    // stamp and the signature, which are marks of authority belonging to the day
    // the certificate was signed.
    $d['company'] = array_merge(tracking_company(), array_filter($d['company'] ?? []));

    // ...but it was never right for the contact details, whatever the comment
    // above claimed. A changed telephone number never reached the certificate,
    // because the snapshot already held the old one and therefore won. A phone
    // number is not a record of anything - it is an instruction to call - and a
    // certificate in a glovebox printing a dead line helps nobody. So these are
    // refreshed outright.
    $contact = tracking_company();
    foreach (['name', 'name_caps', 'tagline', 'address', 'emergency',
              'email', 'website'] as $field) {
        if (trim((string)($contact[$field] ?? '')) !== '') {
            $d['company'][$field] = $contact[$field];
        }
    }

    $d['cert']['number'] = $d['cert']['number'] ?: tracking_label($cert);
    $d['cert']['status'] = tracking_status($cert);

    $assets = ($mode === 'pdf')
        ? 'file://' . str_replace('\\', '/', ROOT_DIR . '/public/assets')
        : 'assets';
    $trackingMode = $mode;

    // Onto a pre-printed blank: the entries only, placed on the blank's boxes.
    // Only ever on screen (for the print dialog) - a PDF always carries the
    // whole design, since nobody knows what paper it will meet.
    $view = ($mode === 'screen' && ($GLOBALS['tracking_print_stock'] ?? 'plain') === 'preprinted')
        ? 'tracking_sheet_preprinted.php' : 'tracking_sheet.php';

    ob_start();
    include APP_DIR . '/views/' . $view;
    return (string)ob_get_clean();
}

/**
 * Replaces tracking_pdf() in app/tracking.php.
 *
 * Find the line
 *
 *     function tracking_pdf(array $cert, bool $force = false): ?string
 *
 * and replace everything from there down to its closing brace - the line with a
 * single } just before the next function - with the function below.
 *
 * ---------------------------------------------------------------------------
 * Why "PDF conversion is not set up on this machine"
 * ---------------------------------------------------------------------------
 * Nothing is missing. The tracking module was still asking for wkhtmltopdf:
 *
 *     if (empty($config['wkhtmltopdf'])) { return null; }
 *
 * and that setting has been empty since the governor side moved to headless
 * Chrome. So the function returned null, tracking_print.php took that to mean
 * "no engine on this machine", and showed the message - while the governors
 * were rendering perfectly through a different route the whole time.
 *
 * Everything the governors use already handles tracking:
 *
 *   pdf_engine()            finds Chrome, Edge or wkhtmltopdf
 *   pdf_sheet.php           already accepts kind=tracking and renders the sheet
 *   pdf_render_certificate  signs a loopback URL and drives the browser
 *   pdf_file_is_sane()      checks what is on disk is a real PDF the right way up
 *
 * so this is the governor's certificate_pdf() with three things changed: the
 * folder, the table, and portrait instead of landscape.
 */

/**
 * The installation certificate as a PDF, rendered once and kept.
 *
 * Returns null when there is no engine at all - the caller then falls back to
 * the browser's own print dialog rather than showing an error.
 */
function tracking_pdf(array $cert, bool $force = false): ?string
{
    global $config;

    // Nothing to render with. Hand back null so tracking_print.php can offer the
    // print dialog instead of failing in front of a customer.
    if (pdf_engine() === null) {
        return null;
    }

    $dir = rtrim((string)$config['storage'], '/\\') . '/tracking';
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create storage folder: ' . $dir);
    }

    $pdfPath = $dir . '/installation-' . tracking_label($cert) . '.pdf';

    // Cached, but checked.
    //
    // is_file() alone was the fault that cost us an afternoon on the governor
    // side: once a bad PDF was stored - one that rendered as "Not found.", say -
    // it was served for ever, because the renderer underneath was never reached
    // again. pdf_file_is_sane() looks at what is actually on disk.
    //
    // 'portrait' here, where the governor passes 'landscape'. The check reads
    // the page box, so a sheet rendered the wrong way round is re-made rather
    // than handed to a customer sideways.
    if (!$force && !empty($cert['pdf_path']) && pdf_file_is_sane($pdfPath, 'portrait')
        && pdf_matches_design($pdfPath, 'tracking')) { // redrawn after a design change
        return $pdfPath;
    }

    // Both pages come out together - the certificate and the guarantee terms -
    // because the sheet declares the page break itself and Chrome honours it.
    pdf_render_certificate('tracking', (int)$cert['id'], $pdfPath, 'portrait');

    // Front and back: ask the viewer to print it two-sided, so the terms land on the back.
    pdf_request_duplex($pdfPath);

    q('UPDATE tracking_certificates SET pdf_path = ?, pdf_sha256 = ? WHERE id = ?',
        [$pdfPath, hash_file('sha256', $pdfPath), $cert['id']]);

    return $pdfPath;
}

/**
 * Record that the tracker has been taken off this vehicle.
 *
 * The certificate is kept, with the date and the reason. The vehicle stops being
 * covered from that moment, because there is no longer a box in it - the dashboard
 * and the renewal lists both read "covered" from the certificate in force, so this
 * is what takes it out of them.
 *
 * The remaining subscription time ends here. It belonged to the customer who had
 * the vehicle; the next customer buys their own. Nothing is refunded or carried
 * across by the system, and nothing pretends to.
 */
function remove_tracking_unit(array $cert, array $user, string $reason, string $when = ''): array
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('Say why the unit is being removed.');
    }

    if (($cert['approval_status'] ?? '') !== 'approved') {
        throw new RuntimeException('Only a released certificate can have its unit removed. '
            . 'A pending one should be sent back instead.');
    }
    if (($cert['status'] ?? '') === 'removed') {
        throw new RuntimeException('That unit is already recorded as removed.');
    }
    if (in_array($cert['status'] ?? '', ['voided', 'superseded'], true)) {
        throw new RuntimeException('That certificate is already ' . $cert['status']
            . ', so there is nothing to remove from it.');
    }

    $when = trim($when) !== '' ? $when : date('Y-m-d');
    if (strtotime($when) === false) {
        throw new RuntimeException('That removal date is not a date.');
    }
    // A unit cannot come off before it went on, and recording a removal in the
    // future would take a vehicle out of cover it still has.
    if (strtotime($when) < strtotime((string)$cert['installed_on'])) {
        throw new RuntimeException('The unit cannot be removed before it was installed ('
            . fmt_date((string)$cert['installed_on']) . ').');
    }
    if (strtotime($when) > strtotime(date('Y-m-d'))) {
        throw new RuntimeException('A removal cannot be dated in the future.');
    }

    q("UPDATE tracking_certificates
          SET status = 'removed', removed_on = ?, removed_reason = ?, removed_by = ?
        WHERE id = ?",
      [date('Y-m-d', strtotime($when)), $reason, (int)$user['id'], $cert['id']]);

    audit('remove_tracking_unit', 'tracking_certificate', (int)$cert['id'], [
        'reg_no'      => $cert['reg_no'],
        'imei'        => $cert['imei'],
        'removed_on'  => date('Y-m-d', strtotime($when)),
        'reason'      => $reason,
        'cover_ended' => $cert['expiry_date'],   // what the customer gave up
    ]);

    return q('SELECT * FROM tracking_certificates WHERE id = ?', [$cert['id']])->fetch();
}

/**
 * Is this IMEI already spoken for?
 *
 * Returns the certificate holding it, or null. Read the row's approval_status to
 * tell the two cases apart - they need different words, not different rules.
 *
 *   approved + issued   the unit is in that vehicle now
 *   pending             a certificate awaiting approval has already claimed it
 *
 * ---------------------------------------------------------------------------
 * Pending certificates count, and that is the whole point
 * ---------------------------------------------------------------------------
 * The first version of this asked only about approved certificates, on the
 * reasoning that a pending one has not been released so the unit is not really in
 * service. True, and beside the point.
 *
 * A certificate sitting with accounts is a CLAIM on that unit. If a second
 * certificate is raised for another vehicle with the same IMEI while the first is
 * waiting, both pass the check, both go to accounts, and both get approved - two
 * vehicles holding one box, which is exactly what this function exists to prevent.
 * The window is however long accounts take, so days, not milliseconds.
 *
 * Rejected certificates are excluded: that claim was turned down. Removed, voided
 * and superseded are excluded too - the unit has left, the certificate was an
 * error, or it is a renewal of the same vehicle where the unit never moved.
 *
 * "Approved" deliberately does NOT mean unexpired. An expired certificate whose
 * unit was never removed still means the box is in that vehicle.
 */
function tracking_imei_in_use(string $imei, ?int $exceptCertId = null): ?array
{
    $imei = trim($imei);
    if ($imei === '') {
        return null;
    }

    $row = q("SELECT c.*
                FROM tracking_certificates c
                JOIN tracking_imeis i ON i.cert_id = c.id
               WHERE i.imei = ?
                 AND c.approval_status IN ('approved', 'pending')
                 AND c.status = 'issued'
                 -- cast needed: PostgreSQL cannot infer the type of a parameter
                 -- that only ever appears in an IS NULL test
                 AND (?::int IS NULL OR c.id <> ?::int)
               -- an approved claim outranks a pending one, so the message names
               -- the vehicle the unit is actually in when both exist
               ORDER BY (c.approval_status = 'approved') DESC,
                        c.subscription_date DESC, c.id DESC
               LIMIT 1", [$imei, $exceptCertId, $exceptCertId])->fetch();

    return $row ?: null;
}

/**
 * Everywhere this unit has been, newest first.
 *
 * The question anybody holding a recovered tracker asks, and the one support asks
 * when a customer says their vehicle stopped reporting. Renewals of the same
 * vehicle are included - they are part of the unit's life even though it did not
 * move - so the caller can show them or fold them up as it likes.
 */
function tracking_device_history(string $imei): array
{
    $imei = trim($imei);
    if ($imei === '') {
        return [];
    }

    return q("SELECT c.id, c.number, c.reg_no, c.client_name, c.status, c.approval_status,
                     c.installed_on, c.subscription_date, c.expiry_date,
                     c.removed_on, c.removed_reason
                FROM tracking_certificates c
                JOIN tracking_imeis i ON i.cert_id = c.id
               WHERE i.imei = ?
               ORDER BY c.subscription_date DESC, c.id DESC", [$imei])->fetchAll();
}


// ---------------------------------------------------------------------------
// Rules shared by the single-vehicle form and the fleet upload
// ---------------------------------------------------------------------------

/**
 * Extra months a financier's agreement adds to a FIRST installation.
 *
 * KCB and GAPTECH have an arrangement that gives KCB-financed vehicles three
 * months on top. It lives in the `financier_bonus` setting rather than in this
 * file, written as NAME:MONTHS, comma separated:
 *
 *     KENYA COMMERCIAL BANK:3, KCB:3
 *
 * Both spellings are listed because either may be typed. Commercial agreements
 * end, and when this one does it is a line in Settings, not a code change and a
 * deployment - clear the setting and new certificates go back to the plain term.
 *
 * Matching ignores case, punctuation and spacing, so "Kenya Commercial Bank",
 * "KENYA COMMERCIAL BANK" and "K.C.B." all find their entry.
 */
function trk_financier_bonus(string $financier): int
{
    // Compared by the name the certificate prints, so every spelling of a bank
    // ("KCB", "KCB Bank", "Kenya Commercial Bank Ltd") finds the same agreement.
    $want = trk_financier_canonical($financier);
    if ($want === '') {
        return 0;
    }

    foreach (explode(',', (string)setting('financier_bonus', 'KCB BANK KENYA LTD:3')) as $pair) {
        $bits = explode(':', $pair);
        if (count($bits) !== 2) {
            continue;
        }
        if (trk_financier_canonical($bits[0]) === $want) {
            return max(0, min(24, (int)trim($bits[1])));   // a sane ceiling, not a free hand
        }
    }

    return 0;
}

/**
 * The financier as the certificate prints it, whichever way it was typed.
 *
 * The `financier_names` setting lists each bank's printed name and the spellings that
 * mean it:
 *
 *     KCB BANK KENYA LTD = KCB | KENYA COMMERCIAL BANK ; HFCB BANK = HFC | HFCB | HOUSING FINANCE
 *
 * Spellings are compared ignoring case, punctuation and the filler words that get added
 * or dropped (Bank, Kenya, Ltd, Limited, PLC, Group, Company), so "KCB", "K.C.B.",
 * "KCB Bank", "KCB Bank Kenya Ltd" and "Kenya Commercial Bank Limited" all print as
 * KCB BANK KENYA LTD. A financier not on the list is kept as typed, in capitals.
 * "None", "N/A", "Cash" or nothing at all means no financier: '' (printed as N/A).
 */
function trk_financier_canonical(string $typed): string
{
    $clean = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $typed)), 'UTF-8');
    if ($clean === '' || in_array(preg_replace('/[^A-Z]/', '', $clean), ['NA', 'NONE', 'NIL', 'CASH', 'N'], true)
        || trim($clean, " -/.") === '') {
        return '';
    }
    $names = trk_financier_names();
    return $names[trk_financier_key($clean)] ?? $clean;
}

/** A spelling reduced to what identifies the bank: capitals, no punctuation, no filler words. */
function trk_financier_key(string $s): string
{
    $words = preg_split('/[^A-Z0-9]+/', mb_strtoupper($s, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
    $filler = ['BANK', 'KENYA', 'K', 'LTD', 'LIMITED', 'PLC', 'GROUP', 'CO', 'COMPANY', 'THE'];
    $kept = array_values(array_diff($words, $filler));
    // A name made only of filler words ("Bank") is kept whole rather than becoming nothing.
    return implode('', $kept ?: $words);
}

/** key => printed name, from the financier_names setting. */
function trk_financier_names(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    $raw = (string)setting('financier_names',
        'KCB BANK KENYA LTD = KCB | KENYA COMMERCIAL BANK ; HFCB BANK = HFC | HFCB | HOUSING FINANCE');
    foreach (explode(';', $raw) as $entry) {
        $parts = explode('=', $entry, 2);
        $printed = mb_strtoupper(trim($parts[0]), 'UTF-8');
        if ($printed === '') {
            continue;
        }
        $map[trk_financier_key($printed)] = $printed;
        foreach (explode('|', (string)($parts[1] ?? '')) as $alias) {
            if (trim($alias) !== '') {
                $map[trk_financier_key($alias)] = $printed;
            }
        }
    }
    return $map;
}

/**
 * Has this vehicle ever had a tracking certificate before?
 *
 * The agreement is a sign-up benefit, so it applies to a first installation only.
 * A rejected certificate does not count as a previous one - it was never issued -
 * but a voided or expired one does: the vehicle was signed up, whatever happened
 * afterwards.
 *
 * Checked on the server at save time, not taken from the form, because this
 * decides a date that gets printed.
 */
function trk_is_first_installation(string $reg): bool
{
    if (trim($reg) === '') {
        return true;
    }
    $seen = q("SELECT 1 FROM tracking_certificates
                WHERE regexp_replace(upper(reg_no), '[^A-Z0-9]', '', 'g') = ?
                  AND approval_status <> 'rejected'
                LIMIT 1", [plate_key($reg)])->fetchColumn();

    return $seen === false;
}



// ---------------------------------------------------------------------------
// Correcting a certificate
// ---------------------------------------------------------------------------

/** The details an administrator may correct: key => label. Serial and payment are not here. */
const TRK_EDITABLE = [
    'client_name'       => 'Customer name',
    'client_phone'      => 'Contact',
    'reg_no'            => 'Registration',
    'civilian_plate'    => 'Civilian plate',
    'make_model'        => 'Make / model',
    'chassis_no'        => 'Chassis no.',
    'engine_no'         => 'Engine no.',
    'colour'            => 'Colour',
    'ownership'         => 'Ownership',
    'financier'         => 'Financier',
    'imei'              => 'Track IMEI',
    'imei_2'            => 'Second unit',
    'sim_card'          => 'SIM card',
    'installed_on'      => 'Installed on',
    'subscription_date' => 'Subscription date',
    'expiry_date'       => 'Expiry date',
];

/** A certificate's current values, in TRK_EDITABLE's shape. */
function trk_current_values(array $cert): array
{
    $units = tracking_imeis((int)$cert['id']);
    $v = [];
    foreach (array_keys(TRK_EDITABLE) as $k) {
        $v[$k] = (string)($cert[$k] ?? '');
    }
    $v['imei']   = (string)($units[0] ?? $cert['imei'] ?? '');
    $v['imei_2'] = (string)($units[1] ?? '');
    return $v;
}

/**
 * Correct any detail of a tracking certificate, with a reason.
 *
 * Changes the record AND the copy frozen for printing together, so the screen and the
 * certificate can never disagree, and writes every change - from what, to what, and why -
 * to the audit log, where the certificate page shows it as its edit history.
 *
 * Voided and superseded certificates cannot be changed: they are history. The serial
 * number and the payment are not editable here - those belong to accounts.
 *
 * Returns the changes as field => [from, to].
 */
function trk_amend(array $cert, array $in, array $user, string $reason, bool $ownTransaction = true,
                   array $auditExtra = []): array
{
    $reason = trim($reason);
    if (mb_strlen($reason) < 5) {
        throw new RuntimeException('Say why these details are being changed (a few words at least). '
            . 'It is kept with the certificate.');
    }
    if (in_array((string)$cert['status'], ['voided', 'superseded'], true)) {
        throw new RuntimeException('A ' . $cert['status'] . ' certificate is history and cannot be changed.');
    }
    if (($cert['approval_status'] ?? '') === 'rejected') {
        throw new RuntimeException('A rejected certificate cannot be changed. Raise a new one.');
    }

    $old = trk_current_values($cert);
    $new = [];
    foreach (array_keys(TRK_EDITABLE) as $k) {
        $new[$k] = trim((string)($in[$k] ?? $old[$k]));
    }
    foreach (['client_name', 'make_model', 'chassis_no', 'engine_no', 'colour', 'ownership', 'civilian_plate'] as $k) {
        $new[$k] = mb_strtoupper($new[$k], 'UTF-8');
    }
    $new['reg_no']         = plate_display($new['reg_no']);
    $new['civilian_plate'] = $new['civilian_plate'] !== '' ? plate_display($new['civilian_plate']) : '';
    $new['financier']      = trk_financier_canonical($new['financier']);

    // The same rules as raising one.
    foreach (['client_name', 'reg_no', 'make_model', 'chassis_no', 'engine_no', 'colour', 'ownership', 'imei',
              'installed_on', 'subscription_date', 'expiry_date'] as $k) {
        if ($new[$k] === '') {
            throw new RuntimeException(TRK_EDITABLE[$k] . ' cannot be blank.');
        }
    }
    if ($new['client_phone'] !== '' && !preg_match('/^[0-9+ ]+$/', $new['client_phone'])) {
        throw new RuntimeException('Contact may contain only digits, spaces and +.');
    }
    foreach (['installed_on', 'subscription_date', 'expiry_date'] as $k) {
        $t = strtotime($new[$k]);
        if ($t === false) {
            throw new RuntimeException(TRK_EDITABLE[$k] . ' is not a valid date.');
        }
        $new[$k] = date('Y-m-d', $t);
    }
    if ($new['subscription_date'] < $new['installed_on']) {
        throw new RuntimeException('The subscription cannot start before the unit was installed.');
    }
    if ($new['expiry_date'] <= $new['subscription_date']) {
        throw new RuntimeException('The expiry date must be after the subscription date.');
    }
    if (!preg_match('/^[0-9]{10,17}$/', $new['imei'])) {
        throw new RuntimeException('A tracker IMEI is 10 to 17 digits.');
    }
    if ($new['imei_2'] !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-\/]{3,31}$/', $new['imei_2'])) {
        throw new RuntimeException('The second unit number looks wrong: 4 to 32 letters, digits or hyphens.');
    }
    if ($new['imei'] === $new['imei_2']) {
        throw new RuntimeException('Both unit boxes have the same number.');
    }

    $changes = [];
    foreach ($new as $k => $v) {
        if ($v !== $old[$k]) {
            $changes[$k] = [$old[$k], $v];
        }
    }
    if (!$changes) {
        throw new RuntimeException('Nothing was changed.');
    }

    // A unit cannot be in two vehicles: a new IMEI must not be live on another certificate.
    foreach (['imei', 'imei_2'] as $k) {
        if (isset($changes[$k]) && $new[$k] !== '' && ($held = tracking_imei_in_use($new[$k], (int)$cert['id']))) {
            throw new RuntimeException('Unit ' . $new[$k] . ' is already on ' . tracking_label($held)
                . ' for ' . $held['reg_no'] . '. Record its removal there first.');
        }
    }

    // A fleet edit runs every vehicle inside ONE transaction of its own (all or none), so it
    // asks this not to open another.
    $pdo = db();
    if ($ownTransaction) { $pdo->beginTransaction(); }
    try {
        $s = json_decode((string)$cert['snapshot'], true) ?: [];
        $s['client']['name']  = $new['client_name'];
        $s['client']['phone'] = $new['client_phone'];
        foreach (['reg_no', 'make_model', 'chassis_no', 'engine_no', 'colour', 'ownership', 'financier', 'civilian_plate'] as $k) {
            $s['vehicle'][$k] = $new[$k];
        }
        $s['device']['sim_card'] = $new['sim_card'];
        foreach (['installed_on', 'subscription_date', 'expiry_date'] as $k) {
            $s['cert'][$k] = $new[$k];
            $s['cert'][$k . '_fmt'] = fmt_date($new[$k]);
        }
        $units = tracking_imei_clean([$new['imei'], $new['imei_2']]);
        $s['device']['imeis'] = $units;
        $s['device']['imei']  = tracking_imei_line($units);

        q('UPDATE tracking_certificates
              SET client_name = ?, client_phone = ?, reg_no = ?, civilian_plate = ?, make_model = ?,
                  chassis_no = ?, engine_no = ?, colour = ?, ownership = ?, financier = ?, sim_card = ?,
                  installed_on = ?, subscription_date = ?, expiry_date = ?, snapshot = ?,
                  pdf_path = NULL, pdf_sha256 = NULL
            WHERE id = ?',
          [$new['client_name'], $new['client_phone'], $new['reg_no'], $new['civilian_plate'], $new['make_model'],
           $new['chassis_no'], $new['engine_no'], $new['colour'], $new['ownership'], $new['financier'], $new['sim_card'],
           $new['installed_on'], $new['subscription_date'], $new['expiry_date'], json_encode($s), (int)$cert['id']]);
        if (isset($changes['imei']) || isset($changes['imei_2'])) {
            set_tracking_imeis((int)$cert['id'], $units);
        }

        // A released certificate whose expiry or plate moved: work out again which of the
        // vehicle's certificates is the current one, the same rule approval uses.
        if (($cert['approval_status'] ?? '') === 'approved'
            && array_intersect_key($changes, ['expiry_date' => 1, 'reg_no' => 1])) {
            foreach (array_unique([plate_key((string)$cert['reg_no']), plate_key($new['reg_no'])]) as $key) {
                q("UPDATE tracking_certificates c
                      SET status = CASE WHEN c.id = cur.id THEN 'issued' ELSE 'superseded' END
                    FROM (SELECT id FROM tracking_certificates
                           WHERE regexp_replace(upper(reg_no), '[^A-Z0-9]', '', 'g') = ?
                             AND approval_status = 'approved' AND status NOT IN ('voided', 'removed')
                           ORDER BY expiry_date DESC, number DESC LIMIT 1) cur
                    WHERE regexp_replace(upper(c.reg_no), '[^A-Z0-9]', '', 'g') = ?
                      AND c.approval_status = 'approved' AND c.status NOT IN ('voided', 'removed')",
                  [$key, $key]);
            }
        }

        audit('amend_tracking', 'tracking_certificate', (int)$cert['id'], [
            'reason'  => $reason,
            'changes' => $changes,
            'by'      => (int)$user['id'],
        ] + $auditExtra);
        if ($ownTransaction) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTransaction) { $pdo->rollBack(); }
        throw $e;
    }
    return $changes;
}

/** This certificate's corrections, newest first: when, who, why, and what changed. */
function trk_amendments(int $certId): array
{
    return q("SELECT a.created_at, a.details, u.name AS by_name
                FROM audit_log a
                LEFT JOIN users u ON a.actor_type = 'staff' AND u.id = a.actor_id
               WHERE a.action = 'amend_tracking' AND a.entity = 'tracking_certificate' AND a.entity_id = ?
               ORDER BY a.created_at DESC", [$certId])->fetchAll();
}
