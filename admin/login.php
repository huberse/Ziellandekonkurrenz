<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/layout.php';

// Beides laeuft ueber dieselbe Regel, damit es nicht zwei Orte gibt, an denen
// man sich entscheidet, wo es nach dem Anmelden hingeht: wer schon angemeldet
// ist und trotzdem /admin/login.php aufruft, und wer sich gerade angemeldet hat.
if (current_user()) {
    redirect(login_landing_page());
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $benutzer = trim(post('username'));

    if (login_ist_gesperrt($benutzer)) {
        // Wortgleich fuer jeden Namen, ob es das Konto gibt oder nicht. Ein
        // eigener Satz nur fuer gesperrte Konten wuerde an der Antwort selbst
        // verraten, welche Benutzernamen es gibt.
        // Ohne Namen: hier steht nicht, wer gerade angegriffen wird.
        $error = 'Dieses Konto ist gesperrt. Nach zehn Fehlversuchen greift eine Sperre, damit '
            . 'niemand ein Passwort erraten kann. Ein SuperAdmin kann sie wieder aufheben.';
    } elseif (login($benutzer, post('password'))) {
        // Ein Erfolg loescht den Zaehler: wer nach fuenf Fehlversuchen die
        // richtige Kombination hat, startet danach sauber.
        login_sperre_heben($benutzer);
        redirect(login_landing_page(get('next')));
    } else {
        login_fehlversuch_verzeichnen($benutzer);
        $error = 'Benutzername oder Passwort stimmt nicht.';
        usleep(400000);
    }
}

page_start('Anmelden', 'admin', '', false, false);
?>
<div class="login-wrap">
    <div class="panel login-card">
        <h2>Wettkampfbüro</h2>
        <p class="lead">Nur für die Wettkampfleitung.</p>
        <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <div class="field">
                <label for="u">Benutzername</label>
                <input type="text" id="u" name="username" autocomplete="username" autofocus required>
            </div>
            <div class="field">
                <label for="p">Passwort</label>
                <input type="password" id="p" name="password" autocomplete="current-password" required>
            </div>
            <button class="btn big" type="submit" style="width:100%">Anmelden</button>
        </form>
        <p class="hint login-note">
            Beim Anmelden wird eine Sitzung angelegt, damit du angemeldet bleibst. Sie ist
            technisch nötig – ohne sie ginge die Anmeldung nicht. Weitere Daten werden nicht
            gespeichert und nichts wird an Dritte übertragen.
        </p>
        <p class="login-back"><a href="../index.php">← zur Startseite</a></p>
    </div>
</div>
<?php page_end();
