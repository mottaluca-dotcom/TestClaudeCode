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
    // Default admin user
    if (!$pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn()) {
        $hash = password_hash('alc2024', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (email, password, nome, ruolo) VALUES (?, ?, ?, ?)")
            ->execute(['admin@alc.it', $hash, 'Amministratore', 'admin']);
    }
    // Migrazione nomi Business Unit (idempotente)
    $pdo->exec("UPDATE applicazioni SET business_unit='K System'  WHERE business_unit='K-System'");
    $pdo->exec("UPDATE applicazioni SET business_unit='K Thermo' WHERE business_unit='K-Termo'");
    // Normalizza business_unit in prodotti_alc (fix import case-insensitive)
    $pdo->exec("UPDATE prodotti_alc SET business_unit='K System' WHERE LOWER(business_unit)='k system' AND business_unit!='K System'");
    $pdo->exec("UPDATE prodotti_alc SET business_unit='K Thermo' WHERE LOWER(business_unit)='k thermo' AND business_unit!='K Thermo'");
    // Utente l.motta (INSERT OR IGNORE — non sovrascrive se già esistente)
    $pdo->prepare("INSERT OR IGNORE INTO users (email, password, nome, ruolo) VALUES (?, ?, ?, ?)")
        ->execute(['l.motta@alcgruppo.com', password_hash('admin', PASSWORD_DEFAULT), 'Luca Motta', 'admin']);
    // Default app permissions (INSERT OR IGNORE = non sovrascrive personalizzazioni)
    $ap = $pdo->prepare("INSERT OR IGNORE INTO role_app_permissions (ruolo,app,mode) VALUES(?,?,?)");
    foreach ([
        ['admin','bam','edit'],['admin','app1','edit'],['admin','app2','edit'],['admin','app3','edit'],
        ['responsabile','bam','edit'],['responsabile','app1','edit'],['responsabile','app2','edit'],['responsabile','app3','edit'],
        ['utente','bam','edit'],
        ['viewer','bam','view'],
    ] as $r) $ap->execute($r);
    // Default BAM section-edit permissions
    // admin: tutto | responsabile: no note_interne | utente: no note_interne, no media | viewer: niente
    $sp = $pdo->prepare("INSERT OR IGNORE INTO bam_section_edit (ruolo,sezione,can_edit) VALUES(?,?,?)");
    foreach ([
        ['admin','anagrafica',1],['admin','prodotto',1],['admin','note_interne',1],['admin','media',1],
        ['responsabile','anagrafica',1],['responsabile','prodotto',1],['responsabile','note_interne',0],['responsabile','media',1],
        ['utente','anagrafica',1],['utente','prodotto',1],['utente','note_interne',0],['utente','media',0],
        ['viewer','anagrafica',0],['viewer','prodotto',0],['viewer','note_interne',0],['viewer','media',0],
    ] as $r) $sp->execute($r);
}

// ============================================================
// HELPERS
// ============================================================
function logged(): bool { return !empty($_SESSION['uid']); }
function me(): array    { return $_SESSION['user'] ?? []; }

function guard(): void {
    if (!logged()) { redir('login'); }
}

// Restituisce 'edit' o 'view' per il ruolo corrente su BAM
function bamMode(): string {
    $ruolo = me()['ruolo'] ?? 'viewer';
    if ($ruolo === 'admin') return 'edit';
    $stmt = db()->prepare("SELECT mode FROM role_app_permissions WHERE ruolo=? AND app='bam'");
    $stmt->execute([$ruolo]);
    return $stmt->fetchColumn() ?: 'view';
}

