<?php
/**
 * BAM - Bonding Application Management
 * Piattaforma per condivisione know-how applicativo nastri adesivi ALC
 */
session_start();

// ============================================================
// CONFIG
// ============================================================
define('DB_PATH',    __DIR__ . '/bam.sqlite');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('MAX_FILE_MB', 50);

if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

// ============================================================
// DATABASE
// ============================================================
function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL;");
        db_init($pdo);
    }
    return $pdo;
}

function db_init(PDO $pdo): void {
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
    ");
    // Default admin user
    if (!$pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn()) {
        $hash = password_hash('alc2024', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (email, password, nome, ruolo) VALUES (?, ?, ?, ?)")
            ->execute(['admin@alc.it', $hash, 'Amministratore', 'admin']);
    }
}

// ============================================================
// HELPERS
// ============================================================
function logged(): bool { return !empty($_SESSION['uid']); }
function me(): array    { return $_SESSION['user'] ?? []; }

function guard(): void {
    if (!logged()) { redir('login'); }
}

function redir(string $p, string $qs = ''): never {
    header("Location: index.php?p=$p$qs");
    exit;
}

function h(mixed $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function icon(string $name): string {
    $icons = [
        'home'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
        'plus'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
        'database' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>',
        'logout'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
        'back'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>',
        'search'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
        'filter'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>',
        'camera'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>',
        'eye'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
        'check'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>',
        'star'     => '<svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
        'chart'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        'user'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'pin'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>',
        'tag'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
        'play'     => '<svg viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>',
    ];
    return '<span class="icon">' . ($icons[$name] ?? '') . '</span>';
}

// ============================================================
// ROUTING — POST
// ============================================================
$p        = $_GET['p'] ?? (logged() ? 'welcome' : 'login');
$flash    = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['_action'] ?? '';

    // --- LOGIN ---
    if ($act === 'login') {
        $email = trim($_POST['email'] ?? '');
        $pw    = $_POST['pw'] ?? '';
        $stmt  = db()->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if ($u && password_verify($pw, $u['password'])) {
            $_SESSION['uid']  = $u['id'];
            $_SESSION['user'] = $u;
            redir('welcome');
        }
        $p         = 'login';
        $flash     = 'Email o password non corretti.';
        $flashType = 'error';
    }

    // --- SALVA APPLICAZIONE ---
    if ($act === 'salva') {
        guard();
        $data = [
            'business_unit' => $_POST['business_unit'] ?? '',
            'settore'       => $_POST['settore']       ?? '',
            'regione'       => $_POST['regione']       ?? '',
            'cliente'       => trim($_POST['cliente']  ?? ''),
            'problema'      => trim($_POST['problema'] ?? ''),
            'prodotto_alc'  => trim($_POST['prodotto_alc'] ?? ''),
            'note'          => trim($_POST['note']     ?? ''),
        ];
        $errs = [];
        $required = ['business_unit','settore','regione','cliente','problema','prodotto_alc'];
        foreach ($required as $k) if (!$data[$k]) $errs[] = $k;

        $media_path = $media_type = null;
        if (!empty($_FILES['media']['name']) && $_FILES['media']['error'] === UPLOAD_ERR_OK) {
            $f   = $_FILES['media'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $imgs = ['jpg','jpeg','png','gif','webp','heic','avif'];
            $vids = ['mp4','mov','avi','webm','3gp','mkv'];
            if (in_array($ext, $imgs))     { $media_type = 'image'; }
            elseif (in_array($ext, $vids)) { $media_type = 'video'; }
            else { $errs[] = 'formato_file'; }

            if ($media_type) {
                $fn = 'bam_' . uniqid('', true) . '.' . $ext;
                if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . $fn)) {
                    $errs[] = 'upload_failed';
                } else {
                    $media_path = 'uploads/' . $fn;
                }
            }
        }

        if (empty($errs)) {
            db()->prepare("INSERT INTO applicazioni
                (business_unit,settore,regione,cliente,problema,prodotto_alc,note,media_path,media_type,user_id)
                VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $data['business_unit'], $data['settore'], $data['regione'],
                    $data['cliente'], $data['problema'], $data['prodotto_alc'],
                    $data['note'], $media_path, $media_type, $_SESSION['uid']
                ]);
            redir('welcome', '&saved=1');
        }
        $p        = 'inserimento';
        $formData = $data;
        $formErrs = $errs;
        $flash    = 'Compila tutti i campi obbligatori.';
        $flashType = 'error';
    }

    // --- ELIMINA ---
    if ($act === 'elimina') {
        guard();
        $id = (int)($_POST['id'] ?? 0);
        $row = db()->prepare("SELECT media_path FROM applicazioni WHERE id=?")->execute([$id])
             ? db()->query("SELECT media_path FROM applicazioni WHERE id=$id")->fetch()
             : null;
        if ($row && $row['media_path'] && file_exists(__DIR__ . '/' . $row['media_path'])) {
            unlink(__DIR__ . '/' . $row['media_path']);
        }
        db()->prepare("DELETE FROM applicazioni WHERE id=?")->execute([$id]);
        redir('database', '&msg=deleted');
    }
}

// --- LOGOUT ---
if ($p === 'logout') { session_destroy(); redir('login'); }

// Guard
if (in_array($p, ['welcome','inserimento','database','dettaglio'])) guard();

