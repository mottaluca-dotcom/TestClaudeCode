<?php
/**
 * ALC 365° — Portale Applicazioni
 * Landing page centralizzata con login, accesso alle app e gestione permessi
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
        portal_db_init($pdo);
    }
    return $pdo;
}

function portal_db_init(PDO $pdo): void {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        email      TEXT UNIQUE NOT NULL,
        password   TEXT NOT NULL,
        nome       TEXT DEFAULT '',
        ruolo      TEXT DEFAULT 'user',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS applicazioni (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        business_unit TEXT NOT NULL,
        settore       TEXT NOT NULL,
        regione       TEXT NOT NULL,
        cliente       TEXT NOT NULL,
        problema      TEXT NOT NULL,
        prodotto_alc  TEXT NOT NULL,
        note          TEXT DEFAULT '',
        media_path    TEXT,
        media_type    TEXT,
        user_id       INTEGER REFERENCES users(id),
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS role_app_permissions (
        ruolo TEXT NOT NULL,
        app   TEXT NOT NULL,
        mode  TEXT NOT NULL DEFAULT 'view',
        PRIMARY KEY (ruolo, app)
    );
    CREATE TABLE IF NOT EXISTS bam_section_edit (
        ruolo    TEXT NOT NULL,
        sezione  TEXT NOT NULL,
        can_edit INTEGER DEFAULT 0,
        PRIMARY KEY (ruolo, sezione)
    );
    CREATE TABLE IF NOT EXISTS prodotti_alc (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        codice        TEXT NOT NULL,
        business_unit TEXT NOT NULL,
        tipo          TEXT DEFAULT '',
        attributo     TEXT DEFAULT ''
    );
    ");
    // Utenti di default
    $existing = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($existing == 0) {
        $users = [
            ['admin@alc.it',          password_hash('alc2024', PASSWORD_DEFAULT), 'Admin',    'admin'],
            ['l.motta@alcgruppo.com',  password_hash('admin',   PASSWORD_DEFAULT), 'L. Motta', 'admin'],
        ];
        $ins = $pdo->prepare("INSERT OR IGNORE INTO users (email,password,nome,ruolo) VALUES (?,?,?,?)");
        foreach ($users as $u) $ins->execute($u);

        // Permessi di default
        $perms = [
            ['admin','bam','edit'],
            ['responsabile','bam','edit'],
            ['utente','bam','edit'],
            ['viewer','bam','view'],
        ];
        $insP = $pdo->prepare("INSERT OR IGNORE INTO role_app_permissions (ruolo,app,mode) VALUES (?,?,?)");
        foreach ($perms as $pr) $insP->execute($pr);

        // Sezioni editabili
        $sections = [
            ['admin','anagrafica',1],['admin','prodotto',1],['admin','note_interne',1],['admin','media',1],
            ['responsabile','anagrafica',1],['responsabile','prodotto',1],['responsabile','media',1],
            ['utente','anagrafica',1],['utente','prodotto',1],
        ];
        $insS = $pdo->prepare("INSERT OR IGNORE INTO bam_section_edit (ruolo,sezione,can_edit) VALUES (?,?,?)");
        foreach ($sections as $s) $insS->execute($s);
    }
}

// ============================================================
// HELPERS
// ============================================================
function logged(): bool   { return !empty($_SESSION['uid']); }
function me(): array      { return $_SESSION['user'] ?? []; }
function myRole(): string { return me()['ruolo'] ?? 'viewer'; }
function isAdmin(): bool  { return myRole() === 'admin'; }
function h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function getAccessibleApps(): array {
    if (!logged()) return [];
    if (isAdmin()) return ['bam'=>'edit','app1'=>'edit','app2'=>'edit','app3'=>'edit'];
    $stmt = portalDb()->prepare("SELECT app, mode FROM role_app_permissions WHERE ruolo = ?");
    $stmt->execute([myRole()]);
    $result = [];
    foreach ($stmt->fetchAll() as $row) $result[$row['app']] = $row['mode'];
    return $result;
}

// ============================================================
// ROUTING
// ============================================================
$p   = $_GET['p']   ?? (logged() ? 'portal' : 'login');
$tab = $_GET['tab'] ?? 'utenti';

// Guard admin
if ($p === 'admin' && (!logged() || !isAdmin())) {
    header('Location: index.php'); exit;
}
// Guard portal
if ($p === 'portal' && !logged()) {
    header('Location: index.php'); exit;
}

// ============================================================
// POST ACTIONS
// ============================================================
$flash = ''; $flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['_action'] ?? '';

    // ---- LOGIN ----
    if ($act === 'login') {
        $email = trim($_POST['email'] ?? '');
        $pw    = $_POST['pw'] ?? '';
        $stmt  = portalDb()->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if ($u && password_verify($pw, $u['password'])) {
            $_SESSION['uid']  = $u['id'];
            $_SESSION['user'] = $u;
            header('Location: index.php'); exit;
        }
        $flash = 'Email o password non corretti.'; $flashType = 'error';
    }

    // ---- LOGOUT ----
    if ($act === 'logout') {
        session_destroy();
        header('Location: index.php'); exit;
    }

    // ---- SAVE USER (admin only) ----
    if ($act === 'save_user' && isAdmin()) {
        $uid   = (int)($_POST['user_id'] ?? 0);
        $email = trim($_POST['email'] ?? '');
        $nome  = trim($_POST['nome']  ?? '');
        $ruolo = $_POST['ruolo'] ?? 'viewer';
        $pw    = $_POST['pw'] ?? '';
        if ($uid) {
            if ($pw) {
                portalDb()->prepare("UPDATE users SET email=?,nome=?,ruolo=?,password=? WHERE id=?")
                    ->execute([$email, $nome, $ruolo, password_hash($pw, PASSWORD_DEFAULT), $uid]);
            } else {
                portalDb()->prepare("UPDATE users SET email=?,nome=?,ruolo=? WHERE id=?")
                    ->execute([$email, $nome, $ruolo, $uid]);
            }
        } else {
            portalDb()->prepare("INSERT INTO users (email,nome,ruolo,password) VALUES(?,?,?,?)")
                ->execute([$email, $nome, $ruolo, password_hash($pw ?: uniqid(), PASSWORD_DEFAULT)]);
        }
        header('Location: index.php?p=admin&tab=utenti&ok=1'); exit;
    }

    // ---- DELETE USER (admin only) ----
    if ($act === 'del_user' && isAdmin()) {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid && $uid !== (int)($_SESSION['uid'] ?? 0)) {
            portalDb()->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
        }
        header('Location: index.php?p=admin&tab=utenti'); exit;
    }

    // ---- SAVE APP PERMISSIONS (admin only) ----
    if ($act === 'save_app_perms' && isAdmin()) {
        $ruolo = $_POST['ruolo'] ?? '';
        foreach (['bam','app1','app2','app3'] as $app) {
            $mode = $_POST['perm'][$app] ?? 'none';
            if ($mode === 'none') {
                portalDb()->prepare("DELETE FROM role_app_permissions WHERE ruolo=? AND app=?")
                    ->execute([$ruolo, $app]);
            } else {
                portalDb()->prepare(
                    "INSERT INTO role_app_permissions (ruolo,app,mode) VALUES(?,?,?)
                     ON CONFLICT(ruolo,app) DO UPDATE SET mode=excluded.mode")
                    ->execute([$ruolo, $app, $mode]);
            }
        }
        header('Location: index.php?p=admin&tab=perm_app&ok=1'); exit;
    }

    // ---- SAVE BAM SECTION PERMISSIONS (admin only) ----
    if ($act === 'save_bam_perms' && isAdmin()) {
        $ruolo = $_POST['ruolo'] ?? '';
        foreach (['anagrafica','prodotto','note_interne','media'] as $s) {
            $val = isset($_POST['sec'][$s]) ? 1 : 0;
            portalDb()->prepare(
                "INSERT INTO bam_section_edit (ruolo,sezione,can_edit) VALUES(?,?,?)
                 ON CONFLICT(ruolo,sezione) DO UPDATE SET can_edit=excluded.can_edit")
                ->execute([$ruolo, $s, $val]);
        }
        header('Location: index.php?p=admin&tab=perm_bam&ok=1'); exit;
    }
}

// ============================================================
// DATA LOADING
// ============================================================
$appCatalog = [
    'bam'  => ['name'=>'BAM',  'full'=>'Bonding Application Management','desc'=>'Gestione e condivisione del know-how applicativo nastri adesivi ALC.','icon'=>'<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 36 36" fill="none"><circle cx="18" cy="18" r="16" fill="rgba(209,42,47,.15)" stroke="#D12A2F" stroke-width="2.5"/><circle cx="18" cy="18" r="7" fill="#fff" stroke="#D12A2F" stroke-width="2"/><circle cx="18" cy="18" r="2.5" fill="#D12A2F"/></svg>','color'=>'#D12A2F','url'=>'bam/'],
    'app1' => ['name'=>'APP1', 'full'=>'Applicazione 1','desc'=>'Descrizione da definire. Applicazione in sviluppo.','icon'=>'📊','color'=>'#2a6dd1','url'=>'app1/'],
    'app2' => ['name'=>'APP2', 'full'=>'Applicazione 2','desc'=>'Descrizione da definire. Applicazione in sviluppo.','icon'=>'🌿','color'=>'#1e9e5a','url'=>'app2/'],
    'app3' => ['name'=>'APP3', 'full'=>'Applicazione 3','desc'=>'Descrizione da definire. Applicazione in sviluppo.','icon'=>'⚙️','color'=>'#f0a500','url'=>'app3/'],
];
$accessibleApps = getAccessibleApps();

// Admin data
$adminUsers = $adminAppPerms = $adminBamPerms = [];
$editUser = null;
if ($p === 'admin') {
    $adminUsers = portalDb()->query("SELECT * FROM users ORDER BY ruolo,nome,email")->fetchAll();
    $adminAppPerms = [];
    foreach (portalDb()->query("SELECT * FROM role_app_permissions")->fetchAll() as $r) {
        $adminAppPerms[$r['ruolo']][$r['app']] = $r['mode'];
    }
    $adminBamPerms = [];
    foreach (portalDb()->query("SELECT * FROM bam_section_edit")->fetchAll() as $r) {
        $adminBamPerms[$r['ruolo']][$r['sezione']] = (bool)$r['can_edit'];
    }
    if (isset($_GET['edit'])) {
        $s = portalDb()->prepare("SELECT * FROM users WHERE id=?");
        $s->execute([(int)$_GET['edit']]);
        $editUser = $s->fetch();
    }
}

$roles   = ['admin','responsabile','utente','viewer'];
$sections = ['anagrafica'=>'Anagrafica','prodotto'=>'Prodotto ALC','note_interne'=>'Note Interne','media'=>'Media'];
$ok = isset($_GET['ok']);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ALC 365° — Portale</title>
<meta name="theme-color" content="#D12A2F">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --red:    #D12A2F;
  --red2:   #b02428;
  --green:  #1e9e5a;
  --blue:   #2a6dd1;
  --text:   #1e293b;
  --gray:   #64748b;
  --light:  #f1f5f9;
  --white:  #ffffff;
  --border: #e2e8f0;
  --radius: 14px;
  --shadow: 0 4px 24px rgba(0,0,0,.10);
}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--light);color:var(--text);min-height:100vh}

/* ---- TOPBAR ---- */
.topbar{background:linear-gradient(135deg,var(--red) 0%,#b02428 50%,#8c1c1f 100%);
  padding:.75rem 1.5rem;display:flex;align-items:center;gap:1rem;
  box-shadow:0 2px 12px rgba(0,0,0,.2)}
.topbar-brand{font-size:1.3rem;font-weight:900;color:#fff;letter-spacing:1px;flex:1}
.topbar-brand span{color:#fff;opacity:.85}
.topbar-user{display:flex;align-items:center;gap:.6rem;color:rgba(255,255,255,.85);font-size:.85rem}
.topbar-user strong{color:#fff}
.role-badge{font-size:.65rem;font-weight:700;padding:.15rem .45rem;border-radius:20px;letter-spacing:.5px;text-transform:uppercase}
.role-admin{background:#fff;color:#D12A2F}
.role-responsabile{background:#2a6dd1;color:#fff}
.role-utente{background:#1e9e5a;color:#fff}
.role-viewer{background:#64748b;color:#fff}
.btn-topbar{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);
  color:#fff;padding:.35rem .85rem;border-radius:8px;font-size:.8rem;cursor:pointer;
  text-decoration:none;transition:background .2s;white-space:nowrap}
.btn-topbar:hover{background:rgba(255,255,255,.25)}
.btn-topbar.admin-btn{background:rgba(255,255,255,.25);font-weight:700}

/* ---- HERO ---- */
.hero{background:linear-gradient(135deg,var(--red) 0%,#8c1c1f 100%);
  color:#fff;text-align:center;padding:3rem 1.5rem 4.5rem}
.hero h1{font-size:clamp(1.8rem,5vw,3rem);font-weight:900;letter-spacing:-1px;margin-bottom:.4rem}
.hero h1 span{color:#fff;opacity:.9}
.hero p{font-size:.95rem;opacity:.85;max-width:480px;margin:0 auto}

/* ---- LOGIN ---- */
.login-wrap{display:flex;justify-content:center;padding:2rem 1rem;margin-top:-2.5rem}
.login-card{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
  width:100%;max-width:420px;padding:2rem}
.login-card h2{font-size:1.2rem;margin-bottom:1.25rem;text-align:center}
.form-group{margin-bottom:1rem}
.form-group label{display:block;font-size:.85rem;font-weight:600;margin-bottom:.4rem}
.form-control{width:100%;padding:.75rem 1rem;border:1.5px solid var(--border);
  border-radius:10px;font-size:.95rem;transition:border-color .2s}
.form-control:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(209,42,47,.12)}
.btn-primary{width:100%;padding:.85rem;background:var(--red);color:#fff;
  border:none;border-radius:10px;font-size:1rem;font-weight:700;cursor:pointer;
  transition:background .2s;margin-top:.5rem}
.btn-primary:hover{background:var(--red2)}
.flash{padding:.75rem 1rem;border-radius:8px;font-size:.9rem;margin-bottom:1rem}
.flash.error{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
.flash.success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}

/* ---- PORTAL GRID ---- */
.portal-wrap{padding:2rem 1rem;max-width:1100px;margin:0 auto}
.portal-header{text-align:center;margin-bottom:2rem;margin-top:-1rem}
.portal-header h2{font-size:1.3rem;font-weight:800;margin-bottom:.25rem}
.portal-header p{color:var(--gray);font-size:.875rem}
.app-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1.25rem}
.app-card{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);
  overflow:hidden;text-decoration:none;color:var(--text);
  transition:transform .18s,box-shadow .18s;display:flex;flex-direction:column}
.app-card:hover{transform:translateY(-4px);box-shadow:0 8px 32px rgba(0,0,0,.15)}
.app-card-stripe{height:5px;width:100%}
.app-card-header{padding:1.25rem 1.25rem .75rem;display:flex;align-items:center;gap:.75rem}
.app-icon{font-size:2rem;line-height:1}
.app-card-name{font-size:1.5rem;font-weight:900;letter-spacing:-0.5px}
.app-card-body{padding:0 1.25rem 1rem;flex:1;display:flex;flex-direction:column;gap:.4rem}
.app-card-full{font-size:.75rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.5px}
.app-card-desc{font-size:.85rem;color:var(--gray);line-height:1.5;flex:1}
.app-card-footer{padding:.7rem 1.25rem;display:flex;align-items:center;justify-content:space-between;
  border-top:1px solid var(--light);font-size:.78rem;font-weight:600}
.mode-edit{color:var(--green)}
.mode-view{color:var(--blue)}
.no-apps{text-align:center;padding:3rem 1rem;color:var(--gray)}

/* ---- ADMIN PANEL ---- */
.admin-wrap{padding:1.5rem 1rem;max-width:1100px;margin:0 auto}
.admin-title{font-size:1.4rem;font-weight:900;margin-bottom:1.25rem;display:flex;align-items:center;gap:.5rem}
.admin-tabs{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.5rem;
  border-bottom:2px solid var(--border);padding-bottom:.75rem}
.admin-tab{padding:.45rem 1rem;border-radius:8px 8px 0 0;font-size:.85rem;font-weight:600;
  text-decoration:none;color:var(--gray);border:1.5px solid transparent;background:transparent;
  transition:all .15s;cursor:pointer}
.admin-tab.active,.admin-tab:hover{background:var(--white);color:var(--red);
  border-color:var(--border);border-bottom-color:var(--white)}
.admin-card{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
.admin-card-title{padding:1rem 1.25rem;font-weight:700;font-size:.95rem;
  border-bottom:1px solid var(--light);display:flex;align-items:center;justify-content:space-between}
/* User table */
.admin-table{width:100%;border-collapse:collapse;font-size:.875rem}
.admin-table th{background:var(--light);padding:.65rem 1rem;text-align:left;font-weight:700;
  font-size:.78rem;text-transform:uppercase;letter-spacing:.4px;color:var(--gray)}
.admin-table td{padding:.7rem 1rem;border-bottom:1px solid var(--border);vertical-align:middle}
.admin-table tr:last-child td{border-bottom:none}
.admin-table tr:hover td{background:#fafafa}
/* User form */
.user-form{padding:1.25rem}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
@media(max-width:600px){.form-row{grid-template-columns:1fr}}
.form-actions{display:flex;gap:.75rem;margin-top:1rem;flex-wrap:wrap}
.btn{padding:.55rem 1.1rem;border-radius:8px;font-size:.875rem;font-weight:600;
  border:none;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;
  gap:.4rem;transition:all .15s}
.btn-red{background:var(--red);color:#fff}.btn-red:hover{background:var(--red2)}
.btn-outline{background:transparent;border:1.5px solid var(--border);color:var(--text)}
.btn-outline:hover{border-color:var(--gray);background:var(--light)}
.btn-danger{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
.btn-danger:hover{background:#fee2e2}
.btn-sm{padding:.35rem .75rem;font-size:.8rem}
/* Perm matrix */
.perm-section{padding:1.25rem}
.perm-section h3{font-size:.85rem;font-weight:700;color:var(--gray);text-transform:uppercase;
  letter-spacing:.5px;margin-bottom:.75rem}
.perm-tabs{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem}
.perm-role-tab{padding:.4rem .9rem;border-radius:20px;font-size:.8rem;font-weight:600;
  text-decoration:none;border:1.5px solid var(--border);color:var(--gray);transition:all .15s}
.perm-role-tab.active{background:var(--red);color:#fff;border-color:var(--red)}
.perm-matrix{border:1.5px solid var(--border);border-radius:10px;overflow:hidden}
.perm-row{display:flex;align-items:center;padding:.7rem 1rem;border-bottom:1px solid var(--border);gap:1rem}
.perm-row:last-child{border-bottom:none}
.perm-row-label{flex:1;font-weight:600;font-size:.875rem}
.perm-row-sub{font-size:.75rem;color:var(--gray);font-weight:400}
.perm-select{padding:.35rem .6rem;border:1.5px solid var(--border);border-radius:8px;
  font-size:.85rem;background:var(--white);cursor:pointer}
.perm-check{width:1.1rem;height:1.1rem;cursor:pointer;accent-color:var(--red)}
.perm-actions{margin-top:1rem;display:flex;gap:.75rem;align-items:center}
/* Badges */
.badge{display:inline-block;padding:.15rem .5rem;border-radius:20px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.badge-admin{background:#fef2f2;color:var(--red)}
.badge-responsabile{background:#eff6ff;color:var(--blue)}
.badge-utente{background:#f0fdf4;color:var(--green)}
.badge-viewer{background:var(--light);color:var(--gray)}

/* ---- FOOTER ---- */
footer{text-align:center;padding:2rem;font-size:.8rem;color:var(--gray)}
footer span{color:var(--red);font-weight:700}
</style>
</head>
<body>

<!-- ===== TOPBAR ===== -->
<nav class="topbar">
  <div class="topbar-brand"></div>
  <?php if (logged()): ?>
  <div class="topbar-user">
    <strong><?= h(me()['nome'] ?: me()['email']) ?></strong>
    <span class="role-badge role-<?= h(myRole()) ?>"><?= h(myRole()) ?></span>
    <?php if (isAdmin()): ?>
    <a href="index.php?p=admin" class="btn-topbar admin-btn">⚙ Admin</a>
    <?php endif; ?>
    <?php if ($p === 'admin'): ?>
    <a href="index.php" class="btn-topbar">← Portale</a>
    <?php endif; ?>
    <form method="POST" action="index.php" style="margin:0">
      <input type="hidden" name="_action" value="logout">
      <button class="btn-topbar" type="submit">Esci</button>
    </form>
  </div>
  <?php endif; ?>
</nav>

<?php if ($p !== 'admin'): ?>
<!-- ===== HERO ===== -->
<div class="hero">
  <h1>ALC <span>365°</span></h1>
  <p>Dove il lavoro diventa semplice</p>
</div>
<?php endif; ?>

<?php
// ============================================================
// PAGE: LOGIN
// ============================================================
if (!logged()): ?>
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
               placeholder="nome@alcgruppo.com" required value="<?= h($_POST['email'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="pw">Password</label>
        <input class="form-control" type="password" id="pw" name="pw" placeholder="••••••••" required>
      </div>
      <button class="btn-primary" type="submit">Accedi</button>
    </form>
  </div>
</div>

<?php
// ============================================================
// PAGE: PORTAL (app grid)
// ============================================================
elseif ($p === 'portal'): ?>
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

<?php
// ============================================================
// PAGE: ADMIN PANEL
// ============================================================
elseif ($p === 'admin'):
$selectedRole = $_GET['role'] ?? 'admin';
?>
<div class="admin-wrap">
  <div class="admin-title">⚙ Gestione Portale</div>

  <?php if ($ok): ?>
  <div class="flash success" style="margin-bottom:1rem">✓ Modifiche salvate correttamente.</div>
  <?php endif; ?>

  <!-- TABS -->
  <div class="admin-tabs">
    <a href="?p=admin&tab=utenti"    class="admin-tab <?= $tab==='utenti'   ?'active':'' ?>">👥 Utenti</a>
    <a href="?p=admin&tab=perm_app"  class="admin-tab <?= $tab==='perm_app' ?'active':'' ?>">🔑 Permessi App</a>
    <a href="?p=admin&tab=perm_bam"  class="admin-tab <?= $tab==='perm_bam' ?'active':'' ?>">📋 Permessi BAM</a>
  </div>

<?php if ($tab === 'utenti'): ?>
  <!-- ======================================================
       TAB: UTENTI
       ====================================================== -->
  <div style="display:grid;gap:1.25rem;grid-template-columns:<?= $editUser ? '1fr 380px' : '1fr' ?>">

    <!-- LISTA UTENTI -->
    <div class="admin-card">
      <div class="admin-card-title">
        <span>Elenco utenti (<?= count($adminUsers) ?>)</span>
        <a href="?p=admin&tab=utenti&new=1" class="btn btn-red btn-sm">+ Nuovo utente</a>
      </div>
      <table class="admin-table">
        <thead><tr>
          <th>Nome</th><th>Email</th><th>Ruolo</th><th>Creato il</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($adminUsers as $u): ?>
        <tr>
          <td><strong><?= h($u['nome'] ?: '—') ?></strong></td>
          <td style="color:var(--gray)"><?= h($u['email']) ?></td>
          <td><span class="badge badge-<?= h($u['ruolo']) ?>"><?= h($u['ruolo']) ?></span></td>
          <td style="color:var(--gray);font-size:.8rem"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
          <td style="display:flex;gap:.4rem">
            <a href="?p=admin&tab=utenti&edit=<?= $u['id'] ?>" class="btn btn-outline btn-sm">✏</a>
            <?php if ($u['id'] !== (int)($_SESSION['uid']??0)): ?>
            <form method="POST" onsubmit="return confirm('Eliminare <?= h($u['email']) ?>?')">
              <input type="hidden" name="_action" value="del_user">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <button class="btn btn-danger btn-sm" type="submit">🗑</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- FORM UTENTE (edit o nuovo) -->
    <?php if ($editUser || isset($_GET['new'])): ?>
    <div class="admin-card">
      <div class="admin-card-title">
        <?= $editUser ? 'Modifica utente' : 'Nuovo utente' ?>
      </div>
      <div class="user-form">
        <form method="POST" action="index.php">
          <input type="hidden" name="_action" value="save_user">
          <input type="hidden" name="user_id" value="<?= $editUser ? $editUser['id'] : 0 ?>">
          <div class="form-row">
            <div class="form-group">
              <label>Nome</label>
              <input class="form-control" name="nome" type="text"
                     value="<?= h($editUser['nome'] ?? '') ?>" placeholder="Nome cognome">
            </div>
            <div class="form-group">
              <label>Ruolo</label>
              <select class="form-control" name="ruolo">
                <?php foreach (['admin','responsabile','utente','viewer'] as $r): ?>
                <option value="<?= $r ?>" <?= ($editUser['ruolo']??'viewer')===$r?'selected':'' ?>><?= $r ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label>Email</label>
            <input class="form-control" name="email" type="email"
                   value="<?= h($editUser['email'] ?? '') ?>" placeholder="nome@alcgruppo.com" required>
          </div>
          <div class="form-group">
            <label>Password <?= $editUser ? '<span style="font-weight:400;color:var(--gray)">(lascia vuoto per non cambiare)</span>' : '<span style="color:var(--red)">*</span>' ?></label>
            <input class="form-control" name="pw" type="password"
                   placeholder="••••••••" <?= !$editUser ? 'required' : '' ?>>
          </div>
          <div class="form-actions">
            <button class="btn btn-red" type="submit">💾 Salva</button>
            <a href="?p=admin&tab=utenti" class="btn btn-outline">Annulla</a>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'perm_app'): ?>
  <!-- ======================================================
       TAB: PERMESSI APP
       ====================================================== -->
  <div class="admin-card">
    <div class="admin-card-title">Accesso alle applicazioni per ruolo</div>
    <div class="perm-section">
      <!-- Role tabs -->
      <div class="perm-tabs">
        <?php foreach ($roles as $r): ?>
        <a href="?p=admin&tab=perm_app&role=<?= $r ?>"
           class="perm-role-tab <?= $selectedRole===$r?'active':'' ?>"><?= $r ?></a>
        <?php endforeach; ?>
      </div>

      <form method="POST" action="index.php">
        <input type="hidden" name="_action" value="save_app_perms">
        <input type="hidden" name="ruolo" value="<?= h($selectedRole) ?>">
        <div class="perm-matrix">
          <?php foreach ($appCatalog as $key => $app):
            $cur = $adminAppPerms[$selectedRole][$key] ?? 'none';
          ?>
          <div class="perm-row">
            <div style="display:flex;align-items:center;gap:.75rem;flex:1">
              <span style="font-size:1.4rem"><?= $app['icon'] ?></span>
              <div>
                <div class="perm-row-label"><?= h($app['name']) ?></div>
                <div class="perm-row-sub"><?= h($app['full']) ?></div>
              </div>
            </div>
            <select class="perm-select" name="perm[<?= h($key) ?>]">
              <option value="none"  <?= $cur==='none'?'selected':'' ?>>🚫 Nessun accesso</option>
              <option value="view"  <?= $cur==='view'?'selected':'' ?>>👁 Solo lettura</option>
              <option value="edit"  <?= $cur==='edit'?'selected':'' ?>>✏️ Modifica</option>
            </select>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="perm-actions">
          <button class="btn btn-red" type="submit">💾 Salva permessi per «<?= h($selectedRole) ?>»</button>
        </div>
      </form>
    </div>
  </div>

<?php elseif ($tab === 'perm_bam'): ?>
  <!-- ======================================================
       TAB: PERMESSI BAM SEZIONI
       ====================================================== -->
  <div class="admin-card">
    <div class="admin-card-title">Permessi sezioni BAM per ruolo</div>
    <div class="perm-section">
      <p style="font-size:.85rem;color:var(--gray);margin-bottom:1rem">
        Definisci quali sezioni del form BAM ogni ruolo può <strong>modificare</strong>.
        La visualizzazione è sempre consentita.
      </p>
      <!-- Role tabs -->
      <div class="perm-tabs">
        <?php foreach ($roles as $r): ?>
        <a href="?p=admin&tab=perm_bam&role=<?= $r ?>"
           class="perm-role-tab <?= $selectedRole===$r?'active':'' ?>"><?= $r ?></a>
        <?php endforeach; ?>
      </div>

      <form method="POST" action="index.php">
        <input type="hidden" name="_action" value="save_bam_perms">
        <input type="hidden" name="ruolo" value="<?= h($selectedRole) ?>">
        <div class="perm-matrix">
          <?php
          $secIcons = ['anagrafica'=>'🏷','prodotto'=>'📦','note_interne'=>'📝','media'=>'📷'];
          $secDesc  = [
            'anagrafica'   => 'Business Unit, Settore, Regione, Cliente',
            'prodotto'     => 'Codice prodotto ALC e descrizione applicazione',
            'note_interne' => 'Note aggiuntive interne (visibili a tutti)',
            'media'        => 'Upload foto e video dell\'applicazione',
          ];
          foreach ($sections as $key => $label):
            $canEdit = $adminBamPerms[$selectedRole][$key] ?? false;
          ?>
          <div class="perm-row">
            <div style="display:flex;align-items:center;gap:.75rem;flex:1">
              <span style="font-size:1.4rem"><?= $secIcons[$key] ?></span>
              <div>
                <div class="perm-row-label"><?= h($label) ?></div>
                <div class="perm-row-sub"><?= h($secDesc[$key]) ?></div>
              </div>
            </div>
            <label style="display:flex;align-items:center;gap:.4rem;font-size:.85rem;font-weight:600;cursor:pointer">
              <input class="perm-check" type="checkbox" name="sec[<?= h($key) ?>]" <?= $canEdit?'checked':'' ?>>
              Può modificare
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="perm-actions">
          <button class="btn btn-red" type="submit">💾 Salva permessi BAM per «<?= h($selectedRole) ?>»</button>
        </div>
      </form>
    </div>
  </div>

<?php endif; ?>
</div><!-- /admin-wrap -->

<?php endif; ?>

<footer>&copy; <?= date('Y') ?> <span>ALC</span> — 365° Portale Applicazioni</footer>

</body>
</html>
