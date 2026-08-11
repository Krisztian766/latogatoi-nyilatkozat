<?php
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$db   = getDB();
$id   = (int)($_GET['id'] ?? 0);
$type = getVisitType($id);
if (!$type) { header('Location: /admin/visit_types.php'); exit; }

$error   = '';
$success = '';
$csrf    = generateCsrf();

const MAX_VIDEO_BYTES = 64 * 1024 * 1024; // matches this host's post_max_size/upload_max_filesize
const ALLOWED_VIDEO_EXT = ['mp4', 'webm', 'mov', 'ogg'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf($_POST['csrf_token'] ?? '')) {
    $error = 'Érvénytelen kérés (CSRF). Próbálja újra.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['save_details'])) {
        $name_hu       = trim($_POST['name_hu'] ?? '');
        $name_en       = trim($_POST['name_en'] ?? '');
        $doc_title_hu  = trim($_POST['doc_title_hu'] ?? '');
        $doc_title_en  = trim($_POST['doc_title_en'] ?? '');
        $doc_content_hu = trim($_POST['doc_content_hu'] ?? '');
        $doc_content_en = trim($_POST['doc_content_en'] ?? '');
        $pass_percent  = max(0, min(100, (int)($_POST['quiz_pass_percent'] ?? 80)));
        $is_active     = !empty($_POST['is_active']) ? 1 : 0;
        $sort_order    = (int)($_POST['sort_order'] ?? 0);
        $trainer_name  = trim($_POST['trainer_name'] ?? '');
        $trainer_qual  = trim($_POST['trainer_qualification'] ?? '');
        $validity_days = trim($_POST['validity_days'] ?? '') !== '' ? max(1, (int)$_POST['validity_days']) : null;
        $show_position = !empty($_POST['show_position']) ? 1 : 0;

        if ($name_hu === '') {
            $error = 'A típus neve kötelező!';
        } else {
            $db->prepare('UPDATE visit_types SET name_hu=?, name_en=?, doc_title_hu=?, doc_title_en=?,
                           doc_content_hu=?, doc_content_en=?, quiz_pass_percent=?, is_active=?, sort_order=?,
                           trainer_name=?, trainer_qualification=?, validity_days=?, show_position=? WHERE id=?')
               ->execute([$name_hu, $name_en, $doc_title_hu, $doc_title_en,
                          $doc_content_hu, $doc_content_en, $pass_percent, $is_active, $sort_order,
                          $trainer_name, $trainer_qual, $validity_days, $show_position, $id]);
            logAudit('visit_type_updated', $id, $name_hu);
            $success = 'Adatok mentve!';
            $type = getVisitType($id);
        }
    }

    if (isset($_POST['upload_video']) && isset($_FILES['video_file'])) {
        $file = $_FILES['video_file'];
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            $error = 'Nem választott ki fájlt!';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $error = 'Feltöltési hiba (kód: ' . (int)$file['error'] . '). Lehet, hogy a fájl túl nagy (max 64MB).';
        } elseif ($file['size'] > MAX_VIDEO_BYTES) {
            $error = 'A videó túl nagy! Maximum 64MB tölthető fel.';
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ALLOWED_VIDEO_EXT, true)) {
                $error = 'Nem támogatott formátum! Engedélyezett: ' . implode(', ', ALLOWED_VIDEO_EXT);
            } elseif (!is_uploaded_file($file['tmp_name'])) {
                $error = 'Feltöltési hiba!';
            } else {
                $dir = videoUploadDir();
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $newName = 'vt' . $id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dir . $newName)) {
                    // remove the previous video file, if any
                    if (!empty($type['video_path'])) {
                        $old = $dir . basename($type['video_path']);
                        if (is_file($old)) @unlink($old);
                    }
                    $db->prepare('UPDATE visit_types SET video_path=? WHERE id=?')
                       ->execute(['uploads/videos/' . $newName, $id]);
                    logAudit('visit_type_video_uploaded', $id, $newName);
                    $success = 'Videó feltöltve!';
                    $type = getVisitType($id);
                } else {
                    $error = 'A fájl mentése sikertelen!';
                }
            }
        }
    }

    if (isset($_POST['remove_video'])) {
        if (!empty($type['video_path'])) {
            $old = videoUploadDir() . basename($type['video_path']);
            if (is_file($old)) @unlink($old);
        }
        $db->prepare('UPDATE visit_types SET video_path=NULL WHERE id=?')->execute([$id]);
        logAudit('visit_type_video_removed', $id);
        $success = 'Videó eltávolítva!';
        $type = getVisitType($id);
    }

    if (isset($_POST['add_question'])) {
        $qHu = trim($_POST['question_hu'] ?? '');
        $qEn = trim($_POST['question_en'] ?? '');
        $optsHu = array_map('trim', $_POST['option_hu'] ?? []);
        $optsEn = array_map('trim', $_POST['option_en'] ?? []);
        $correctIdx = (int)($_POST['correct_option'] ?? -1);

        $filledCount = count(array_filter($optsHu, fn($v) => $v !== ''));

        if ($qHu === '') {
            $error = 'A kérdés szövege kötelező!';
        } elseif ($filledCount < 2) {
            $error = 'Legalább 2 válaszlehetőség szükséges!';
        } elseif ($correctIdx < 0 || $correctIdx >= count($optsHu) || trim($optsHu[$correctIdx] ?? '') === '') {
            $error = 'Jelölje meg, melyik a helyes válasz!';
        } else {
            $db->prepare('INSERT INTO quiz_questions (visit_type_id, question_hu, question_en) VALUES (?, ?, ?)')
               ->execute([$id, $qHu, $qEn]);
            $qid = (int)$db->lastInsertId();
            $stmt = $db->prepare('INSERT INTO quiz_options (question_id, option_hu, option_en, is_correct, sort_order) VALUES (?, ?, ?, ?, ?)');
            $order = 0;
            foreach ($optsHu as $i => $oHu) {
                if (trim($oHu) === '') continue;
                $stmt->execute([$qid, $oHu, $optsEn[$i] ?? '', $i === $correctIdx ? 1 : 0, $order++]);
            }
            logAudit('quiz_question_added', $id, $qHu);
            $success = 'Kérdés hozzáadva!';
        }
    }

    if (isset($_POST['update_question'])) {
        $qid = (int)($_POST['question_id'] ?? 0);
        $qHu = trim($_POST['question_hu'] ?? '');
        $qEn = trim($_POST['question_en'] ?? '');
        $optsHu = array_map('trim', $_POST['option_hu'] ?? []);
        $optsEn = array_map('trim', $_POST['option_en'] ?? []);
        $correctIdx = (int)($_POST['correct_option'] ?? -1);

        $filledCount = count(array_filter($optsHu, fn($v) => $v !== ''));

        $check = $db->prepare('SELECT id FROM quiz_questions WHERE id = ? AND visit_type_id = ?');
        $check->execute([$qid, $id]);

        if (!$check->fetch()) {
            $error = 'A kérdés nem található!';
        } elseif ($qHu === '') {
            $error = 'A kérdés szövege kötelező!';
        } elseif ($filledCount < 2) {
            $error = 'Legalább 2 válaszlehetőség szükséges!';
        } elseif ($correctIdx < 0 || $correctIdx >= count($optsHu) || trim($optsHu[$correctIdx] ?? '') === '') {
            $error = 'Jelölje meg, melyik a helyes válasz!';
        } else {
            $db->prepare('UPDATE quiz_questions SET question_hu=?, question_en=? WHERE id=?')
               ->execute([$qHu, $qEn, $qid]);
            // Simplest correct way to handle changed/added/removed options: replace them all,
            // rather than trying to diff — avoids the class of bug where an edit could leave
            // a stale is_correct=1 on an option that's no longer meant to be the answer.
            $db->prepare('DELETE FROM quiz_options WHERE question_id = ?')->execute([$qid]);
            $stmt = $db->prepare('INSERT INTO quiz_options (question_id, option_hu, option_en, is_correct, sort_order) VALUES (?, ?, ?, ?, ?)');
            $order = 0;
            foreach ($optsHu as $i => $oHu) {
                if (trim($oHu) === '') continue;
                $stmt->execute([$qid, $oHu, $optsEn[$i] ?? '', $i === $correctIdx ? 1 : 0, $order++]);
            }
            logAudit('quiz_question_updated', $id, $qHu);
            $success = 'Kérdés frissítve!';
        }
    }

    if (isset($_POST['delete_question'])) {
        $qid = (int)$_POST['question_id'];
        $db->prepare('DELETE FROM quiz_questions WHERE id = ? AND visit_type_id = ?')->execute([$qid, $id]);
        logAudit('quiz_question_deleted', $id, (string)$qid);
        $success = 'Kérdés törölve!';
    }

    if (isset($_POST['delete_type'])) {
        if (!empty($type['video_path'])) {
            $old = videoUploadDir() . basename($type['video_path']);
            if (is_file($old)) @unlink($old);
        }
        $name = $type['name_hu'];
        $db->prepare('DELETE FROM visit_types WHERE id = ?')->execute([$id]);
        logAudit('visit_type_deleted', null, $name);
        header('Location: /admin/visit_types.php?deleted=1');
        exit;
    }
}