// Load data for pages
$app = null;
if ($p === 'dettaglio') {
    $id   = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare("SELECT a.*, u.nome as inserito_da, u.email as user_email
                           FROM applicazioni a LEFT JOIN users u ON a.user_id=u.id
                           WHERE a.id=?");
    $stmt->execute([$id]);
    $app = $stmt->fetch();
    if (!$app) redir('database');
}

$apps = [];
$search = $bu_f = $settore_f = '';
if ($p === 'database') {
    $search   = trim($_GET['q']       ?? '');
    $bu_f     = $_GET['bu']           ?? '';
    $settore_f= $_GET['settore']      ?? '';
    $sql      = "SELECT a.*, u.nome as inserito_da
                 FROM applicazioni a LEFT JOIN users u ON a.user_id=u.id WHERE 1=1";
    $params   = [];
    if ($search)    { $sql .= " AND (a.cliente LIKE ? OR a.prodotto_alc LIKE ? OR a.problema LIKE ? OR a.regione LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]); }
    if ($bu_f)      { $sql .= " AND a.business_unit=?"; $params[] = $bu_f; }
    if ($settore_f) { $sql .= " AND a.settore=?";       $params[] = $settore_f; }
    $sql .= " ORDER BY a.created_at DESC";
    $stmt = db()->prepare($sql); $stmt->execute($params);
    $apps = $stmt->fetchAll();
    if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') { $flash = 'Applicazione eliminata.'; $flashType='info'; }
}

$stats = [];
$recenti = [];
if ($p === 'welcome') {
    $stats['totale']       = db()->query("SELECT COUNT(*) FROM applicazioni")->fetchColumn();
    $stats['mese']         = db()->query("SELECT COUNT(*) FROM applicazioni WHERE strftime('%Y-%m',created_at)=strftime('%Y-%m','now')")->fetchColumn();
    $stats['clienti']      = db()->query("SELECT COUNT(DISTINCT cliente) FROM applicazioni")->fetchColumn();
    $stats['bu_ksystem']   = db()->query("SELECT COUNT(*) FROM applicazioni WHERE business_unit='K-System'")->fetchColumn();
    $stats['bu_ktermo']    = db()->query("SELECT COUNT(*) FROM applicazioni WHERE business_unit='K-Termo'")->fetchColumn();
    $recenti               = db()->query("SELECT a.*,u.nome as ins FROM applicazioni a LEFT JOIN users u ON a.user_id=u.id ORDER BY a.created_at DESC LIMIT 5")->fetchAll();
    if (isset($_GET['saved'])) { $flash = 'Applicazione salvata con successo!'; $flashType='success'; }
}

// ============================================================
// HTML OUTPUT
// ============================================================
$pageTitles = [
    'login'       => 'Accesso',
    'welcome'     => 'Home',
    'inserimento' => 'Nuova Applicazione',
    'database'    => 'Database',
    'dettaglio'   => 'Dettaglio',
];
$pageTitle = $pageTitles[$p] ?? 'BAM';
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>BAM — <?= h($pageTitle) ?></title>
<meta name="theme-color" content="#0d2137">
<style>
/* ============================================================
   RESET & ROOT
   ============================================================ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --navy:   #0d2137;
  --blue:   #1a4a7a;
  --blue2:  #2563a8;
  --gold:   #f0a500;
  --gold2:  #e09200;
  --green:  #1e9e5a;
  --red:    #dc3545;
  --info:   #0e7abe;
  --light:  #f0f4f8;
  --white:  #ffffff;
  --gray:   #64748b;
  --gray2:  #94a3b8;
  --border: #dde4ed;
  --card:   #ffffff;
  --shadow: 0 2px 12px rgba(13,33,55,.10);
  --shadow2:0 8px 32px rgba(13,33,55,.18);
  --radius: 14px;
  --radius2: 8px;
  --font:   'Segoe UI',system-ui,-apple-system,sans-serif;
  --trans:  all .2s ease;
}
html{font-family:var(--font);font-size:16px;-webkit-tap-highlight-color:transparent}
body{background:var(--light);color:var(--navy);min-height:100vh;display:flex;flex-direction:column}
a{color:inherit;text-decoration:none}
img,video{max-width:100%;border-radius:var(--radius2)}
button,input,select,textarea{font-family:var(--font)}
.icon{display:inline-flex;align-items:center;justify-content:center;width:1.1em;height:1.1em;vertical-align:middle}
.icon svg{width:100%;height:100%;display:block}

/* ============================================================
   TOPBAR / NAV
   ============================================================ */
.topbar{
  background:var(--navy);
  color:#fff;
  padding:.75rem 1.25rem;
  display:flex;
  align-items:center;
  justify-content:space-between;
  position:sticky;
  top:0;
  z-index:200;
  box-shadow:0 2px 12px rgba(0,0,0,.25);
}
.topbar-brand{
  display:flex;
  align-items:center;
  gap:.6rem;
  font-weight:700;
  font-size:1.15rem;
  letter-spacing:.5px;
}
.topbar-brand .badge{
  background:var(--gold);
  color:var(--navy);
  font-size:.6rem;
  font-weight:800;
  letter-spacing:1px;
  padding:.2rem .45rem;
  border-radius:4px;
  text-transform:uppercase;
}
.topbar-nav{display:flex;align-items:center;gap:.5rem}
.topbar-nav a,.topbar-nav button{
  display:flex;align-items:center;gap:.35rem;
  padding:.45rem .85rem;
  border-radius:var(--radius2);
  font-size:.85rem;
  font-weight:500;
  color:rgba(255,255,255,.75);
  background:transparent;
  border:none;
  cursor:pointer;
  transition:var(--trans);
}
.topbar-nav a:hover,.topbar-nav button:hover{background:rgba(255,255,255,.12);color:#fff}
.topbar-nav a.active{background:rgba(240,165,0,.15);color:var(--gold)}
.topbar-nav .nav-label{display:none}
@media(min-width:600px){.topbar-nav .nav-label{display:inline}}
.topbar-user{
  display:flex;align-items:center;gap:.5rem;
  font-size:.82rem;color:rgba(255,255,255,.5);
  padding-right:.5rem;
  border-right:1px solid rgba(255,255,255,.1);
  margin-right:.25rem;
}
.topbar-user strong{color:#fff}

/* ============================================================
   LAYOUT
   ============================================================ */
.page{flex:1;padding:1.5rem 1rem 3rem;max-width:960px;margin:0 auto;width:100%}
.page-wide{max-width:1200px}
@media(min-width:700px){.page{padding:2rem 1.5rem 4rem}}

/* ============================================================
   FLASH
   ============================================================ */
.flash{
  padding:.85rem 1.2rem;
  border-radius:var(--radius2);
  font-size:.92rem;
  font-weight:500;
  display:flex;align-items:center;gap:.6rem;
  margin-bottom:1.5rem;
  animation:slideDown .3s ease;
}
.flash.success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0}
.flash.error  {background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
.flash.info   {background:#dbeafe;color:#1e40af;border:1px solid #93c5fd}
@keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}

/* ============================================================
   LOGIN PAGE
   ============================================================ */
.login-wrap{
  min-height:100vh;
  display:flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,var(--navy) 0%,#1a3a5c 50%,#1a4a7a 100%);
  padding:1.5rem;
}
.login-card{
  background:var(--white);
  border-radius:20px;
  padding:2.5rem 2rem;
  width:100%;max-width:420px;
  box-shadow:0 20px 60px rgba(0,0,0,.35);
}
.login-logo{
  text-align:center;
  margin-bottom:2rem;
}
.login-logo .bam-big{
  font-size:3rem;font-weight:900;letter-spacing:2px;
  background:linear-gradient(135deg,var(--navy),var(--blue2));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
  background-clip:text;
}
.login-logo .tagline{
  font-size:.8rem;color:var(--gray);margin-top:.3rem;letter-spacing:.3px;
}
.login-logo .tape-deco{
  display:flex;align-items:center;justify-content:center;gap:.5rem;
  margin:1rem 0;
}
.tape-strip{height:8px;width:60px;background:linear-gradient(90deg,var(--gold),var(--gold2));border-radius:4px;opacity:.8}
.form-group{margin-bottom:1.25rem}
.form-group label{display:block;font-size:.82rem;font-weight:600;color:var(--navy);margin-bottom:.4rem;letter-spacing:.2px}
.form-group label .req{color:var(--red)}
.form-control{
  width:100%;padding:.75rem 1rem;
  border:2px solid var(--border);
  border-radius:var(--radius2);
  font-size:.95rem;color:var(--navy);
  background:var(--white);
  transition:var(--trans);
}
.form-control:focus{outline:none;border-color:var(--blue2);box-shadow:0 0 0 3px rgba(37,99,168,.12)}
.form-control::placeholder{color:var(--gray2)}
textarea.form-control{resize:vertical;min-height:100px;line-height:1.6}
select.form-control{cursor:pointer}

/* ============================================================
   BUTTONS
   ============================================================ */
.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:.5rem;
  padding:.7rem 1.5rem;
  border-radius:var(--radius2);
  font-size:.95rem;font-weight:600;
  cursor:pointer;border:none;
  transition:var(--trans);
  white-space:nowrap;
}
.btn:hover{transform:translateY(-1px);filter:brightness(1.07)}
.btn:active{transform:translateY(0);filter:brightness(.97)}
.btn-primary {background:linear-gradient(135deg,var(--blue2),var(--blue));color:#fff;box-shadow:0 4px 14px rgba(37,99,168,.35)}
.btn-gold    {background:linear-gradient(135deg,var(--gold),var(--gold2));color:var(--navy);box-shadow:0 4px 14px rgba(240,165,0,.3)}
.btn-outline {background:transparent;border:2px solid var(--blue2);color:var(--blue2)}
.btn-outline:hover{background:var(--blue2);color:#fff}
.btn-danger  {background:#fee2e2;color:var(--red);border:1px solid #fca5a5}
.btn-danger:hover{background:var(--red);color:#fff}
.btn-sm{padding:.45rem 1rem;font-size:.82rem}
.btn-lg{padding:1rem 2.5rem;font-size:1.05rem;border-radius:12px}
.btn-block{width:100%;display:flex}
.btn .icon{width:1em;height:1em}

/* ============================================================
   WELCOME / DASHBOARD
   ============================================================ */
.welcome-hero{
  background:linear-gradient(135deg,var(--navy) 0%,var(--blue) 100%);
  border-radius:var(--radius);
  padding:2.5rem 2rem;
  color:#fff;
  margin-bottom:1.5rem;
  position:relative;
  overflow:hidden;
}
.welcome-hero::before{
  content:'';
  position:absolute;top:-40px;right:-40px;
  width:200px;height:200px;
  background:radial-gradient(circle,rgba(240,165,0,.15),transparent 70%);
  border-radius:50%;
}
.welcome-hero::after{
  content:'';
  position:absolute;bottom:-60px;left:-20px;
  width:160px;height:160px;
  background:radial-gradient(circle,rgba(255,255,255,.05),transparent 70%);
  border-radius:50%;
}
.welcome-hero h1{font-size:clamp(1.4rem,4vw,2.1rem);font-weight:800;line-height:1.2;margin-bottom:.6rem}
.welcome-hero h1 span{color:var(--gold)}
.welcome-hero p{color:rgba(255,255,255,.7);font-size:.95rem;line-height:1.6;max-width:520px}
.welcome-ctas{display:flex;gap:1rem;margin-top:1.75rem;flex-wrap:wrap}

.stats-grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(140px,1fr));
  gap:1rem;
  margin-bottom:1.5rem;
}
.stat-card{
  background:var(--card);
  border-radius:var(--radius);
  padding:1.25rem 1rem;
  text-align:center;
  box-shadow:var(--shadow);
  border-top:3px solid var(--blue2);
}
.stat-card.gold{border-top-color:var(--gold)}
.stat-card.green{border-top-color:var(--green)}
.stat-card.navy{border-top-color:var(--navy)}
.stat-num{font-size:2rem;font-weight:800;color:var(--navy);line-height:1}
.stat-label{font-size:.75rem;color:var(--gray);margin-top:.3rem;font-weight:500;text-transform:uppercase;letter-spacing:.5px}

.section-head{
  display:flex;align-items:center;justify-content:space-between;
  margin-bottom:1rem;
}
.section-head h2{font-size:1rem;font-weight:700;color:var(--navy)}

.recent-list{display:flex;flex-direction:column;gap:.6rem}
.recent-item{
  background:var(--card);
  border-radius:var(--radius2);
  padding:.9rem 1.1rem;
  display:flex;align-items:center;gap:1rem;
  box-shadow:var(--shadow);
  cursor:pointer;
  transition:var(--trans);
  text-decoration:none;
  color:inherit;
}
.recent-item:hover{transform:translateX(4px);box-shadow:var(--shadow2)}
.recent-badge{
  width:40px;height:40px;border-radius:10px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  font-size:.7rem;font-weight:700;letter-spacing:.5px;
  text-transform:uppercase;
}
.recent-badge.ks{background:rgba(37,99,168,.12);color:var(--blue2)}
.recent-badge.kt{background:rgba(240,165,0,.15);color:var(--gold2)}
.recent-info{flex:1;min-width:0}
.recent-info strong{display:block;font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.recent-info span{font-size:.78rem;color:var(--gray)}
.recent-meta{font-size:.75rem;color:var(--gray2);text-align:right;white-space:nowrap}

/* ============================================================
   FORM — INSERIMENTO
   ============================================================ */
.form-card{
  background:var(--card);
  border-radius:var(--radius);
  padding:1.75rem 1.5rem;
  box-shadow:var(--shadow);
  margin-bottom:1.5rem;
}
.form-card-title{
  font-size:1rem;font-weight:700;color:var(--navy);
  padding-bottom:.75rem;margin-bottom:1.25rem;
  border-bottom:2px solid var(--light);
  display:flex;align-items:center;gap:.5rem;
}
.form-card-title .icon{color:var(--blue2)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
@media(max-width:540px){.form-row{grid-template-columns:1fr}}

/* RADIO BUTTONS */
.radio-group{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:.25rem}
.radio-opt{
  display:flex;align-items:center;gap:.5rem;
  padding:.55rem 1.1rem;
  border-radius:50px;
  border:2px solid var(--border);
  cursor:pointer;
  font-size:.88rem;font-weight:500;color:var(--gray);
  transition:var(--trans);
  user-select:none;
}
.radio-opt:hover{border-color:var(--blue2);color:var(--blue2)}
.radio-opt input{display:none}
.radio-opt.checked,.radio-opt:has(input:checked){
  border-color:var(--blue2);
  background:rgba(37,99,168,.08);
  color:var(--blue2);font-weight:600;
}
.radio-opt.gold-check.checked,.radio-opt.gold-check:has(input:checked){
  border-color:var(--gold2);
  background:rgba(240,165,0,.1);
  color:var(--gold2);
}
.radio-dot{
  width:10px;height:10px;border-radius:50%;
  border:2px solid currentColor;flex-shrink:0;
  position:relative;
}
.radio-opt:has(input:checked) .radio-dot::after{
  content:'';
  position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);
  width:5px;height:5px;border-radius:50%;background:currentColor;
}

/* UPLOAD AREA */
.upload-area{
  border:2px dashed var(--border);
  border-radius:var(--radius);
  padding:2rem 1rem;
  text-align:center;
  cursor:pointer;
  transition:var(--trans);
  background:var(--light);
  position:relative;
}
.upload-area:hover,.upload-area.drag{border-color:var(--blue2);background:rgba(37,99,168,.04)}
.upload-area input[type=file]{
  position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;
}
.upload-icon{font-size:2.5rem;margin-bottom:.5rem;display:block}
.upload-area p{font-size:.88rem;color:var(--gray);line-height:1.5}
.upload-area strong{color:var(--blue2)}
.upload-preview{margin-top:1rem;display:none}
.upload-preview img,.upload-preview video{
  max-height:200px;border-radius:var(--radius2);
  object-fit:contain;
}

/* ============================================================
   DATABASE LIST
   ============================================================ */
.db-filters{
  background:var(--card);
  border-radius:var(--radius);
  padding:1rem 1.25rem;
  box-shadow:var(--shadow);
  margin-bottom:1rem;
  display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;
}
.db-search{
  flex:1;min-width:160px;
  position:relative;
}
.db-search input{
  padding:.6rem .9rem .6rem 2.4rem;
  border:2px solid var(--border);
  border-radius:50px;
  font-size:.9rem;width:100%;
  transition:var(--trans);
  background:var(--light);
}
.db-search input:focus{outline:none;border-color:var(--blue2);background:#fff}
.db-search .search-icon{
  position:absolute;left:.75rem;top:50%;transform:translateY(-50%);
  color:var(--gray2);pointer-events:none;
  width:1rem;height:1rem;
}
.filter-select{
  padding:.55rem .9rem;
  border:2px solid var(--border);
  border-radius:50px;
  font-size:.85rem;color:var(--navy);
  background:var(--light);cursor:pointer;
  transition:var(--trans);
}
.filter-select:focus{outline:none;border-color:var(--blue2)}
.db-count{font-size:.82rem;color:var(--gray);margin-left:auto}

.db-table-wrap{
  background:var(--card);
  border-radius:var(--radius);
  box-shadow:var(--shadow);
  overflow:hidden;
}
.db-table{width:100%;border-collapse:collapse;font-size:.88rem}
.db-table thead{background:var(--navy);color:#fff}
.db-table thead th{padding:.85rem 1rem;text-align:left;font-size:.78rem;font-weight:600;letter-spacing:.5px;text-transform:uppercase;white-space:nowrap}
.db-table tbody tr{border-bottom:1px solid var(--border);cursor:pointer;transition:var(--trans)}
.db-table tbody tr:hover{background:rgba(37,99,168,.04)}
.db-table tbody tr:last-child{border-bottom:none}
.db-table td{padding:.85rem 1rem;vertical-align:middle}
.db-table td .ellipsis{max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block}
.bu-chip{
  display:inline-block;padding:.2rem .6rem;border-radius:50px;
  font-size:.72rem;font-weight:700;letter-spacing:.3px;text-transform:uppercase;
}
.bu-chip.ks{background:rgba(37,99,168,.1);color:var(--blue2)}
.bu-chip.kt{background:rgba(240,165,0,.15);color:var(--gold2)}
.settore-chip{
  display:inline-block;padding:.2rem .6rem;border-radius:50px;
  font-size:.72rem;font-weight:600;
  background:var(--light);color:var(--gray);
}
.media-dot{width:8px;height:8px;border-radius:50%;background:var(--green);display:inline-block}
.db-table .action-cell{text-align:right;white-space:nowrap}

/* Mobile table → cards */
@media(max-width:680px){
  .db-table thead{display:none}
  .db-table tbody tr{display:block;border-bottom:none;border-radius:var(--radius2);margin-bottom:.75rem;padding:.75rem 1rem;background:var(--card);box-shadow:var(--shadow)}
  .db-table td{display:flex;align-items:center;padding:.3rem 0;border:none}
  .db-table td::before{content:attr(data-label);font-size:.72rem;font-weight:700;color:var(--gray2);text-transform:uppercase;letter-spacing:.4px;min-width:90px;flex-shrink:0}
  .db-table td .ellipsis{max-width:none}
  .db-table-wrap{background:transparent;box-shadow:none;overflow:visible}
  .db-table{background:transparent}
  .db-table tbody tr:hover{background:var(--card)}
}

/* ============================================================
   DETTAGLIO
   ============================================================ */
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
@media(max-width:640px){.detail-grid{grid-template-columns:1fr}}
.detail-field{
  background:var(--card);
  border-radius:var(--radius2);
  padding:1rem 1.1rem;
  box-shadow:var(--shadow);
}
.detail-field label{
  font-size:.72rem;font-weight:700;color:var(--gray2);
  text-transform:uppercase;letter-spacing:.5px;
  display:block;margin-bottom:.35rem;
}
.detail-field .val{font-size:.95rem;color:var(--navy);line-height:1.6}
.detail-field.full{grid-column:1/-1}
.detail-media{
  background:var(--card);border-radius:var(--radius);
  padding:1.25rem;box-shadow:var(--shadow);
  margin-bottom:1rem;
}
.detail-media h3{font-size:.88rem;font-weight:700;color:var(--gray2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:.75rem}
.media-container{text-align:center}
.media-container img{max-height:400px;object-fit:contain;border-radius:var(--radius2)}
.media-container video{max-height:400px;width:100%;border-radius:var(--radius2)}

.detail-header{
  background:linear-gradient(135deg,var(--navy),var(--blue));
  border-radius:var(--radius);padding:1.5rem;color:#fff;
  margin-bottom:1.25rem;
}
.detail-header h1{font-size:1.4rem;font-weight:800;margin-bottom:.4rem}
.detail-header .meta{font-size:.82rem;color:rgba(255,255,255,.65);display:flex;flex-wrap:wrap;gap:.75rem}
.detail-actions{display:flex;gap:.75rem;margin-top:1rem;flex-wrap:wrap}

/* ============================================================
   EMPTY STATE
   ============================================================ */
.empty{
  text-align:center;padding:4rem 1rem;color:var(--gray);
}
.empty .emoji{font-size:3rem;margin-bottom:.75rem;display:block}
.empty h3{font-size:1.1rem;font-weight:700;color:var(--navy);margin-bottom:.4rem}
.empty p{font-size:.9rem}

/* ============================================================
   FOOTER
   ============================================================ */
footer{
  background:var(--navy);color:rgba(255,255,255,.4);
  text-align:center;padding:.9rem;font-size:.75rem;letter-spacing:.3px;
}
footer span{color:var(--gold)}

/* ============================================================
   UTILITIES
   ============================================================ */
.mt1{margin-top:1rem}.mt2{margin-top:1.5rem}.mb1{margin-bottom:1rem}
.text-gray{color:var(--gray)}
.page-title{font-size:1.3rem;font-weight:800;color:var(--navy);margin-bottom:1.25rem;display:flex;align-items:center;gap:.6rem}
.page-title a{color:var(--gray2);transition:var(--trans)}.page-title a:hover{color:var(--navy)}
.divider{border:none;border-top:2px solid var(--border);margin:1.5rem 0}
</style>
</head>
<body>

<?php // ---- TOPBAR (only if logged) ----
if (logged()): ?>
<nav class="topbar">
  <div class="topbar-brand">
    <span>BAM</span>
    <span class="badge">ALC</span>
  </div>
  <div class="topbar-user">
    <?= icon('user') ?>
    <strong><?= h(me()['nome'] ?: me()['email']) ?></strong>
  </div>
  <div class="topbar-nav">
    <a href="?p=welcome"     class="<?= $p==='welcome'?'active':'' ?>"><?= icon('home') ?><span class="nav-label">Home</span></a>
    <a href="?p=inserimento" class="<?= $p==='inserimento'?'active':'' ?>"><?= icon('plus') ?><span class="nav-label">Nuova</span></a>
    <a href="?p=database"    class="<?= $p==='database'?'active':'' ?>"><?= icon('database') ?><span class="nav-label">Database</span></a>
    <a href="?p=logout"><?= icon('logout') ?><span class="nav-label">Esci</span></a>
  </div>
</nav>
<?php endif; ?>

<?php // ===== FLASH MESSAGE =====
if ($flash): ?>
<div class="flash <?= h($flashType) ?>" style="margin:1rem auto;max-width:960px;padding-left:1rem;padding-right:1rem">
  <?php if ($flashType==='success') echo icon('check'); ?>
  <?= h($flash) ?>
</div>
<?php endif; ?>

<?php
// ============================================================
// PAGE: LOGIN
// ============================================================
if ($p === 'login'): ?>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-logo">
      <div class="bam-big">BAM</div>
      <div class="tagline">Bonding Application Management</div>
      <div class="tape-deco">
        <div class="tape-strip"></div>
        <span style="font-size:.7rem;color:#94a3b8;letter-spacing:1px;text-transform:uppercase">ALC</span>
        <div class="tape-strip"></div>
      </div>
    </div>
    <?php if (!empty($flash) && $flashType==='error'): ?>
    <div class="flash error"><?= h($flash) ?></div>
    <?php endif; ?>
    <form method="POST" action="?p=login">
      <input type="hidden" name="_action" value="login">
      <div class="form-group">
        <label for="email">Email aziendale</label>
        <input class="form-control" type="email" id="email" name="email"
               placeholder="nome@alc.it" required
               value="<?= h($_POST['email'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="pw">Password</label>
        <input class="form-control" type="password" id="pw" name="pw" placeholder="••••••••" required>
      </div>
      <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top:.5rem">
        Accedi alla piattaforma
      </button>
    </form>
    <p style="text-align:center;font-size:.78rem;color:#94a3b8;margin-top:1.5rem">
      Accesso riservato al personale ALC<br>
      <em>Default: admin@alc.it / alc2024</em>
    </p>
  </div>
</div>

<?php
// ============================================================
// PAGE: WELCOME
// ============================================================
elseif ($p === 'welcome'): ?>
<div class="page">
  <!-- HERO -->
  <div class="welcome-hero">
    <h1>Benvenuto in <span>BAM</span></h1>
    <p>Una piattaforma per organizzare e condividere il know-how applicativo<br>al servizio dello sviluppo commerciale.</p>
    <div class="welcome-ctas">
      <a href="?p=inserimento" class="btn btn-gold btn-lg">
        <?= icon('plus') ?> Inserisci Applicazione
      </a>
      <a href="?p=database" class="btn btn-outline btn-lg" style="border-color:rgba(255,255,255,.5);color:#fff">
        <?= icon('database') ?> Visualizza Database
      </a>
    </div>
  </div>

  <!-- STATS -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-num"><?= h($stats['totale']) ?></div>
      <div class="stat-label">Applicazioni totali</div>
    </div>
    <div class="stat-card gold">
      <div class="stat-num"><?= h($stats['mese']) ?></div>
      <div class="stat-label">Inserite questo mese</div>
    </div>
    <div class="stat-card green">
      <div class="stat-num"><?= h($stats['clienti']) ?></div>
      <div class="stat-label">Clienti distinti</div>
    </div>
    <div class="stat-card navy">
      <div class="stat-num"><?= h($stats['bu_ksystem']) ?> / <?= h($stats['bu_ktermo']) ?></div>
      <div class="stat-label">K-System / K-Termo</div>
    </div>
  </div>

  <!-- RECENTI -->
  <div class="section-head">
    <h2><?= icon('star') ?> Ultime applicazioni inserite</h2>
    <a href="?p=database" class="btn btn-sm btn-outline">Vedi tutte</a>
  </div>
  <?php if (empty($recenti)): ?>
    <div class="empty">
      <span class="emoji">📋</span>
      <h3>Nessuna applicazione ancora</h3>
      <p>Inizia inserendo la prima applicazione.</p>
      <a href="?p=inserimento" class="btn btn-primary mt1">Inserisci ora</a>
    </div>
  <?php else: ?>
  <div class="recent-list">
    <?php foreach ($recenti as $r):
      $isBU = $r['business_unit'] === 'K-System' ? 'ks' : 'kt';
    ?>
    <a href="?p=dettaglio&id=<?= $r['id'] ?>" class="recent-item">
      <div class="recent-badge <?= $isBU ?>"><?= $r['business_unit']==='K-System'?'KS':'KT' ?></div>
      <div class="recent-info">
        <strong><?= h($r['cliente']) ?></strong>
        <span><?= h($r['settore']) ?> · <?= h($r['regione']) ?> · <?= h($r['prodotto_alc']) ?></span>
      </div>
      <div class="recent-meta">
        <?= date('d/m/y', strtotime($r['created_at'])) ?><br>
        <?= h($r['ins'] ?: '—') ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php
// ============================================================
// PAGE: INSERIMENTO
// ============================================================
elseif ($p === 'inserimento'):
$fd = $formData ?? [];
$fe = $formErrs ?? [];
$regions = ['Lombardia','Veneto','Toscana','Marche','Piemonte','Campania','Emilia-Romagna'];
?>
<div class="page">
  <div class="page-title">
    <a href="?p=welcome"><?= icon('back') ?></a>
    Nuova Applicazione
  </div>

  <?php if (!empty($flash) && $flashType==='error'): ?>
  <div class="flash error"><?= h($flash) ?></div>
  <?php endif; ?>

  <form method="POST" action="?p=inserimento" enctype="multipart/form-data">
    <input type="hidden" name="_action" value="salva">

    <!-- BUSINESS UNIT -->
    <div class="form-card">
      <div class="form-card-title"><?= icon('tag') ?> Business Unit <span style="color:var(--red);margin-left:.2rem">*</span></div>
      <div class="radio-group">
        <?php foreach (['K-System','K-Termo'] as $bu):
          $checked = ($fd['business_unit'] ?? '') === $bu;
        ?>
        <label class="radio-opt <?= in_array('business_unit',$fe)?'border-red':'' ?> <?= $checked?'checked':'' ?>">
          <input type="radio" name="business_unit" value="<?= h($bu) ?>" <?= $checked?'checked':'' ?> required>
          <span class="radio-dot"></span>
          <?= h($bu) ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- SETTORE -->
    <div class="form-card">
      <div class="form-card-title"><?= icon('filter') ?> Settore <span style="color:var(--red);margin-left:.2rem">*</span></div>
      <div class="radio-group">
        <?php foreach (['Calzatura','Pelletteria','Industria'] as $s):
          $checked = ($fd['settore'] ?? '') === $s;
        ?>
        <label class="radio-opt gold-check <?= $checked?'checked':'' ?>">
          <input type="radio" name="settore" value="<?= h($s) ?>" <?= $checked?'checked':'' ?> required>
          <span class="radio-dot"></span>
          <?= h($s) ?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- DATI PRINCIPALI -->
    <div class="form-card">
      <div class="form-card-title"><?= icon('user') ?> Dati Applicazione</div>

      <div class="form-row">
        <div class="form-group">
          <label for="regione">Regione <span class="req">*</span></label>
          <select class="form-control" id="regione" name="regione" required>
            <option value="">— Seleziona —</option>
            <?php foreach ($regions as $r): ?>
            <option value="<?= h($r) ?>" <?= ($fd['regione']??'')===$r?'selected':'' ?>><?= h($r) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="cliente">Cliente <span class="req">*</span></label>
          <input class="form-control" type="text" id="cliente" name="cliente"
                 placeholder="Nome azienda cliente" required
                 value="<?= h($fd['cliente']??'') ?>">
        </div>
      </div>

      <div class="form-group">
        <label for="prodotto_alc">Prodotto ALC — Codice <span class="req">*</span></label>
        <input class="form-control" type="text" id="prodotto_alc" name="prodotto_alc"
               placeholder="es. K-502, T-310..." required
               value="<?= h($fd['prodotto_alc']??'') ?>">
      </div>

      <div class="form-group">
        <label for="problema">Problema risolto / Applicazione <span class="req">*</span></label>
        <textarea class="form-control" id="problema" name="problema"
                  placeholder="Descrivi il problema risolto, l'applicazione e i benefici ottenuti..."
                  required rows="4"><?= h($fd['problema']??'') ?></textarea>
      </div>

      <div class="form-group">
        <label for="note">Note aggiuntive</label>
        <textarea class="form-control" id="note" name="note"
                  placeholder="Eventuali note, parametri tecnici, condizioni particolari..."
                  rows="2"><?= h($fd['note']??'') ?></textarea>
      </div>
    </div>

    <!-- FOTO / VIDEO -->
    <div class="form-card">
      <div class="form-card-title"><?= icon('camera') ?> Foto / Video Applicazione</div>
      <div class="upload-area" id="uploadArea">
        <input type="file" name="media" id="mediaInput"
               accept="image/*,video/*"
               capture="environment"
               onchange="previewMedia(this)">
        <span class="upload-icon">📷</span>
        <p><strong>Scatta una foto</strong> o registra un video<br>
           oppure seleziona dal tuo dispositivo<br>
           <small style="color:var(--gray2)">JPG, PNG, MP4, MOV — Max <?= MAX_FILE_MB ?>MB</small>
        </p>
      </div>
      <div class="upload-preview" id="uploadPreview">
        <img id="previewImg" src="" alt="Anteprima" style="display:none">
        <video id="previewVid" controls style="display:none"></video>
        <p style="font-size:.8rem;color:var(--gray);margin-top:.4rem" id="previewName"></p>
      </div>
    </div>

    <div style="display:flex;gap:1rem;flex-wrap:wrap">
      <button type="submit" class="btn btn-gold btn-lg" style="flex:1">
        <?= icon('check') ?> Salva Applicazione
      </button>
      <a href="?p=welcome" class="btn btn-outline" style="padding:.9rem 1.5rem">
        Annulla
      </a>
    </div>
  </form>
</div>

<?php
// ============================================================
// PAGE: DATABASE
// ============================================================
elseif ($p === 'database'): ?>
<div class="page page-wide">
  <div class="page-title">
    <?= icon('database') ?> Database Applicazioni
  </div>

  <!-- FILTERS -->
  <form method="GET" action="?" class="db-filters">
    <input type="hidden" name="p" value="database">
    <div class="db-search">
      <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" name="q" placeholder="Cerca cliente, prodotto, problema..." value="<?= h($search) ?>">
    </div>
    <select name="bu" class="filter-select" onchange="this.form.submit()">
      <option value="">Tutte le BU</option>
      <option value="K-System" <?= $bu_f==='K-System'?'selected':'' ?>>K-System</option>
      <option value="K-Termo"  <?= $bu_f==='K-Termo'?'selected':'' ?>>K-Termo</option>
    </select>
    <select name="settore" class="filter-select" onchange="this.form.submit()">
      <option value="">Tutti i settori</option>
      <?php foreach (['Calzatura','Pelletteria','Industria'] as $s): ?>
      <option value="<?= h($s) ?>" <?= $settore_f===$s?'selected':'' ?>><?= h($s) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><?= icon('search') ?> Cerca</button>
    <?php if ($search || $bu_f || $settore_f): ?>
    <a href="?p=database" class="btn btn-sm" style="background:var(--light);color:var(--gray)">✕ Reset</a>
    <?php endif; ?>
    <span class="db-count"><?= count($apps) ?> risultat<?= count($apps)===1?'o':'i' ?></span>
  </form>

  <?php if (empty($apps)): ?>
  <div class="empty">
    <span class="emoji">🔍</span>
    <h3>Nessuna applicazione trovata</h3>
    <p><?= $search||$bu_f||$settore_f ? 'Prova a modificare i filtri di ricerca.' : 'Inizia inserendo la prima applicazione!' ?></p>
    <a href="?p=inserimento" class="btn btn-primary mt1"><?= icon('plus') ?> Inserisci Applicazione</a>
  </div>
  <?php else: ?>
  <div class="db-table-wrap">
    <table class="db-table">
      <thead>
        <tr>
          <th>BU</th>
          <th>Settore</th>
          <th>Regione</th>
          <th>Cliente</th>
          <th>Prodotto ALC</th>
          <th>Applicazione</th>
          <th>Media</th>
          <th>Data</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($apps as $app):
          $buClass = $app['business_unit']==='K-System'?'ks':'kt';
        ?>
        <tr onclick="location.href='?p=dettaglio&id=<?= $app['id'] ?>'">
          <td data-label="BU">
            <span class="bu-chip <?= $buClass ?>"><?= h($app['business_unit']) ?></span>
          </td>
          <td data-label="Settore">
            <span class="settore-chip"><?= h($app['settore']) ?></span>
          </td>
          <td data-label="Regione"><?= h($app['regione']) ?></td>
          <td data-label="Cliente"><strong><?= h($app['cliente']) ?></strong></td>
          <td data-label="Prodotto ALC"><code style="background:var(--light);padding:.15rem .4rem;border-radius:4px;font-size:.8rem"><?= h($app['prodotto_alc']) ?></code></td>
          <td data-label="Applicazione"><span class="ellipsis"><?= h($app['problema']) ?></span></td>
          <td data-label="Media">
            <?php if ($app['media_path']): ?>
              <?= $app['media_type']==='video' ? '🎥' : '📸' ?>
            <?php else: echo '—'; endif; ?>
          </td>
          <td data-label="Data" class="text-gray"><?= date('d/m/Y', strtotime($app['created_at'])) ?></td>
          <td class="action-cell">
            <a href="?p=dettaglio&id=<?= $app['id'] ?>" class="btn btn-sm btn-outline" onclick="event.stopPropagation()"><?= icon('eye') ?></a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php
// ============================================================
// PAGE: DETTAGLIO
// ============================================================
elseif ($p === 'dettaglio' && $app):
  $buClass = $app['business_unit']==='K-System'?'ks':'kt';
?>
<div class="page">
  <!-- HEADER -->
  <div class="detail-header">
    <div style="margin-bottom:.75rem">
      <span class="bu-chip <?= $buClass ?>"><?= h($app['business_unit']) ?></span>
      <span class="settore-chip" style="margin-left:.5rem"><?= h($app['settore']) ?></span>
    </div>
    <h1><?= h($app['cliente']) ?></h1>
    <div class="meta">
      <span><?= icon('pin') ?> <?= h($app['regione']) ?></span>
      <span><?= icon('tag') ?> <?= h($app['prodotto_alc']) ?></span>
      <span><?= icon('user') ?> <?= h($app['inserito_da'] ?: $app['user_email']) ?></span>
      <span>📅 <?= date('d/m/Y H:i', strtotime($app['created_at'])) ?></span>
    </div>
    <div class="detail-actions">
      <a href="?p=database" class="btn btn-outline btn-sm" style="border-color:rgba(255,255,255,.4);color:#fff"><?= icon('back') ?> Torna al database</a>
    </div>
  </div>

  <!-- MEDIA -->
  <?php if ($app['media_path']): ?>
  <div class="detail-media">
    <h3><?= $app['media_type']==='video' ? '🎥 Video applicazione' : '📸 Foto applicazione' ?></h3>
    <div class="media-container">
      <?php if ($app['media_type'] === 'image'): ?>
        <img src="<?= h($app['media_path']) ?>" alt="Foto applicazione" loading="lazy">
      <?php else: ?>
        <video controls preload="metadata">
          <source src="<?= h($app['media_path']) ?>">
          Il tuo browser non supporta la riproduzione video.
        </video>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- CAMPI -->
  <div class="detail-grid">
    <div class="detail-field">
      <label>Business Unit</label>
      <div class="val"><span class="bu-chip <?= $buClass ?>"><?= h($app['business_unit']) ?></span></div>
    </div>
    <div class="detail-field">
      <label>Settore</label>
      <div class="val"><?= h($app['settore']) ?></div>
    </div>
    <div class="detail-field">
      <label>Regione</label>
      <div class="val"><?= h($app['regione']) ?></div>
    </div>
    <div class="detail-field">
      <label>Cliente</label>
      <div class="val"><strong><?= h($app['cliente']) ?></strong></div>
    </div>
    <div class="detail-field">
      <label>Prodotto ALC</label>
      <div class="val"><code style="background:var(--light);padding:.2rem .5rem;border-radius:4px"><?= h($app['prodotto_alc']) ?></code></div>
    </div>
    <div class="detail-field">
      <label>Inserito da</label>
      <div class="val"><?= h($app['inserito_da'] ?: $app['user_email']) ?></div>
    </div>
    <div class="detail-field full">
      <label>Problema risolto / Applicazione</label>
      <div class="val" style="white-space:pre-line"><?= h($app['problema']) ?></div>
    </div>
    <?php if ($app['note']): ?>
    <div class="detail-field full">
      <label>Note aggiuntive</label>
      <div class="val text-gray" style="white-space:pre-line"><?= h($app['note']) ?></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ACTIONS -->
  <hr class="divider">
  <div style="display:flex;gap:.75rem;flex-wrap:wrap">
    <a href="?p=database" class="btn btn-outline"><?= icon('back') ?> Torna al database</a>
    <form method="POST" action="?p=database" onsubmit="return confirm('Sei sicuro di voler eliminare questa applicazione? L\'operazione è irreversibile.')" style="margin-left:auto">
      <input type="hidden" name="_action" value="elimina">
      <input type="hidden" name="id" value="<?= $app['id'] ?>">
      <button type="submit" class="btn btn-danger btn-sm">🗑 Elimina</button>
    </form>
  </div>
</div>

<?php endif; ?>

<footer>
  &copy; <?= date('Y') ?> <span>ALC</span> — BAM Bonding Application Management — v1.0
</footer>

<script>
// Radio button visual update
document.querySelectorAll('.radio-opt input[type=radio]').forEach(radio => {
  radio.addEventListener('change', () => {
    const group = radio.closest('.radio-group');
    if (!group) return;
    group.querySelectorAll('.radio-opt').forEach(opt => opt.classList.remove('checked'));
    radio.closest('.radio-opt').classList.add('checked');
  });
});

// File preview
function previewMedia(input) {
  const file = input.files[0];
  if (!file) return;
  const preview  = document.getElementById('uploadPreview');
  const img      = document.getElementById('previewImg');
  const vid      = document.getElementById('previewVid');
  const name     = document.getElementById('previewName');
  const url      = URL.createObjectURL(file);
  preview.style.display = 'block';
  if (file.type.startsWith('image/')) {
    img.src = url; img.style.display = 'block';
    vid.style.display = 'none'; vid.src = '';
  } else {
    vid.src = url; vid.style.display = 'block';
    img.style.display = 'none'; img.src = '';
  }
  const mb = (file.size / 1024 / 1024).toFixed(1);
  name.textContent = `${file.name} (${mb} MB)`;
}

// Drag and drop
const ua = document.getElementById('uploadArea');
if (ua) {
  ua.addEventListener('dragover', e => { e.preventDefault(); ua.classList.add('drag'); });
  ua.addEventListener('dragleave', () => ua.classList.remove('drag'));
  ua.addEventListener('drop', e => {
    e.preventDefault(); ua.classList.remove('drag');
    const input = ua.querySelector('input[type=file]');
    input.files = e.dataTransfer.files;
    previewMedia(input);
  });
}

// Auto-hide flash
setTimeout(() => {
  document.querySelectorAll('.flash').forEach(el => {
    el.style.transition = 'opacity .5s'; el.style.opacity = '0';
    setTimeout(() => el.remove(), 500);
  });
}, 4000);
</script>
</body>
</html>
