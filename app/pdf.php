<?php
/**
 * Turning a rendered sheet into a PDF.
 *
 * Goes in app/pdf.php, and is required from app/bootstrap.php.
 *
 * ---------------------------------------------------------------------------
 * Why this file exists
 * ---------------------------------------------------------------------------
 * certificate_pdf() and tracking_pdf() both shelled out to wkhtmltopdf, and both
 * returned null when it was not configured. certificate_print.php reads that null
 * and falls back to showing the HTML sheet with the print dialog open.
 *
 * On a laptop that fallback is invisible: the print dialog obeys
 * "@page { size: A4 landscape }" and you get a landscape page. On a phone it is
 * not invisible at all. Mobile Chrome and Safari ignore @page size when they save
 * to PDF, so the client gets a web page in portrait with the certificate squeezed
 * into the top third of it. That is the screenshot.
 *
 * So the orientation was never the bug. The bug is that no PDF was ever produced,
 * and a phone was being asked to lay out an A4 landscape document.
 *
 * ---------------------------------------------------------------------------
 * What changed
 * ---------------------------------------------------------------------------
 * The renderer is now Chrome rather than wkhtmltopdf, and it is found rather than
 * installed. Three reasons:
 *
 *   1. It is already on the machine. Chrome, or failing that Edge, which ships
 *      with Windows and is the same engine underneath. Nothing to install, which
 *      matters on a machine you do not fully control.
 *   2. It renders the sheet the way you have been checking it. sheet.php is built
 *      on "body { zoom: }" and absolutely positioned pixels - zoom is a Chrome
 *      feature, and wkhtmltopdf's WebKit is old enough to get it subtly wrong.
 *      The PDF now matches what you see when you open the sheet on the laptop,
 *      because it IS what you see on the laptop.
 *   3. Orientation stops being a request. The server decides, once, and every
 *      phone on every network gets the same file.
 *
 * wkhtmltopdf is still honoured if it is configured, so nothing breaks for a
 * machine that already has it.
 *
 * ---------------------------------------------------------------------------
 * The one subtlety
 * ---------------------------------------------------------------------------
 * Headless Chrome has no reliable --landscape switch for --print-to-pdf. It takes
 * the orientation from the page's own CSS. So the @page rule is injected into the
 * HTML here, just before </head>, exactly the way certificate_print.php already
 * injects it for the browser print dialog.
 *
 * That is deliberate: it means sheet.php and tracking_sheet.php do not change at
 * all, and the PDF and the print dialog are driven by the same rule.
 */

/**
 * Where Chrome might be, in the order worth trying.
 *
 * Edge is on the list on purpose. It is Chromium, it supports --print-to-pdf
 * identically, and it is present on every Windows 10 and 11 machine - so the
 * certificate keeps coming out as a PDF even on a machine where nobody has
 * installed Chrome.
 */
function pdf_browser_candidates(): array
{
    $local = getenv('LOCALAPPDATA') ?: '';
    $pf    = getenv('ProgramFiles') ?: 'C:\Program Files';
    $pf86  = getenv('ProgramFiles(x86)') ?: 'C:\Program Files (x86)';

    return array_values(array_filter([
        $pf   . '\Google\Chrome\Application\chrome.exe',
        $pf86 . '\Google\Chrome\Application\chrome.exe',
        $local !== '' ? $local . '\Google\Chrome\Application\chrome.exe' : null,
        $pf86 . '\Microsoft\Edge\Application\msedge.exe',
        $pf   . '\Microsoft\Edge\Application\msedge.exe',
        // the sandbox and any Linux box
        '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser',
    ]));
}

/**
 * Which engine will be used, as [kind, path], or null if there is none.
 *
 * An explicit setting always wins, so a machine can be pinned to a particular
 * build. Otherwise Chrome is looked for, then Edge, then wkhtmltopdf.
 */
