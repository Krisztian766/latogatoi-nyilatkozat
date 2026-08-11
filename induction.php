<?php
require_once __DIR__ . '/includes/functions.php';
requireSiteAccess();
if (session_status() === PHP_SESSION_NONE) session_start();

if (isset($_GET['lang']) && in_array($_GET['lang'], ['hu', 'en'])) {
    $_SESSION['lang'] = $_GET['lang'];
}
$lang = $_SESSION['lang'] ?? 'hu';
$allT = require __DIR__ . '/includes/lang.php';
$t    = $allT[$lang];
$csrf = generateCsrf();

if (isset($_GET['change_type'])) {
    unset($_SESSION['induction']);
    header('Location: /induction.php?lang=' . $lang);
    exit;
}

$types = getActiveVisitTypes();
if (empty($types)) { header('Location: /'); exit; }

$ind = $_SESSION['induction'] ?? null;

// Resolve which visit type this session is currently doing.
$requestedTypeId = isset($_GET['type']) ? (int)$_GET['type'] : 0;
$activeIds = array_column($types, 'id');

if ($requestedTypeId && in_array($requestedTypeId, $activeIds, true)) {
    if (!$ind || (int)$ind['visit_type_id'] !== $requestedTypeId) {
        $ind = ['visit_type_id' => $requestedTypeId, 'video_done' => false, 'doc_done' => false,
                'quiz_passed' => false, 'quiz_score' => null, 'quiz_total' => null];
        $_SESSION['induction'] = $ind;
    }
} elseif (count($types) === 1 && (!$ind || !in_array((int)$ind['visit_type_id'], $activeIds, true))) {
    $ind = ['visit_type_id' => (int)$types[0]['id'], 'video_done' => false, 'doc_done' => false,
            'quiz_passed' => false, 'quiz_score' => null, 'quiz_total' => null];
    $_SESSION['induction'] = $ind;
} elseif ($ind && !in_array((int)$ind['visit_type_id'], $activeIds, true)) {
    // previously selected type no longer active
    $ind = null;
    unset($_SESSION['induction']);
}

// No type resolved yet and more than one choice exists: show the picker.
if (!$ind) {
    ?>
    <!DOCTYPE html>
    <html lang="<?= $lang ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= e($t['ind_choose_type']) ?></title>
        <link rel="stylesheet" href="/assets/css/style.css">
    </head>
    <body>
    <div class="page-top">
        <div class="brand">
            <img src="/assets/logo.png" alt="Logo" class="site-logo">
            <?php $companyName = getSetting('company_name'); if ($companyName): ?>
                <span class="brand-name"><?= e($companyName) ?></span>
            <?php endif; ?>
        </div>
        <div class="lang-switcher">
            <a href="?lang=hu" class="lang-btn <?= $lang==='hu'?'active':'' ?>">HU</a>
            <a href="?lang=en" class="lang-btn <?= $lang==='en'?'active':'' ?>">EN</a>
        </div>
    </div>
    <div class="container">
        <div class="form-card">
            <img src="/assets/logo.png" alt="Logo" class="picker-hero-logo">
            <h2 style="text-align:center;margin-bottom:1.5rem"><?= e($t['ind_choose_type']) ?></h2>
            <div class="visit-type-grid">
                <?php foreach ($types as $vt): ?>
                    <a class="visit-type-card" href="/induction.php?type=<?= $vt['id'] ?>&lang=<?= $lang ?>">
                        <?= e($lang === 'en' && trim($vt['name_en']) !== '' ? $vt['name_en'] : $vt['name_hu']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$type      = getVisitType((int)$ind['visit_type_id']);
if (!$type) { unset($_SESSION['induction']); header('Location: /induction.php'); exit; }

$questions = getQuizQuestions((int)$type['id']);
$hasVideo  = !empty($type['video_path']);
// Matches inductionSatisfied()'s check exactly: content in EITHER language
// counts, regardless of which language the visitor is currently viewing —
// otherwise a visit type with only-English (or only-Hungarian) content
// causes this page and index.php to disagree on whether a document step
// exists, producing an infinite redirect loop between the two.
$hasDoc    = trim((string)$type['doc_content_hu']) !== '' || trim((string)$type['doc_content_en']) !== '';
$hasQuiz   = count($questions) > 0;

$quizError  = '';
$quizResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        // ignore silently, just re-render the current step
    } elseif (isset($_POST['mark_video_done'])) {
        // Backstop against instantly POSTing this without the video ever
        // playing (e.g. via devtools): require a minimum elapsed time since
        // the video step was first shown. Not a real watch-time proof — the
        // 'ended' event driving the button is the primary gate — but it
        // closes the trivial one-request bypass.
        $startedAt = $_SESSION['induction']['step_started_at']['video'] ?? 0;
        if (time() - $startedAt >= 5) {
            $_SESSION['induction']['video_done'] = true;
            header('Location: /induction.php?lang=' . $lang);
            exit;
        }
        // else: fall through and re-render the video step unmarked
    } elseif (isset($_POST['mark_doc_done'])) {
        $docText = $lang === 'en' && trim((string)$type['doc_content_en']) !== '' ? $type['doc_content_en'] : $type['doc_content_hu'];
        $startedAt = $_SESSION['induction']['step_started_at']['document'] ?? 0;
        if (time() - $startedAt >= minReadSeconds((string)$docText)) {
            $_SESSION['induction']['doc_done'] = true;
            header('Location: /induction.php?lang=' . $lang);
            exit;
        }
    } elseif (isset($_POST['go_back'])) {
        // Lets a visitor who can't answer the quiz jump back to re-watch/re-read
        // the previous step. Only clears that one step's flag — earlier steps
        // (e.g. an already-watched video) stay completed.
        $target = $_POST['target_step'] ?? '';
        if ($target === 'video') {
            $_SESSION['induction']['video_done'] = false;
            unset($_SESSION['induction']['step_started_at']['video']);
        } elseif ($target === 'document') {
            $_SESSION['induction']['doc_done'] = false;
            unset($_SESSION['induction']['step_started_at']['document']);
        }
        header('Location: /induction.php?lang=' . $lang);
        exit;
    } elseif (isset($_POST['submit_quiz'])) {
        $answers = [];
        foreach (($_POST['answers'] ?? []) as $qid => $optId) {
            $answers[(int)$qid] = (int)$optId;
        }
        $quizResult = scoreQuiz((int)$type['id'], $answers);
        $_SESSION['induction']['quiz_score'] = $quizResult['score'];
        $_SESSION['induction']['quiz_total'] = $quizResult['total'];
        if ($quizResult['passed']) {
            $_SESSION['induction']['quiz_passed'] = true;
            header('Location: /induction.php?lang=' . $lang);
            exit;
        }
    }
}

