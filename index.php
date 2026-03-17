<?php
/**
 * ALC — Portale Applicazioni
 * Landing page centralizzata con login e accesso alle app
 */
session_start();

define('PORTAL_DB', __DIR__ . '/bam/bam.sqlite');

// ============================================================
// DATABASE (condiviso con BAM)
// ============================================================
function portalDb(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO('sqlite:' . PORTAL_DB, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL;");
    }
    return $pdo;
}

// ============================================================
// HELPERS
// ============================================================
function logged(): bool   { return !empty($_SESSION['uid']); }
function me(): array      { return $_SESSION['user'] ?? []; }
function myRole(): string { return me()['ruolo'] ?? 'viewer'; }
function h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function getAccessibleApps(): array {
    if (!logged()) return [];
    $ruolo = myRole();
    if ($ruolo === 'admin') {
        return ['bam'=>'edit','app1'=>'edit','app2'=>'edit','app3'=>'edit'];
    }
    $stmt = portalDb()->prepare("SELECT app, mode FROM role_app_permissions WHERE ruolo = ?");
    $stmt->execute([$ruolo]);
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[$row['app']] = $row['mode'];
    }
    return $result;
}

// ============================================================
// ACTIONS
// ============================================================
$flash = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['_action'] ?? '';

    if ($act === 'login') {
        $email = trim($_POST['email'] ?? '');
        $pw    = $_POST['pw'] ?? '';
        $stmt  = portalDb()->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if ($u && password_verify($pw, $u['password'])) {
            $_SESSION['uid']  = $u['id'];
            $_SESSION['user'] = $u;
            header('Location: index.php');
            exit;
        }
        $flash     = 'Email o password non corretti.';
        $flashType = 'error';
    }

    if ($act === 'logout') {
        session_destroy();
        header('Location: index.php');
        exit;
    }
}

// ============================================================
// APP CATALOG
// ============================================================
$appCatalog = [
    'bam'  => [
        'name'  => 'BAM',
        'full'  => 'Bonding Application Management',
        'desc'  => 'Gestione e condivisione del know-how applicativo nastri adesivi ALC.',
        'icon'  => '🏭',
        'color' => '#D12A2F',
        'url'   => 'bam/',
    ],
    'app1' => [
        'name'  => 'APP1',
        'full'  => 'Applicazione 1',
        'desc'  => 'Descrizione da definire. Applicazione in sviluppo.',
        'icon'  => '📊',
        'color' => '#2a6dd1',
        'url'   => 'app1/',
    ],
    'app2' => [
        'name'  => 'APP2',
        'full'  => 'Applicazione 2',
        'desc'  => 'Descrizione da definire. Applicazione in sviluppo.',
        'icon'  => '🌿',
        'color' => '#1e9e5a',
        'url'   => 'app2/',
    ],
    'app3' => [
        'name'  => 'APP3',
        'full'  => 'Applicazione 3',
        'desc'  => 'Descrizione da definire. Applicazione in sviluppo.',
        'icon'  => '⚙️',
        'color' => '#f0a500',
        'url'   => 'app3/',
    ],
];

