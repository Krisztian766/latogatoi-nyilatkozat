<?php
require_once __DIR__ . '/includes/functions.php';

// Language detection
if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_GET['lang']) && in_array($_GET['lang'], ['hu','en'])) {
    $_SESSION['lang'] = $_GET['lang'];
}
$lang = $_SESSION['lang'] ?? 'hu';
$allT = require __DIR__ . '/includes/lang.php';
$t    = $allT[$lang];

$csrf     = generateCsrf();
$today    = date('Y-m-d');
$today_hu = $lang === 'hu' ? date('Y. m. d.') : date('d/m/Y');

$title    = getSetting('site_title_' . $lang,    $lang === 'hu' ? 'Látogatói nyilatkozat' : 'Visitor Declaration');
$subtitle = getSetting('site_title_' . ($lang === 'hu' ? 'en' : 'hu'), $lang === 'hu' ? 'Visitor Declaration' : 'Látogatói nyilatkozat');

$fName    = getSetting('field_name_label_'    . $lang, $lang === 'hu' ? 'Név' : 'Name');
$fCompany = getSetting('field_company_label_' . $lang, $lang === 'hu' ? 'Képviselt Cég' : 'Company');
$fContact = getSetting('field_contact_label_' . $lang, $lang === 'hu' ? 'Helyi kapcsolattartó' : 'Who are you visiting?');
$gdprLabel  = getSetting('gdpr_checkbox_label_' . $lang, '');
$gdprNotice = getSetting('gdpr_notice_text_'    . $lang, '');

$para = [];
for ($i = 1; $i <= 4; $i++) {
    $p = getSetting("decl_para_{$i}_{$lang}");
    if (trim($p)) $para[] = $p;
}

