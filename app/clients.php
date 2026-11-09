<?php
/**
 * Clients: finding them, creating them, and reading them out of a spreadsheet.
 *
 * Goes in app/clients.php. Require it from any page that needs it; it depends
 * only on bootstrap.php.
 *
 * ---------------------------------------------------------------------------
 * The one idea
 * ---------------------------------------------------------------------------
 * There must be ONE way to decide whether two client records are the same
 * client, and everything must use it: the CSV import, the manual form, and
 * create_certificate(). Two rules in two places is how you end up with "ACME
 * HAULIERS LTD" holding the vehicles and "Acme Hauliers" holding the portal
 * login, with a customer on the phone saying their account is empty.
 *
 * The rule, in order:
 *
 *   1. the normalised phone  - the strongest signal, because people mistype
 *                              names far more often than their own number
 *   2. the normalised name   - letters and digits only, case ignored
 *
 * Neither is treated as proof. The import SHOWS you every match it found and
 * writes nothing until you say so.
 */

/**
 * A Kenyan phone number reduced to one comparable form: 0722123456.
 *
 * Strip to digits, drop a 254 country code, drop any leading zeros left behind -
 * "+254 (0) 722 ..." is written often enough to matter - and if nine digits
 * remain, put the single leading zero back.
 *
 * Anything that is not that shape, a landline or a half-typed number, keeps its
 * digits unchanged rather than being bent into something it is not.
 *
 * This is the same expression as the phone_key generated column in
 * migration_client_import.sql. If you change one, change the other: they are
 * cross-checked case for case, and silent disagreement here means duplicate
 * clients nobody can explain.
 */
function phone_key(string $phone): string
{
    $digits = preg_replace('/[^0-9]/', '', $phone);

    // Too short to be a phone number, so too short to be an IDENTITY.
    //
    // There are already three clients on file whose phone reads "0700" - somebody
    // interrupted halfway through typing. Those are three different companies, and
    // treating the fragment as a key makes them one: the second company's lorries
    // attach to the first, and whoever gets the portal login sees the wrong fleet.
    // A number this short tells us nothing, so it says nothing, and matching falls
    // back to the name.
    //
    // Nine digits is the shortest real number here - a landline is 020 plus seven.
    if (strlen($digits) < 9) {
        return '';
    }

    $core = ltrim(preg_replace('/^254/', '', $digits), '0');

    return strlen($core) === 9 ? '0' . $core : $digits;
}

/** A name reduced for comparison: letters and digits only, upper case. */
function client_name_key(string $name): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name));
}

/**
 * The client this name and phone most likely already refers to, or null.
 *
 * Phone first. A person gets their own number right; the spelling of their
 * company is anybody's guess.
 */
