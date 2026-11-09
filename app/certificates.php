<?php
/**
 * Everything to do with issuing a certificate, drawing the sheet and making the PDF.
 */
declare(strict_types=1);
require_once __DIR__ . '/clients.php';

/** Next certificate number. Locks the counter so two users can never get the same one. */
function next_certificate_number(): int
{
    $row = q("SELECT value FROM settings WHERE key = 'last_cert_number' FOR UPDATE")->fetch();
    if (!$row) {
        throw new RuntimeException("Setting 'last_cert_number' is missing. Run sql/schema.sql.");
    }
    $next = (int)$row['value'] + 1;
    q("UPDATE settings SET value = ? WHERE key = 'last_cert_number'", [(string)$next]);
    return $next;
}

/** Company details and signatory, as printed on the sheet. */
function company_details(): array
{
    return [
        'name' => setting('company_name', 'GAPTECH Solutions Ltd'),
        'name_caps' => strtoupper(setting('company_name', 'GAPTECH Solutions Ltd')),
        'tagline' => setting('tagline', 'Vehicle tracking, Fleet Management & IT Solutions'),
        'address_lines' => [setting('address_line1', ''), setting('address_line2', '')],
        'cell' => setting('cell', ''),
        'landline' => setting('landline', ''),
        'email' => setting('email', ''),
        'reg_no' => setting('reg_no', ''),
        'dealer_no' => setting('dealer_no', ''),
        'dealer_name' => setting('dealer_name', ''),
        'kebs_permit' => setting('kebs_permit', ''),
        'stamp_image' => setting('stamp_image', ''),
    ];
}

/** Public address a QR code points to. */
function verify_url(array $cert): string
{
    global $config;
    return rtrim($config['base_url'], '/') . '/verify.php?c=' . $cert['number'] . '-' . $cert['verify_token'];
}


 
function certificate_number_svg(?string $number): string
{
    // A pending certificate has no number, so the sheet says so rather than
    // showing digits that could be mistaken for a real certificate number.
    if ($number === null || $number === '') {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 100" width="300" height="100">'
            . '<text x="0" y="78" font-family="Arial, sans-serif" font-size="74" font-weight="bold"'
            . ' letter-spacing="4" fill="#9AA3B2">PENDING</text></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
 
    $x0 = 8; $x1 = 50; $y0 = 6; $ym = 50; $y1 = 94; $g = 5; $cell = 74;
    $seg = [
        'a' => [$x0 + $g, $y0, $x1 - $g, $y0], 'b' => [$x1, $y0 + $g, $x1, $ym - $g],
        'c' => [$x1, $ym + $g, $x1, $y1 - $g], 'd' => [$x0 + $g, $y1, $x1 - $g, $y1],
        'e' => [$x0, $ym + $g, $x0, $y1 - $g], 'f' => [$x0, $y0 + $g, $x0, $ym - $g],
        'g' => [$x0 + $g, $ym, $x1 - $g, $ym],
    ];
    $digits = [
        '0' => 'abcdef', '1' => '', '2' => 'abged', '3' => 'abgcd', '4' => 'fgbc',
        '5' => 'afgcd', '6' => 'afgedc', '7' => 'abc', '8' => 'abcdefg', '9' => 'abcdfg',
    ];
    $parts = '';
    $chars = str_split($number);
    foreach ($chars as $i => $ch) {
        $dx = $i * $cell;
        if ($ch === '1') {
            $mx = intdiv($x0 + $x1, 2) + 6 + $dx;
            $parts .= '<line x1="' . ($mx - 14) . '" y1="' . ($y0 + 12) . '" x2="' . $mx . '" y2="' . ($y0 + 1) . '"/>';
            $parts .= '<line x1="' . $mx . '" y1="' . ($y0 + 1) . '" x2="' . $mx . '" y2="' . $y1 . '"/>';
            continue;
        }
        foreach (str_split($digits[$ch] ?? '') as $s) {
            list($ax, $ay, $bx, $by) = $seg[$s];
            $parts .= '<line x1="' . ($ax + $dx) . '" y1="' . $ay . '" x2="' . ($bx + $dx) . '" y2="' . $by . '"/>';
        }
    }
    $width = count($chars) * $cell;
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' 100" width="' . $width
        . '" height="100"><g stroke="#1e1e1e" stroke-width="12" stroke-linecap="square">' . $parts . '</g></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/**
 * Render the sheet.
 *
 * $mode 'screen' produces a page for the browser (also used for browser printing);
 * $mode 'pdf' uses file:// paths and the zoom wkhtmltopdf needs.
 */
function render_sheet(array $cert, string $mode = 'screen'): string
{
    global $config;

    // These are declared global because the drawing helpers in sheet_helpers.php read them.
    global $d, $assets, $zoom, $qr, $certno_img, $logo, $logo_gold, $kebs, $sticker_bg, $microtext;

    $d = json_decode($cert['snapshot'], true);
    $sheetToCss = 1123 / 2338;                       // sheet units -> CSS pixels

    if ($mode === 'pdf') {
        $assets = 'file://' . str_replace('\\', '/', ROOT_DIR . '/public/assets');
        $zoom = round($sheetToCss * (float)$config['render_zoom'], 5);
    } else {
        $assets = 'assets';
        $zoom = round($sheetToCss, 5);
    }

    $qr = QrCode::dataUri(verify_url($cert));
    $certno_img = certificate_number_svg((string)$cert['number']);
    $logo = $assets . '/gap_logo.png';
    $logo_gold = $assets . '/gap_logo_gold.png';
    $kebs = $assets . '/kebs_mark.png';
    $sticker_bg = $assets . '/sticker_bg.png';
    $microtext = str_repeat('GAPTECHSOLUTIONSLTD', 12);

    ob_start();
    include APP_DIR . '/views/sheet.php';
    return (string)ob_get_clean();
}

function certificate_pdf(array $cert, bool $force = false): ?string
{
    global $config;
 
    // Nothing to render with at all: hand back null so certificate_print.php falls
    // back to the browser's print dialog rather than showing a client an error.
    if (pdf_engine() === null) {
        return null;
    }
 
    $dir = rtrim((string)$config['storage'], '/\\') . '/certificates';
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create storage folder: ' . $dir);
    }
 
    $pdfPath = $dir . '/certificate-' . $cert['number'] . '.pdf';
 
    // Cached, but checked. is_file() alone meant that once a bad PDF was stored -
    // one that rendered as "Not found.", say - it was served for ever, because
    // the renderer underneath was never reached again. pdf_file_is_sane() looks
    // at what is actually on disk, and anything wrong is made again below.
    if (!$force && !empty($cert['pdf_path']) && pdf_file_is_sane($pdfPath, 'landscape')) {
        return $pdfPath;
    }
 
    pdf_render_certificate('cert', (int)$cert['id'], $pdfPath, 'landscape');
 
    q('UPDATE certificates SET pdf_path = ?, pdf_sha256 = ? WHERE id = ?',
        [$pdfPath, hash_file('sha256', $pdfPath), $cert['id']]);
 
    return $pdfPath;
} 

/** Expiry date the certificate wording implies: twelve months from issue, less a day. */
function default_expiry(string $issueDate, int $months = 12): string
{
    $date = new DateTime($issueDate);
    $date->modify("+{$months} months")->modify('-1 day');
    return $date->format('Y-m-d');
}
/** A registration reduced to letters and digits, for matching: "kdf 745b" -> "KDF745B" */
function plate_key(string $reg): string
{
    return (string)preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($reg)));
}