// Determine the next required step purely from server-side session state.
if ($hasVideo && empty($_SESSION['induction']['video_done'])) {
    $step = 'video';
} elseif ($hasDoc && empty($_SESSION['induction']['doc_done'])) {
    $step = 'document';
} elseif ($hasQuiz && empty($_SESSION['induction']['quiz_passed'])) {
    $step = 'quiz';
} else {
    header('Location: /?lang=' . $lang);
    exit;
}

// Start the dwell-time clock for this step the first time it's actually shown.
if (($step === 'video' || $step === 'document') && empty($_SESSION['induction']['step_started_at'][$step])) {
    $_SESSION['induction']['step_started_at'][$step] = time();
}

$steps = [];
if ($hasVideo) $steps[] = ['key' => 'video', 'label' => $t['ind_step_video']];
if ($hasDoc)   $steps[] = ['key' => 'document', 'label' => $t['ind_step_document']];
if ($hasQuiz)  $steps[] = ['key' => 'quiz', 'label' => $t['ind_step_quiz']];
$stepIndex = array_search($step, array_column($steps, 'key'), true);

$typeName = $lang === 'en' && trim($type['name_en']) !== '' ? $type['name_en'] : $type['name_hu'];
$docTitle = $lang === 'en' && trim($type['doc_title_en']) !== '' ? $type['doc_title_en'] : $type['doc_title_hu'];
$docBody  = $lang === 'en' && trim((string)$type['doc_content_en']) !== '' ? $type['doc_content_en'] : $type['doc_content_hu'];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($typeName) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="page-top">
    <div class="brand">
        <img src="/assets/logo.png" alt="Logo" class="site-logo">
        <?php $companyName = getSetting('company_name'); if ($companyName): ?>
            <span class="brand-name"><?= e($companyName) ?></span>
        <?php endif; ?>
    </div>
    <div class="lang-switcher">
        <a href="?lang=hu" class="lang-btn <?= $lang==='hu'?'active':'' ?>">HU</a>
        <a href="?lang=en" class="lang-btn <?= $lang==='en'?'active':'' ?>">EN</a>
    </div>