function pdf_engine(): ?array
{
    global $config;

    $chrome = trim((string)($config['chrome'] ?? ''));
    if ($chrome !== '') {
        return is_file($chrome) ? ['chrome', $chrome] : null;
    }

    foreach (pdf_browser_candidates() as $path) {
        if (is_file($path)) {
            return ['chrome', $path];
        }
    }

    $wk = trim((string)($config['wkhtmltopdf'] ?? ''));
    if ($wk !== '' && is_file($wk)) {
        return ['wkhtmltopdf', $wk];
    }

    return null;
}

/** A one-line description of the engine, for check_setup.php. */
function pdf_engine_label(): string
{
    $engine = pdf_engine();
    if ($engine === null) {
        return 'none found';
    }
    [$kind, $path] = $engine;
    $name = (stripos($path, 'msedge') !== false) ? 'Edge (Chromium)'
          : ($kind === 'chrome' ? 'Chrome' : 'wkhtmltopdf');
    return $name . ' - ' . $path;
}

/**
 * Render HTML to a PDF file at $pdfPath.
 *
 * $orientation is 'landscape' or 'portrait' and becomes the @page rule.
 * Throws if it cannot be done; the caller decides whether that is fatal.
 */
function pdf_render_html(string $html, string $pdfPath, string $orientation = 'landscape'): void
{
    if (!function_exists('exec')) {
        throw new RuntimeException(
            'PHP cannot run external programs: exec() is disabled in php.ini. '
            . 'Remove it from disable_functions and restart Apache.');
    }

    $engine = pdf_engine();
    if ($engine === null) {
        throw new RuntimeException(
            'No PDF engine found. Chrome, Edge and wkhtmltopdf were all absent. '
            . "Set 'chrome' in config.php to the full path of chrome.exe.");
    }
    [$kind, $binary] = $engine;

    $orientation = ($orientation === 'portrait') ? 'portrait' : 'landscape';

    // The orientation lives in the document, not in a command-line switch. Chrome
    // takes @page seriously and nothing else is reliable across versions.
    $rule = '<style>@page { size: A4 ' . $orientation . '; margin: 0; }</style>';
    $html = (stripos($html, '</head>') !== false)
        ? preg_replace('~</head>~i', $rule . "\n</head>", $html, 1)
        : $rule . $html;

    // Everything the page needs goes INTO the page before Chrome ever sees it.
    //
    // This is the thing that was actually breaking. The sheet referenced its logos
    // and its blackletter font as file:// URLs, and a headless browser loading a
    // temp file from C:\Windows\Temp while reaching for assets in C:\xampp has to be
    // granted cross-file access, has to resolve both paths the same way, and has to
    // survive font CORS - and when any of that fails it does not complain, it just
    // never reaches a printable state and exits 0 having written nothing. Which is
    // exactly what you saw.
    //
    // Inlined, there is nothing left to fetch, nothing to be denied, and nothing to
    // wait for. The page cannot half-load because there is no second half.
    $html = pdf_inline_assets($html);

    $dir = dirname($pdfPath);
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create folder: ' . $dir);
    }

    // tempnam() creates the file, then we append .html to the NAME - so the original
    // is left behind unless we clean it up. Her wkhtmltopdf version had the same leak.
    $stem     = tempnam(sys_get_temp_dir(), 'gtsheet');
    $htmlPath = $stem . '.html';
    file_put_contents($htmlPath, $html);
    @unlink($stem);

    @unlink($pdfPath);                       // so a stale file cannot look like success

    try {
        if ($kind === 'chrome') {
            pdf_run_chrome($binary, $htmlPath, $pdfPath);
        } else {
            pdf_run_wkhtmltopdf($binary, $htmlPath, $pdfPath, $orientation);
        }
        @unlink($htmlPath);
    } catch (Throwable $err) {
        // Keep the page that failed. Being able to open it in Chrome by hand is worth
        // more than any amount of guessing at command-line switches.
        $kept = pdf_keep_failed_html($htmlPath, $html);
        @unlink($htmlPath);
        throw new RuntimeException($err->getMessage()
            . ($kept !== '' ? ' | the page that failed was saved as: ' . $kept : ''), 0, $err);
    }

    // Chrome can exit 0 and still write nothing useful, so check the file itself
    // rather than trusting the status code.
    if (!is_file($pdfPath) || filesize($pdfPath) < 1000
        || strncmp((string)file_get_contents($pdfPath, false, null, 0, 5), '%PDF-', 5) !== 0) {
        $size = is_file($pdfPath) ? filesize($pdfPath) . ' bytes' : 'no file';
        @unlink($pdfPath);
        throw new RuntimeException($kind . ' reported success but the file is not a PDF ('
            . $size . '). Engine: ' . $binary);
    }
}

