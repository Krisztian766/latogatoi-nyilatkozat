<?php $uri = $_SERVER['REQUEST_URI'] ?? ''; ?>
<nav class="admin-nav" id="adminNav">
    <div class="nav-left">
        <img src="/assets/logo.png" alt="Logo" class="nav-logo">
        <span class="nav-brand">Admin Panel</span>
    </div>

    <button class="nav-toggle" id="navToggle" aria-label="Menü">
        <span></span><span></span><span></span>
    </button>

    <div class="nav-links" id="navLinks">
        <a href="/admin/dashboard.php" <?= str_contains($uri,'dashboard') ? 'class="active"':'' ?>>&#128203; Nyilatkozatok</a>
        <a href="/admin/new.php"       <?= str_contains($uri,'/new')      ? 'class="active"':'' ?>>&#43; Új</a>
        <a href="/admin/documents.php" <?= str_contains($uri,'document')  ? 'class="active"':'' ?>>&#128196; Dokumentumok</a>
        <a href="/admin/audit.php"     <?= str_contains($uri,'audit')     ? 'class="active"':'' ?>>&#128270; Audit napló</a>
        <a href="/admin/settings.php"  <?= str_contains($uri,'settings')  ? 'class="active"':'' ?>>&#9881; Beállítások</a>
        <a href="/admin/users.php"     <?= str_contains($uri,'users')     ? 'class="active"':'' ?>>&#128100; Felhasználók</a>
        <a href="/admin/logout.php">&#128682; Kilépés</a>
    </div>
</nav>
<script>
document.getElementById('navToggle').addEventListener('click', function() {
    document.getElementById('adminNav').classList.toggle('nav-open');
});
document.querySelectorAll('.nav-links a').forEach(function(a) {
    a.addEventListener('click', function() {
        document.getElementById('adminNav').classList.remove('nav-open');
    });
});
</script>