// Restituisce true se il ruolo corrente può modificare la sezione indicata
function canEditSection(string $sezione): bool {
    $ruolo = me()['ruolo'] ?? 'viewer';
    if ($ruolo === 'admin') return true;
    if (bamMode() !== 'edit') return false;
    $stmt = db()->prepare("SELECT can_edit FROM bam_section_edit WHERE ruolo=? AND sezione=?");
    $stmt->execute([$ruolo, $sezione]);
    $val = $stmt->fetchColumn();
    return $val !== false && (bool)$val;
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
        // Codice deve esistere nella tabella prodotti_alc con la BU selezionata
        if ($data['prodotto_alc'] && $data['business_unit']) {
            $chk = db()->prepare("SELECT id FROM prodotti_alc WHERE codice=? AND business_unit=?");
            $chk->execute([$data['prodotto_alc'], $data['business_unit']]);
            if (!$chk->fetch()) $errs[] = 'prodotto_alc_invalid';
        }

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

// --- API AUTOCOMPLETE ---
if ($p === 'api') {
    guard();
    if (($_GET['action'] ?? '') === 'prodotti') {
        $bu = $_GET['bu'] ?? '';
        $q  = '%' . ($_GET['q'] ?? '') . '%';
        if ($bu !== '') {
            $stmt = db()->prepare(
                "SELECT codice, tipo, attributo FROM prodotti_alc WHERE business_unit=? AND codice LIKE ? ORDER BY codice LIMIT 40"
            );
            $stmt->execute([$bu, $q]);
        } else {
            $stmt = db()->prepare(
                "SELECT codice, tipo, attributo FROM prodotti_alc WHERE codice LIKE ? ORDER BY codice LIMIT 40"
            );
            $stmt->execute([$q]);
        }
        header('Content-Type: application/json');
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    exit;
}

// --- ADMIN CRUD PRODOTTI ALC ---
if ($p === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    guard();
    if ((me()['ruolo'] ?? '') !== 'admin') redir('welcome');
    $act_a = $_POST['_action'] ?? '';
    if ($act_a === 'add_prodotto') {
        $cod = trim($_POST['codice'] ?? '');
        $bu  = $_POST['business_unit'] ?? '';
        $tip = trim($_POST['tipo'] ?? '');
        $att = trim($_POST['attributo'] ?? '');
        if ($cod && $bu) {
            db()->prepare("INSERT INTO prodotti_alc (codice,business_unit,tipo,attributo) VALUES (?,?,?,?)")
               ->execute([$cod, $bu, $tip, $att]);
        }
        redir('admin');
    }
    if ($act_a === 'edit_prodotto') {
        $id  = (int)($_POST['id'] ?? 0);
        $cod = trim($_POST['codice'] ?? '');
        $bu  = $_POST['business_unit'] ?? '';
        $tip = trim($_POST['tipo'] ?? '');
        $att = trim($_POST['attributo'] ?? '');
        if ($id && $cod && $bu) {
            db()->prepare("UPDATE prodotti_alc SET codice=?,business_unit=?,tipo=?,attributo=? WHERE id=?")
               ->execute([$cod, $bu, $tip, $att, $id]);
        }
        redir('admin');
    }
    if ($act_a === 'del_prodotto') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) db()->prepare("DELETE FROM prodotti_alc WHERE id=?")->execute([$id]);
        redir('admin');
    }
    if ($act_a === 'import_csv') {
        $file = $_FILES['csv_file'] ?? null;
        $imported = 0; $skipped = 0; $errors = 0;
        if ($file && $file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['csv', 'txt'])) {
                $handle = fopen($file['tmp_name'], 'r');
                $firstRow = true;
                $ins = db()->prepare("INSERT OR IGNORE INTO prodotti_alc (codice,business_unit,tipo,attributo) VALUES (?,?,?,?)");
                while (($row = fgetcsv($handle, 1000, ';')) !== false) {
                    // Prova anche separatore virgola se punto e virgola non funziona
                    if (count($row) < 2 && strpos($row[0] ?? '', ',') !== false) {
                        $row = str_getcsv($row[0], ',');
                    }
                    // Salta sempre la prima riga (intestazione)
                    if ($firstRow) { $firstRow = false; continue; }
                    $cod = trim($row[0] ?? '');
                    $bu  = trim($row[1] ?? '');
                    $tip = trim($row[2] ?? '');
                    $att = trim($row[3] ?? '');
                    if (!$cod || !$bu) { $errors++; continue; }
                    $bu_map = ['k system' => 'K System', 'k thermo' => 'K Thermo'];
                    $bu_norm = $bu_map[strtolower($bu)] ?? null;
                    if (!$bu_norm) { $errors++; continue; }
                    $ins->execute([$cod, $bu_norm, $tip, $att]);
                    if ($ins->rowCount() > 0) $imported++; else $skipped++;
                }
                fclose($handle);
                $_SESSION['csv_flash'] = "Import completato: <strong>$imported</strong> importati, <strong>$skipped</strong> già presenti, <strong>$errors</strong> righe non valide.";
                $_SESSION['csv_flash_type'] = ($errors > 0 && $imported === 0) ? 'error' : 'success';
            } else {
                $_SESSION['csv_flash'] = 'Formato non supportato. Carica un file .csv';
                $_SESSION['csv_flash_type'] = 'error';
            }
        } else {
            $_SESSION['csv_flash'] = 'Errore nel caricamento del file.';
            $_SESSION['csv_flash_type'] = 'error';
        }
        redir('admin');
    }
}

// --- DOWNLOAD TEMPLATE CSV ---
if ($p === 'admin' && ($_GET['action'] ?? '') === 'csv_template') {
    guard();
    if ((me()['ruolo'] ?? '') !== 'admin') redir('welcome');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template_prodotti_alc.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8 per Excel
    fputcsv($out, ['Codice','Business Unit','Tipo','Attributo'], ';');
    fputcsv($out, ['K-502','K System','Macchina','Standard'], ';');
    fputcsv($out, ['KT-100','K Thermo','Accessorio',''], ';');
    fclose($out);
    exit;
}

// --- LOGOUT ---
if ($p === 'logout') { session_destroy(); redir('login'); }

