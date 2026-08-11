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

// Site-wide password gate: this public visitor kiosk and the admin login both
// live on a public domain but are for staff/on-site use only, so they sit
// behind a single shared password. Document e-signing (sign.php) and the
// cron purge endpoint are deliberately NOT gated — they authenticate via
// their own per-recipient token / CRON_SECRET and gating them would break
// the remote-signer and the scheduled task, which have no way to enter a
// shared password.
function requireSiteAccess(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['site_unlocked'])) return;

    if (validateGateCookie($_COOKIE['site_access'] ?? '')) {
        $_SESSION['site_unlocked'] = true;
        return;
    }

    // No password configured yet: gate is inert rather than locking everyone out.
    if (getSetting('site_password_hash') === '') return;

    $return = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: /gate.php?return=' . urlencode($return));
    exit;
}

function issueGateCookie(): void {
    $exp     = time() + 60 * 60 * 24 * 180; // 180 days, so a kiosk device stays unlocked across restarts
    $payload = base64_encode(json_encode(['exp' => $exp]));
    $sig     = hash_hmac('sha256', $payload, SITE_GATE_SECRET);
    setcookie('site_access', $payload . '.' . $sig, [
        'expires'  => $exp,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function validateGateCookie(string $cookie): bool {
    if ($cookie === '' || substr_count($cookie, '.') !== 1) return false;
    [$payload, $sig] = explode('.', $cookie, 2);
    $expected = hash_hmac('sha256', $payload, SITE_GATE_SECRET);
    if (!hash_equals($expected, $sig)) return false;
    $data = json_decode((string)base64_decode($payload, true), true);
    if (!is_array($data) || empty($data['exp'])) return false;
    return time() < (int)$data['exp'];
}

// Only ever redirect back to a same-site relative path (blocks //host and
// scheme-based open-redirect payloads via the gate's ?return= parameter).
function safeReturnPath(string $path): string {
    // Browsers normalize a leading "/\" the same as "//" for http(s) URLs,
    // treating it as a protocol-relative absolute URL — so both must be
    // blocked, not just "//", or this becomes an open redirect.
    if ($path === '' || $path[0] !== '/' || (isset($path[1]) && ($path[1] === '/' || $path[1] === '\\'))) {
        return '/';
    }
    return $path;
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
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("sendSmtpEmail: refusing malformed recipient address: {$to}");
        return false;
    }

    $host     = SMTP_HOST;
    $port     = SMTP_PORT;
    $username = SMTP_USER;
    $password = SMTP_PASS;
    $from     = SMTP_FROM;
    $fromName = SMTP_FROM_NAME;

    $context = stream_context_create([
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
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

// Minimal monochrome line-icon set (stroke-based, currentColor) used across the UI instead of emoji.
function icon(string $name): string {
    $defs = [
        'search'     => '<circle cx="10" cy="10" r="6"/><line x1="20" y1="20" x2="14.5" y2="14.5"/>',
        'plus'       => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'eye'        => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'edit'       => '<path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'trash'      => '<path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
        'download'   => '<path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M5 21h14"/>',
        'upload'     => '<path d="M12 21V9"/><path d="M7 14l5-5 5 5"/><path d="M5 21h14"/>',
        'lock'       => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'folder'     => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/>',
        'gear'       => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
        'users'      => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'mail'       => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/>',
        'chart'      => '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
        'link'       => '<path d="M10 13a5 5 0 0 0 7.07 0l2.83-2.83a5 5 0 0 0-7.07-7.07L11.5 4.5"/><path d="M14 11a5 5 0 0 0-7.07 0L4.1 13.83a5 5 0 0 0 7.07 7.07L12.5 19.5"/>',
        'document'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><polyline points="14 2 14 8 20 8"/>',
        'arrow-left' => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
        'check'      => '<polyline points="20 6 9 17 4 12"/>',
        'x'          => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'refresh'    => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
        'ban'        => '<circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>',
        'warning'    => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'minus'      => '<circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/>',
        'paperclip'  => '<path d="M21.44 11.05 12.25 20.24a5.5 5.5 0 0 1-7.78-7.78l9.19-9.19a3.5 3.5 0 0 1 4.95 4.95l-9.2 9.19a1.5 1.5 0 0 1-2.12-2.12l8.49-8.48"/>',
        'log-out'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'chevron-down' => '<polyline points="6 9 12 15 18 9"/>',
        'list'       => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
        'calendar'   => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    ];
    $inner = $defs[$name] ?? '';
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}

function computeHash(string $name, string $company, string $contact, string $visit_date, string $signature_data): string {
    $payload = implode('||', [$name, $company, $contact, $visit_date, substr($signature_data, 0, 200)]);
    return hash('sha256', $payload);
}

function verifyHash(array $row): bool {
    $expected = computeHash($row['name'], $row['company'], $row['contact'], $row['visit_date'], $row['signature_data'] ?? '');
    return hash_equals($expected, $row['data_hash'] ?? '');
}

function computeDocHash(int $documentId, string $recipientEmail, string $signatureData): string {
    $payload = implode('||', [$documentId, $recipientEmail, substr($signatureData, 0, 200)]);
    return hash('sha256', $payload);
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
    // Deliberately ignores client-supplied X-Forwarded-For/X-Real-IP headers:
    // this app is not known to sit behind a trusted reverse proxy, and trusting
    // those headers let an attacker spoof a fresh IP per request to bypass both
    // the submission rate limiter and the admin login lockout.
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

// Shared between admin/pdf.php and admin/pdf_bulk.php, which are otherwise
// independent standalone print documents (each needs its own full <style>
// block for printing), so this one rule can't silently drift out of sync
// between the single and bulk PDF exports the way a copy-pasted block could.
function pdfCompanyNameCss(): string {
    return '.doc-header .logo .company-name {
            display: block;
            margin-top: 6px;
            font-size: 7.5pt;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: rgba(255,255,255,.75);
        }';
}

function loadPdfBrandingSettings(): array {
    $paraHu = [];
    $paraEn = [];
    for ($i = 1; $i <= 4; $i++) {
        $ph = getSetting("decl_para_{$i}_hu");
        $pe = getSetting("decl_para_{$i}_en");
        if (trim($ph)) $paraHu[] = $ph;
        if (trim($pe)) $paraEn[] = $pe;
    }

    $logoPath = __DIR__ . '/../assets/logo.png';
    $logoB64  = file_exists($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : '';

    return [
        'paraHu'    => $paraHu,
        'paraEn'    => $paraEn,
        'fName'     => getSetting('field_name_label_hu',    'Név / Name'),
        'fCompany'  => getSetting('field_company_label_hu', 'Képviselt Cég / Company'),
        'fContact'  => getSetting('field_contact_label_hu', 'Helyi kapcsolattartó / Who are you visiting?'),
        'titleHu'   => getSetting('site_title_hu', 'Látogatói nyilatkozat'),
        'titleEn'   => getSetting('site_title_en', 'Visitor Declaration'),
        'docDate'   => date('Y-m-d'),
        'docId'     => getSetting('pdf_doc_id',      'F76'),
        'docVer'    => getSetting('pdf_doc_ver',     'v8'),
        'prepared'  => getSetting('pdf_prepared_by', ''),
        'approved'  => getSetting('pdf_approved_by', ''),
        'logoB64'   => $logoB64,
    ];
}

// ── Induction flow: visit types (video + document + quiz) ──────────────────
// A visit type bundles an optional safety video, an optional long-form
// document, and an optional quiz gating entry. If no active visit type
// exists, index.php falls back to the original single-form flow untouched.

function getActiveVisitTypes(): array {
    return getDB()->query('SELECT * FROM visit_types WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
}

function getVisitType(int $id): ?array {
    $stmt = getDB()->prepare('SELECT * FROM visit_types WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getQuizQuestions(int $visitTypeId): array {
    $db = getDB();
    $qStmt = $db->prepare('SELECT * FROM quiz_questions WHERE visit_type_id = ? ORDER BY sort_order, id');
    $qStmt->execute([$visitTypeId]);
    $questions = $qStmt->fetchAll();

    $oStmt = $db->prepare('SELECT * FROM quiz_options WHERE question_id = ? ORDER BY sort_order, id');
    foreach ($questions as &$q) {
        $oStmt->execute([$q['id']]);
        $q['options'] = $oStmt->fetchAll();
    }
    unset($q);
    return $questions;
}

// $answers: [question_id => selected_option_id]. Scored purely server-side
// against the DB so a visitor can never influence their own score by editing
// client-side HTML/JS — the quiz page never sends is_correct to the browser.
function scoreQuiz(int $visitTypeId, array $answers): array {
    $questions = getQuizQuestions($visitTypeId);
    $total     = count($questions);
    $score     = 0;

    foreach ($questions as $q) {
        $selected = (int)($answers[$q['id']] ?? 0);
        foreach ($q['options'] as $opt) {
            if ((int)$opt['id'] === $selected && (int)$opt['is_correct'] === 1) {
                $score++;
                break;
            }
        }
    }

    $visitType = getVisitType($visitTypeId);
    $threshold = $visitType ? (int)$visitType['quiz_pass_percent'] : 100;
    $percent   = $total > 0 ? ($score / $total) * 100 : 100;

    return ['score' => $score, 'total' => $total, 'passed' => $percent >= $threshold];
}

function videoUploadDir(): string {
    return __DIR__ . '/../uploads/videos/';
}

// Rough minimum server-side dwell time before a document-read step can be
// marked done — a skim-reading allowance (not full careful-reading speed),
// floored at 5s. This is a backstop against instantly POSTing mark_doc_done
// without ever viewing the page (e.g. via devtools), not a precise read-time
// measurement; the real UX gate is the client-side scroll-to-bottom check.
function minReadSeconds(string $text): int {
    $words = str_word_count(strip_tags($text));
    return max(5, (int)ceil($words / 200 * 60 * 0.35));
}

// Server-side re-check of induction completeness — index.php uses this to
// decide whether to redirect into induction.php, and submit.php uses it
// again right before writing to the DB so the gate can't be bypassed by
// POSTing straight to submit.php.
function inductionSatisfied(): bool {
    $activeTypes = getActiveVisitTypes();
    if (empty($activeTypes)) return true; // feature not configured, nothing to satisfy

    if (session_status() === PHP_SESSION_NONE) session_start();

    // A logged-in admin previewing the public form (e.g. the "preview" links in
    // Settings) isn't a real visitor and shouldn't be routed through the
    // visitor safety induction just to look at the declaration text.
    if (!empty($_SESSION['admin_logged_in'])) return true;

    $ind = $_SESSION['induction'] ?? null;
    if (!$ind) return false;

    $type = getVisitType((int)$ind['visit_type_id']);
    if (!$type || !in_array((int)$type['id'], array_column($activeTypes, 'id'), true)) return false;

    $hasVideo = !empty($type['video_path']);
    $hasDoc   = trim((string)$type['doc_content_hu']) !== '' || trim((string)$type['doc_content_en']) !== '';
    $hasQuiz  = count(getQuizQuestions((int)$type['id'])) > 0;

    if ($hasVideo && empty($ind['video_done']))  return false;
    if ($hasDoc   && empty($ind['doc_done']))    return false;
    if ($hasQuiz  && empty($ind['quiz_passed'])) return false;
    return true;
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
