<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['save_gdpr'])) {
        setSetting('gdpr_checkbox_label_hu', trim($_POST['gdpr_checkbox_label_hu'] ?? ''));
        setSetting('gdpr_checkbox_label_en', trim($_POST['gdpr_checkbox_label_en'] ?? ''));
        setSetting('gdpr_notice_text_hu',    trim($_POST['gdpr_notice_text_hu'] ?? ''));
        setSetting('gdpr_notice_text_en',    trim($_POST['gdpr_notice_text_en'] ?? ''));
        setSetting('retention_days',         max(30, (int)($_POST['retention_days'] ?? 730)));
        $success = 'GDPR beállítások mentve!';
    }

    if (isset($_POST['cleanup_expired'])) {
        $db      = getDB();
        $deleted = $db->exec("DELETE FROM declarations WHERE expires_at < CURDATE()");
        logAudit('cleanup', null, "{$deleted} lejárt rekord törölve");
        $success = "{$deleted} lejárt rekord sikeresen törölve.";
    }

    if (isset($_POST['save_general'])) {
        setSetting('site_title_hu', trim($_POST['site_title_hu'] ?? ''));
        setSetting('site_title_en', trim($_POST['site_title_en'] ?? ''));
        setSetting('company_name',  trim($_POST['company_name'] ?? ''));
        $success = 'Általános beállítások mentve!';
    }

    if (isset($_POST['save_fields'])) {
        foreach (['hu','en'] as $l) {
            setSetting("field_name_label_{$l}",    trim($_POST["field_name_label_{$l}"] ?? ''));
            setSetting("field_company_label_{$l}", trim($_POST["field_company_label_{$l}"] ?? ''));
            setSetting("field_contact_label_{$l}", trim($_POST["field_contact_label_{$l}"] ?? ''));
        }
        $success = 'Mezőfeliratok mentve!';
    }

    if (isset($_POST['save_declaration'])) {
        foreach (['hu','en'] as $l) {
            for ($i = 1; $i <= 4; $i++) {
                setSetting("decl_para_{$i}_{$l}", trim($_POST["decl_para_{$i}_{$l}"] ?? ''));
            }
        }
        $success = 'Nyilatkozat szövege mentve!';
    }

    if (isset($_POST['save_email'])) {
        setSetting('notification_email',    trim($_POST['notification_email'] ?? ''));
        setSetting('notification_email_cc', trim($_POST['notification_email_cc'] ?? ''));
        $success = 'Email beállítások mentve!';
    }

    if (isset($_POST['clear_email'])) {
        setSetting('notification_email', '');
        $success = 'Értesítési email cím törölve.';
    }

    if (isset($_POST['clear_email_cc'])) {
        setSetting('notification_email_cc', '');
        $success = 'CC email cím törölve.';
    }

    if (isset($_POST['test_email'])) {
        $email = getSetting('notification_email');
        if ($email) {
            $ok = sendSmtpEmail($email, 'Teszt email – Látogatói rendszer',
                "Ez egy teszt email a látogatói nyilatkozat rendszerből.\nHa ezt látja, az email küldés működik!");
            $success = $ok ? 'Teszt email elküldve: ' . $email : 'Email küldés sikertelen!';
        } else {
            $error = 'Nincs beállítva értesítési email cím!';
        }
    }

    if (isset($_POST['save_pdf'])) {
        setSetting('pdf_doc_id',      trim($_POST['pdf_doc_id'] ?? ''));
        setSetting('pdf_doc_ver',     trim($_POST['pdf_doc_ver'] ?? ''));
        setSetting('pdf_prepared_by', trim($_POST['pdf_prepared_by'] ?? ''));
        setSetting('pdf_approved_by', trim($_POST['pdf_approved_by'] ?? ''));
        $success = 'PDF beállítások mentve!';
    }

    if (isset($_POST['change_password'])) {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $db      = getDB();
        $stmt    = $db->prepare('SELECT password_hash FROM admin_users WHERE username = ?');
        $stmt->execute([$_SESSION['admin_username']]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($current, $user['password_hash'])) {
            $error = 'A jelenlegi jelszó helytelen!';
        } elseif ($new !== $confirm) {
            $error = 'Az új jelszavak nem egyeznek!';
        } elseif (strlen($new) < 6) {
            $error = 'A jelszónak legalább 6 karakter hosszúnak kell lennie!';
        } else {
            $db->prepare('UPDATE admin_users SET password_hash=? WHERE username=?')
               ->execute([password_hash($new, PASSWORD_BCRYPT), $_SESSION['admin_username']]);
            $success = 'Jelszó sikeresen megváltoztatva!';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin – Beállítások</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="admin-wrapper">
<?php include __DIR__ . '/partials/nav.php'; ?>

<div class="admin-content">
    <h2 style="margin-bottom:1.5rem">Beállítások / Settings</h2>

    <?php if ($error):   ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <div class="settings-grid">

        <!-- General -->
        <div class="form-card">
            <h3>Általános</h3>
            <form method="POST">
                <div class="lang-tabs-wrap">
                    <div class="lang-tabs">
                        <button type="button" class="lang-tab active" data-tab="hu-gen">Magyar</button>
                        <button type="button" class="lang-tab" data-tab="en-gen">English</button>
                    </div>
                    <div class="lang-tab-content active" id="tab-hu-gen">
                        <div class="form-group">
                            <label>Oldal főcíme (HU):</label>
                            <input type="text" name="site_title_hu" value="<?= e(getSetting('site_title_hu','Látogatói nyilatkozat')) ?>">
                        </div>
                    </div>
                    <div class="lang-tab-content" id="tab-en-gen">
                        <div class="form-group">
                            <label>Page title (EN):</label>
                            <input type="text" name="site_title_en" value="<?= e(getSetting('site_title_en','Visitor Declaration')) ?>">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Szervezet neve (emailekben):</label>
                    <input type="text" name="company_name" value="<?= e(getSetting('company_name','')) ?>" placeholder="pl. SDS Kft.">
                </div>
                <button type="submit" name="save_general" class="btn btn-primary"><?= icon('check') ?> Mentés</button>
            </form>
        </div>

        <!-- GDPR -->
        <div class="form-card settings-full">
            <h3>GDPR &ndash; Adatkezelési beállítások</h3>
            <form method="POST">
                <div class="form-group" style="max-width:300px">
                    <label>Adatmegőrzési idő (napokban):</label>
                    <input type="number" name="retention_days" min="30" max="3650"
                           value="<?= e(getSetting('retention_days','730')) ?>">
                    <small>Default: 730 nap (2 év).</small>
                </div>

                <div class="lang-tabs-wrap" style="margin-top:1.25rem">
                    <div class="lang-tabs">
                        <button type="button" class="lang-tab active" data-tab="hu-gdpr">Magyar</button>
                        <button type="button" class="lang-tab" data-tab="en-gdpr">English</button>
                    </div>
                    <div class="lang-tab-content active" id="tab-hu-gdpr">
                        <div class="form-group">
                            <label>Checkbox felirata (HU):</label>
                            <input type="text" name="gdpr_checkbox_label_hu"
                                   value="<?= e(getSetting('gdpr_checkbox_label_hu','Hozzájárulok az adatkezelési tájékoztatóban foglaltakhoz.')) ?>">
                        </div>
                        <div class="form-group">
                            <label>Adatkezelési tájékoztató szövege (HU):</label>
                            <textarea name="gdpr_notice_text_hu" rows="7"><?= e(getSetting('gdpr_notice_text_hu','')) ?></textarea>
                            <small>Ha üres, nem jelenik meg a tájékoztató gomb.</small>
                        </div>
                    </div>
                    <div class="lang-tab-content" id="tab-en-gdpr">
                        <div class="form-group">
                            <label>Checkbox label (EN):</label>
                            <input type="text" name="gdpr_checkbox_label_en"
                                   value="<?= e(getSetting('gdpr_checkbox_label_en','I consent to the processing of my personal data as described in the privacy notice.')) ?>">
                        </div>
                        <div class="form-group">
                            <label>Privacy notice text (EN):</label>
                            <textarea name="gdpr_notice_text_en" rows="7"><?= e(getSetting('gdpr_notice_text_en','')) ?></textarea>
                            <small>If empty, the privacy notice button won't appear.</small>
                        </div>
                    </div>
                </div>
                <button type="submit" name="save_gdpr" class="btn btn-primary"><?= icon('check') ?> Mentés</button>
            </form>
        </div>

        <!-- Data retention management -->
        <div class="form-card">
            <h3>Adatmegőrzés kezelése</h3>
            <?php
                $retDays = (int)getSetting('retention_days','730');
                $expiredCount = (int)getDB()->query("SELECT COUNT(*) FROM declarations WHERE expires_at < CURDATE()")->fetchColumn();
                $soonCount    = (int)getDB()->query("SELECT COUNT(*) FROM declarations WHERE expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
            ?>
            <table class="info-table" style="margin-bottom:1rem">
                <tr><th>Megőrzési idő</th><td><strong><?= $retDays ?> nap</strong> (<?= round($retDays/365,1) ?> év)</td></tr>
                <tr><th>Lejárt rekordok</th>
                    <td><?php if ($expiredCount > 0): ?>
                        <span style="color:var(--danger);font-weight:700;display:inline-flex;align-items:center;gap:.3rem"><?= icon('warning') ?> <?= $expiredCount ?> db lejárt</span>
                    <?php else: ?>
                        <span style="color:var(--success);display:inline-flex;align-items:center;gap:.3rem"><?= icon('check') ?> Nincs lejárt</span>
                    <?php endif; ?></td></tr>
                <tr><th>30 napon belül lejár</th><td><?= $soonCount ?> db</td></tr>
            </table>
            <?php if ($expiredCount > 0): ?>
            <form method="POST" onsubmit="return confirm('Biztosan törli az összes lejárt nyilatkozatot? Ez visszafordíthatatlan!')">
                <button type="submit" name="cleanup_expired" class="btn btn-danger">
                    <?= icon('trash') ?> <?= $expiredCount ?> lejárt rekord törlése
                </button>
            </form>
            <?php else: ?>
            <p style="color:var(--gray-500);font-size:.88rem">Nincs törlendő rekord.</p>
            <?php endif; ?>
        </div>

        <!-- Field labels -->
        <div class="form-card">
            <h3>Mezőfeliratok szerkesztése</h3>
            <form method="POST">
                <div class="lang-tabs-wrap">
                    <div class="lang-tabs">
                        <button type="button" class="lang-tab active" data-tab="hu-fields">Magyar</button>
                        <button type="button" class="lang-tab" data-tab="en-fields">English</button>
                    </div>
                    <div class="lang-tab-content active" id="tab-hu-fields">
                        <div class="form-group"><label>1. mező – Név (HU):</label>
                            <input type="text" name="field_name_label_hu" value="<?= e(getSetting('field_name_label_hu','Név')) ?>"></div>
                        <div class="form-group"><label>2. mező – Cég (HU):</label>
                            <input type="text" name="field_company_label_hu" value="<?= e(getSetting('field_company_label_hu','Képviselt Cég')) ?>"></div>
                        <div class="form-group"><label>3. mező – Kapcsolattartó (HU):</label>
                            <input type="text" name="field_contact_label_hu" value="<?= e(getSetting('field_contact_label_hu','Helyi kapcsolattartó')) ?>"></div>
                    </div>
                    <div class="lang-tab-content" id="tab-en-fields">
                        <div class="form-group"><label>1st field – Name (EN):</label>
                            <input type="text" name="field_name_label_en" value="<?= e(getSetting('field_name_label_en','Name')) ?>"></div>
                        <div class="form-group"><label>2nd field – Company (EN):</label>
                            <input type="text" name="field_company_label_en" value="<?= e(getSetting('field_company_label_en','Company')) ?>"></div>
                        <div class="form-group"><label>3rd field – Contact (EN):</label>
                            <input type="text" name="field_contact_label_en" value="<?= e(getSetting('field_contact_label_en','Who are you visiting?')) ?>"></div>
                    </div>
                </div>
                <button type="submit" name="save_fields" class="btn btn-primary"><?= icon('check') ?> Mentés</button>
            </form>
        </div>

        <!-- Declaration text - full width -->
        <div class="form-card settings-full">
            <h3>Nyilatkozat szövegének szerkesztése</h3>
            <p style="font-size:.82rem;color:var(--gray-500);margin-bottom:1rem">
                Minden bekezdés külön szerkeszthető mindkét nyelven. Ha üres, nem jelenik meg.
            </p>
            <form method="POST">
                <div class="lang-tabs-wrap">
                    <div class="lang-tabs">
                        <button type="button" class="lang-tab active" data-tab="hu-decl">Magyar</button>
                        <button type="button" class="lang-tab" data-tab="en-decl">English</button>
                    </div>
                    <div class="lang-tab-content active" id="tab-hu-decl">
                        <?php for ($i=1;$i<=4;$i++): ?>
                        <div class="form-group">
                            <label><?= $i ?>. bekezdés (HU):</label>
                            <textarea name="decl_para_<?= $i ?>_hu" rows="3"><?= e(getSetting("decl_para_{$i}_hu")) ?></textarea>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <div class="lang-tab-content" id="tab-en-decl">
                        <?php for ($i=1;$i<=4;$i++): ?>
                        <div class="form-group">
                            <label>Paragraph <?= $i ?> (EN):</label>
                            <textarea name="decl_para_<?= $i ?>_en" rows="3"><?= e(getSetting("decl_para_{$i}_en")) ?></textarea>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin-top:1rem">
                    <button type="submit" name="save_declaration" class="btn btn-primary"><?= icon('check') ?> Mentés</button>
                    <a href="/?lang=hu" target="_blank" class="btn btn-ghost"><?= icon('eye') ?> Magyar előnézet</a>
                    <a href="/?lang=en" target="_blank" class="btn btn-ghost"><?= icon('eye') ?> English preview</a>
                </div>
            </form>
        </div>

        <!-- Email -->
        <div class="form-card">
            <h3>Email értesítők</h3>

            <?php
                $curEmail = getSetting('notification_email');
                $curCc    = getSetting('notification_email_cc');
            ?>
            <div class="notify-status">
                <div class="notify-row">
                    <span class="notify-label"><?= icon('mail') ?> Értesítve (TO):</span>
                    <?php if ($curEmail): ?>
                        <span class="notify-email"><?= e($curEmail) ?></span>
                        <form method="POST" style="display:inline">
                            <button type="submit" name="clear_email" class="btn-inline-del"
                                    onclick="return confirm('Törli az értesítési email címet?')"
                                    title="Törlés"><?= icon('x') ?></button>
                        </form>
                    <?php else: ?>
                        <span class="notify-none">– nincs beállítva –</span>
                    <?php endif; ?>
                </div>
                <div class="notify-row">
                    <span class="notify-label"><?= icon('mail') ?> Másolat (CC):</span>
                    <?php if ($curCc): ?>
                        <span class="notify-email"><?= e($curCc) ?></span>
                        <form method="POST" style="display:inline">
                            <button type="submit" name="clear_email_cc" class="btn-inline-del"
                                    onclick="return confirm('Törli a CC email címet?')"
                                    title="Törlés"><?= icon('x') ?></button>
                        </form>
                    <?php else: ?>
                        <span class="notify-none">– nincs beállítva –</span>
                    <?php endif; ?>
                </div>
            </div>

            <form method="POST" style="margin-top:1rem">
                <div class="form-group">
                    <label>Értesítési cím módosítása (TO):</label>
                    <input type="email" name="notification_email" value="<?= e($curEmail) ?>" placeholder="iroda@ceg.hu">
                </div>
                <div class="form-group">
                    <label>Másolat módosítása (CC):</label>
                    <input type="email" name="notification_email_cc" value="<?= e($curCc) ?>" placeholder="vezeto@ceg.hu">
                </div>
                <div style="display:flex;gap:.6rem;flex-wrap:wrap">
                    <button type="submit" name="save_email" class="btn btn-primary"><?= icon('check') ?> Mentés</button>
                    <button type="submit" name="test_email" class="btn btn-secondary"><?= icon('mail') ?> Teszt küldés</button>
                </div>
            </form>
        </div>

        <!-- PDF Document settings -->
        <div class="form-card">
            <h3>PDF dokumentum adatok</h3>
            <p style="font-size:.82rem;color:var(--gray-500);margin-bottom:1rem">
                A PDF láblécében és fejlécében megjelenő adatok.
            </p>
            <form method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label>Dokumentum azonosító:</label>
                        <input type="text" name="pdf_doc_id" value="<?= e(getSetting('pdf_doc_id','')) ?>" placeholder="pl. F76">
                    </div>
                    <div class="form-group">
                        <label>Verzió:</label>
                        <input type="text" name="pdf_doc_ver" value="<?= e(getSetting('pdf_doc_ver','')) ?>" placeholder="pl. v8">
                    </div>
                </div>
                <div class="form-group">
                    <label>Készítette:</label>
                    <input type="text" name="pdf_prepared_by" value="<?= e(getSetting('pdf_prepared_by','')) ?>" placeholder="Teljes név">
                </div>
                <div class="form-group">
                    <label>Jóváhagyta:</label>
                    <input type="text" name="pdf_approved_by" value="<?= e(getSetting('pdf_approved_by','')) ?>" placeholder="Teljes név">
                </div>
                <button type="submit" name="save_pdf" class="btn btn-primary"><?= icon('check') ?> Mentés</button>
            </form>
        </div>

        <!-- Password -->
        <div class="form-card">
            <h3>Jelszó módosítása</h3>
            <p style="font-size:.82rem;color:var(--gray-500);margin-bottom:1rem">Bejelentkezve: <strong><?= e($_SESSION['admin_username']) ?></strong></p>
            <form method="POST">
                <div class="form-group">
                    <label>Jelenlegi jelszó:</label>
                    <input type="password" name="current_password" required>
                </div>
                <div class="form-group">
                    <label>Új jelszó:</label>
                    <input type="password" name="new_password" required minlength="6">
                </div>
                <div class="form-group">
                    <label>Megerősítés:</label>
                    <input type="password" name="confirm_password" required>
                </div>
                <button type="submit" name="change_password" class="btn btn-primary">Módosítás</button>
            </form>
        </div>

        <!-- QR + Info -->
        <div class="form-card">
            <h3>QR kód</h3>
            <p style="font-size:.82rem;color:var(--gray-500);margin-bottom:.75rem">Nyomtassa ki a bejárathoz:</p>
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= urlencode(SITE_URL) ?>"
                 alt="QR kód" style="border:1px solid #ddd;padding:6px;border-radius:4px;display:block;margin-bottom:.75rem">
            <a href="https://api.qrserver.com/v1/create-qr-code/?size=600x600&data=<?= urlencode(SITE_URL) ?>"
               target="_blank" class="btn btn-secondary btn-sm"><?= icon('download') ?> Nagy méret letöltése</a>

            <div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid #eee">
                <h3>Rendszer</h3>
                <table class="info-table" style="font-size:.82rem">
                    <tr><th>PHP</th><td><?= phpversion() ?></td></tr>
                    <tr><th>Adatbázis</th><td><?= DB_NAME ?></td></tr>
                    <tr><th>URL</th><td><?= SITE_URL ?></td></tr>
                </table>
            </div>
        </div>

    </div>
</div>
</div>
<script>
document.querySelectorAll('.lang-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
        var wrap = tab.closest('.lang-tabs-wrap');
        wrap.querySelectorAll('.lang-tab').forEach(function(t) { t.classList.remove('active'); });
        wrap.querySelectorAll('.lang-tab-content').forEach(function(c) { c.classList.remove('active'); });
        tab.classList.add('active');
        var target = document.getElementById('tab-' + tab.dataset.tab);
        if (target) target.classList.add('active');
    });
});
</script>
</body>
</html>