/** Pull the payment reference out of a pasted M-Pesa or bank message. */
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
            // a real reference mixes letters and digits - this skips phone numbers and amounts
            if (preg_match('/[0-9]/', $m[1]) && preg_match('/[A-Z]/', $m[1])) {
                return $m[1];
            }
        }
    }
    return '';
}

/** Only an approved, current, unexpired certificate may be printed or downloaded. */
function certificate_is_releasable(array $cert): bool
{
    return certificate_status($cert) === 'Valid';
}
 
/** What a pending certificate is called before it has a number. */
function draft_ref(array $cert): string
{
    return 'DRAFT-' . str_pad((string)$cert['id'], 5, '0', STR_PAD_LEFT);
}
 
/** The number for display: the real one once approved, otherwise the draft reference. */
function certificate_label(array $cert): string
{
    return $cert['number'] !== null && $cert['number'] !== ''
        ? (string)$cert['number']
        : draft_ref($cert);
}


/**
 * Replaces approve_certificate() in app/certificates.php.
 *
 * Needs migration_lpo.sql to have been run first - it writes certificates.lpo_no.
 *
 * Three things may now be recorded, and all three are optional:
 *
 *   $ref    money received. One payment cannot approve two certificates, so this
 *           is checked against every other certificate - but only when it is
 *           given. Any number of certificates may have none.
 *
 *   $note   the payment message, pasted by whoever had it. An administrator may
 *           paste it when raising the renewal, or leave it for accounts.
 *
 *   $lpo    a local purchase order: the client has not paid, and the certificate
 *           is being issued against their promise to. NOT checked for duplicates
 *           on purpose - one LPO routinely covers a whole fleet.
 *
 * Approving is still accounts saying the certificate may go out. What changed is
 * that they can now say so on the strength of an LPO rather than a payment.
 */

/**
 * Accounts releases the certificate: it gets its number and becomes printable,
 * downloadable and verifiable.
 */
function approve_certificate(array $cert, array $user, string $ref = '',
                             string $note = '', string $lpo = ''): array
{
    $ref  = trim($ref);
    $lpo  = trim($lpo);
    $note = trim($note);

    if (($cert['approval_status'] ?? '') === 'approved') {
        throw new RuntimeException('That certificate has already been approved.');
    }

    // Something has to be recorded - but it does not have to be a reference.
    //
    // The old rule demanded a payment reference, which made it impossible to
    // release a certificate against an LPO: the client has not paid, so there is
    // no reference to type, and the one thing accounts is relying on is the LPO
    // number itself. It also blocked releasing on a pasted M-Pesa message alone,
    // where the reference is right there in the text.
    //
    // So the floor is "say what you are releasing this on", not "say it in this
    // particular field". All three blank still fails, because a release with
    // nothing recorded is accounts approving a certificate on no evidence at all.
    if ($ref === '' && $lpo === '' && $note === '') {
        throw new RuntimeException('Record the payment reference, the LPO number, or paste '
            . 'the payment message before releasing this certificate.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // One payment, one certificate - but only when a reference was recorded.
        // A blank is not a duplicate of another blank.
        if ($ref !== '') {
            $used = q('SELECT id, number FROM certificates
                       WHERE upper(payment_ref) = upper(?) AND id <> ?',
                      [$ref, $cert['id']])->fetch();
            if ($used) {
                throw new RuntimeException('That payment reference was already used on certificate '
                    . ($used['number'] ?: 'draft ' . $used['id']) . '.');
            }
        }

        $number = next_certificate_number();

        // the number is printed on the sheet, so it goes into the snapshot too
        $snapshot = json_decode($cert['snapshot'], true);
        $snapshot['cert']['number'] = (string)$number;

        q("UPDATE certificates
              SET number = ?, snapshot = ?, approval_status = 'approved', status = 'issued',
                  payment_ref = ?, payment_note = ?, lpo_no = ?,
                  approved_by = ?, approved_at = now(),
                  rejection_reason = NULL, pdf_path = NULL, pdf_sha256 = NULL
            WHERE id = ?",
            [$number, json_encode($snapshot), $ref, $note, $lpo, $user['id'], $cert['id']]);

        // Work out which of the vehicle's certificates is current TODAY, and
        // retire the rest.
        //
        // Both halves say issue_date <= current_date, and that is the whole of
        // the early-renewal change:
        //
        //   the subquery  - a certificate paid for early must not be taken as the
        //                   current one merely because its date is the latest
        //   the outer one - and it must not be retired either; it is waiting for
        //                   its start date, by which time the old one has expired
        //                   on its own
        q("UPDATE certificates c
              SET status = CASE WHEN c.id = cur.id THEN 'issued' ELSE 'superseded' END
            FROM (SELECT id FROM certificates
                   WHERE vehicle_id = ? AND approval_status = 'approved'
                     AND status <> 'voided' AND issue_date <= current_date
                   ORDER BY issue_date DESC, number DESC LIMIT 1) cur
            WHERE c.vehicle_id = ? AND c.approval_status = 'approved'
              AND c.status <> 'voided' AND c.issue_date <= current_date",
            [$cert['vehicle_id'], $cert['vehicle_id']]);

        $pdo->commit();
    } catch (Throwable $err) {
        $pdo->rollBack();
        throw $err;
    }

    audit('approve_certificate', 'certificate', (int)$cert['id'], [
        'number'       => $number,
        'payment_ref'  => $ref,
        'lpo_no'       => $lpo,
        'on_credit'    => $ref === '' && $lpo !== '',
        'confirmed_by' => (int)$user['id'],
    ]);

    return q('SELECT * FROM certificates WHERE id = ?', [$cert['id']])->fetch();
}

function reject_certificate(array $cert, array $user, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('Give a reason for rejecting it.');
    }
    if (($cert['approval_status'] ?? '') === 'approved') {
        throw new RuntimeException('That certificate is already approved. Void it instead.');
    }
 
    q("UPDATE certificates SET approval_status = 'rejected', rejection_reason = ?,
              approved_by = ?, approved_at = now() WHERE id = ?",
        [$reason, $user['id'], $cert['id']]);
 
    audit('reject_certificate', 'certificate', (int)$cert['id'], ['reason' => $reason]);
 
    return q('SELECT * FROM certificates WHERE id = ?', [$cert['id']])->fetch();
}