$accessibleApps = getAccessibleApps();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ALC — Portale Applicazioni</title>
<meta name="theme-color" content="#D12A2F">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --red:    #D12A2F;
  --red2:   #b02428;
  --gold:   #f0a500;
  --green:  #1e9e5a;
  --blue:   #2a6dd1;
  --text:   #1e293b;
  --gray:   #64748b;
  --light:  #f1f5f9;
  --white:  #ffffff;
  --radius: 14px;
  --shadow: 0 4px 24px rgba(0,0,0,.10);
}
body{
  font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
  background:var(--light);color:var(--text);min-height:100vh;
}
/* ---- TOPBAR ---- */
.topbar{
  background:linear-gradient(135deg,var(--red) 0%,#b02428 50%,#8c1c1f 100%);
  padding:.75rem 1.5rem;display:flex;align-items:center;gap:1rem;
  box-shadow:0 2px 12px rgba(0,0,0,.2);
}
.topbar-brand{font-size:1.3rem;font-weight:900;color:#fff;letter-spacing:1px;flex:1}
.topbar-brand span{color:var(--gold)}
.topbar-user{display:flex;align-items:center;gap:.6rem;color:rgba(255,255,255,.85);font-size:.85rem}
.topbar-user strong{color:#fff}
.role-badge{font-size:.65rem;font-weight:700;padding:.15rem .45rem;border-radius:20px;letter-spacing:.5px;text-transform:uppercase}
.role-admin{background:#f0a500;color:#1a1a1a}
.role-responsabile{background:#2a6dd1;color:#fff}
.role-utente{background:#1e9e5a;color:#fff}
.role-viewer{background:#64748b;color:#fff}
.btn-logout{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);
  color:#fff;padding:.35rem .85rem;border-radius:8px;font-size:.8rem;cursor:pointer;
  text-decoration:none;transition:background .2s}
.btn-logout:hover{background:rgba(255,255,255,.25)}
/* ---- HERO ---- */
.hero{
  background:linear-gradient(135deg,var(--red) 0%,#8c1c1f 100%);
  color:#fff;text-align:center;padding:3rem 1.5rem 4.5rem;
}
.hero h1{font-size:clamp(1.8rem,5vw,3rem);font-weight:900;letter-spacing:-1px;margin-bottom:.4rem}
.hero h1 span{color:var(--gold)}
.hero p{font-size:.95rem;opacity:.8;max-width:480px;margin:0 auto}
/* ---- LOGIN ---- */
.login-wrap{display:flex;justify-content:center;padding:2rem 1rem;margin-top:-2.5rem}
.login-card{
  background:var(--white);border-radius:var(--radius);
  box-shadow:var(--shadow);width:100%;max-width:420px;padding:2rem;
}
.login-card h2{font-size:1.2rem;margin-bottom:1.25rem;text-align:center}
.form-group{margin-bottom:1rem}
.form-group label{display:block;font-size:.85rem;font-weight:600;margin-bottom:.4rem}
.form-control{
  width:100%;padding:.75rem 1rem;border:1.5px solid #e2e8f0;
  border-radius:10px;font-size:.95rem;transition:border-color .2s;
}
.form-control:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(209,42,47,.12)}
.btn-primary{
  width:100%;padding:.85rem;background:var(--red);color:#fff;
  border:none;border-radius:10px;font-size:1rem;font-weight:700;
  cursor:pointer;transition:background .2s;margin-top:.5rem;
}
.btn-primary:hover{background:var(--red2)}
.flash{padding:.75rem 1rem;border-radius:8px;font-size:.9rem;margin-bottom:1rem}
.flash.error{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
/* ---- PORTAL GRID ---- */
.portal-wrap{padding:2rem 1rem;max-width:1000px;margin:0 auto}
.portal-header{text-align:center;margin-bottom:2rem;margin-top:-1rem}
.portal-header h2{font-size:1.3rem;font-weight:800;margin-bottom:.25rem}
.portal-header p{color:var(--gray);font-size:.875rem}
.app-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1.25rem}
.app-card{
  background:var(--white);border-radius:var(--radius);
  box-shadow:var(--shadow);overflow:hidden;text-decoration:none;color:var(--text);
  transition:transform .18s,box-shadow .18s;display:flex;flex-direction:column;
}
.app-card:hover{transform:translateY(-4px);box-shadow:0 8px 32px rgba(0,0,0,.15)}
.app-card-stripe{height:5px;width:100%}
.app-card-header{padding:1.25rem 1.25rem .75rem;display:flex;align-items:center;gap:.75rem}
.app-icon{font-size:2rem;line-height:1}
.app-card-name{font-size:1.5rem;font-weight:900;letter-spacing:-0.5px}
.app-card-body{padding:0 1.25rem 1rem;flex:1;display:flex;flex-direction:column;gap:.4rem}
.app-card-full{font-size:.75rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.5px}
.app-card-desc{font-size:.85rem;color:var(--gray);line-height:1.5;flex:1}
.app-card-footer{
  padding:.7rem 1.25rem;display:flex;align-items:center;justify-content:space-between;
  border-top:1px solid var(--light);font-size:.78rem;font-weight:600;
}
.mode-edit{color:var(--green)}
.mode-view{color:var(--blue)}
/* ---- NO ACCESS ---- */
.no-apps{text-align:center;padding:3rem 1rem;color:var(--gray)}
/* ---- FOOTER ---- */
footer{text-align:center;padding:2rem;font-size:.8rem;color:var(--gray)}
footer span{color:var(--red);font-weight:700}
</style>
</head>
<body>

<nav class="topbar">
  <div class="topbar-brand">ALC <span>Portale</span></div>
  <?php if (logged()): ?>
  <div class="topbar-user">
    <strong><?= h(me()['nome'] ?: me()['email']) ?></strong>
    <span class="role-badge role-<?= h(myRole()) ?>"><?= h(myRole()) ?></span>
    <form method="POST" action="index.php" style="margin:0">
      <input type="hidden" name="_action" value="logout">
      <button class="btn-logout" type="submit">Esci</button>
    </form>
  </div>
  <?php endif; ?>
</nav>

<div class="hero">
  <h1>ALC <span>Portale</span></h1>
  <p>Accedi alle applicazioni aziendali con un unico login centralizzato.</p>
</div>

<?php if (!logged()): ?>
<div class="login-wrap">
  <div class="login-card">
    <h2>Accedi al Portale</h2>
    <?php if ($flash): ?>
    <div class="flash <?= h($flashType) ?>"><?= h($flash) ?></div>
    <?php endif; ?>
    <form method="POST" action="index.php">
      <input type="hidden" name="_action" value="login">
      <div class="form-group">
        <label for="email">Email aziendale</label>
        <input class="form-control" type="email" id="email" name="email"
               placeholder="nome@alc.it" required value="<?= h($_POST['email'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="pw">Password</label>
        <input class="form-control" type="password" id="pw" name="pw" placeholder="••••••••" required>
      </div>
      <button class="btn-primary" type="submit">Accedi</button>
    </form>
  </div>
</div>

<?php else: ?>
<div class="portal-wrap">
  <div class="portal-header">
    <h2>Le tue applicazioni</h2>
    <p>Clicca su un'app per accedervi. Le applicazioni visibili dipendono dal tuo profilo.</p>
  </div>

  <?php if (empty($accessibleApps)): ?>
  <div class="no-apps">
    <div style="font-size:2.5rem">🔒</div>
    <p style="margin-top:.5rem">Non hai accesso ad alcuna applicazione.<br>Contatta l'amministratore.</p>
  </div>
  <?php else: ?>
  <div class="app-grid">
    <?php foreach ($appCatalog as $key => $app):
      if (!isset($accessibleApps[$key])) continue;
      $mode = $accessibleApps[$key];
    ?>
    <a class="app-card" href="<?= h($app['url']) ?>">
      <div class="app-card-stripe" style="background:<?= h($app['color']) ?>"></div>
      <div class="app-card-header">
        <div class="app-icon"><?= $app['icon'] ?></div>
        <div class="app-card-name" style="color:<?= h($app['color']) ?>"><?= h($app['name']) ?></div>
      </div>
      <div class="app-card-body">
        <div class="app-card-full"><?= h($app['full']) ?></div>
        <div class="app-card-desc"><?= h($app['desc']) ?></div>
      </div>
      <div class="app-card-footer">
        <?php if ($mode === 'edit'): ?>
        <span class="mode-edit">✏️ Modifica</span>
        <?php else: ?>
        <span class="mode-view">👁 Sola lettura</span>
        <?php endif; ?>
        <span style="opacity:.35">→</span>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<footer>&copy; <?= date('Y') ?> <span>ALC</span> — Portale Applicazioni</footer>

</body>
</html>