$questions = getQuizQuestions($id);

$editQid       = (int)($_GET['edit_question'] ?? 0);
$editQuestion  = null;
foreach ($questions as $q) {
    if ((int)$q['id'] === $editQid) { $editQuestion = $q; break; }
}
$editOpts = [];
$editCorrectIdx = -1;
if ($editQuestion) {
    foreach ($editQuestion['options'] as $i => $o) {
        $editOpts[$i] = $o;
        if ($o['is_correct']) $editCorrectIdx = $i;
    }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin – <?= e($type['name_hu']) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="admin-wrapper">
<?php include __DIR__ . '/partials/nav.php'; ?>

<div class="admin-content">
    <div style="margin-bottom:1rem">
        <a href="/admin/visit_types.php" class="btn btn-secondary btn-sm"><?= icon('arrow-left') ?> Vissza a típusokhoz</a>
    </div>
    <h2 style="margin-bottom:1.5rem"><?= e($type['name_hu']) ?></h2>

    <?php if (isset($_GET['created'])): ?><div class="alert alert-success">Típus létrehozva! Töltse ki az alábbi adatokat.</div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <div class="settings-grid">

        <!-- Basic details -->
        <div class="form-card settings-full">
            <h3>Alapadatok</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <div class="lang-tabs-wrap">
                    <div class="lang-tabs">
                        <button type="button" class="lang-tab active" data-tab="hu-vt">Magyar</button>
                        <button type="button" class="lang-tab" data-tab="en-vt">English</button>
                    </div>
                    <div class="lang-tab-content active" id="tab-hu-vt">
                        <div class="form-group">
                            <label>Típus neve (HU):</label>
                            <input type="text" name="name_hu" required value="<?= e($type['name_hu']) ?>">
                        </div>
                        <div class="form-group">
                            <label>Dokumentum címe (HU):</label>
                            <input type="text" name="doc_title_hu" value="<?= e($type['doc_title_hu']) ?>" placeholder="pl. Munkavédelmi tájékoztató">
                        </div>
                        <div class="form-group">
                            <label>Elolvasandó dokumentum szövege (HU):</label>
                            <textarea name="doc_content_hu" rows="10"><?= e($type['doc_content_hu'] ?? '') ?></textarea>
                            <small>Ha üres, a dokumentum-olvasási lépés kimarad ennél a típusnál.</small>
                        </div>
                    </div>
                    <div class="lang-tab-content" id="tab-en-vt">
                        <div class="form-group">
                            <label>Type name (EN):</label>
                            <input type="text" name="name_en" value="<?= e($type['name_en']) ?>">
                        </div>
                        <div class="form-group">
                            <label>Document title (EN):</label>
                            <input type="text" name="doc_title_en" value="<?= e($type['doc_title_en']) ?>">
                        </div>
                        <div class="form-group">
                            <label>Document text (EN):</label>
                            <textarea name="doc_content_en" rows="10"><?= e($type['doc_content_en'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="form-row" style="max-width:500px">
                    <div class="form-group">
                        <label>Teszt minimum eredmény (%):</label>
                        <input type="number" name="quiz_pass_percent" min="0" max="100" value="<?= (int)$type['quiz_pass_percent'] ?>">
                        <small>Ha nincs egyetlen kérdés sem, a teszt lépés automatikusan kimarad.</small>
                    </div>
                    <div class="form-group">
                        <label>Sorrend a választón:</label>
                        <input type="number" name="sort_order" value="<?= (int)$type['sort_order'] ?>">
                        <small>Kisebb szám kerül előrébb a látogatói választón.</small>
                    </div>
                </div>

                <h4 style="margin:1.25rem 0 .5rem">Oktatási igazoláshoz</h4>
                <p style="font-size:.82rem;color:var(--gray-500);margin-bottom:.75rem">
                    Ezeket az adatokat a dedikált oktatási igazolás PDF mutatja. A tartalom szakmai jóváhagyása
                    (a videó/dokumentum/teszt ténylegesen megfelel-e a jogszabályi előírásoknak) az itt megadott
                    oktató felelőssége — a rendszer csak dokumentálja, nem hitelesíti a tartalmat.
                </p>
                <div class="form-row" style="max-width:600px">
                    <div class="form-group">
                        <label>Oktató neve:</label>
                        <input type="text" name="trainer_name" value="<?= e($type['trainer_name'] ?? '') ?>" placeholder="pl. Kovács János">
                    </div>
                    <div class="form-group">
                        <label>Oktató képesítése / engedélyszáma:</label>
                        <input type="text" name="trainer_qualification" value="<?= e($type['trainer_qualification'] ?? '') ?>" placeholder="pl. Munkavédelmi technikus, eng.sz. 12345">
                    </div>
                </div>
                <div class="form-group" style="max-width:300px">
                    <label>Érvényesség (nap):</label>
                    <input type="number" name="validity_days" min="1" value="<?= e($type['validity_days'] !== null ? (int)$type['validity_days'] : '') ?>" placeholder="pl. 365">
                    <small>Ha üres, az oktatás nem jár le. Tipikusan 365 nap (évenkénti ismétlés).</small>
                </div>
                <label class="checkbox-label" style="margin-bottom:.5rem">
                    <input type="checkbox" name="show_position" value="1" <?= !empty($type['show_position']) ? 'checked' : '' ?>>
                    <span>Munkakör mező megjelenítése az űrlapon (új munkavállalóknak — a Cég mező mellett)</span>
                </label>
                <label class="checkbox-label" style="margin-bottom:1rem">
                    <input type="checkbox" name="is_active" value="1" <?= $type['is_active'] ? 'checked' : '' ?>>
                    <span>Aktív (választható a látogatók számára)</span>
                </label>
                <button type="submit" name="save_details" class="btn btn-primary"><?= icon('check') ?> Mentés</button>
            </form>
        </div>

        <!-- Video -->
        <div class="form-card">
            <h3>Oktatóvideó</h3>
            <?php if (!empty($type['video_path'])): ?>
                <video src="/<?= e($type['video_path']) ?>" controls style="width:100%;border-radius:6px;margin-bottom:.75rem;max-height:240px"></video>
                <form method="POST" onsubmit="return confirm('Biztosan eltávolítja a videót?')">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <button type="submit" name="remove_video" class="btn btn-danger btn-sm"><?= icon('trash') ?> Videó eltávolítása</button>
                </form>
            <?php else: ?>
                <p style="color:var(--gray-500);font-size:.88rem;margin-bottom:.75rem">Nincs feltöltött videó ehhez a típushoz — a videó lépés ki lesz hagyva.</p>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data" style="margin-top:1rem">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <div class="form-group">
                    <label>Új videó feltöltése (max 64MB, mp4/webm/mov/ogg):</label>
                    <input type="file" name="video_file" accept="video/mp4,video/webm,video/quicktime,video/ogg">
                </div>
                <button type="submit" name="upload_video" class="btn btn-secondary"><?= icon('upload') ?> Feltöltés</button>
            </form>
        </div>

        <!-- Danger zone -->
        <div class="form-card">
            <h3>Veszélyes műveletek</h3>
            <p style="font-size:.82rem;color:var(--gray-500);margin-bottom:.75rem">
                A típus törlése törli a hozzá tartozó teszt kérdéseket és a videót is. A már beküldött nyilatkozatok megmaradnak, de a típus-hivatkozásuk törlődik.
            </p>
            <form method="POST" onsubmit="return confirm('Biztosan törli ezt a típust és az összes hozzá tartozó kérdést? Ez visszafordíthatatlan!')">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <button type="submit" name="delete_type" class="btn btn-danger"><?= icon('trash') ?> Típus törlése</button>
            </form>
        </div>

        <!-- Quiz questions -->
        <div class="form-card settings-full">
            <h3>Teszt kérdések (<?= count($questions) ?> db)</h3>

            <?php if (!empty($questions)): ?>
            <div class="table-wrapper" style="margin-bottom:1.5rem">
                <table class="data-table">
                    <thead><tr><th>#</th><th>Kérdés</th><th>Válaszok</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($questions as $q): ?>
                        <tr>
                            <td><?= $q['id'] ?></td>
                            <td><?= e($q['question_hu']) ?></td>
                            <td>
                                <?php foreach ($q['options'] as $o): ?>
                                    <div style="<?= $o['is_correct'] ? 'color:var(--success);font-weight:700' : '' ?>">
                                        <?= $o['is_correct'] ? icon('check') : '' ?> <?= e($o['option_hu']) ?>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                            <td class="actions">
                                <a href="/admin/visit_type_edit.php?id=<?= $id ?>&edit_question=<?= $q['id'] ?>#question-form"
                                   class="btn btn-sm btn-secondary"><?= icon('edit') ?></a>
                                <form method="POST" onsubmit="return confirm('Törli ezt a kérdést?')" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                                    <button type="submit" name="delete_question" class="btn btn-sm btn-danger"><?= icon('trash') ?></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <p style="color:var(--gray-500);font-size:.88rem;margin-bottom:1.5rem">Még nincs egyetlen kérdés sem — a teszt lépés kimarad, amíg nincs kérdés.</p>
            <?php endif; ?>

            <h4 id="question-form" style="margin-bottom:.75rem"><?= $editQuestion ? 'Kérdés szerkesztése' : 'Új kérdés hozzáadása' ?></h4>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <?php if ($editQuestion): ?>
                    <input type="hidden" name="question_id" value="<?= $editQuestion['id'] ?>">
                <?php endif; ?>
                <div class="form-group">
                    <label>Kérdés (HU):</label>
                    <input type="text" name="question_hu" required value="<?= e($editQuestion['question_hu'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Question (EN):</label>
                    <input type="text" name="question_en" value="<?= e($editQuestion['question_en'] ?? '') ?>">
                </div>
                <p style="font-size:.82rem;color:var(--gray-500);margin-bottom:.5rem">Adja meg a válaszlehetőségeket (min. 2), és jelölje be a rádiógombbal a helyeset:</p>
                <?php for ($i = 0; $i < 5; $i++): ?>
                <div class="form-row" style="align-items:center;margin-bottom:.5rem">
                    <input type="radio" name="correct_option" value="<?= $i ?>" style="width:auto;flex:0"
                           <?= $i === 0 ? 'required' : '' ?>
                           <?= $i === $editCorrectIdx ? 'checked' : '' ?>>
                    <input type="text" name="option_hu[]" placeholder="<?= $i + 1 ?>. válasz (HU)" style="flex:1" value="<?= e($editOpts[$i]['option_hu'] ?? '') ?>">
                    <input type="text" name="option_en[]" placeholder="<?= $i + 1 ?>. answer (EN)" style="flex:1" value="<?= e($editOpts[$i]['option_en'] ?? '') ?>">
                </div>
                <?php endfor; ?>
                <?php if ($editQuestion): ?>
                    <button type="submit" name="update_question" class="btn btn-primary" style="margin-top:.5rem"><?= icon('check') ?> Kérdés mentése</button>
                    <a href="/admin/visit_type_edit.php?id=<?= $id ?>#question-form" class="btn btn-ghost" style="margin-top:.5rem">Mégse</a>
                <?php else: ?>
                    <button type="submit" name="add_question" class="btn btn-primary" style="margin-top:.5rem"><?= icon('plus') ?> Kérdés hozzáadása</button>
                <?php endif; ?>
            </form>
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