function certificate_current_for_plate(string $reg): ?array
{
    $row = q("SELECT c.*
              FROM certificates c
              JOIN vehicles v ON v.id = c.vehicle_id
              WHERE regexp_replace(upper(v.reg_no), '[^A-Z0-9]', '', 'g')
                    = regexp_replace(upper(?), '[^A-Z0-9]', '', 'g')
                AND c.approval_status = 'approved'
                AND c.status = 'issued'
                AND c.issue_date <= current_date
              ORDER BY c.issue_date DESC, c.number DESC
              LIMIT 1", [$reg])->fetch();

    return $row ?: null;
}

/**
 * Withdraw a certificate.
 *
 * Voiding is for a certificate that should never have gone out, or whose vehicle
 * has had the governor removed. It is NOT how a certificate is replaced - a
 * renewal supersedes the old one on its own.
 *
 * The row is kept. Verification still finds it and says it was withdrawn, which
 * is the whole point: a voided certificate may be in somebody's glovebox, and an
 * officer checking it deserves an answer rather than "not found".
 */
function void_certificate(array $cert, array $user, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('Say why the certificate is being withdrawn.');
    }
    if (($cert['status'] ?? '') === 'voided') {
        throw new RuntimeException('That certificate has already been withdrawn.');
    }

    q("UPDATE certificates SET status = 'voided', void_reason = ? WHERE id = ?",
      [$reason, $cert['id']]);

    audit('void_certificate', 'certificate', (int)$cert['id'], [
        'number' => $cert['number'],
        'reason' => $reason,
        'by'     => (int)$user['id'],
    ]);

    return q('SELECT * FROM certificates WHERE id = ?', [$cert['id']])->fetch();
}
function renewal_start_date(string $currentExpiry, ?string $today = null): string
{
    $today = $today ?: date('Y-m-d');

    if (strtotime($currentExpiry) >= strtotime($today)) {
        return date('Y-m-d', strtotime($currentExpiry . ' +1 day'));
    }

    return $today;
}


/**
 * Three functions to ADD to the end of app/certificates.php.
 *
 * Paste everything below the line, inside the file, after the last existing
 * function. Nothing in the file changes.
 *
 * They are the governor twin of what app/tracking.php already has for trackers:
 * tracking_imei_in_use(), remove_tracking_unit() and tracking_device_history().
 * Same rules, same words, same reasoning - because a sealed governor and a
 * tracker are the same kind of thing. One box, one lorry, and a record of when
 * it moved.
 *
 * Run sql/migration_governor_removal.sql first: these write a 'removed' status
 * the certificates table will otherwise refuse.
 */

// ===========================================================================
// PASTE FROM HERE
// ===========================================================================

/**
 * The certificate that currently holds this governor serial, or null.
 *
 * Read the row's approval_status to tell the two cases apart - they need
 * different words, not different rules:
 *
 *   approved + issued   the governor is in that lorry now
 *   pending             a certificate awaiting accounts has already claimed it
 *
 * ---------------------------------------------------------------------------
 * Pending certificates count, and that is the whole point
 * ---------------------------------------------------------------------------
 * A certificate sitting with accounts is a CLAIM on that box. If a second
 * certificate is raised for another lorry with the same serial while the first
 * is waiting, both pass the check, both reach accounts, and both get approved -
 * two lorries certified as holding one sealed governor, which is exactly what
 * this exists to prevent. The window is however long accounts take, so days.
 *
 * Rejected certificates are excluded: that claim was turned down. Removed,
 * voided and superseded are excluded too - the box has left, the certificate was
 * an error, or it is a renewal of the same lorry where the governor never moved.
 *
 * "Approved" deliberately does NOT mean unexpired. An expired certificate whose
 * governor was never removed still means the box is in that lorry.
 *
 * ---------------------------------------------------------------------------
 * $exceptReg, and why leaving it out would break every renewal
 * ---------------------------------------------------------------------------
 * A governor certificate is renewed on this same form, with type = Renewal, and
 * a renewal has the SAME box in the SAME lorry - that is what makes it a
 * renewal. So the lorry's own current certificate always holds that serial, and
 * a check that did not know which lorry was being certified would refuse every
 * renewal GAPTECH ever raises.
 *
 * Pass the registration being certified and its own certificates stop counting.
 * Matched on letters and digits only, the same rule the rest of this system
 * uses, so KDK017N and KDK 017N are one lorry.
 */