/**
 * Headless Chrome.
 *
 * Tried first with Chrome's own sandbox left on, and only retried without it if
 * that fails. Chrome refuses to start sandboxed under some service accounts - and
 * XAMPP's Apache is sometimes installed as a service - so the retry is what keeps
 * this working on a machine set up that way. Doing it in that order means the
 * sandbox is only given up where it genuinely cannot be had, rather than switched
 * off everywhere to save one line.
 */
function pdf_run_chrome(string $binary, string $htmlPath, string $pdfPath): void
{
    try {
        pdf_run_chrome_once($binary, $htmlPath, $pdfPath, false);
        return;
    } catch (RuntimeException $first) {
        // Retry on ANY first failure, not just one that says "sandbox". On Windows
        // the sandbox refusal often comes back as a bare non-zero exit with nothing
        // on stderr at all, so matching on the word was useless there - which is
        // exactly how this got through the first time.
        error_log('[pdf] first attempt failed, retrying without sandbox: ' . $first->getMessage());
    }
    pdf_run_chrome_once($binary, $htmlPath, $pdfPath, true);
}

/** One attempt. $noSandbox is the fallback described above. */
function pdf_run_chrome_once(string $binary, string $htmlPath, string $pdfPath, bool $noSandbox): void
{
    // Chrome refuses to start against a profile another Chrome is using, and
    // Michelle's own browser is usually open. A throwaway profile per run avoids
    // that entirely.
    $profile = pdf_temp_profile();

    // $htmlPath is either a local file to render or an http:// URL to fetch.
    $fileUrl = preg_match('~^https?://~i', $htmlPath)
        ? $htmlPath
        : 'file:///' . str_replace('\\', '/', ltrim($htmlPath, '/\\'));

    $args = [
        '--headless',
        '--disable-gpu',
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-extensions',
        '--disable-background-networking',
        '--disable-dev-shm-usage',
        // the sheet pulls its logos and the blackletter font off disk; without this
        // Chrome treats every local file as a separate origin and the font is dropped
        '--allow-file-access-from-files',
        // Without this, Chrome on Windows writes its log INTO the profile folder
        // rather than to stderr - so exec() captures nothing and the failure reads
        // as "it said nothing". That is why the first round of this was silent.
        '--enable-logging=stderr',
        '--user-data-dir=' . $profile,
        // NOTE: --virtual-time-budget and --run-all-compositor-stages-before-draw
        // used to be here as a precaution so fonts and images could settle.
        //
        // They are gone because they earn nothing: without them Chrome waits for the
        // page to load and then prints, which is what we wanted anyway, and the budget
        // can only ever cut a slow page off early. They are NOT, however, the cause of
        // the silent failure - tested directly on a 2.2 MB inlined page with and
        // without them, and both produced the PDF. Said plainly so nobody re-derives
        // a theory that has already been ruled out.
        // both spellings; Chrome ignores a switch it does not know, and which one
        // applies depends on the version installed
        '--no-pdf-header-footer',
        '--print-to-pdf-no-header',
        '--print-to-pdf=' . $pdfPath,
    ];

    if ($noSandbox) {
        array_unshift($args, '--no-sandbox');
    }

    $cmd = escapeshellarg($binary);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    $cmd .= ' ' . escapeshellarg($fileUrl) . ' 2>&1';

    // The command itself, logged every time. If Chrome misbehaves only under Apache,
    // being able to paste this into Command Prompt and watch it work is the fastest
    // way to prove where the difference is.
    error_log('[pdf] running: ' . $cmd);

    $output = [];
    exec($cmd, $output, $status);
    pdf_rmdir($profile);

    // Everything Chrome said goes to the log whether it worked or not. Chrome is
    // talkative about why it will not start, and throwing that away is what made
    // the first round of this impossible to diagnose.
    if ($output) {
        // Capped, because Chrome will happily write a hundred lines about things
        // that do not matter and bury the one line that does. The last few are the
        // ones worth having.
        error_log('[pdf] chrome(' . ($noSandbox ? 'no-sandbox' : 'sandboxed') . ') status='
            . $status . ': ' . implode(' | ', array_slice($output, -8)));
    }

    // Do not trust the exit code. On Windows, when the same account already has
    // Chrome open, a new chrome.exe can hand its command line to the running process
    // and exit AT ONCE - status 0, no output, and the PDF written a moment later by
    // a process exec() never waited for. That is "exit 0, said nothing, no file" to
    // the letter, and it is why a small test page could pass while a big sheet
    // failed: the small one won the race.
    //
    // So wait for the file instead of asking whether the program succeeded. If
    // Chrome really did fail, this costs the wait and nothing else.
    // Only wait long when Chrome claimed success. A non-zero exit means it really
    // did run and really did fail, and there is nothing to wait for - without this
    // split, a first attempt that fails on the sandbox costs 30 seconds before the
    // retry even starts, which is how the fix becomes its own problem.
    if (!pdf_wait_for_file($pdfPath, $status === 0 ? 30 : 2)) {
        throw new RuntimeException(pdf_failure_detail('Chrome', $binary, $pdfPath, $status, $output, $noSandbox));
    }
}

