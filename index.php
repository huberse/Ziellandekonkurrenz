<?php
/**
 * Startseite für Besucher.
 *
 * Eine Seite, zwei Dinge: anmelden und Rangliste lesen. Beides steht
 * gleich oben, in dieser Reihenfolge - wer die Seite öffnet, will sich
 * entweder eintragen oder Ergebnisse sehen, und muss nicht erst blättern,
 * um zu erfahren, dass es das gibt.
 *
 * Der Anmeldeweg steht als Kasten darüber, weil er eine Anfrage ist und
 * einen Plan braucht. Die Wettbewerbe stehen darunter als geschlossener
 * Block: wer nur Ergebnisse will, klickt direkt auf eine Kachel.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/scoring.php';
require_once __DIR__ . '/lib/layout.php';

if (!is_file(__DIR__ . '/config.php')) {
    exit('config.php fehlt. Kopiere config.sample.php nach config.php.');
}
try {
    db()->query('SELECT 1 FROM rounds LIMIT 1');
} catch (PDOException $e) {
    redirect('install.php');
}
if (!schema_has_competitions()) {
    redirect('upgrade.php');
}

$wettbewerbe = competitions_uebersicht();
// Das Abzeichen im Seitenkopf nennt den aktiven Wettbewerb. Genau den soll man
// hier ja erst wählen, deshalb bleibt es auf dieser Seite weg.
page_start('Start', 'public', 'index.php', false, true, true);
?>
<div class="start-anmeldung">
    <section class="panel">
        <h2>Anmelden in drei Schritten</h2>
        <ol class="erklaerung">
            <li>Wettbewerb anklicken und das Formular ausfüllen: Name, Verein, Modelltyp, Modell und
                deine E-Mail-Adresse.</li>
            <li>Die Wettkampfleitung prüft die Anmeldung und trägt dich in die Startliste ein.</li>
            <li>Danach stehst du in der Teilnehmerliste, mit deiner Startnummer.</li>
        </ol>
        <p class="small muted">Deine E-Mail-Adresse wird nur für die Bestätigung gebraucht und danach nicht
            gespeichert. Die Bestätigung kommt vom Verein, nicht von dieser Seite.</p>
    </section>
</div>

<?php competition_cards($wettbewerbe); ?>
<?php page_end();