function governor_serial_in_use(string $serial, ?int $exceptCertId = null,
                                ?string $exceptReg = null): ?array
{
    $serial = trim($serial);
    if ($serial === '') {
        return null;
    }

    $reg = trim((string)$exceptReg);

    $row = q("SELECT c.*, v.reg_no, cl.name AS client_name, d.serial_no
                FROM certificates c
                JOIN devices  d  ON d.id  = c.device_id
                JOIN vehicles v  ON v.id  = c.vehicle_id
                LEFT JOIN clients cl ON cl.id = c.client_id
               WHERE upper(trim(d.serial_no)) = upper(?)
                 AND c.approval_status IN ('approved', 'pending')
                 AND c.status = 'issued'
                 -- cast needed: PostgreSQL cannot infer the type of a parameter
                 -- that only ever appears in an IS NULL test
                 AND (?::int IS NULL OR c.id <> ?::int)
                 -- the lorry being certified does not clash with itself
                 AND (? = '' OR regexp_replace(upper(v.reg_no), '[^A-Z0-9]', '', 'g')
                             <> regexp_replace(upper(?), '[^A-Z0-9]', '', 'g'))
               -- an approved claim outranks a pending one, so the message names
               -- the lorry the box is actually in when both exist
               ORDER BY (c.approval_status = 'approved') DESC,
                        c.issue_date DESC, c.id DESC
               LIMIT 1", [$serial, $exceptCertId, $exceptCertId, $reg, $reg])->fetch();

    return $row ?: null;
}

/**
 * Record that the governor has been taken off this lorry.
 *
 * The certificate is kept, with the date and the reason. The lorry stops being
 * covered from that moment, because there is no longer a sealed box in it.
 *
 * TWO things are closed, and both are needed:
 *
 *   the certificate      what the customer holds, and what the registers,
 *                        the dashboard and the renewal lists read
 *   the installation row what the NEXT certificate for this serial is checked
 *                        against - create_certificate() looks for an
 *                        installation "WHERE ... AND removed_on IS NULL"
 *
 * Closing only the first would leave the serial looking fitted to the old lorry
 * forever. Closing only the second would leave a lorry reading "covered" with no
 * governor in it.
 */
function remove_governor_unit(array $cert, array $user, string $reason, string $when = ''): array
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('Say why the governor is being removed.');
    }

    if (($cert['approval_status'] ?? '') !== 'approved') {
        throw new RuntimeException('Only a released certificate can have its governor '
            . 'removed. A pending one should be sent back instead.');
    }
    if (($cert['status'] ?? '') === 'removed') {
        throw new RuntimeException('That governor is already recorded as removed.');
    }
    if (in_array($cert['status'] ?? '', ['voided', 'superseded'], true)) {
        throw new RuntimeException('That certificate is already ' . $cert['status']
            . ', so there is nothing to remove from it.');
    }

    $when = trim($when) !== '' ? $when : date('Y-m-d');
    if (strtotime($when) === false) {
        throw new RuntimeException('That removal date is not a date.');
    }

    // The fitting date comes from the installation, not from the certificate -
    // a renewal reuses the installation, so the certificate's issue date can be
    // years after the box went on.
    $fitted = q('SELECT installed_on FROM installations WHERE id = ?',
                [(int)$cert['installation_id']])->fetchColumn();

    if ($fitted && strtotime($when) < strtotime((string)$fitted)) {
        throw new RuntimeException('The governor cannot be removed before it was fitted ('
            . fmt_date((string)$fitted) . ').');
    }
    if (strtotime($when) > strtotime(date('Y-m-d'))) {
        throw new RuntimeException('A removal cannot be dated in the future.');
    }

    $day = date('Y-m-d', strtotime($when));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q("UPDATE certificates
              SET status = 'removed', removed_on = ?, removed_reason = ?, removed_by = ?
            WHERE id = ?", [$day, $reason, (int)$user['id'], (int)$cert['id']]);

        // The other half. Guarded by removed_on IS NULL so running this twice
        // cannot move a date that was already recorded by somebody else.
        q("UPDATE installations
              SET removed_on = ?
            WHERE id = ? AND removed_on IS NULL", [$day, (int)$cert['installation_id']]);

        $pdo->commit();
    } catch (Throwable $err) {
        $pdo->rollBack();
        throw $err;
    }

    audit('remove_governor_unit', 'certificate', (int)$cert['id'], [
        'reg_no'      => $cert['reg_no'] ?? '',
        'serial_no'   => $cert['serial_no'] ?? '',
        'removed_on'  => $day,
        'reason'      => $reason,
        'cover_ended' => $cert['expiry_date'] ?? '',   // what the customer gave up
    ]);

    return q('SELECT * FROM certificates WHERE id = ?', [(int)$cert['id']])->fetch();
}

/**
 * Everywhere this governor has been, newest first.
 *
 * The question anybody holding a box in their hand asks. Renewals of the same
 * lorry are included - they are part of the unit's life even though it did not
 * move - so the caller can show them or fold them up as it likes.
 */