function client_match(string $name, string $phone, ?int $exceptId = null): ?array
{
    $pk = phone_key($phone);
    $nk = client_name_key($name);

    if ($pk !== '') {
        $row = q('SELECT * FROM clients WHERE phone_key = ? AND (?::int IS NULL OR id <> ?::int)
                  ORDER BY id LIMIT 1', [$pk, $exceptId, $exceptId])->fetch();
        if ($row) {
            return $row;
        }
    }

    if ($nk !== '') {
        $row = q("SELECT * FROM clients
                   WHERE upper(regexp_replace(name, '[^A-Za-z0-9]', '', 'g')) = ?
                     AND (?::int IS NULL OR id <> ?::int)
                   ORDER BY id LIMIT 1", [$nk, $exceptId, $exceptId])->fetch();
        if ($row) {
            return $row;
        }
    }

    return null;
}

/**
 * Add a client.
 *
 * $source is only ever shown to a person - 'manual', 'import', 'certificate' -
 * so a row can be explained later. Nothing branches on it.
 */
function client_create(array $in, string $source = 'manual'): array
{
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('A client needs a name.');
    }

    $id = (int)q('INSERT INTO clients (name, phone, address, email, source)
                  VALUES (?, ?, ?, ?, ?) RETURNING id',
        [$name,
         trim((string)($in['phone'] ?? '')),
         trim((string)($in['address'] ?? '')),
         trim((string)($in['email'] ?? '')),
         $source])->fetchColumn();

    audit('create_client', 'client', $id, ['name' => $name, 'source' => $source]);

    return q('SELECT * FROM clients WHERE id = ?', [$id])->fetch();
}

/**
 * Change a client's details.
 *
 * Blank fields in $in are IGNORED rather than written. A spreadsheet column
 * somebody left empty is almost never an instruction to erase a phone number
 * that is already on file.
 */
function client_update(int $id, array $in): array
{
    $before = q('SELECT * FROM clients WHERE id = ?', [$id])->fetch();
    if (!$before) {
        throw new RuntimeException('That client no longer exists.');
    }

    $set = [];
    $val = [];
    foreach (['name', 'phone', 'address', 'email'] as $field) {
        $new = trim((string)($in[$field] ?? ''));
        if ($new === '' || $new === (string)$before[$field]) {
            continue;
        }

        // The same name in different clothes is not a new name. A spreadsheet
        // saying "brian omondi were" where the file says "BRIAN OMONDI WERE" is
        // how the match was made in the first place, so there is nothing in the
        // spelling worth taking - and taking it quietly un-capitalises records
        // that every other screen shows in capitals.
        if ($field === 'name'
            && client_name_key($new) === client_name_key((string)$before['name'])) {
            continue;
        }

        $set[] = $field . ' = ?';
        $val[] = $new;
    }

    if (!$set) {
        return $before;
    }

    $val[] = $id;
    q('UPDATE clients SET ' . implode(', ', $set) . ' WHERE id = ?', $val);

    audit('update_client', 'client', $id, [
        'changed' => array_map(static fn($s) => trim(explode('=', $s)[0]), $set),
    ]);

    return q('SELECT * FROM clients WHERE id = ?', [$id])->fetch();
}

// ===========================================================================
// Vehicles
//
// A client never exists on their own here. GAPTECH only ever gets somebody's
// details because a governor is going into a vehicle, so the natural shape of an
// import is ONE ROW PER VEHICLE with the owner's details repeated - which is also
// how a fleet list already looks in a spreadsheet.
// ===========================================================================

if (!function_exists('plate_key')) {
    /** A registration reduced to letters and digits, so KDF745B matches KDF 745B. */
    function plate_key(string $reg): string
    {
        return (string)preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($reg)));
    }
}

/**
 * A registration written the way every other row in this system writes it.
 *
 * All 26 registrations already on file carry a space - "KDA 107G" - because that
 * is how they were typed. Storing "KDA107G" from a spreadsheet would match fine
 * (that is what reg_key is for) but every screen would then show two different
 * spellings of the same kind of thing, and a certificate would print whichever
 * one happened to be typed that day.
 *
 * Only a registration typed SOLID, in the ordinary Kenyan shape - three letters,
 * three digits, an optional letter - gets its space. Anything already typed with
 * a space is left exactly as it stands, because the typist knows where the space
 * belongs and the machine does not: a government plate is "GK A123A", and a rule
 * clever enough to guess would turn it into "GKA 123A".
 */
function plate_display(string $reg): string
{
    $clean = strtoupper(trim((string)preg_replace('/\s+/', ' ', $reg)));

    if (strpos($clean, ' ') === false
        && preg_match('/^([A-Z]{3})([0-9]{3}[A-Z]?)$/', $clean, $m)) {
        return $m[1] . ' ' . $m[2];
    }

    return $clean;
}

/**
 * The vehicle this registration already refers to, or null.
 *
 * Matched on letters and digits only. Every registration in this system is stored
 * with a space - "KDA 107G" - so comparing the typed string would miss "KDA107G"
 * and create a second row for the same lorry.
 */
