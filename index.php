<?php
/**
 * Startseite für Besucher.
 *
 * Zwei Fragen auf einer Seite: welcher Wettbewerb, und wofür stehen die Zahlen.
 * Die Wettbewerbsauswahl ist das Hauptelement und steht deshalb gross oben -
 * ein Klick führt zur Rangliste. Die Erklärung tritt zurück und steht darunter,
 * ruhig und schmal.
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
<section class="hero">
    <h2>Wettbewerb wählen</h2>
    <p class="lead">Hier stehen die Resultate der Segelflug-Wettbewerbe. Auf die Karte klicken, und die
        Rangliste öffnet sich. <strong>Wenige Punkte sind gut.</strong></p>

    <?php competition_cards($wettbewerbe); ?>
</section>

<div class="why">
    <section class="panel">
        <h3>So liest man die Rangliste</h3>
        <dl class="legende">
            <dt>DG</dt>
            <dd>ein Durchgang, also ein geflogener Start. Ganz rechts steht <b>Total</b>, die Summe
                aller Durchgänge.</dd>

            <dt><span class="lg-drop">durchgestrichen</span></dt>
            <dd>ein <b>Streichresultat</b>. Es steht noch in der Tabelle, zählt aber nicht zur Summe.</dd>

            <dt><span class="lg-rot">rote Zahl</span></dt>
            <dd>etwas Besonderes ist passiert: Aussenlandung, Bruchlandung, Motor angelassen oder der
                Pilot ist gar nicht angetreten. Geht man mit dem Finger oder der Maus auf die Zahl,
                steht der Grund dabei.</dd>

            <dt>gleiche Summe</dt>
            <dd>entscheidet das bessere Streichresultat, danach der beste Einzelwert. Wer gleichauf
                liegt, hat denselben Rang.</dd>
        </dl>
    </section>

    <section class="panel">
        <h3>Wie die Punkte entstehen</h3>
        <ul class="erklaerung">
            <li>Die <b>Zeitabweichung</b> zählt immer – zu lang und zu kurz gleich.</li>
            <li>Der <b>Landewert</b> kommt dazu, ausser bei einer Aussenlandung: dort ist das Landen
                neben dem Feld gerade das Ereignis.</li>
            <li><b>Aussenlandung und Bruchlandung</b> kommen beide dazu, wenn beides passiert ist.</li>
            <li><b>Nicht angetreten</b> heisst: keine Zeit, kein Landewert, nur die Feststrafe.</li>
            <li>Der <b>Motor</b> kommt zu allem dazu, er ersetzt nichts.</li>
        </ul>
        <p class="small muted">Die genauen Punkte stehen im Verein unter <em>Einstellungen → Strafpunkte</em>
            und werden über der Rangliste angezeigt.</p>
    </section>

    <section class="panel">
        <h3>Anmelden in drei Schritten</h3>
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
<?php page_end();