function governor_device_history(string $serial): array
{
    $serial = trim($serial);
    if ($serial === '') {
        return [];
    }

    return q("SELECT c.id, c.number, c.status, c.approval_status, c.issue_date,
                     c.expiry_date, c.removed_on, c.removed_reason,
                     v.reg_no, cl.name AS client_name, i.installed_on
                FROM certificates c
                JOIN devices  d ON d.id = c.device_id
                JOIN vehicles v ON v.id = c.vehicle_id
                LEFT JOIN clients       cl ON cl.id = c.client_id
                LEFT JOIN installations i  ON i.id  = c.installation_id
               WHERE upper(trim(d.serial_no)) = upper(?)
               ORDER BY c.issue_date DESC, c.id DESC", [$serial])->fetchAll();
}


/**
 * Two functions to REPLACE in app/certificates.php, and three small changes
 * elsewhere. All of it is one bug.
 *
 * ---------------------------------------------------------------------------
 * What went wrong
 * ---------------------------------------------------------------------------
 * The last change added a new value to certificates.status - 'removed' - and
 * taught the database to store it, but did not teach the code that READS it.
 *
 * certificate_status() knows about voided, superseded, scheduled and expired.
 * Anything else falls through to the last line and comes back "Valid". So the
 * removal was recorded correctly and the lorry still showed a valid
 * certificate: 13770 (KDY 3456E) and 13771 (KDJ466X) both reading Valid, both
 * on serial 39493489, which is exactly the double-booking the check exists to
 * prevent.
 *
 * The tracking side already handles this - tracking_status() has returned
 * 'Unit removed' since the unit-removal work. The governor twin was not
 * updated to match. That is my miss, and this is it put right.
 *
 * ---------------------------------------------------------------------------
 * Before you change anything: what does 13770 actually say?
 * ---------------------------------------------------------------------------
 * This tells you whether the removal was recorded and only displayed wrong, or
 * never recorded at all. The two have different fixes:
 *
 *   SELECT c.number, c.status, c.removed_on, c.removed_reason,
 *          v.reg_no, d.serial_no, i.removed_on AS fitting_closed
 *     FROM certificates c
 *     JOIN vehicles v ON v.id = c.vehicle_id
 *     JOIN devices  d ON d.id = c.device_id
 *     LEFT JOIN installations i ON i.id = c.installation_id
 *    WHERE c.number IN (13770, 13771);
 *
 *   status = 'removed'  -> it worked; these files fix the display
 *   status = 'issued'   -> the tick never took effect. Send me this output and
 *                          do not apply the SQL at the bottom.
 */

// ===========================================================================
// 1. app/certificates.php - REPLACE both of these functions
// ===========================================================================

/**
 * The state of a certificate, as one word.
 *
 * The order matters: a certificate can be more than one of these at once, and
 * the first match wins. Removed is tested before expiry because a governor that
 * came off in March is not "expired" in April - it is gone, and saying expired
 * invites somebody to renew it.
 */
function certificate_status(array $cert): string
{
    if ($cert['status'] === 'voided') {
        return 'Voided';
    }
    // Added. Without this line a removed certificate falls through to the last
    // line of this function and comes back "Valid", which is how one serial
    // ended up reading valid on two lorries at once.
    if (($cert['status'] ?? '') === 'removed') {
        return 'Unit removed';
    }
    if ($cert['status'] === 'superseded') {
        return 'Superseded';
    }
    // Paid for and numbered, but its cover has not begun. Not downloadable until
    // it does - the certificate in force today is the one before it.
    if (strtotime($cert['issue_date']) > strtotime(date('Y-m-d'))) {
        return 'Scheduled';
    }
    return strtotime($cert['expiry_date']) < strtotime(date('Y-m-d')) ? 'Expired' : 'Valid';
}

/** The same state, written for a human. */
function certificate_status_label(array $cert): string
{
    $status = certificate_status($cert);

    if ($status === 'Scheduled') {
        return 'Starts ' . fmt_date((string)$cert['issue_date']);
    }
    // "Unit removed 9 November 2026" answers the next question in the same
    // breath, the way the tracking label already does.
    if ($status === 'Unit removed' && !empty($cert['removed_on'])) {
        return 'Unit removed ' . fmt_date((string)$cert['removed_on']);
    }

    return $status;
}

/*
 ===========================================================================
 2. public/certificate_view.php - ONE line
 ===========================================================================

 Find:

     $superseded = ($cert['status'] ?? '') === 'superseded';
     $inForce    = $approved && !$voided && !$superseded && !$replacement;

 Replace with:

     $superseded = ($cert['status'] ?? '') === 'superseded';
     $unitGone   = ($cert['status'] ?? '') === 'removed';
     $inForce    = $approved && !$voided && !$superseded && !$replacement && !$unitGone;

 Without it the removed certificate keeps its Renew and Withdraw buttons and
 stays printable - a certificate offering to renew cover for a box that is now
 in somebody else's lorry.

 ===========================================================================
 3. public/dashboard.php - ONE line
 ===========================================================================

 Find (the governor half of the cover query):

     WHERE c.approval_status = 'approved' AND c.status <> 'voided'

 Replace with:

     WHERE c.approval_status = 'approved' AND c.status NOT IN ('voided', 'removed')

 The tracking half of the same query already reads NOT IN ('voided', 'removed').
 This makes the governor half agree with it, so a lorry whose governor has been
 taken off stops being counted as covered.

 ===========================================================================
 4. public/index.php - the register, so removed certificates are findable
 ===========================================================================

 The Valid and Expired tabs are already correct: both test status = 'issued',
 so a removed certificate drops out of them on its own. But with no tab of its
 own it can only be found under All. Three small edits:

 (a) Find:

     if (!in_array($status, ['all', 'pending', 'valid', 'expired', 'superseded', 'voided', 'rejected'], true)) {

     Add 'removed':

     if (!in_array($status, ['all', 'pending', 'valid', 'expired', 'superseded', 'removed', 'voided', 'rejected'], true)) {

 (b) Find:

     } elseif ($status === 'superseded') {
         $where[] = "c.status = 'superseded'";

     Add after that block:

     } elseif ($status === 'removed') {
         $where[] = "c.status = 'removed'";

 (c) Find:

     count(*) FILTER (WHERE status = 'superseded') AS superseded_count,

     Add after it:

     count(*) FILTER (WHERE status = 'removed') AS removed_count,

     and find:

     'superseded' => ['Superseded', $counts['superseded_count']],

     Add after it:

     'removed' => ['Unit removed', $counts['removed_count']],
*/


/**
 * Two functions to ADD to the end of app/certificates.php.
 *
 * Paste everything below the line, inside the file, after the last existing
 * function. Nothing already in the file changes.
 *
 * They answer the two questions the governor form could not ask before:
 *
 *   plate_on_file()    what is this registration already, and is it covered?
 *   chassis_on_file()  is this chassis number already a DIFFERENT lorry?
 *
 * ---------------------------------------------------------------------------
 * Both normalise, and both normalise the same way
 * ---------------------------------------------------------------------------
 * Letters and digits only, in capitals - the rule reg_key already uses, so
 * KDY3456E finds KDY 3456E and MXAA542003017 finds MXAA54-2003017.
 *
 * A check that only matches an exact string is worse than no check: it passes
 * silently on the one case it exists to catch, because the second person to
 * type a number rarely punctuates it the way the first did.
 */

// ===========================================================================
// PASTE FROM HERE
// ===========================================================================

/**
 * What this registration already is, and whether it is covered right now.
 *
 * Returns the vehicle with its owner and its certificate in force, or null if
 * the plate is new. 'cert_number' is null when the lorry is on file but has no
 * certificate in force - a lapsed vehicle coming back, which is the ordinary
 * way a renewal starts.
 *
 * Used to tell somebody three things while they type: whose lorry this is, what
 * chassis is on file for it, and whether raising a Fitting is about to replace
 * cover that is still running.
 */
function plate_on_file(string $reg): ?array
{
    $reg = trim($reg);
    if ($reg === '') {
        return null;
    }

    $row = q("SELECT v.id, v.reg_no, v.make, v.chassis_no,
                     cl.name AS client_name, cl.phone AS client_phone,
                     cur.number     AS cert_number,
                     cur.id         AS cert_id,
                     cur.expiry_date,
                     cur.status     AS cert_status
                FROM vehicles v
                LEFT JOIN clients cl ON cl.id = v.client_id
                LEFT JOIN LATERAL (
                     SELECT c.id, c.number, c.expiry_date, c.status
                       FROM certificates c
                      WHERE c.vehicle_id = v.id
                        AND c.approval_status = 'approved'
                        AND c.status = 'issued'
                        AND c.issue_date <= current_date
                      ORDER BY c.issue_date DESC, c.number DESC
                      LIMIT 1
                ) cur ON true
               WHERE regexp_replace(upper(v.reg_no), '[^A-Z0-9]', '', 'g')
                   = regexp_replace(upper(?),        '[^A-Z0-9]', '', 'g')
               LIMIT 1", [$reg])->fetch();

    return $row ?: null;
}

/**
 * The governor currently fitted to this lorry, or null.
 *
 * An installation row with no removed_on: the box is on the vehicle as far as
 * this system knows. Returns the device with it, so the caller can compare the
 * serial about to be certified against the one already there.
 *
 * ---------------------------------------------------------------------------
 * Why this is needed at all
 * ---------------------------------------------------------------------------
 * A lorry gets a different governor when the old one fails. Nothing in
 * create_certificate() notices: it looks for an installation of THIS vehicle
 * with THIS device, finds none, and creates a new row. The faulty unit's row is
 * left open forever, so the lorry ends up with two governors recorded as fitted
 * and no trace of why the first one was swapped.
 *
 * ORDER BY installed_on DESC is deliberate. If stale rows already exist from
 * before this check, the newest is the one actually on the vehicle.
 */
function vehicle_current_unit(string $reg): ?array
{
    $reg = trim($reg);
    if ($reg === '') {
        return null;
    }

    $row = q("SELECT i.id AS installation_id, i.installed_on, i.technician,
                     d.id AS device_id, d.serial_no, d.model,
                     v.id AS vehicle_id, v.reg_no
                FROM installations i
                JOIN vehicles v ON v.id = i.vehicle_id
                JOIN devices  d ON d.id = i.device_id
               WHERE i.removed_on IS NULL
                 AND regexp_replace(upper(v.reg_no), '[^A-Z0-9]', '', 'g')
                   = regexp_replace(upper(?),        '[^A-Z0-9]', '', 'g')
               ORDER BY i.installed_on DESC, i.id DESC
               LIMIT 1", [$reg])->fetch();

    return $row ?: null;
}

/**
 * Record that a lorry's governor was swapped for a different one.
 *
 * Closes the OLD installation row, which is the only thing that says the faulty
 * box came off. The old certificate is not touched here: create_certificate()
 * supersedes it a moment later, which is the right relationship - this lorry has
 * newer cover - and 'removed' would wrongly suggest the lorry is uncovered.
 *
 * The reason lives in the audit log rather than on a row, because there is no
 * column on an installation for it and inventing one to hold a sentence nobody
 * prints would be the wrong trade.
 */
function swap_governor_unit(array $current, array $user, string $reason,
                            string $when, string $newSerial): void
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('Say why the governor is being replaced.');
    }

    $when = trim($when) !== '' ? $when : date('Y-m-d');
    if (strtotime($when) === false) {
        throw new RuntimeException('That installation date is not a date.');
    }
    if (strtotime($when) < strtotime((string)$current['installed_on'])) {
        throw new RuntimeException('The new governor cannot be fitted before the old one was ('
            . fmt_date((string)$current['installed_on']) . ').');
    }

    $day = date('Y-m-d', strtotime($when));

    // Guarded by removed_on IS NULL so running this twice cannot move a date
    // somebody else already recorded.
    q("UPDATE installations SET removed_on = ? WHERE id = ? AND removed_on IS NULL",
      [$day, (int)$current['installation_id']]);

    audit('swap_governor_unit', 'vehicle', (int)$current['vehicle_id'], [
        'reg_no'     => $current['reg_no'],
        'old_serial' => $current['serial_no'],
        'new_serial' => $newSerial,
        'fitted_on'  => $current['installed_on'],
        'removed_on' => $day,
        'reason'     => $reason,
    ]);
}

/**
 * The OTHER lorry already carrying this chassis number, or null.
 *
 * A chassis number is the one thing about a lorry that cannot change, so the
 * same chassis under two registrations is one of two things:
 *
 *   a typo              somebody keyed a digit wrong, and a certificate is
 *                       about to be issued against the wrong vehicle
 *   a re-plate          the lorry really did get a new number plate, which
 *                       happens, and is the case the override tick is for
 *
 * The registration being certified is passed in and excluded, so a lorry never
 * clashes with itself - without that, every renewal would report its own
 * chassis as a duplicate.
 */
function chassis_on_file(string $chassis, ?string $exceptReg = null): ?array
{
    $chassis = trim($chassis);
    if ($chassis === '') {
        return null;
    }

    // A chassis of one or two characters is somebody halfway through typing,
    // not a number. Matching on it would flag half the fleet.
    if (strlen(preg_replace('/[^A-Za-z0-9]/', '', $chassis)) < 4) {
        return null;
    }

    $reg = trim((string)$exceptReg);

    $row = q("SELECT v.id, v.reg_no, v.make, v.chassis_no,
                     cl.name AS client_name
                FROM vehicles v
                LEFT JOIN clients cl ON cl.id = v.client_id
               WHERE regexp_replace(upper(v.chassis_no), '[^A-Z0-9]', '', 'g')
                   = regexp_replace(upper(?),            '[^A-Z0-9]', '', 'g')
                 AND (? = '' OR regexp_replace(upper(v.reg_no), '[^A-Z0-9]', '', 'g')
                             <> regexp_replace(upper(?),        '[^A-Z0-9]', '', 'g'))
               ORDER BY v.id
               LIMIT 1", [$chassis, $reg, $reg])->fetch();

    return $row ?: null;
}


/**
 * The whole of create_certificate(), with both outstanding changes already made.
 *
 * In app/certificates.php: find the line
 *
 *     function create_certificate(array $in, array $user): array
 *
 * and replace everything from there down to its closing brace - the line with a
 * single } just before the next function - with the function below.
 *
 * ALSO add this near the top of app/certificates.php, with the other requires:
 *
 *     require_once __DIR__ . '/clients.php';
 *
 * ---------------------------------------------------------------------------
 * The four changes, each marked CHANGED in the body
 * ---------------------------------------------------------------------------
 *
 * 1. The client is found with client_match() instead of
 *
 *        WHERE lower(name) = lower(?) OR (phone <> '' AND phone = ?)
 *
 *    That phone test was exact string equality, and the phones in this system are
 *    stored with spaces. Typing 0702118004 where the file says "0702 118 004"
 *    matched nothing and created a SECOND client - vehicles on one row, portal
 *    login on the other, and a client signing in to an empty portal.
 *
 *    client_match() compares the normalised number first and the name with case
 *    and punctuation ignored, so the import, the New client form and this
 *    function all decide "same client" the same way. Two rules in two places is
 *    how the duplicate gets in.
 *
 * 2. The vehicle is found with vehicle_match() instead of
 *
 *        WHERE upper(reg_no) = upper(?)
 *
 *    The same bug, one table over. Every registration on file is stored with a
 *    space - "KDA 107G" - so "KDA107G" typed on the form matched nothing and
 *    created a second vehicle for the same lorry. Worse than a duplicate client:
 *    the supersede at the end of this function retires earlier certificates for
 *    THIS vehicle_id, so the split quietly leaves two live certificates on one
 *    lorry.
 *
 * 3. The installation date in the snapshot comes from the installation ROW that
 *    was actually used, not from whatever the caller passed.
 *
 *    A renewal reuses the existing installation, so $in['installed_on'] is
 *    ignored when the row already exists - but the snapshot went on printing $in
 *    regardless. The certificate could say one fitting date while the database
 *    held another, and the certificate is the thing in the glovebox.
 *
 *    A first fitting is unaffected: the row was just created from these very
 *    values, so reading them back changes nothing.
 *
 * 4. The old certificate is no longer retired when a new one is RAISED. It is
 *    retired when the new one is APPROVED, which is when it becomes true.
 *
 *    Every certificate starts pending, so the old rule took a lorry's valid
 *    certificate away the moment somebody began a renewal: no cover on the
 *    register, none on the dashboard, and the customer's portal telling them
 *    they had none - for as long as accounts took. If the new one was then
 *    rejected, nothing ever put the old one back.
 *
 * Nothing else in the function has moved.
 */

/**
 * Raise a certificate.
 *
 * Client, vehicle, device and installation are created if they are new and reused if they
 * already exist, so staff only ever fill in one form. Everything printed on the sheet is
 * copied into the snapshot, so a reprint years later shows exactly what was issued.
 */
function create_certificate(array $in, array $user): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // ---------------------------------------------------------- CHANGED (1)
        // One matcher, shared with the CSV import and the New client form:
        // normalised phone first, then the name with case and punctuation ignored.
        $client = client_match($in['client_name'], $in['client_phone']);

        if ($client) {
            q('UPDATE clients SET name = ?, phone = ?, address = ?, updated_at = now() WHERE id = ?',
                [$in['client_name'], $in['client_phone'], $in['client_address'], $client['id']]);
            $clientId = (int)$client['id'];
        } else {
            $clientId = (int)q('INSERT INTO clients (name, phone, address) VALUES (?, ?, ?) RETURNING id',
                [$in['client_name'], $in['client_phone'], $in['client_address']])->fetchColumn();
        }

        // ---------------------------------------------------------- CHANGED (2)
        // The vehicle, found the same way everywhere: letters and digits only.
        //
        // This used to be upper(reg_no) = upper(?), and every registration on file
        // is stored with a space - "KDA 107G". So typing "KDA107G" on this form
        // matched nothing and created a SECOND vehicle for the same lorry. The
        // certificates then split across two rows, the fleet count goes wrong, and
        // the supersede at the bottom of this function - which retires earlier
        // certificates for THIS vehicle_id - stops seeing the other half.
        $vehicle = vehicle_match($in['reg_no']);
        if ($vehicle) {
            q('UPDATE vehicles SET client_id = ?, make = ?, chassis_no = ? WHERE id = ?',
                [$clientId, $in['make'], $in['chassis_no'], $vehicle['id']]);
            $vehicleId = (int)$vehicle['id'];
        } else {
            // plate_display() so a solid "kda107g" is stored "KDA 107G", the way
            // every other row in this table is written.
            $vehicleId = (int)q('INSERT INTO vehicles (client_id, reg_no, make, chassis_no)
                                 VALUES (?, ?, ?, ?) RETURNING id',
                [$clientId, plate_display($in['reg_no']), $in['make'], $in['chassis_no']])
                ->fetchColumn();
        }

        // device (serial number is unique)
        $device = q('SELECT * FROM devices WHERE serial_no = ?', [$in['serial_no']])->fetch();
        if ($device) {
            q('UPDATE devices SET model = ?, set_speed_kmh = ? WHERE id = ?',
                [$in['device_model'], (int)$in['set_speed_kmh'], $device['id']]);
            $deviceId = (int)$device['id'];
        } else {
            $deviceId = (int)q('INSERT INTO devices (model, serial_no, set_speed_kmh)
                                VALUES (?, ?, ?) RETURNING id',
                [$in['device_model'], $in['serial_no'], (int)$in['set_speed_kmh']])->fetchColumn();
        }

        // installation
        $installation = q('SELECT * FROM installations WHERE vehicle_id = ? AND device_id = ? AND removed_on IS NULL',
            [$vehicleId, $deviceId])->fetch();
        if ($installation) {
            $installationId = (int)$installation['id'];
        } else {
            $installationId = (int)q('INSERT INTO installations (vehicle_id, device_id, technician, installed_on)
                                      VALUES (?, ?, ?, ?) RETURNING id',
                [$vehicleId, $deviceId, $in['technician'], $in['installed_on']])->fetchColumn();
        }

        // ---------------------------------------------------------- CHANGED (3)
        // Whatever row we ended up with IS the record of when this unit was fitted
        // to this vehicle. A renewal reuses it, which means $in['installed_on'] was
        // ignored just above - so the snapshot must not go and print $in anyway,
        // or the certificate and the database disagree about a date the
        // certificate is the evidence for.
        $fitted = q('SELECT installed_on, technician FROM installations WHERE id = ?',
                    [$installationId])->fetch() ?: [];

        if (!empty($fitted['installed_on'])) {
            $in['installed_on'] = $fitted['installed_on'];
        }
        if (!empty($fitted['technician'])) {
            $in['technician'] = $fitted['technician'];
        }

        $number = next_certificate_number();
        $token = bin2hex(random_bytes(4));
        $snapshot = [
            'company' => company_details(),
            'signatory' => [
                'name' => setting('signatory_name', ''),
                'title' => setting('signatory_title', ''),
                'signature_image' => setting('signature_image', ''),
            ],
            'cert' => [
                'number' => (string)$number,
                'type' => $in['type'],
                'is_renewal' => $in['type'] === 'Renewal',
                'issue_date_fmt' => fmt_date($in['issue_date']),
                'expiry_date_fmt' => fmt_date($in['expiry_date']),
                'validity_months' => (int)$in['validity_months'],
            ],
            'client' => [
                'name' => $in['client_name'],
                'phone' => $in['client_phone'],
                'address' => $in['client_address'],
            ],
            'vehicle' => [
                'reg_no' => strtoupper($in['reg_no']),
                'make' => $in['make'],
                'chassis_no' => $in['chassis_no'],
            ],
            'device' => [
                'model' => $in['device_model'],
                'serial_no' => $in['serial_no'],
                // the cards print this; the separate unit code was dropped, so it is the serial number
                'unit_code' => $in['serial_no'],
                'set_speed_kmh' => (int)$in['set_speed_kmh'],
            ],
            'installation' => [
                'installed_on_fmt' => fmt_date($in['installed_on']),
                'technician' => $in['technician'],
            ],
        ];

        $id = (int)q('INSERT INTO certificates
              (number, type, client_id, vehicle_id, device_id, installation_id, issue_date, expiry_date,
               receipt_ref, snapshot, verify_token, issued_by)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id', [
            $number, $in['type'], $clientId, $vehicleId, $deviceId, $installationId,
            $in['issue_date'], $in['expiry_date'], $in['receipt_ref'] ?? '',
            json_encode($snapshot), $token, $user['id'],
        ])->fetchColumn();

        // ---------------------------------------------------------- CHANGED (4)
        // The old certificate is NOT retired here any more.
        //
        // This used to run, unconditionally, the moment a certificate was raised:
        //
        //     UPDATE certificates SET status = 'superseded'
        //      WHERE vehicle_id = ? AND id <> ? AND status = 'issued'
        //
        // Every certificate starts pending - accounts have not seen it yet - so
        // raising one took the lorry's VALID certificate away immediately. For
        // however long approval takes, the register showed no certificate in
        // force, the dashboard stopped counting the lorry as covered, and the
        // customer's portal told them they had none.
        //
        // Worse if the new one is rejected: nothing ever runs to put the old one
        // back, so the lorry loses a certificate it still holds, silently and
        // permanently.
        //
        // approve_certificate() already does this properly, at the moment it
        // becomes true, and date-aware so an early renewal does not retire cover
        // that is still running. One place deciding it is the point.


        $pdo->commit();
    } catch (Throwable $err) {
        $pdo->rollBack();
        throw $err;
    }

    audit('issue_certificate', 'certificate', $id, ['number' => $number]);
    return q('SELECT * FROM certificates WHERE id = ?', [$id])->fetch();
}