function vehicle_match(string $reg): ?array
{
    $key = plate_key($reg);
    if ($key === '') {
        return null;
    }

    $row = q("SELECT * FROM vehicles
               WHERE upper(regexp_replace(reg_no, '[^A-Za-z0-9]', '', 'g')) = ?
               ORDER BY id LIMIT 1", [$key])->fetch();

    return $row ?: null;
}

/**
 * Add a vehicle, or attach an existing one to this client.
 *
 * Make and chassis are filled in only where they are blank or genuinely new. A
 * spreadsheet column left empty is not an instruction to erase a chassis number
 * already on file.
 */
function vehicle_upsert(array $in, int $clientId): array
{
    $reg = trim((string)($in['reg_no'] ?? ''));
    if ($reg === '') {
        throw new RuntimeException('A vehicle needs a registration.');
    }

    $existing = vehicle_match($reg);

    if (!$existing) {
        $id = (int)q('INSERT INTO vehicles (client_id, reg_no, make, chassis_no)
                      VALUES (?, ?, ?, ?) RETURNING id',
            [$clientId, plate_display($reg),
             trim((string)($in['make'] ?? '')),
             trim((string)($in['chassis_no'] ?? ''))])->fetchColumn();

        audit('create_vehicle', 'vehicle', $id,
            ['reg_no' => plate_display($reg), 'client' => $clientId]);
        return q('SELECT * FROM vehicles WHERE id = ?', [$id])->fetch();
    }

    $set = [];
    $val = [];

    // The owner can genuinely change - a lorry is sold - so this does move.
    if ((int)$existing['client_id'] !== $clientId) {
        $set[] = 'client_id = ?';
        $val[] = $clientId;
    }
    foreach (['make', 'chassis_no'] as $field) {
        $new = trim((string)($in[$field] ?? ''));
        if ($new !== '' && $new !== (string)$existing[$field]) {
            $set[] = $field . ' = ?';
            $val[] = $new;
        }
    }

    if ($set) {
        $val[] = $existing['id'];
        q('UPDATE vehicles SET ' . implode(', ', $set) . ' WHERE id = ?', $val);
        audit('update_vehicle', 'vehicle', (int)$existing['id'],
            ['reg_no' => $existing['reg_no']]);
    }

    return q('SELECT * FROM vehicles WHERE id = ?', [$existing['id']])->fetch();
}

/**
 * Read a spreadsheet of clients and say what each row would do.
 *
 * Nothing is written. Every row comes back with a verdict so the whole import
 * can be looked at before any of it happens:
 *
 *   new      no client like this; it would be added
 *   match    an existing client; it would be updated, if you tick it
 *   repeat   the same client appears earlier in this same file
 *   bad      unusable, with the reason - no name, almost always
 */
function client_csv_rows(string $path): array
{
    $handle = fopen($path, 'r');
    if (!$handle) {
        throw new RuntimeException('That file could not be opened.');
    }

    // Excel writes a byte order mark that otherwise becomes part of the first
    // column name, so "Name" silently stops being recognised.
    $first = fgets($handle);
    if ($first === false) {
        fclose($handle);
        throw new RuntimeException('That file is empty.');
    }
    $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);

    // Excel on a machine set to some European locales writes semicolons. Guess
    // from the header line rather than demanding one or the other.
    $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

    $header = str_getcsv(rtrim($first, "\r\n"), $delimiter);
    $map    = client_csv_columns($header);

    if (!isset($map['name'])) {
        fclose($handle);
        throw new RuntimeException('No "name" column found. The first row must name the '
            . 'columns - name, phone, address, email - in any order.');
    }

    $rows      = [];
    $seen      = [];
    $seenPhone = [];
    $seenName  = [];
    $line      = 1;

    while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
        $line++;
        if ($cells === [null] || !array_filter($cells, static fn($c) => trim((string)$c) !== '')) {
            continue;                                   // blank line
        }

        $get = static fn(string $k) => isset($map[$k]) ? trim((string)($cells[$map[$k]] ?? '')) : '';

        $row = [
            'line'       => $line,
            'name'       => $get('name'),
            'phone'      => $get('phone'),
            'address'    => $get('address'),
            'email'      => $get('email'),
            'reg_no'     => $get('reg_no'),
            'make'       => $get('make'),
            'chassis_no' => $get('chassis_no'),
        ];

        if ($row['name'] === '') {
            $row['verdict'] = 'bad';
            $row['why']     = 'no name';
            $rows[] = $row;
            continue;
        }

        // One row per vehicle, so the same client legitimately appears many times -
        // a sacco with forty lorries is forty rows. It is the REGISTRATION that
        // may not repeat. A row with no registration is just a client, and then
        // the client is what may not repeat.
        $key = $row['reg_no'] !== ''
            ? 'V:' . plate_key($row['reg_no'])
            : 'C:' . (phone_key($row['phone']) ?: client_name_key($row['name']));

        if (isset($seen[$key])) {
            $row['verdict'] = 'repeat';
            $row['why']     = $row['reg_no'] !== ''
                ? 'same vehicle as line ' . $seen[$key]
                : 'same client as line ' . $seen[$key];
            $rows[] = $row;
            continue;
        }
        $seen[$key] = $line;

        // The client, and separately the vehicle. Either can already be on file
        // without the other: a known client buying another lorry is a new vehicle
        // for an existing client, and that is the commonest row of all.
        $existing = client_match($row['name'], $row['phone']);
        if ($existing) {
            $row['verdict']  = 'match';
            $row['existing'] = $existing;
        } else {
            $row['verdict'] = 'new';
        }

        // Which EARLIER LINE in this same file is already this client?
        //
        // Nothing is written during a preview, so client_match() above can only see
        // the database - and a sacco's forty lorries are forty rows naming a client
        // who is not in it yet. Every one of those rows would say "added as a new
        // client", and an apply that believed the preview would leave forty copies
        // of the sacco behind. So the file is checked against itself too, the same
        // way client_match() checks: normalised phone first, then the name.
        $pk = phone_key($row['phone']);
        $nk = client_name_key($row['name']);

        $firstLine = null;
        if ($pk !== '' && isset($seenPhone[$pk])) {
            $firstLine = $seenPhone[$pk];
        } elseif ($nk !== '' && isset($seenName[$nk])) {
            $firstLine = $seenName[$nk];
        }
        if ($firstLine !== null) {
            $row['client_repeat'] = $firstLine;
        }

        // Both spellings point at whichever line named this client FIRST, so a
        // later row carrying only the name still resolves to the same one.
        $home = $firstLine ?? $line;
        if ($pk !== '' && !isset($seenPhone[$pk])) { $seenPhone[$pk] = $home; }
        if ($nk !== '' && !isset($seenName[$nk]))  { $seenName[$nk]  = $home; }

        if ($row['reg_no'] !== '') {
            $onFile = vehicle_match($row['reg_no']);
            $row['vehicle_existing'] = $onFile ?: null;

            if (!$onFile) {
                $row['vehicle_verdict'] = 'new';
            } elseif ((int)($onFile['client_id'] ?? 0) === 0) {
                // On file with nobody against it. Vehicles used to come into
                // existence as a side effect of raising a certificate, so this is
                // giving a lorry its owner - not taking it off anybody.
                $row['vehicle_verdict'] = 'attach';
            } elseif ($existing && (int)$onFile['client_id'] === (int)$existing['id']) {
                $row['vehicle_verdict'] = 'same';
            } else {
                // A lorry genuinely changes hands, and it moves with its history.
                // Worth saying out loud and worth NAMING who it is coming from,
                // because a mistyped registration looks exactly like this.
                $row['vehicle_verdict'] = 'move';
                $row['vehicle_owner']   = (string)(q('SELECT name FROM clients WHERE id = ?',
                    [(int)$onFile['client_id']])->fetchColumn() ?: '');
            }
        } else {
            $row['vehicle_verdict'] = 'none';
        }

        $rows[] = $row;
    }

    fclose($handle);

    if (!$rows) {
        throw new RuntimeException('That file has a header row but no clients under it.');
    }

    return $rows;
}