/**
 * Wait for a PDF to appear and stop growing.
 *
 * Two conditions, because a file that exists is not the same as a file that is
 * finished: it has to be big enough to be real, and its size has to hold steady
 * across two checks so we are not reading something still being written.
 */
function pdf_wait_for_file(string $path, int $seconds): bool
{
    $deadline = microtime(true) + $seconds;
    $previous = -1;

    while (microtime(true) < $deadline) {
        clearstatcache(true, $path);

        if (is_file($path)) {
            $size = (int)filesize($path);
            if ($size >= 1000 && $size === $previous) {
                return true;                       // present, plausible, and settled
            }
            $previous = $size;
        }

        usleep(250000);
    }

    clearstatcache(true, $path);
    return is_file($path) && filesize($path) >= 1000;
}

/**
 * The message a human can act on.
 *
 * Says what was run, what it returned, what landed on disk and what the program
 * printed. "The PDF came out empty" on its own is not a diagnosis, it is a shrug.
 */
function pdf_failure_detail(string $name, string $binary, string $pdfPath, int $status,
                            array $output, bool $noSandbox = false): string
{
    $file = is_file($pdfPath) ? (filesize($pdfPath) . ' bytes') : 'no file written';
    $said = $output ? implode(' | ', array_slice($output, -6)) : '(said nothing)';

    return $name . ' did not produce a usable PDF.'
        . ' exit status: ' . $status . ';'
        . ' sandbox: ' . ($noSandbox ? 'off' : 'on') . ';'
        . ' output file: ' . $file . ';'
        . ' program: ' . $binary . ';'
        . ' it said: ' . $said;
}

/** wkhtmltopdf, kept so a machine already set up for it keeps working. */
function pdf_run_wkhtmltopdf(string $binary, string $htmlPath, string $pdfPath, string $orientation): void
{
    $cmd = escapeshellarg($binary)
        . ' --quiet --page-size A4 --orientation ' . ($orientation === 'portrait' ? 'Portrait' : 'Landscape')
        . ' --margin-top 0 --margin-bottom 0 --margin-left 0 --margin-right 0'
        . ' --dpi 96 --enable-local-file-access'
        . ' ' . escapeshellarg($htmlPath) . ' ' . escapeshellarg($pdfPath) . ' 2>&1';

    exec($cmd, $output, $status);

    if ($status !== 0 && !is_file($pdfPath)) {
        throw new RuntimeException('wkhtmltopdf failed: ' . implode("\n", $output));
    }
}

