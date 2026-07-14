<?php
require_once __DIR__ . '/db.php';

function getSetting(string $key, string $default = ''): string {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function setSetting(string $key, string $value): void {
    $db = getDB();
    $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?')
       ->execute([$key, $value, $value]);
}

function requireAdmin(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['admin_logged_in'])) {
        header('Location: ' . SITE_URL . '/admin/');
        exit;
    }
}

function generateCsrf(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $token): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function sendSmtpEmail(string $to, string $subject, string $body): bool {
    $host     = SMTP_HOST;
    $port     = SMTP_PORT;
    $username = SMTP_USER;
    $password = SMTP_PASS;
    $from     = SMTP_FROM;
    $fromName = SMTP_FROM_NAME;

    $context = stream_context_create([
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ]
    ]);

    $socket = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        error_log("sendSmtpEmail: connection to {$host}:{$port} failed ({$errno}): {$errstr}");
        return false;
    }

    $read = function() use ($socket) { return fgets($socket, 512); };
    $send = function(string $cmd) use ($socket) { fputs($socket, $cmd . "\r\n"); };
    $expectOk = function(string $step) use ($read): bool {
        $line = $read();
        $code = $line !== false ? (int)substr($line, 0, 3) : 0;
        if ($code < 200 || $code >= 400) {
            error_log("sendSmtpEmail: unexpected response after {$step}: " . trim((string)$line));
            return false;
        }
        return true;
    };

    $read();
    $send("EHLO czeczokrisztian.hu");
    while ($line = $read()) { if ($line[3] === ' ') break; }

    $send("AUTH LOGIN");
    $read();
    $send(base64_encode($username));
    $read();
    $send(base64_encode($password));
    if (!$expectOk('AUTH LOGIN')) { fclose($socket); return false; }

    $send("MAIL FROM:<{$from}>");
    if (!$expectOk('MAIL FROM')) { fclose($socket); return false; }
    $send("RCPT TO:<{$to}>");
    if (!$expectOk('RCPT TO')) { fclose($socket); return false; }
    $send("DATA");
    $read();

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFrom    = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $encodedBody    = chunk_split(base64_encode($body));

    $msg  = "From: {$encodedFrom} <{$from}>\r\n";
    $msg .= "To: <{$to}>\r\n";
    $msg .= "Subject: {$encodedSubject}\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n";
    $msg .= "\r\n";
    $msg .= $encodedBody . "\r\n.\r\n";

    fputs($socket, $msg);
    $sent = $expectOk('DATA body');
    $send("QUIT");
    fclose($socket);

    return $sent;
}

function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function computeHash(string $name, string $company, string $contact, string $visit_date, string $signature_data): string {
    $payload = implode('||', [$name, $company, $contact, $visit_date, substr($signature_data, 0, 200)]);
    return hash('sha256', $payload);
}

function verifyHash(array $row): bool {
    $expected = computeHash($row['name'], $row['company'], $row['contact'], $row['visit_date'], $row['signature_data'] ?? '');
    return hash_equals($expected, $row['data_hash'] ?? '');
}

function logAudit(string $action, ?int $recordId = null, string $details = ''): void {
    try {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $db       = getDB();
        $username = $_SESSION['admin_username'] ?? 'system';
        $ip       = $_SERVER['REMOTE_ADDR'] ?? '';
        $db->prepare('INSERT INTO audit_log (admin_username, action, record_id, details, ip_address) VALUES (?,?,?,?,?)')
           ->execute([$username, $action, $recordId, $details, $ip]);
    } catch (Exception $e) {}
}

function getClientIp(): string {
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            return explode(',', $_SERVER[$key])[0];
        }
    }
    return '';
}

function getRetentionDate(): string {
    $days = max(30, (int)getSetting('retention_days', '730'));
    return date('Y-m-d', strtotime("+{$days} days"));
}

function sendNotificationWithCc(array $declaration): void {
    $email = getSetting('notification_email');
    if (empty($email)) return;

    $subject = 'Új látogatói nyilatkozat: ' . $declaration['name'];
    $body  = "Új látogatói nyilatkozat érkezett:\n\n";
    $body .= "Név / Name:                " . $declaration['name'] . "\n";
    $body .= "Képviselt Cég / Company:   " . ($declaration['company'] ?: '-') . "\n";
    $body .= "Kapcsolattartó / Visiting: " . $declaration['contact'] . "\n";
    $body .= "Dátum / Date:              " . $declaration['visit_date'] . "\n";
    $body .= "GDPR hozzájárulás:         " . ($declaration['gdpr_accepted'] ? 'Igen' : 'Nem') . "\n\n";
    $body .= "Megtekintés:\n" . SITE_URL . '/admin/view.php?id=' . $declaration['id'] . "\n";

    if (!sendSmtpEmail($email, $subject, $body)) {
        logAudit('email_failed', $declaration['id'] ?? null, "Értesítő email nem ment ki: {$email}");
    }

    $cc = getSetting('notification_email_cc');
    if ($cc && $cc !== $email) {
        if (!sendSmtpEmail($cc, $subject, $body)) {
            logAudit('email_failed', $declaration['id'] ?? null, "Értesítő email (CC) nem ment ki: {$cc}");
        }
    }
}

// Keep old name as alias
function sendNotification(array $declaration): void {
    sendNotificationWithCc($declaration);
}