/**
 * Work out which column is which.
 *
 * Accepts the names people actually type. "Telephone", "Mobile" and "Phone No"
 * are all the phone column, and insisting on one spelling just means the import
 * fails for a reason nobody can see.
 */
function client_csv_columns(array $header): array
{
    $aliases = [
        'name'    => ['name', 'clientname', 'client', 'customer', 'customername',
                      'company', 'companyname', 'ownership'],
        'phone'   => ['phone', 'phoneno', 'phonenumber', 'telephone', 'tel',
                      'mobile', 'mobileno', 'contact', 'contactno'],
        'address' => ['address', 'location', 'town', 'city', 'postaladdress'],
        'email'   => ['email', 'emailaddress', 'mail', 'e-mail'],

        // One row per vehicle, so these ride alongside the owner's details.
        'reg_no'     => ['regno', 'reg', 'registration', 'registrationno',
                         'registrationnumber', 'vehicle', 'vehicleregno',
                         'vehicleregistration', 'numberplate', 'plate'],
        'make'       => ['make', 'makemodel', 'model', 'vehiclemake', 'makeofvehicle'],
        'chassis_no' => ['chassisno', 'chassis', 'chassisnumber', 'vin'],
    ];

    $map = [];
    foreach ($header as $i => $label) {
        $key = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$label));
        foreach ($aliases as $field => $names) {
            if (!isset($map[$field]) && in_array($key, $names, true)) {
                $map[$field] = $i;
            }
        }
    }

    return $map;
}