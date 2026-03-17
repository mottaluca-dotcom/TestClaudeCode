<?php
/**
 * APP2 — Applicazione 2 (placeholder)
 */
session_start();
define('APP_KEY',  'app2');
define('APP_NAME', 'APP2');
define('APP_FULL', 'Applicazione 2');
define('APP_COLOR','#1e9e5a');
define('PORTAL_DB', __DIR__ . '/../bam/bam.sqlite');

function portalDb(): PDO {
    static $p = null;
    if (!$p) {
        $p = new PDO('sqlite:' . PORTAL_DB, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $p->exec("PRAGMA foreign_keys = ON;");
    }
    return $p;
}
function logged(): bool   { return !empty($_SESSION['uid']); }
function me(): array      { return $_SESSION['user'] ?? []; }
function myRole(): string { return me()['ruolo'] ?? 'viewer'; }
function h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function appMode(): string {
    $ruolo = myRole();
    if ($ruolo === 'admin') return 'edit';
    $stmt = portalDb()->prepare("SELECT mode FROM role_app_permissions WHERE ruolo=? AND app=?");
    $stmt->execute([$ruolo, APP_KEY]);
    return $stmt->fetchColumn() ?: 'none';
}

// Guard
if (!logged()) { header('Location: ../index.php'); exit; }
if (appMode() === 'none') { header('Location: ../index.php?err=noaccess'); exit; }
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= APP_NAME ?> — ALC</title>
<meta name="theme-color" content="<?= APP_COLOR ?>">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
  background:#f1f5f9;color:#1e293b;min-height:100vh;display:flex;flex-direction:column}
.topbar{background:<?= APP_COLOR ?>;padding:.75rem 1.5rem;display:flex;align-items:center;gap:1rem}
.topbar-brand{font-size:1.2rem;font-weight:900;color:#fff;flex:1}
.topbar-back{color:rgba(255,255,255,.8);text-decoration:none;font-size:.85rem;
  padding:.3rem .75rem;border:1px solid rgba(255,255,255,.35);border-radius:8px}
.topbar-back:hover{background:rgba(255,255,255,.15)}
.main{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2rem 1rem;text-align:center}
.app-icon{font-size:4rem;margin-bottom:1rem}
h1{font-size:2.5rem;font-weight:900;color:<?= APP_COLOR ?>;margin-bottom:.25rem}
h2{font-size:1rem;color:#64748b;font-weight:400;margin-bottom:2rem}
.wip-badge{background:#fef3c7;color:#92400e;padding:.5rem 1.25rem;border-radius:20px;
  font-size:.85rem;font-weight:700;letter-spacing:.5px;display:inline-block;margin-bottom:2rem}
.info{color:#64748b;font-size:.875rem;max-width:360px;line-height:1.6}
footer{text-align:center;padding:1.5rem;font-size:.8rem;color:#94a3b8}
footer span{color:<?= APP_COLOR ?>;font-weight:700}
</style>
</head>
<body>
<nav class="topbar">
  <div class="topbar-brand"><?= APP_NAME ?></div>
  <a href="../index.php" class="topbar-back">← Portale</a>
</nav>
<div class="main">
  <div class="app-icon">🌿</div>
  <h1><?= APP_NAME ?></h1>
  <h2><?= APP_FULL ?></h2>
  <div class="wip-badge">🚧 In sviluppo</div>
  <p class="info">Questa applicazione è attualmente in fase di sviluppo.<br>
    Accesso come: <strong><?= h(me()['nome'] ?: me()['email']) ?></strong>
    (<?= appMode() === 'edit' ? '✏️ modifica' : '👁 sola lettura' ?>)
  </p>
</div>
<footer>&copy; <?= date('Y') ?> <span>ALC</span> — <?= APP_FULL ?></footer>
</body>
</html>
