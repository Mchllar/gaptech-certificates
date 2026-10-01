<?php
/**
 * Minimal SMTP sender - no Composer packages needed.
 * Configure the smtp block in app/config.php. If it is left empty, mail_send()
 * returns false and the calling page falls back to asking another administrator.
 */
declare(strict_types=1);

function mail_configured(): bool
{
    global $config;
    $s = $config['smtp'] ?? [];
    return !empty($s['host']) && !empty($s['from']);
}

/** Send a plain-text message. Returns true on success. */
function mail_send(string $to, string $subject, string $body, ?string &$error = null): bool
{
    global $config;
    if (!mail_configured()) {
        $error = 'E-mail is not configured.';
        return false;
    }
    $s = $config['smtp'];
    $host = $s['host'];
    $port = (int)($s['port'] ?? 587);
    $secure = strtolower((string)($s['security'] ?? 'tls'));   // 'tls', 'ssl' or 'none'
    $timeout = 15;

    $target = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $socket = @stream_socket_client($target, $errno, $errstr, $timeout);
    if (!$socket) {
        $error = "Cannot reach the mail server ({$errstr}).";
        return false;
    }
    stream_set_timeout($socket, $timeout);

    $read = function () use ($socket): string {
        $data = '';
        while (($line = fgets($socket, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return $data;
    };
    $expect = function (string $response, string $code) use (&$error): bool {
        if (strncmp($response, $code, strlen($code)) !== 0) {
            $error = 'Mail server said: ' . trim($response);
            return false;
        }
        return true;
    };
    $say = function (string $command) use ($socket, $read): string {
        fwrite($socket, $command . "\r\n");
        return $read();
    };

    if (!$expect($read(), '220')) { fclose($socket); return false; }
    if (!$expect($say('EHLO ' . ($s['helo'] ?? 'localhost')), '250')) { fclose($socket); return false; }

    if ($secure === 'tls') {
        if (!$expect($say('STARTTLS'), '220')) { fclose($socket); return false; }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $error = 'Could not start TLS with the mail server.';
            fclose($socket);
            return false;
        }
        if (!$expect($say('EHLO ' . ($s['helo'] ?? 'localhost')), '250')) { fclose($socket); return false; }
    }

    if (!empty($s['username'])) {
        if (!$expect($say('AUTH LOGIN'), '334')) { fclose($socket); return false; }
        if (!$expect($say(base64_encode((string)$s['username'])), '334')) { fclose($socket); return false; }
        if (!$expect($say(base64_encode((string)$s['password'])), '235')) { fclose($socket); return false; }
    }

    if (!$expect($say('MAIL FROM:<' . $s['from'] . '>'), '250')) { fclose($socket); return false; }
    if (!$expect($say('RCPT TO:<' . $to . '>'), '250')) { fclose($socket); return false; }
    if (!$expect($say('DATA'), '354')) { fclose($socket); return false; }

    $fromName = $s['from_name'] ?? 'GAPTECH Certificates';
    $headers = 'From: ' . $fromName . ' <' . $s['from'] . ">\r\n"
        . 'To: <' . $to . ">\r\n"
        . 'Subject: ' . $subject . "\r\n"
        . 'Date: ' . date('r') . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    // a lone dot on a line would end the message early
    $safeBody = preg_replace('/^\./m', '..', str_replace("\n", "\r\n", $body));
    fwrite($socket, $headers . $safeBody . "\r\n.\r\n");
    if (!$expect($read(), '250')) { fclose($socket); return false; }

    $say('QUIT');
    fclose($socket);
    return true;
}
