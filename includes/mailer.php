<?php
/**
 * includes/mailer.php — SMTP + mail() fallback helper.
 */
require_once __DIR__ . '/../config/db.php';

function sendMail(string $to, string $subject, string $bodyHtml, string $bodyText = ''): bool
{
    try {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $settings = loadMailerSettings();
        $subject  = trim(str_replace(["\r", "\n"], '', $subject));
        $fromName = trim($settings['smtp_from_name']) !== '' ? $settings['smtp_from_name'] : 'ProcureERP';
        $fromName = str_replace(["\r", "\n"], '', $fromName);
        $fromMail = trim($settings['smtp_from_email']);
        if ($fromMail === '' || !filter_var($fromMail, FILTER_VALIDATE_EMAIL)) {
            $fromMail = trim($settings['smtp_user']);
        }
        if ($fromMail === '' || !filter_var($fromMail, FILTER_VALIDATE_EMAIL)) {
            $fromMail = 'no-reply@localhost';
        }

        $html = buildMailTemplate($bodyHtml);
        $text = trim($bodyText) !== '' ? $bodyText : trim(html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES, 'UTF-8'));
        $mime = buildMultipartMessage($fromName, $fromMail, $to, $subject, $text, $html);

        if ($settings['smtp_enabled'] === '1') {
            return sendViaSmtp($mime, $settings);
        }

        $headers = implode("\r\n", $mime['headers']);
        return @mail($to, $subject, $mime['body'], $headers);
    } catch (Throwable $e) {
        error_log('sendMail failed: ' . $e->getMessage());
        return false;
    }
}

function loadMailerSettings(): array
{
    $defaults = [
        'smtp_enabled'    => '0',
        'smtp_host'       => '',
        'smtp_port'       => '587',
        'smtp_encryption' => 'tls',
        'smtp_user'       => '',
        'smtp_pass'       => '',
        'smtp_from_name'  => 'ProcureERP',
        'smtp_from_email' => '',
    ];

    try {
        $pdo = getPDO();
        $stmt = $pdo->query("SELECT `key`, value FROM settings WHERE `key` IN ('smtp_enabled','smtp_host','smtp_port','smtp_encryption','smtp_user','smtp_pass','smtp_from_name','smtp_from_email')");
        $rows = $stmt ? $stmt->fetchAll() : [];
        foreach ($rows as $row) {
            if (isset($defaults[$row['key']])) {
                $defaults[$row['key']] = (string)$row['value'];
            }
        }
    } catch (Throwable $e) {
        error_log('loadMailerSettings failed: ' . $e->getMessage());
    }

    return $defaults;
}

function buildMailTemplate(string $contentHtml): string
{
    return '<!doctype html><html><body style="margin:0;padding:24px;background:#0f172a;font-family:Arial,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
        . '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:100%;background:#ffffff;border-radius:12px;overflow:hidden;">'
        . '<tr><td style="padding:18px 24px;background:#1e293b;color:#ffffff;font-size:18px;font-weight:700;">ProcureERP</td></tr>'
        . '<tr><td style="padding:24px;color:#0f172a;font-size:14px;line-height:1.6;">' . $contentHtml . '</td></tr>'
        . '<tr><td style="padding:16px 24px;background:#f8fafc;color:#64748b;font-size:12px;">This is an automated message from ProcureERP.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function buildMultipartMessage(string $fromName, string $fromEmail, string $to, string $subject, string $text, string $html): array
{
    $boundary  = 'b1_' . bin2hex(random_bytes(12));
    $safeSubj  = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $safeFrom  = '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>';
    $headers   = [
        'From: ' . $safeFrom,
        'To: <' . $to . '>',
        'Subject: ' . $safeSubj,
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . uniqid('procureerp_', true) . '@' . preg_replace('/[^a-z0-9\.\-]/i', '', ($_SERVER['HTTP_HOST'] ?? 'localhost')) . '>',
    ];

    $body = '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $text . "\r\n\r\n"
        . '--' . $boundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $html . "\r\n\r\n"
        . '--' . $boundary . "--\r\n";

    return ['headers' => $headers, 'body' => $body, 'subject' => $subject, 'to' => $to, 'from' => $fromEmail];
}

function sendViaSmtp(array $mime, array $settings): bool
{
    $host       = trim($settings['smtp_host']);
    $port       = (int)($settings['smtp_port'] ?: 587);
    $encryption = strtolower(trim($settings['smtp_encryption']));
    $user       = trim($settings['smtp_user']);
    $pass       = (string)$settings['smtp_pass'];

    if ($host === '' || $port <= 0) {
        error_log('SMTP is enabled but host/port is missing.');
        return false;
    }

    $connectHost = ($encryption === 'ssl' || $port === 465) ? ('ssl://' . $host) : $host;
    $socket = @fsockopen($connectHost, $port, $errno, $errstr, 10);
    if (!$socket) {
        error_log('SMTP connect failed: ' . $errstr . ' (' . $errno . ')');
        return false;
    }

    stream_set_timeout($socket, 15);

    try {
        smtpExpect($socket, [220]);
        smtpCommand($socket, 'EHLO procureerp.local', [250]);

        if ($encryption === 'tls' || ($encryption !== 'ssl' && $port === 587)) {
            smtpCommand($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Unable to enable TLS for SMTP connection.');
            }
            smtpCommand($socket, 'EHLO procureerp.local', [250]);
        }

        if ($user !== '') {
            smtpCommand($socket, 'AUTH LOGIN', [334]);
            smtpCommand($socket, base64_encode($user), [334]);
            smtpCommand($socket, base64_encode($pass), [235]);
        }

        smtpCommand($socket, 'MAIL FROM:<' . $mime['from'] . '>', [250]);
        smtpCommand($socket, 'RCPT TO:<' . $mime['to'] . '>', [250, 251]);
        smtpCommand($socket, 'DATA', [354]);

        $payload = implode("\r\n", $mime['headers']) . "\r\n\r\n" . $mime['body'];
        $payload = preg_replace('/\r?\n\./', "\r\n..", $payload);
        fwrite($socket, $payload . "\r\n.\r\n");
        smtpExpect($socket, [250]);

        smtpCommand($socket, 'QUIT', [221]);
        fclose($socket);
        return true;
    } catch (Throwable $e) {
        error_log('SMTP send failed: ' . $e->getMessage());
        @fwrite($socket, "QUIT\r\n");
        @fclose($socket);
        return false;
    }
}

function smtpCommand($socket, string $command, array $expected): string
{
    fwrite($socket, $command . "\r\n");
    return smtpExpect($socket, $expected);
}

function smtpExpect($socket, array $expected): string
{
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $expected, true)) {
        throw new RuntimeException('Unexpected SMTP response (' . $code . '): ' . trim($response));
    }

    return $response;
}
