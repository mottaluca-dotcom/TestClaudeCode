<?php
$page = isset($_GET['page']) ? $_GET['page'] : 'home';
$allowed_pages = ['home', 'about', 'contact'];
if (!in_array($page, $allowed_pages)) {
    $page = 'home';
}

$nav_links = [
    'home'    => 'Home',
    'about'   => 'Chi Siamo',
    'contact' => 'Contatti',
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyApp PHP</title>
    <style>
        /* ===== RESET & BASE ===== */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f4f6f9;
            color: #333;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        a { text-decoration: none; color: inherit; }
        img { max-width: 100%; }

        /* ===== NAVBAR ===== */
        nav {
            background: #1a1a2e;
            color: #fff;
            padding: 0 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 60px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        .nav-logo {
            font-size: 1.4rem;
            font-weight: 700;
            color: #e94560;
            letter-spacing: 1px;
        }
        .nav-links {
            display: flex;
            gap: 0.5rem;
            list-style: none;
        }
        .nav-links a {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.95rem;
            transition: background 0.2s;
            color: #ccc;
        }
        .nav-links a:hover,
        .nav-links a.active {
            background: #e94560;
            color: #fff;
        }
        /* Hamburger button (mobile only) */
        .hamburger {
            display: none;
            flex-direction: column;
            gap: 5px;
            cursor: pointer;
            background: none;
            border: none;
            padding: 4px;
        }
        .hamburger span {
            display: block;
            width: 25px;
            height: 3px;
            background: #fff;
            border-radius: 3px;
            transition: all 0.3s;
        }

        /* ===== MAIN CONTENT ===== */
        main { flex: 1; }

        /* ===== HERO ===== */
        .hero {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 60%, #0f3460 100%);
            color: #fff;
            text-align: center;
            padding: 5rem 1.5rem 4rem;
        }
        .hero h1 {
            font-size: clamp(1.8rem, 5vw, 3.2rem);
            margin-bottom: 1rem;
            line-height: 1.2;
        }
        .hero h1 span { color: #e94560; }
        .hero p {
            font-size: clamp(1rem, 2.5vw, 1.2rem);
            color: #aaa;
            max-width: 600px;
            margin: 0 auto 2rem;
            line-height: 1.6;
        }
        .btn {
            display: inline-block;
            padding: 0.75rem 2rem;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: transform 0.2s, opacity 0.2s;
        }
        .btn:hover { transform: translateY(-2px); opacity: 0.9; }
        .btn-primary { background: #e94560; color: #fff; }
        .btn-outline { background: transparent; border: 2px solid #e94560; color: #e94560; margin-left: 1rem; }

        /* ===== CARDS SECTION ===== */
        .section {
            padding: 3rem 1.5rem;
            max-width: 1100px;
            margin: 0 auto;
            width: 100%;
        }
        .section-title {
            text-align: center;
            font-size: clamp(1.4rem, 3vw, 2rem);
            margin-bottom: 2rem;
            color: #1a1a2e;
        }
        .section-title span { color: #e94560; }
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1.5rem;
        }
        .card {
            background: #fff;
            border-radius: 12px;
            padding: 2rem 1.5rem;
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover { transform: translateY(-4px); box-shadow: 0 8px 24px rgba(0,0,0,0.12); }
        .card-icon { font-size: 2.5rem; margin-bottom: 1rem; }
        .card h3 { margin-bottom: 0.5rem; color: #1a1a2e; }
        .card p { color: #666; font-size: 0.95rem; line-height: 1.6; }

        /* ===== ABOUT PAGE ===== */
        .about-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            align-items: center;
        }
        .about-text h2 { font-size: 1.8rem; margin-bottom: 1rem; color: #1a1a2e; }
        .about-text h2 span { color: #e94560; }
        .about-text p { color: #555; line-height: 1.8; margin-bottom: 1rem; }
        .about-image {
            background: linear-gradient(135deg, #e94560, #0f3460);
            border-radius: 16px;
            height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 5rem;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-top: 2rem;
        }
        .stat-box {
            background: #fff;
            border-radius: 10px;
            padding: 1.5rem 1rem;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.07);
        }
        .stat-box .number { font-size: 2rem; font-weight: 700; color: #e94560; }
        .stat-box .label { font-size: 0.85rem; color: #666; margin-top: 0.25rem; }

        /* ===== CONTACT PAGE ===== */
        .contact-wrapper {
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            gap: 2rem;
        }
        .contact-info h2 { font-size: 1.6rem; margin-bottom: 1rem; color: #1a1a2e; }
        .contact-info p { color: #555; line-height: 1.7; margin-bottom: 1.5rem; }
        .contact-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
            color: #444;
        }
        .contact-item .icon {
            font-size: 1.3rem;
            background: #fde8ec;
            width: 40px; height: 40px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .form-card {
            background: #fff;
            border-radius: 14px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        .form-group { margin-bottom: 1.25rem; }
        .form-group label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.4rem;
            color: #444;
        }
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 0.7rem 1rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: inherit;
            transition: border-color 0.2s;
            background: #fafafa;
            color: #333;
        }
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #e94560;
            background: #fff;
        }
        .form-group textarea { resize: vertical; min-height: 120px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .alert {
            padding: 0.9rem 1.2rem;
            border-radius: 8px;
            margin-bottom: 1.25rem;
            font-size: 0.95rem;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* ===== FOOTER ===== */
        footer {
            background: #1a1a2e;
            color: #aaa;
            text-align: center;
            padding: 1.5rem 1rem;
            font-size: 0.9rem;
        }
        footer span { color: #e94560; }

        /* ===== MOBILE ===== */
        @media (max-width: 768px) {
            .hamburger { display: flex; }
            .nav-links {
                display: none;
                position: absolute;
                top: 60px; left: 0; right: 0;
                background: #16213e;
                flex-direction: column;
                padding: 1rem;
                gap: 0.25rem;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            }
            .nav-links.open { display: flex; }
            .nav-links a { display: block; padding: 0.75rem 1rem; font-size: 1rem; }

            .hero { padding: 3rem 1rem 2.5rem; }
            .btn-outline { margin-left: 0; margin-top: 0.75rem; }
            .hero .btn-group { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; }

            .about-grid { grid-template-columns: 1fr; }
            .about-image { height: 200px; font-size: 3.5rem; order: -1; }
            .stats { grid-template-columns: 1fr 1fr; }

            .contact-wrapper { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
        }

        @media (max-width: 480px) {
            .stats { grid-template-columns: 1fr; }
            .cards { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- ===== NAVBAR ===== -->
<nav>
    <a href="?page=home" class="nav-logo">&#9670; MyApp</a>
    <button class="hamburger" id="hamburger" aria-label="Menu" aria-expanded="false">
        <span></span><span></span><span></span>
    </button>
    <ul class="nav-links" id="nav-links">
        <?php foreach ($nav_links as $key => $label): ?>
            <li>
                <a href="?page=<?= $key ?>" class="<?= $page === $key ? 'active' : '' ?>">
                    <?= htmlspecialchars($label) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>

<!-- ===== PAGES ===== -->
<main>
<?php if ($page === 'home'): ?>

    <!-- HOME: Hero -->
    <section class="hero">
        <h1>Benvenuto su <span>MyApp</span></h1>
        <p>Un'applicazione web PHP moderna, responsive e ottimizzata per tutti i dispositivi — desktop, tablet e smartphone.</p>
        <div class="btn-group">
            <a href="?page=about" class="btn btn-primary">Scopri di più</a>
            <a href="?page=contact" class="btn btn-outline">Contattaci</a>
        </div>
    </section>

    <!-- HOME: Cards -->
    <div class="section">
        <h2 class="section-title">Le nostre <span>funzionalità</span></h2>
        <div class="cards">
            <?php
            $features = [
                ['&#128241;', 'Mobile First',   'Design pensato per smartphone e tablet, si adatta perfettamente a qualsiasi schermo.'],
                ['&#9889;',   'Veloce & Leggero','Nessun framework pesante. PHP puro + CSS moderno per massime prestazioni.'],
                ['&#128274;', 'Sicuro',          'Protezione da XSS e injection con escape dei dati e validazione server-side.'],
                ['&#127775;', 'Facile da usare', 'Interfaccia intuitiva e navigazione semplice su qualsiasi dispositivo.'],
                ['&#128202;', 'Dashboard',       'Monitora i tuoi dati in tempo reale con grafici e statistiche aggiornate.'],
                ['&#127760;', 'Multilingua',     'Struttura predisposta per supportare più lingue e localizzazioni.'],
            ];
            foreach ($features as [$icon, $title, $desc]): ?>
                <div class="card">
                    <div class="card-icon"><?= $icon ?></div>
                    <h3><?= $title ?></h3>
                    <p><?= $desc ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

<?php elseif ($page === 'about'): ?>

    <div class="section">
        <div class="about-grid">
            <div class="about-text">
                <h2>Chi <span>siamo</span></h2>
                <p>Siamo un team appassionato di sviluppo web che crede nella semplicità e nell'efficienza. Creiamo applicazioni PHP moderne, sicure e ottimizzate per ogni dispositivo.</p>
                <p>La nostra filosofia è "mobile first": ogni progetto nasce già pensato per gli smartphone, poi scalato verso schermi più grandi.</p>
                <a href="?page=contact" class="btn btn-primary" style="margin-top:1rem;">Lavora con noi</a>
            </div>
            <div class="about-image">&#128187;</div>
        </div>

        <div class="stats" style="margin-top: 3rem;">
            <?php
            $stats = [
                ['50+',   'Progetti completati'],
                ['100%',  'Clienti soddisfatti'],
                ['5 anni','Di esperienza'],
            ];
            foreach ($stats as [$num, $lbl]): ?>
                <div class="stat-box">
                    <div class="number"><?= $num ?></div>
                    <div class="label"><?= $lbl ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

<?php elseif ($page === 'contact'): ?>

    <div class="section">
        <h2 class="section-title">Scrivici un <span>messaggio</span></h2>
        <div class="contact-wrapper">

            <!-- Info -->
            <div class="contact-info">
                <h2>Restiamo in contatto</h2>
                <p>Hai domande o vuoi avviare un progetto? Compila il modulo e ti risponderemo entro 24 ore.</p>
                <?php
                $contacts = [
                    ['&#128205;', 'Via Roma 10, Milano'],
                    ['&#128222;', '+39 02 1234567'],
                    ['&#128231;', 'info@myapp.it'],
                    ['&#128336;', 'Lun–Ven: 9:00–18:00'],
                ];
                foreach ($contacts as [$icon, $text]): ?>
                    <div class="contact-item">
                        <div class="icon"><?= $icon ?></div>
                        <span><?= htmlspecialchars($text) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Form -->
            <div class="form-card">
                <?php
                $success = $error = '';
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $nome    = trim(htmlspecialchars($_POST['nome']    ?? ''));
                    $cognome = trim(htmlspecialchars($_POST['cognome'] ?? ''));
                    $email   = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
                    $oggetto = trim(htmlspecialchars($_POST['oggetto'] ?? ''));
                    $msg     = trim(htmlspecialchars($_POST['messaggio'] ?? ''));

                    if (!$nome || !$email || !$msg) {
                        $error = 'Per favore compila tutti i campi obbligatori.';
                    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $error = 'Indirizzo email non valido.';
                    } else {
                        // Qui puoi aggiungere mail(), PDO, ecc.
                        $success = "Grazie $nome! Il tuo messaggio è stato inviato. Ti risponderemo presto.";
                    }
                }
                ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?= $success ?></div>
                <?php elseif ($error): ?>
                    <div class="alert alert-error"><?= $error ?></div>
                <?php endif; ?>

                <form method="POST" action="?page=contact">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="nome">Nome *</label>
                            <input type="text" id="nome" name="nome" placeholder="Mario"
                                   value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="cognome">Cognome</label>
                            <input type="text" id="cognome" name="cognome" placeholder="Rossi"
                                   value="<?= htmlspecialchars($_POST['cognome'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" placeholder="mario@esempio.it"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="oggetto">Oggetto</label>
                        <select id="oggetto" name="oggetto">
                            <option value="">Seleziona...</option>
                            <?php
                            $opts = ['Informazioni generali','Richiesta preventivo','Supporto tecnico','Collaborazione'];
                            foreach ($opts as $o): ?>
                                <option value="<?= $o ?>" <?= (($_POST['oggetto'] ?? '') === $o) ? 'selected' : '' ?>>
                                    <?= $o ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="messaggio">Messaggio *</label>
                        <textarea id="messaggio" name="messaggio" placeholder="Scrivi il tuo messaggio..." required><?= htmlspecialchars($_POST['messaggio'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;">
                        &#128233; Invia messaggio
                    </button>
                </form>
            </div>
        </div>
    </div>

<?php endif; ?>
</main>

<!-- ===== FOOTER ===== -->
<footer>
    <p>&copy; <?= date('Y') ?> <span>MyApp</span> &mdash; Tutti i diritti riservati &mdash; Fatto con <span>&#9829;</span> in PHP</p>
</footer>

<script>
    // Hamburger menu toggle
    const hamburger = document.getElementById('hamburger');
    const navLinks  = document.getElementById('nav-links');
    hamburger.addEventListener('click', () => {
        const isOpen = navLinks.classList.toggle('open');
        hamburger.setAttribute('aria-expanded', isOpen);
    });
    // Chiudi menu al click su un link
    navLinks.querySelectorAll('a').forEach(a => {
        a.addEventListener('click', () => navLinks.classList.remove('open'));
    });
</script>

</body>
</html>