// ===========================================================================
// Rendering from a URL instead of a file
// ===========================================================================
//
// This is the approach that replaced loading a temp file from disk, and the
// reasoning is short: the sheet already renders correctly in Chrome over HTTP.
// That is how it has been checked on the laptop all along. The only arrangement
// that ever failed was Chrome opening a temp file in C:\Windows\Temp and reaching
// across to assets in C:\xampp - cross-file access, path translation and font CORS
// all at once, and when any of it fails headless Chrome exits 0 and says nothing.
//
// Served over HTTP there is no file:// anywhere, the asset paths are the ordinary
// relative ones the browser already uses, and Chrome is doing the single thing it
// is best at: loading a web page.
//
// The page it loads is public/pdf_sheet.php, which exists only for this. It is
// signed with a short-lived token and refuses anything that is not a local
// request, so putting it in public/ does not put a certificate on the internet.

/**
 * The key used to sign the internal render URL.
 *
 * Taken from config if set; otherwise derived from values that are already secret
 * and already stable on this installation. That means there is nothing to generate
 * and nothing new to keep safe - and the key never leaves the server either way.
 */
function pdf_secret(): string
{
    global $config;

    $explicit = (string)($config['pdf_secret'] ?? '');
    if ($explicit !== '') {
        return $explicit;
    }

    return hash('sha256', 'gaptech-pdf|' . ROOT_DIR . '|' . (string)($config['db']['pass'] ?? ''));
}

/** A signature for one certificate, valid for a couple of minutes. */
function pdf_token(string $kind, int $id, int $expires): string
{
    return hash_hmac('sha256', $kind . '|' . $id . '|' . $expires, pdf_secret());
}

/**
 * Is this request allowed to render that sheet?
 *
 * Three things have to hold: the signature matches, it has not expired, and the
 * request came from this machine. The last one matters most - it is what stops the
 * page being useful to anybody who ever sees a URL in a log.
 */
function pdf_token_check(string $kind, int $id, int $expires, string $sig): bool
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!in_array($remote, ['127.0.0.1', '::1', 'localhost'], true)) {
        return false;
    }
    if ($expires < time()) {
        return false;
    }
    return hash_equals(pdf_token($kind, $id, $expires), $sig);
}

/**
 * Where this site answers itself.
 *
 * 127.0.0.1 rather than the machine's name on purpose: it does not depend on DNS,
 * on the network being up, or on which address the server happens to have today.
 */
function pdf_base_url(): string
{
    global $config;

    $set = trim((string)($config['base_url'] ?? ''));
    if ($set !== '') {
        return rtrim($set, '/') . '/';
    }

    // Work it out from the request that is running. public/certificate_print.php
    // gives /gaptech-certificates/public, which is exactly what we want.
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $dir    = $script !== '' ? rtrim(str_replace('\\', '/', dirname($script)), '/') : '';
    $port   = (int)($_SERVER['SERVER_PORT'] ?? 80);
    $host   = '127.0.0.1' . ($port && $port !== 80 ? ':' . $port : '');

    return 'http://' . $host . $dir . '/';
}

/**
 * Render one certificate to a PDF by fetching it over HTTP.
 *
 * $kind is 'cert' or 'tracking'.
 */
function pdf_render_certificate(string $kind, int $id, string $pdfPath, string $orientation): void
{
    if (!function_exists('exec')) {
        throw new RuntimeException(
            'PHP cannot run external programs: exec() is disabled in php.ini. '
            . 'Remove it from disable_functions and restart Apache.');
    }

    $engine = pdf_engine();
    if ($engine === null) {
        throw new RuntimeException(
            'No PDF engine found. Chrome, Edge and wkhtmltopdf were all absent. '
            . "Set 'chrome' in config.php to the full path of chrome.exe.");
    }
    [$engineKind, $binary] = $engine;

    if ($engineKind !== 'chrome') {
        throw new RuntimeException('Rendering from a URL needs Chrome or Edge.');
    }

    $expires = time() + 120;
    $url = pdf_base_url() . 'pdf_sheet.php?kind=' . rawurlencode($kind)
         . '&id=' . $id
         . '&orientation=' . rawurlencode($orientation)
         . '&exp=' . $expires
         . '&sig=' . pdf_token($kind, $id, $expires);

    $dir = dirname($pdfPath);
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create folder: ' . $dir);
    }

    @unlink($pdfPath);
    pdf_run_chrome($binary, $url, $pdfPath);

    if (!is_file($pdfPath) || filesize($pdfPath) < 1000
        || strncmp((string)file_get_contents($pdfPath, false, null, 0, 5), '%PDF-', 5) !== 0) {
        $size = is_file($pdfPath) ? filesize($pdfPath) . ' bytes' : 'no file';
        @unlink($pdfPath);
        throw new RuntimeException('Chrome reported success but the file is not a PDF ('
            . $size . '). The page it was asked to render: ' . $url);
    }
}