// Contact-person autocomplete. Company names are deliberately NOT offered here —
// this page is public, and listing prior visitor companies would leak business
// relationships to anyone viewing the page source.
$knownContacts = getDB()->query("SELECT DISTINCT contact FROM declarations WHERE contact <> '' ORDER BY contact LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);

$error = '';
if (isset($_GET['error'])) {
    $map = [
        'missing_fields' => $t['err_missing'],
        'no_signature'   => $t['err_signature'],
        'no_gdpr'        => $t['err_gdpr'],
        'db_error'       => $t['err_db'],
        'rate_limit'     => $t['err_rate_limit'],
    ];
    $error = $map[$_GET['error']] ?? '';
}

// Base URL preserving lang
$langParam = '?lang=' . $lang;
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>

<div class="page-top">
    <img src="/assets/logo.png" alt="Logo" class="site-logo">
    <div class="lang-switcher">
        <a href="<?= $langParam ?>" class="lang-btn <?= $lang==='hu' ? 'active' : '' ?>"
           onclick="setLang('hu'); return false;">HU</a>
        <a href="<?= $langParam ?>" class="lang-btn <?= $lang==='en' ? 'active' : '' ?>"
           onclick="setLang('en'); return false;">EN</a>
    </div>
</div>

<div class="container">
    <div class="form-card">

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">
                <?= icon('check') ?> <?= e($t['success']) ?>
            </div>
            <p style="text-align:center;margin-top:1.5rem">
                <a href="/<?= $langParam ?>" class="btn btn-primary"><?= e($t['btn_new']) ?></a>
            </p>
            <p id="autoResetHint" style="text-align:center;margin-top:1rem;font-size:.82rem;color:var(--gray-500)"></p>
            <script>
            (function () {
                var seconds  = 8;
                var el       = document.getElementById('autoResetHint');
                var template = <?= json_encode($t['auto_reset']) ?>;
                function tick() {
                    el.textContent = template.replace('{s}', seconds);
                    if (seconds <= 0) { window.location.href = '/<?= $langParam ?>'; return; }
                    seconds--;
                    setTimeout(tick, 1000);
                }
                tick();
            })();
            </script>
        <?php else: ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>

        <form id="declarationForm" method="POST" action="/submit.php<?= $langParam ?>">
            <input type="hidden" name="csrf_token"     value="<?= e($csrf) ?>">
            <input type="hidden" name="visit_date"     value="<?= e($today) ?>">
            <input type="hidden" name="lang"           value="<?= $lang ?>">
            <input type="hidden" name="signature_data" id="signatureData">

            <p class="section-label"><?= e($t['section_personal']) ?></p>

            <div class="form-group">
                <label for="name"><?= e($fName) ?> <span class="required">*</span></label>
                <input type="text" id="name" name="name" required
                       placeholder="<?= e($t['placeholder_name']) ?>"
                       value="<?= e($_GET['name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="company"><?= e($fCompany) ?></label>
                <input type="text" id="company" name="company"
                       placeholder="<?= e($t['placeholder_company']) ?>"
                       value="<?= e($_GET['company'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="contact"><?= e($fContact) ?> <span class="required">*</span></label>
                <input type="text" id="contact" name="contact" required list="contactList"
                       placeholder="<?= e($t['placeholder_contact']) ?>"
                       value="<?= e($_GET['contact'] ?? '') ?>">
                <datalist id="contactList">
                    <?php foreach ($knownContacts as $c): ?>
                        <option value="<?= e($c) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <p class="section-label"><?= e($t['section_declaration']) ?></p>
            <div class="declaration-box">
                <?php foreach ($para as $p): ?>
                    <p><?= nl2br(e($p)) ?></p>
                <?php endforeach; ?>
            </div>

            <p class="section-label"><?= e($t['section_signature']) ?></p>
            <div class="date-row">
                <span><?= e($t['date_label']) ?></span>
                <strong><?= e($today_hu) ?></strong>
            </div>
            <div class="form-group">
                <label><?= e($t['section_signature']) ?> <span class="required">*</span></label>
                <div class="sig-wrapper">
                    <canvas id="signaturePad"></canvas>
                    <button type="button" id="clearSig" class="btn-clear"><?= icon('x') ?> <?= e($t['btn_clear']) ?></button>
                </div>
                <p class="sig-hint"><?= e($t['sig_hint']) ?></p>
                <p class="alert alert-error" id="sigError" style="display:none;margin-top:.6rem"><?= e($t['err_signature']) ?></p>
            </div>

            <?php if ($gdprLabel || $gdprNotice): ?>
            <div class="gdpr-block" style="margin-top:2rem">
                <div class="gdpr-header">
                    <span class="gdpr-header-text"><?= e($t['section_gdpr']) ?></span>
                </div>
                <div class="gdpr-body">
                    <?php if ($gdprNotice): ?>
                        <button type="button" class="gdpr-toggle" id="gdprToggle">
                            <span><?= e($t['gdpr_show']) ?></span>
                            <span class="gdpr-toggle-arrow"><?= icon('chevron-down') ?></span>
                        </button>
                        <div class="gdpr-notice-text" id="gdprNoticeText" style="display:none">
                            <?= nl2br(e($gdprNotice)) ?>
                        </div>
                    <?php endif; ?>
                    <label class="checkbox-label gdpr-check">
                        <input type="checkbox" name="gdpr_consent" value="1" required id="gdprCheck">
                        <span><?= e($gdprLabel) ?> <span class="required">*</span></span>
                    </label>
                </div>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary btn-full" id="submitBtn">
                <?= icon('check') ?> <?= e($t['btn_submit']) ?>
            </button>
        </form>

        <?php endif; ?>
    </div>
</div>

<script src="/assets/js/signature_pad.min.js"></script>
<script src="/assets/js/app.js"></script>
<script>
var gdprShowText = <?= json_encode($t['gdpr_show']) ?>;
var gdprHideText = <?= json_encode($t['gdpr_hide']) ?>;

var toggle = document.getElementById('gdprToggle');
if (toggle) {
    toggle.addEventListener('click', function() {
        var box  = document.getElementById('gdprNoticeText');
        var open = box.style.display !== 'none';
        box.style.display = open ? 'none' : 'block';
        toggle.classList.toggle('open', !open);
        toggle.querySelector('span:first-child').textContent = open ? gdprShowText : gdprHideText;
    });
}

function setLang(l) {
    var url = new URL(window.location.href);
    url.searchParams.set('lang', l);
    window.location.href = url.toString();
}
</script>
</body>
</html>