// Guard
if (in_array($p, ['welcome','inserimento','database','dettaglio','admin'])) guard();
if ($p === 'admin' && logged() && (me()['ruolo'] ?? '') !== 'admin') redir('welcome');
// Viewer non può accedere all'inserimento
if ($p === 'inserimento' && logged() && bamMode() !== 'edit') redir('database');

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
$search = $bu_f = $settore_f = $alc_f = '';
if ($p === 'database') {
    $search   = trim($_GET['q']       ?? '');
    $bu_f     = $_GET['bu']           ?? '';
    $settore_f= $_GET['settore']      ?? '';
    if ($bu_f === 'K Thermo') $settore_f = ''; // Settore esiste solo per K System
    $alc_f    = trim($_GET['alc']     ?? '');
    $sql      = "SELECT a.*, u.nome as inserito_da
                 FROM applicazioni a LEFT JOIN users u ON a.user_id=u.id WHERE 1=1";
    $params   = [];
    if ($search)    { $sql .= " AND (a.cliente LIKE ? OR a.prodotto_alc LIKE ? OR a.problema LIKE ? OR a.regione LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]); }
    if ($bu_f)      { $sql .= " AND a.business_unit=?"; $params[] = $bu_f; }
    if ($settore_f) { $sql .= " AND a.settore=?";       $params[] = $settore_f; }
    if ($alc_f)     { $sql .= " AND a.prodotto_alc=?";  $params[] = $alc_f; }
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
    $stats['bu_ksystem']   = db()->query("SELECT COUNT(*) FROM applicazioni WHERE business_unit='K System'")->fetchColumn();
    $stats['bu_ktermo']    = db()->query("SELECT COUNT(*) FROM applicazioni WHERE business_unit='K Thermo'")->fetchColumn();
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
<meta name="theme-color" content="#D12A2F">
<style>
/* ============================================================
   RESET & ROOT
   ============================================================ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --navy:   #D12A2F;
  --blue:   #D12A2F;
  --blue2:  #D12A2F;
  --gold:   #f0a500;
  --gold2:  #e09200;
  --green:  #1e9e5a;
  --red:    #dc3545;
  --info:   #D12A2F;
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
body{background:var(--light);color:#1a1a1a;min-height:100vh;display:flex;flex-direction:column}
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
.topbar-nav a.active{background:rgba(255,255,255,.18);color:#fff}
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
.role-badge{font-size:.65rem;font-weight:700;padding:.15rem .45rem;border-radius:20px;letter-spacing:.5px;text-transform:uppercase}
.role-admin{background:#f0a500;color:#1a1a1a}
.role-responsabile{background:#2a6dd1;color:#fff}
.role-utente{background:#1e9e5a;color:#fff}
.role-viewer{background:#64748b;color:#fff}
.form-cards-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
@media(max-width:600px){.form-cards-row{grid-template-columns:1fr}}
.section-readonly{opacity:.7;border-left:3px solid var(--gray2)!important}
.section-lock-banner{font-size:.75rem;color:var(--gray);background:var(--light);border-radius:6px;padding:.35rem .7rem;margin-bottom:.75rem}
.disabled-opt{pointer-events:none;opacity:.6}

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
.flash.info   {background:#fde8e8;color:#D12A2F;border:1px solid #f5a5a7}
@keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}

/* ============================================================
   LOGIN PAGE
   ============================================================ */
.login-wrap{
  min-height:100vh;
  display:flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,var(--navy) 0%,#b02428 50%,#8c1c1f 100%);
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
.form-group label{display:block;font-size:.82rem;font-weight:600;color:#1a1a1a;margin-bottom:.4rem;letter-spacing:.2px}
.form-group label .req{color:var(--red)}
.form-control{
  width:100%;padding:.75rem 1rem;
  border:2px solid var(--border);
  border-radius:var(--radius2);
  font-size:.95rem;color:#1a1a1a;
  background:var(--white);
  transition:var(--trans);
}
.form-control:focus{outline:none;border-color:var(--blue2);box-shadow:0 0 0 3px rgba(209,42,47,.12)}
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
.btn-primary {background:linear-gradient(135deg,var(--blue2),var(--blue));color:#fff;box-shadow:0 4px 14px rgba(209,42,47,.35)}
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
.welcome-hero h1 span{color:#fff}
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
.stat-num{font-size:2rem;font-weight:400;color:#1a1a1a;line-height:1}
.stat-label{font-size:.75rem;color:var(--gray);margin-top:.3rem;font-weight:500;text-transform:uppercase;letter-spacing:.5px}

.section-head{
  display:flex;align-items:center;justify-content:space-between;
  margin-bottom:1rem;
}
.section-head h2{font-size:1rem;font-weight:700;color:#1a1a1a}

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
  text-transform:uppercase;border:2px solid;
}
.recent-badge.ks{background:#fff;color:#D12A2F;border-color:#D12A2F}
.recent-badge.kt{background:#fff;color:#2563eb;border-color:#2563eb}
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
  font-size:1rem;font-weight:700;color:#1a1a1a;
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
  background:rgba(209,42,47,.08);
  color:var(--blue2);font-weight:600;
}
.radio-opt.gold-check.checked,.radio-opt.gold-check:has(input:checked){
  border-color:var(--red);
  background:rgba(209,42,47,.08);
  color:var(--red);
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
.upload-area:hover,.upload-area.drag{border-color:var(--blue2);background:rgba(209,42,47,.04)}
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
  font-size:.85rem;color:#1a1a1a;
  background:var(--light);cursor:pointer;
  transition:var(--trans);
}
.filter-select:focus{outline:none;border-color:var(--blue2)}
.db-count{font-size:.82rem;color:var(--gray);margin-left:auto}
.db-filters-row{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;width:100%}
.db-filters-radios{padding:.25rem 0}
.filter-label{font-size:.78rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}
.filter-sep{color:var(--border);font-size:1.2rem;margin:0 .15rem}
.filter-radio{
  display:inline-flex;align-items:center;gap:.3rem;
  padding:.35rem .75rem;
  border-radius:50px;
  border:2px solid var(--border);
  font-size:.82rem;font-weight:500;color:var(--gray);
  cursor:pointer;transition:var(--trans);
  user-select:none;white-space:nowrap;
}
.filter-radio input{display:none}
.filter-radio:hover{border-color:var(--blue2);color:var(--blue2)}
.filter-radio.active{border-color:var(--blue2);background:rgba(209,42,47,.08);color:var(--blue2);font-weight:600}

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
.db-table tbody tr:hover{background:rgba(209,42,47,.04)}
.db-table tbody tr:last-child{border-bottom:none}
.db-table td{padding:.85rem 1rem;vertical-align:middle}
.db-table td .ellipsis{max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block}
.bu-chip{
  display:inline-block;padding:.2rem .6rem;border-radius:50px;
  font-size:.72rem;font-weight:700;letter-spacing:.3px;text-transform:uppercase;
}
.bu-chip.ks{background:#fff;color:#D12A2F;border:2px solid #D12A2F}
.bu-chip.kt{background:#fff;color:#2563eb;border:2px solid #2563eb}
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
.detail-field .val{font-size:.95rem;color:#1a1a1a;line-height:1.6}
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
.empty h3{font-size:1.1rem;font-weight:700;color:#1a1a1a;margin-bottom:.4rem}
.empty p{font-size:.9rem}

/* ============================================================
   FOOTER
   ============================================================ */
footer{
  background:var(--navy);color:rgba(255,255,255,.4);
  text-align:center;padding:.9rem;font-size:.75rem;letter-spacing:.3px;
}
footer span{color:var(--red)}

/* ============================================================
   UTILITIES
   ============================================================ */
/* ---- Autocomplete ALC ---- */
.alc-wrap{position:relative}
.alc-dropdown{position:absolute;top:calc(100% + 4px);left:0;right:0;background:#fff;border:1.5px solid var(--gray2);border-radius:var(--radius2);box-shadow:0 6px 18px rgba(0,0,0,.13);z-index:300;max-height:220px;overflow-y:auto}
.alc-item{padding:.55rem .9rem;cursor:pointer;display:flex;align-items:center;gap:.6rem;font-size:.9rem;border-bottom:1px solid #f2f2f2}
.alc-item:last-child{border-bottom:none}
.alc-item:hover,.alc-item.alc-active{background:var(--light)}
.alc-item-code{font-weight:600;color:#1a1a1a;min-width:80px}
.alc-item-sub{font-size:.78rem;color:var(--gray);margin-left:auto;text-align:right}
.alc-empty{padding:.7rem .9rem;color:var(--gray);font-size:.85rem;font-style:italic}
.alc-meta{display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.55rem}
.alc-chip{background:var(--light);border:1px solid var(--border);padding:.3rem .65rem;border-radius:20px;font-size:.82rem;color:#1a1a1a;display:flex;align-items:center;gap:.35rem}
.alc-chip strong{font-weight:600;color:var(--gray)}
.mt1{margin-top:1rem}.mt2{margin-top:1.5rem}.mb1{margin-bottom:1rem}
.text-gray{color:var(--gray)}
.page-title{font-size:1.3rem;font-weight:800;color:#1a1a1a;margin-bottom:1.25rem;display:flex;align-items:center;gap:.6rem}
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
  </div>
  <div class="topbar-user">
    <?= icon('user') ?>
    <strong><?= h(me()['nome'] ?: me()['email']) ?></strong>
    <span class="role-badge role-<?= h(me()['ruolo'] ?? 'viewer') ?>"><?= h(me()['ruolo'] ?? 'viewer') ?></span>
  </div>
  <div class="topbar-nav">
    <a href="../index.php" title="Portale app" style="opacity:.75"><?= icon('home') ?><span class="nav-label">Portale</span></a>
    <a href="?p=welcome"     class="<?= $p==='welcome'?'active':'' ?>"><?= icon('chart') ?><span class="nav-label">Home</span></a>
    <?php if (bamMode()==='edit'): ?>
    <a href="?p=inserimento" class="<?= $p==='inserimento'?'active':'' ?>"><?= icon('plus') ?><span class="nav-label">Nuova</span></a>
    <?php endif; ?>
    <a href="?p=database"    class="<?= $p==='database'?'active':'' ?>"><?= icon('database') ?><span class="nav-label">Database</span></a>
    <?php if ((me()['ruolo'] ?? '') === 'admin'): ?>
    <a href="?p=admin" class="<?= $p==='admin'?'active':'' ?>"><?= icon('tag') ?><span class="nav-label">Catalogo Prodotti</span></a>
    <?php endif; ?>
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
    <h1><span>BAM</span></h1>
    <p><strong>B</strong>ond <strong>A</strong>pplication <strong>M</strong>anagement</p>
    <div class="welcome-ctas">
      <a href="?p=inserimento" class="btn btn-outline btn-lg" style="border-color:rgba(255,255,255,.6);color:#fff;background:rgba(255,255,255,.12)">
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
      $isBU = $r['business_unit'] === 'K System' ? 'ks' : 'kt';
    ?>
    <a href="?p=dettaglio&id=<?= $r['id'] ?>" class="recent-item">
      <div class="recent-badge <?= $isBU ?>"><?= $r['business_unit']==='K System'?'KS':'KT' ?></div>
      <div class="recent-info">
        <strong><?= h($r['cliente']) ?></strong>
        <span><?= h($r['settore']) ?> · <?= h($r['regione']) ?> · <?= h($r['prodotto_alc']) ?></span>
      </div>
      <div class="recent-meta">
        <?= date('d/m/y', strtotime($r['created_at'])) ?><br>
        <?php $parts = $r['ins'] ? explode(' ', trim($r['ins'])) : []; echo h($parts ? end($parts) : '—'); ?>
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

    <!-- SEZIONE ANAGRAFICA: BU + Settore affiancati -->
    <?php $canAna = canEditSection('anagrafica'); ?>
    <div class="form-cards-row">
      <div class="form-card <?= !$canAna ? 'section-readonly' : '' ?>" style="margin-bottom:0">
        <?php if (!$canAna): ?><div class="section-lock-banner">🔒 Sezione in sola lettura per il tuo ruolo</div><?php endif; ?>
        <div class="form-card-title"><?= icon('tag') ?> Business Unit <span style="color:var(--red);margin-left:.2rem">*</span></div>
        <div class="radio-group">
          <?php foreach (['K System','K Thermo'] as $bu):
            $checked = ($fd['business_unit'] ?? '') === $bu;
          ?>
          <label class="radio-opt <?= in_array('business_unit',$fe)?'border-red':'' ?> <?= $checked?'checked':'' ?> <?= !$canAna?'disabled-opt':'' ?>">
            <input type="radio" name="business_unit" value="<?= h($bu) ?>" <?= $checked?'checked':'' ?> <?= !$canAna?'disabled':'' ?> required>
            <span class="radio-dot"></span>
            <?= h($bu) ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="form-card <?= !$canAna ? 'section-readonly' : '' ?>" style="margin-bottom:0">
        <div class="form-card-title"><?= icon('filter') ?> Settore <span style="color:var(--red);margin-left:.2rem">*</span></div>
        <div class="radio-group">
          <?php foreach (['Calzatura','Pelletteria','Industria'] as $s):
            $checked = ($fd['settore'] ?? '') === $s;
          ?>
          <label class="radio-opt gold-check <?= $checked?'checked':'' ?> <?= !$canAna?'disabled-opt':'' ?>">
            <input type="radio" name="settore" value="<?= h($s) ?>" <?= $checked?'checked':'' ?> <?= !$canAna?'disabled':'' ?> required>
            <span class="radio-dot"></span>
            <?= h($s) ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- SEZIONE PRODOTTO -->
    <?php $canProd = canEditSection('prodotto'); ?>
    <div class="form-card <?= !$canProd ? 'section-readonly' : '' ?>">
      <?php if (!$canProd): ?><div class="section-lock-banner">🔒 Sezione in sola lettura per il tuo ruolo</div><?php endif; ?>
      <div class="form-card-title"><?= icon('user') ?> Dati Applicazione</div>

      <div class="form-row">
        <div class="form-group">
          <label for="regione">Regione <span class="req">*</span></label>
          <select class="form-control" id="regione" name="regione" required <?= !$canAna?'disabled':'' ?>>
            <option value="">— Seleziona —</option>
            <?php foreach ($regions as $r): ?>
            <option value="<?= h($r) ?>" <?= ($fd['regione']??'')===$r?'selected':'' ?>><?= h($r) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="cliente">Cliente <span class="req">*</span></label>
          <input class="form-control" type="text" id="cliente" name="cliente"
                 placeholder="Nome azienda cliente" required <?= !$canAna?'disabled':'' ?>
                 value="<?= h($fd['cliente']??'') ?>">
        </div>
      </div>

      <?php
        // Pre-carica tipo/attributo se il codice è già valorizzato (es. errore form)
        $alc_preload = null;
        if (!empty($fd['prodotto_alc'])) {
            $stmt_pre = db()->prepare("SELECT tipo, attributo FROM prodotti_alc WHERE codice=? LIMIT 1");
            $stmt_pre->execute([$fd['prodotto_alc']]);
            $alc_preload = $stmt_pre->fetch(PDO::FETCH_ASSOC);
        }
      ?>
      <div class="form-group">
        <label for="alc_input">Prodotto ALC — Codice <span class="req">*</span></label>
        <div class="alc-wrap">
          <input class="form-control <?= in_array('prodotto_alc',$fe)||in_array('prodotto_alc_invalid',$fe) ? 'border-red' : '' ?>"
                 type="text" id="alc_input" autocomplete="off"
                 placeholder="Digita per cercare il codice…"
                 <?= !$canProd?'disabled':'' ?>
                 value="<?= h($fd['prodotto_alc']??'') ?>">
          <input type="hidden" id="prodotto_alc" name="prodotto_alc" value="<?= h($fd['prodotto_alc']??'') ?>">
          <div class="alc-dropdown" id="alcDropdown" style="display:none"></div>
        </div>
        <?php if (in_array('prodotto_alc_invalid',$fe)): ?>
          <small style="color:var(--red)">Codice non valido per la Business Unit selezionata.</small>
        <?php endif; ?>
        <div class="alc-meta" id="alcMeta" style="display:<?= $alc_preload ? 'flex' : 'none' ?>">
          <span class="alc-chip"><strong>Tipo</strong> <span id="alcTipo"><?= h($alc_preload['tipo'] ?? '—') ?></span></span>
          <span class="alc-chip"><strong>Attributo</strong> <span id="alcAttributo"><?= h($alc_preload['attributo'] ?? '—') ?></span></span>
        </div>
      </div>

      <div class="form-group">
        <label for="problema">Problema risolto / Applicazione <span class="req">*</span></label>
        <textarea class="form-control" id="problema" name="problema"
                  placeholder="Descrivi il problema risolto, l'applicazione e i benefici ottenuti..."
                  required <?= !$canProd?'disabled':'' ?> rows="4"><?= h($fd['problema']??'') ?></textarea>
      </div>
    </div>

    <!-- SEZIONE NOTE INTERNE -->
    <?php $canNote = canEditSection('note_interne'); ?>
    <div class="form-card <?= !$canNote ? 'section-readonly' : '' ?>">
      <?php if (!$canNote): ?><div class="section-lock-banner">🔒 Note interne — accesso riservato</div><?php endif; ?>
      <div class="form-card-title"><?= icon('eye') ?> Note aggiuntive</div>
      <div class="form-group">
        <label for="note">Note <?= $canNote ? '' : '<span style="font-size:.75rem;color:var(--gray)">(sola lettura)</span>' ?></label>
        <textarea class="form-control" id="note" name="note"
                  placeholder="<?= $canNote ? 'Eventuali note, parametri tecnici, condizioni particolari...' : 'Non hai i permessi per compilare questo campo.' ?>"
                  <?= !$canNote?'disabled':'' ?> rows="2"><?= h($fd['note']??'') ?></textarea>
      </div>
    </div>

    <!-- SEZIONE MEDIA -->
    <?php $canMedia = canEditSection('media'); ?>
    <?php if ($canMedia): ?>
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
    <?php endif; ?>

    <div style="display:flex;gap:1rem;flex-wrap:wrap">
      <button type="submit" class="btn btn-primary btn-lg" style="flex:1">
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
  <form method="GET" action="?" class="db-filters" id="dbFiltersForm">
    <input type="hidden" name="p" value="database">

    <!-- riga 1: testo libero -->
    <div class="db-filters-row">
      <div class="db-search" style="flex:1;min-width:200px">
        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" name="q" placeholder="Cerca cliente, regione, problema..." value="<?= h($search) ?>">
      </div>

      <!-- ALC autocomplete -->
      <div class="db-alc-wrap" style="position:relative;flex:1;min-width:160px">
        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;left:.75rem;top:50%;transform:translateY(-50%);width:1rem;height:1rem;color:var(--gray2);pointer-events:none"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
        <input type="text" id="dbAlcInput" autocomplete="off"
               placeholder="Codice ALC..."
               value="<?= h($alc_f) ?>"
               style="width:100%;padding:.6rem .9rem .6rem 2.4rem;border:2px solid var(--border);border-radius:50px;font-size:.9rem;background:var(--light);transition:var(--trans);color:#1a1a1a">
        <input type="hidden" name="alc" id="dbAlcHidden" value="<?= h($alc_f) ?>">
        <div id="dbAlcDropdown" class="alc-dropdown" style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:#fff;border:1px solid var(--border);border-radius:var(--radius2);box-shadow:var(--shadow2);z-index:200;max-height:220px;overflow-y:auto"></div>
      </div>
    </div>

    <!-- riga 2: radio BU -->
    <div class="db-filters-row db-filters-radios">
      <span class="filter-label">BU:</span>
      <label class="filter-radio <?= $bu_f==='K System'?'active':'' ?>">
        <input class="filter-radio-input" type="radio" name="bu" value="K System" <?= $bu_f==='K System'?'checked':'' ?> onchange="this.form.submit()"> K System
      </label>
      <label class="filter-radio <?= $bu_f==='K Thermo'?'active':'' ?>">
        <input class="filter-radio-input" type="radio" name="bu" value="K Thermo" <?= $bu_f==='K Thermo'?'checked':'' ?> onchange="this.form.submit()"> K Thermo
      </label>
      <span class="filter-sep" id="settore-sep" <?= $bu_f==='K Thermo'?'style="display:none"':'' ?>>|</span>
      <span id="settore-group" <?= $bu_f==='K Thermo'?'style="display:none"':'' ?> style="display:<?= $bu_f==='K Thermo'?'none':'inline-flex' ?>;align-items:center;gap:.35rem;flex-wrap:wrap">
        <span class="filter-label">Settore:</span>
        <?php foreach (['Calzatura','Pelletteria','Industria'] as $s): ?>
        <label class="filter-radio <?= $settore_f===$s?'active':'' ?>">
          <input class="filter-radio-input" type="radio" name="settore" value="<?= h($s) ?>" <?= $settore_f===$s?'checked':'' ?> onchange="this.form.submit()"> <?= h($s) ?>
        </label>
        <?php endforeach; ?>
      </span>
    </div>

    <!-- riga 3: azioni -->
    <div class="db-filters-row">
      <button type="submit" class="btn btn-primary btn-sm"><?= icon('search') ?> Cerca</button>
      <?php if ($search || $bu_f || $settore_f || $alc_f): ?>
      <a href="?p=database" class="btn btn-sm" style="background:var(--light);color:var(--gray)">✕ Reset</a>
      <?php endif; ?>
      <span class="db-count"><?= count($apps) ?> risultat<?= count($apps)===1?'o':'i' ?></span>
    </div>
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
          <th style="width:60px">BU</th>
          <th style="width:110px">Settore</th>
          <th style="width:130px">Regione</th>
          <th style="width:200px">Cliente</th>
          <th style="width:180px">Prodotto ALC</th>
          <th style="width:60px"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($apps as $app):
          $buClass = $app['business_unit']==='K System'?'ks':'kt';
        ?>
        <tr onclick="location.href='?p=dettaglio&id=<?= $app['id'] ?>'">
          <td data-label="BU">
            <span class="bu-chip <?= $buClass ?>"><?= $app['business_unit']==='K System'?'KS':'KT' ?></span>
          </td>
          <td data-label="Settore">
            <span class="settore-chip"><?= h($app['settore']) ?></span>
          </td>
          <td data-label="Regione"><?= h($app['regione']) ?></td>
          <td data-label="Cliente"><?= h($app['cliente']) ?></td>
          <td data-label="Prodotto ALC"><strong><?= h($app['prodotto_alc']) ?></strong></td>
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
  $buClass = $app['business_unit']==='K System'?'ks':'kt';
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
      <span>📅 <?= date('d/m/Y', strtotime($app['created_at'])) ?></span>
    </div>
    <div class="detail-actions">
      <a href="?p=database" class="btn btn-outline btn-sm" style="border-color:rgba(255,255,255,.4);color:#fff"><?= icon('back') ?> Torna al database</a>
    </div>
  </div>

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
      <div class="val"><?= h($app['cliente']) ?></div>
    </div>
    <div class="detail-field">
      <label>Prodotto ALC</label>
      <div class="val"><strong><code style="background:var(--light);padding:.2rem .5rem;border-radius:4px"><?= h($app['prodotto_alc']) ?></code></strong></div>
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

  <!-- MEDIA -->
  <?php if ($app['media_path']): ?>
  <div class="detail-media" style="margin-top:1.5rem">
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

  <!-- ACTIONS -->
  <hr class="divider">
  <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
    <a href="?p=database" class="btn btn-outline"><?= icon('back') ?> Torna al database</a>
    <span style="font-size:.75rem;color:var(--gray);margin-left:auto">
      <?php $ruolo = me()['ruolo'] ?? 'viewer'; ?>
      Accesso: <strong><?= h($ruolo) ?></strong> — <?= bamMode()==='edit' ? 'modalità modifica' : 'sola lettura' ?>
    </span>
    <?php if (in_array($ruolo, ['admin','responsabile'])): ?>
    <form method="POST" action="?p=database" onsubmit="return confirm('Sei sicuro di voler eliminare questa applicazione? L\'operazione è irreversibile.')">
      <input type="hidden" name="_action" value="elimina">
      <input type="hidden" name="id" value="<?= $app['id'] ?>">
      <button type="submit" class="btn btn-danger btn-sm">🗑 Elimina</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($p === 'admin'):
  $editId  = (int)($_GET['edit'] ?? 0);
  $editRow = null;
  if ($editId) {
      $st = db()->prepare("SELECT * FROM prodotti_alc WHERE id=?");
      $st->execute([$editId]);
      $editRow = $st->fetch(PDO::FETCH_ASSOC);
  }
  $prodotti = db()->query("SELECT * FROM prodotti_alc ORDER BY business_unit, codice")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="page">
  <div class="page-title"><?= icon('tag') ?> Catalogo Prodotti</div>

  <!-- FORM ADD / EDIT -->
  <div class="form-card">
    <div class="form-card-title"><?= $editRow ? icon('check').' Modifica Prodotto' : icon('plus').' Aggiungi Prodotto' ?></div>
    <form method="POST" action="?p=admin">
      <input type="hidden" name="_action" value="<?= $editRow ? 'edit_prodotto' : 'add_prodotto' ?>">
      <?php if ($editRow): ?><input type="hidden" name="id" value="<?= $editRow['id'] ?>"><?php endif; ?>
      <div class="form-row" style="grid-template-columns:1fr 1fr 1fr 1fr">
        <div class="form-group">
          <label>Codice <span class="req">*</span></label>
          <input class="form-control" name="codice" placeholder="es. K-502" required value="<?= h($editRow['codice'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Business Unit <span class="req">*</span></label>
          <select class="form-control" name="business_unit" required>
            <option value="">— Seleziona —</option>
            <?php foreach (['K System','K Thermo'] as $buOpt): ?>
            <option value="<?= h($buOpt) ?>" <?= ($editRow['business_unit'] ?? '') === $buOpt ? 'selected' : '' ?>><?= h($buOpt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Tipo</label>
          <input class="form-control" name="tipo" placeholder="es. Macchina" value="<?= h($editRow['tipo'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Attributo</label>
          <input class="form-control" name="attributo" placeholder="es. Standard" value="<?= h($editRow['attributo'] ?? '') ?>">
        </div>
      </div>
      <div style="display:flex;gap:.75rem;margin-top:.25rem">
        <button type="submit" class="btn btn-primary"><?= $editRow ? 'Salva modifiche' : 'Aggiungi' ?></button>
        <?php if ($editRow): ?>
        <a href="?p=admin" class="btn btn-outline">Annulla</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- IMPORT CSV -->
  <?php
    $csvFlash = $_SESSION['csv_flash'] ?? '';
    $csvFlashType = $_SESSION['csv_flash_type'] ?? 'success';
    unset($_SESSION['csv_flash'], $_SESSION['csv_flash_type']);
  ?>
  <?php if ($csvFlash): ?>
  <div class="flash flash-<?= h($csvFlashType) ?>" style="margin-top:1rem"><?= $csvFlash ?></div>
  <?php endif; ?>
  <div class="form-card" style="margin-top:1.5rem">
    <div class="form-card-title"><?= icon('database') ?> Importazione massiva da CSV</div>
    <p style="margin:.25rem 0 .75rem;color:#000;font-size:.875rem">
      Carica un file <code>.csv</code> con separatore <code>;</code> (punto e virgola).<br>
      <strong>La prima riga è sempre considerata intestazione e viene ignorata.</strong><br>
      Colonne attese in ordine: <code>Codice ; Business Unit ; Tipo ; Attributo</code><br>
      Valori validi per <em>Business Unit</em>: <code>K System</code>, <code>K Thermo</code>.<br>
      I duplicati (stesso codice + BU) vengono saltati automaticamente.
    </p>
    <form method="POST" action="?p=admin" enctype="multipart/form-data">
      <input type="hidden" name="_action" value="import_csv">
      <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
        <input type="file" name="csv_file" accept=".csv,.txt" required
               style="flex:1;min-width:0;padding:.4rem .6rem;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--text)">
        <button type="submit" class="btn btn-primary">Importa</button>
      </div>
    </form>
  </div>

  <!-- TABELLA PRODOTTI -->
  <div class="section-head" style="margin-top:1.5rem">
    <h2><?= icon('database') ?> Codici presenti</h2>
    <span class="text-gray"><?= count($prodotti) ?> totali</span>
  </div>

  <?php if (empty($prodotti)): ?>
  <div class="empty">
    <span class="emoji">📋</span>
    <h3>Nessun prodotto inserito</h3>
    <p>Aggiungi il primo codice con il form sopra.</p>
  </div>
  <?php else: ?>
  <div class="db-table-wrap">
    <table class="db-table">
      <thead>
        <tr>
          <th>Codice</th>
          <th>Business Unit</th>
          <th>Tipo</th>
          <th>Attributo</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($prodotti as $prod): ?>
        <tr>
          <td><strong><?= h($prod['codice']) ?></strong></td>
          <td><span class="bu-chip <?= $prod['business_unit']==='K System'?'ks':'kt' ?>"><?= h($prod['business_unit']) ?></span></td>
          <td><?= h($prod['tipo'] ?: '—') ?></td>
          <td><?= h($prod['attributo'] ?: '—') ?></td>
          <td style="white-space:nowrap;text-align:right">
            <a href="?p=admin&edit=<?= $prod['id'] ?>" class="btn btn-sm btn-outline">Modifica</a>
            <form method="POST" action="?p=admin" style="display:inline" onsubmit="return confirm('Eliminare il codice <?= h(addslashes($prod['codice'])) ?>?')">
              <input type="hidden" name="_action" value="del_prodotto">
              <input type="hidden" name="id" value="<?= $prod['id'] ?>">
              <button type="submit" class="btn btn-sm btn-danger">Elimina</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
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

// ---- ALC Autocomplete ----
(function(){
  const buInputs  = document.querySelectorAll('input[name="business_unit"]');
  const textInput = document.getElementById('alc_input');
  const hidden    = document.getElementById('prodotto_alc');
  const dropdown  = document.getElementById('alcDropdown');
  const metaDiv   = document.getElementById('alcMeta');
  const tipoEl    = document.getElementById('alcTipo');
  const attrEl    = document.getElementById('alcAttributo');
  if (!textInput) return;

  let timer = null, data = [], activeIdx = -1;

  function getBU() {
    for (const r of buInputs) if (r.checked) return r.value;
    return '';
  }

  function showMeta(tipo, attributo) {
    tipoEl.textContent  = tipo      || '—';
    attrEl.textContent  = attributo || '—';
    metaDiv.style.display = 'flex';
  }

  function hideMeta() { metaDiv.style.display = 'none'; }

  function render(items) {
    dropdown.innerHTML = '';
    activeIdx = -1;
    if (!items.length) {
      dropdown.innerHTML = '<div class="alc-empty">Nessun codice trovato</div>';
      dropdown.style.display = 'block';
      return;
    }
    items.forEach((item, i) => {
      const d = document.createElement('div');
      d.className = 'alc-item';
      const sub = [item.tipo, item.attributo].filter(Boolean).join(' · ');
      d.innerHTML = `<span class="alc-item-code">${item.codice}</span>${sub ? `<span class="alc-item-sub">${sub}</span>` : ''}`;
      d.addEventListener('mousedown', e => { e.preventDefault(); pick(item); });
      dropdown.appendChild(d);
    });
    dropdown.style.display = 'block';
  }

  function pick(item) {
    textInput.value  = item.codice;
    hidden.value     = item.codice;
    dropdown.style.display = 'none';
    showMeta(item.tipo, item.attributo);
  }

  function fetch_(q) {
    const bu = getBU();
    if (!bu) {
      dropdown.innerHTML = '<div class="alc-empty">Seleziona prima la Business Unit</div>';
      dropdown.style.display = 'block';
      return;
    }
    fetch(`?p=api&action=prodotti&bu=${encodeURIComponent(bu)}&q=${encodeURIComponent(q)}`)
      .then(r => r.json()).then(d => { data = d; render(d); })
      .catch(() => { dropdown.style.display = 'none'; });
  }

  // Quando cambia BU, cancella la selezione corrente
  buInputs.forEach(r => r.addEventListener('change', () => {
    hidden.value = ''; textInput.value = ''; hideMeta();
    dropdown.style.display = 'none';
  }));

  textInput.addEventListener('input', () => {
    hidden.value = ''; hideMeta();
    clearTimeout(timer);
    timer = setTimeout(() => fetch_(textInput.value.trim()), 220);
  });

  textInput.addEventListener('focus', () => {
    if (!hidden.value) fetch_(textInput.value.trim());
  });

  textInput.addEventListener('blur', () => {
    setTimeout(() => {
      dropdown.style.display = 'none';
      // Se il testo non corrisponde a un codice valido, azzera
      if (textInput.value.trim() && !hidden.value) {
        const exact = data.find(d => d.codice === textInput.value.trim());
        if (exact) pick(exact); else textInput.value = '';
      }
    }, 180);
  });

  textInput.addEventListener('keydown', e => {
    const items = dropdown.querySelectorAll('.alc-item');
    if (!items.length) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      activeIdx = Math.min(activeIdx + 1, items.length - 1);
      items.forEach((el, i) => el.classList.toggle('alc-active', i === activeIdx));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      activeIdx = Math.max(activeIdx - 1, 0);
      items.forEach((el, i) => el.classList.toggle('alc-active', i === activeIdx));
    } else if (e.key === 'Enter' && activeIdx >= 0) {
      e.preventDefault();
      if (data[activeIdx]) pick(data[activeIdx]);
    } else if (e.key === 'Escape') {
      dropdown.style.display = 'none';
    }
  });

  // Pre-popola meta se già valorizzato (es. errore form)
  if (hidden.value) {
    const bu = getBU();
    if (bu) {
      fetch(`?p=api&action=prodotti&bu=${encodeURIComponent(bu)}&q=${encodeURIComponent(hidden.value)}`)
        .then(r => r.json()).then(d => {
          const m = d.find(x => x.codice === hidden.value);
          if (m) showMeta(m.tipo, m.attributo);
        });
    }
  }
})();

// ---- DB ALC Autocomplete (filtro database) ----
(function(){
  const inp  = document.getElementById('dbAlcInput');
  const hid  = document.getElementById('dbAlcHidden');
  const drop = document.getElementById('dbAlcDropdown');
  if (!inp) return;
  let timer = null, data = [];

  function getFilterBU() {
    const r = document.querySelector('#dbFiltersForm input[name="bu"]:checked');
    return r ? r.value : '';
  }

  function render(items) {
    drop.innerHTML = '';
    if (!items.length) {
      drop.innerHTML = '<div class="alc-empty">Nessun codice trovato</div>';
      drop.style.display = 'block'; return;
    }
    items.forEach(item => {
      const d = document.createElement('div');
      d.className = 'alc-item';
      const sub = [item.tipo, item.attributo].filter(Boolean).join(' · ');
      d.innerHTML = `<span class="alc-item-code">${item.codice}</span>${sub ? `<span class="alc-item-sub">${sub}</span>` : ''}`;
      d.addEventListener('mousedown', e => {
        e.preventDefault();
        inp.value = item.codice; hid.value = item.codice;
        drop.style.display = 'none';
      });
      drop.appendChild(d);
    });
    drop.style.display = 'block';
  }

  function doFetch(q) {
    const bu = getFilterBU();
    const url = `?p=api&action=prodotti&bu=${encodeURIComponent(bu)}&q=${encodeURIComponent(q)}`;
    fetch(url).then(r => r.json()).then(d => { data = d; render(d); }).catch(() => { drop.style.display = 'none'; });
  }

  inp.addEventListener('input', () => {
    hid.value = '';
    clearTimeout(timer);
    timer = setTimeout(() => doFetch(inp.value.trim()), 220);
  });

  inp.addEventListener('focus', () => { doFetch(inp.value.trim()); });

  inp.addEventListener('blur', () => {
    setTimeout(() => {
      drop.style.display = 'none';
      if (inp.value.trim() && !hid.value) {
        const exact = data.find(d => d.codice === inp.value.trim());
        if (exact) { hid.value = exact.codice; } else { inp.value = hid.value = ''; }
      }
    }, 180);
  });
})();

// ---- Deselect filter radios on second click ----
(function(){
  document.querySelectorAll('.filter-radio-input').forEach(function(radio){
    radio.addEventListener('mousedown', function(){ this._wasChecked = this.checked; });
    radio.addEventListener('click', function(){
      if (this._wasChecked) {
        this.checked = false;
        this.form.submit();
      }
    });
  });
})();

// Auto-hide flash
setTimeout(() => {
  document.querySelectorAll('.flash').forEach(el => {
    el.style.transition = 'opacity .5s'; el.style.opacity = '0';
    setTimeout(() => el.remove(), 500);
  });
}, 4000);

// Nascondi/mostra Settore in base a BU selezionata
document.querySelectorAll('input[name="bu"]').forEach(radio => {
  radio.addEventListener('change', function() {
    const show = this.value !== 'K Thermo';
    const grp = document.getElementById('settore-group');
    const sep = document.getElementById('settore-sep');
    if (grp) grp.style.display = show ? 'inline-flex' : 'none';
    if (sep) sep.style.display = show ? '' : 'none';
  });
});
</script>
</body>
</html>