/**
 * Replace every file:// reference in the page with the file itself, as a data: URI.
 *
 * Images and fonts both. A missing file is left exactly as it was rather than
 * blanked, so a broken asset shows up as a broken asset instead of quietly
 * vanishing from the certificate.
 */
function pdf_inline_assets(string $html): string
{
    static $types = [
        'png' => 'image/png',   'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',   'svg'  => 'image/svg+xml', 'webp' => 'image/webp',
        'ttf' => 'font/ttf',    'otf'  => 'font/otf',
        'woff' => 'font/woff',  'woff2' => 'font/woff2',
    ];

    return (string)preg_replace_callback(
        '~file://([^"\'\)\s>]+)~',
        static function (array $m) use ($types): string {
            // file://C:/x and file:///C:/x both mean C:\x, while file:///home/x
            // means /home/x. Only strip the leading slash when a drive letter
            // follows it, or POSIX absolute paths get quietly mangled.
            $raw = $m[1];
            if (preg_match('~^/[A-Za-z]:~', $raw)) {
                $raw = substr($raw, 1);
            }
            $path = str_replace('/', DIRECTORY_SEPARATOR, $raw);

            if (!is_file($path) || !is_readable($path)) {
                error_log('[pdf] asset not found, left as-is: ' . $path);
                return $m[0];
            }

            $ext  = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
            $mime = $types[$ext] ?? 'application/octet-stream';
            $data = file_get_contents($path);

            if ($data === false) {
                return $m[0];
            }

            return 'data:' . $mime . ';base64,' . base64_encode($data);
        },
        $html);
}

/**
 * Save the HTML of a render that failed, so it can be opened by hand.
 *
 * Returns where it went, or '' if it could not be saved. Never throws - this runs
 * while another error is already being reported and must not replace it.
 */
function pdf_keep_failed_html(string $htmlPath, string $html): string
{
    global $config;

    try {
        $dir = rtrim((string)($config['storage'] ?? sys_get_temp_dir()), '/\\');
        if (!is_dir($dir)) {
            return '';
        }
        $kept = $dir . DIRECTORY_SEPARATOR . 'failed-sheet.html';
        return file_put_contents($kept, $html) !== false ? $kept : '';
    } catch (Throwable $ignored) {
        return '';
    }
}

/**
 * A throwaway Chrome profile folder, and a sweep of any left behind.
 *
 * Windows sometimes still has a handle open on the profile when we try to delete
 * it, so the delete is best-effort and anything older than an hour is cleared on
 * the next run. Without the sweep these quietly fill the disk.
 */
function pdf_temp_profile(): string
{
    global $config;

    $base = rtrim((string)($config['storage'] ?? sys_get_temp_dir()), '/\\')
          . DIRECTORY_SEPARATOR . 'chrome-tmp';
    if (!is_dir($base)) {
        @mkdir($base, 0770, true);
    }

    foreach ((array)glob($base . DIRECTORY_SEPARATOR . 'p-*') as $old) {
        if (is_dir($old) && filemtime($old) < time() - 3600) {
            pdf_rmdir($old);
        }
    }

    $dir = $base . DIRECTORY_SEPARATOR . 'p-' . bin2hex(random_bytes(6));
    @mkdir($dir, 0770, true);
    return $dir;
}

/** Delete a folder and everything in it. Best effort - never throws. */
function pdf_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}