</div>
<div class="container">
    <div class="form-card">
        <div class="induction-steps">
            <?php foreach ($steps as $i => $s): ?>
                <span class="induction-step <?= $i < $stepIndex ? 'done' : ($i === $stepIndex ? 'current' : '') ?>">
                    <?= $i + 1 ?>. <?= e($s['label']) ?>
                </span>
            <?php endforeach; ?>
        </div>
        <h2 style="text-align:center;margin:1rem 0 1.5rem"><?= e($typeName) ?></h2>

        <?php if (count($types) > 1): ?>
            <p style="text-align:center;margin-bottom:1rem">
                <a href="/induction.php?change_type=1&lang=<?= $lang ?>" class="visit-type-change">
                    <?= icon('arrow-left') ?> <?= e($t['ind_change_type']) ?>
                </a>
            </p>
        <?php endif; ?>

        <?php if ($stepIndex > 0): ?>
            <?php $prevStep = $steps[$stepIndex - 1]; ?>
            <form method="POST" style="margin-bottom:1rem">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="target_step" value="<?= e($prevStep['key']) ?>">
                <button type="submit" name="go_back" class="btn btn-ghost btn-full">
                    <?= icon('arrow-left') ?> <?= e(sprintf($t['ind_btn_back'], $prevStep['label'])) ?>
                </button>
            </form>
        <?php endif; ?>

        <?php if ($step === 'video'): ?>
            <p style="text-align:center;color:var(--gray-500);margin-bottom:1rem"><?= e($t['ind_video_hint']) ?></p>
            <video id="inductionVideo" src="/<?= e($type['video_path']) ?>" controls style="width:100%;border-radius:8px;background:#000"></video>
            <form method="POST" style="margin-top:1.25rem">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button type="submit" name="mark_video_done" id="videoNextBtn" class="btn btn-primary btn-full" disabled>
                    <?= icon('check') ?> <?= e($t['ind_btn_next']) ?>
                </button>
            </form>
            <script>
                var vid = document.getElementById('inductionVideo');
                var btn = document.getElementById('videoNextBtn');
                vid.addEventListener('ended', function () { btn.disabled = false; });
            </script>

        <?php elseif ($step === 'document'): ?>
            <?php if ($docTitle): ?><h3 style="margin-bottom:.75rem"><?= e($docTitle) ?></h3><?php endif; ?>
            <p style="color:var(--gray-500);margin-bottom:.75rem"><?= e($t['ind_doc_hint']) ?></p>
            <div id="docReader" class="doc-reader"><?= nl2br(e($docBody)) ?></div>
            <form method="POST" style="margin-top:1.25rem">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button type="submit" name="mark_doc_done" id="docNextBtn" class="btn btn-primary btn-full" disabled>
                    <?= icon('check') ?> <?= e($t['ind_btn_next']) ?>
                </button>
            </form>
            <script>
                var reader = document.getElementById('docReader');
                var dbtn   = document.getElementById('docNextBtn');
                function checkScroll() {
                    if (reader.scrollHeight <= reader.clientHeight + 4 ||
                        reader.scrollTop + reader.clientHeight >= reader.scrollHeight - 10) {
                        dbtn.disabled = false;
                    }
                }
                reader.addEventListener('scroll', checkScroll);
                checkScroll();
            </script>

        <?php elseif ($step === 'quiz'): ?>
            <?php if ($quizResult && !$quizResult['passed']): ?>
                <div class="alert alert-error">
                    <?= e(sprintf($t['ind_quiz_fail'], $quizResult['score'], $quizResult['total'], (int)$type['quiz_pass_percent'])) ?>
                </div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <?php foreach ($questions as $qi => $q): ?>
                    <div class="quiz-question">
                        <p class="quiz-question-text"><?= $qi + 1 ?>. <?= e($lang === 'en' && trim($q['question_en']) !== '' ? $q['question_en'] : $q['question_hu']) ?></p>
                        <?php foreach ($q['options'] as $o): ?>
                            <label class="quiz-option">
                                <input type="radio" name="answers[<?= $q['id'] ?>]" value="<?= $o['id'] ?>" required>
                                <span><?= e($lang === 'en' && trim($o['option_en']) !== '' ? $o['option_en'] : $o['option_hu']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <button type="submit" name="submit_quiz" class="btn btn-primary btn-full" style="margin-top:1rem">
                    <?= icon('check') ?> <?= e($t['ind_quiz_submit']) ?>
                </button>
            </form>
        <?php endif; ?>

    </div>
</div>
</body>
</html>
