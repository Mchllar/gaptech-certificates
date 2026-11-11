<?php
/**
 * app/views/mode_switch.php
 *
 * One vehicle or a fleet, as two settings of the same thing.
 *
 * Staff do not decide "single or bulk" before they start - they start typing a
 * customer and then realise. So the two forms are one entry point with a switch
 * at the top, and whatever has been typed follows across. Switching costs
 * nothing, which is the only reason anybody will use the right one.
 *
 * It also speaks up: when the customer being typed already has several vehicles
 * on file, it says so and offers the upload. That is the moment the information
 * is useful - not buried in a menu somebody has to know about first.
 *
 * Save as app/views/mode_switch.php.
 */

/**
 * Carry the customer across the switch, so nothing is typed twice.
 *
 * Only fills fields still empty: a posted form always wins over the query string.
 */
function carry_client(array $form): array
{
    foreach (['client_name', 'client_phone', 'client_address'] as $k) {
        if (($form[$k] ?? '') === '' && isset($_GET[$k])) {
            $form[$k] = trim((string)$_GET[$k]);
        }
    }
    return $form;
}

/**
 * The switch itself.
 *
 * $here is 'single' or 'bulk'. $form supplies the customer to carry over.
 */
function mode_switch(string $here, array $form = []): void
{
    $carry = http_build_query(array_filter([
        'client_name'    => $form['client_name']    ?? '',
        'client_phone'   => $form['client_phone']   ?? '',
        'client_address' => $form['client_address'] ?? '',
    ], fn($v) => trim((string)$v) !== ''));

    $tabs = [
        'single' => ['certificate_new.php', 'One vehicle',   'A lorry at a time, with every check as you type.'],
        'bulk'   => ['batch_new.php',       'A whole fleet', 'A spreadsheet of vehicles, checked and raised in one go.'],
    ];

    echo '<div class="mode-switch" style="display:flex; gap:8px; margin:0 0 6px; flex-wrap:wrap">';
    foreach ($tabs as $key => [$page, $label, $blurb]) {
        $on   = $key === $here;
        $href = $page . ($carry ? '?' . $carry : '');
        $style = 'display:block; padding:10px 18px; border-radius:8px; text-decoration:none;'
               . ' font-weight:600; border:1px solid ' . ($on ? '#0D4F72' : '#D3DCE4') . ';'
               . ($on ? ' background:#0D4F72; color:#fff;' : ' background:#fff; color:#0D4F72;');

        if ($on) {
            echo '<span style="' . $style . '" aria-current="page">' . e($label) . '</span>';
        } else {
            echo '<a href="' . e($href) . '" style="' . $style . '" title="' . e($blurb) . '">'
               . e($label) . '</a>';
        }
    }
    echo '</div>';
    echo '<p class="field-hint" style="margin:0 0 20px">' . e($tabs[$here][2]) . '</p>';

    if ($here === 'single') {
        mode_switch_fleet_hint($form);
    }
}

/**
 * "This customer has 38 vehicles on file."
 *
 * Said where it matters: on the one-vehicle form, about the customer already
 * typed in. Nobody goes looking for a bulk upload they have never heard of, but
 * everybody reads the line that names their own customer.
 */
function mode_switch_fleet_hint(array $form): void
{
    $name = trim((string)($form['client_name'] ?? ''));
    if ($name === '' || !function_exists('client_match')) {
        return;
    }

    $client = client_match($name, (string)($form['client_phone'] ?? ''));
    if (!$client) {
        return;
    }

    $n = (int)q('SELECT count(*) FROM vehicles WHERE client_id = ?', [$client['id']])->fetchColumn();
    if ($n < 3) {
        return;                 // two lorries is not a fleet; it is two forms
    }

    $href = 'batch_new.php?' . http_build_query([
        'client_name'    => $client['name'],
        'client_phone'   => $client['phone'] ?? '',
        'client_address' => $client['address'] ?? '',
    ]);

    echo '<p class="hint" style="text-align:left; margin:0 0 20px; padding:12px 14px;'
       . ' border-left:3px solid #8CC63F; background:#F4F9EC">'
       . e($client['name']) . ' has <strong>' . $n . ' vehicles</strong> on file. '
       . '<a href="' . e($href) . '">Do them in one upload</a> &mdash; their registrations, '
       . 'chassis numbers and fitted governors come down already filled in.</p>';
}