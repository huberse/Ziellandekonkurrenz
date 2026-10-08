# Änderungen

Was sich zwischen den Fassungen geändert hat. Die Aktualisierungsseite zeigt
diese Liste an, wenn sie ein Update anbietet – und zwar nur die Fassungen, die
seit dem eigenen Stand dazugekommen sind.

Die Liste beginnt bei 1.1.0, weil dort die Aktualisierung überhaupt dazukam.
Darunter war eine Aktualisierung nicht möglich; wer eine ältere Fassung im
Betrieb hatte, hat sie durch eine frische Installation ersetzt. Wer von weit
her kommt, liest deshalb eine lange Liste – das ist beabsichtigt, weil sonst
wichtige Änderungen wie die Vereinszugehörigkeit in 1.9.0 untergingen.

## 2.0.7

**Ein Wettbewerb ließ sich überhaupt nicht mehr aktivieren – seit 2.0.0.** Der Knopf „Aktivieren“
war beim Umbau für „Wettbewerb abgesagt“ weggefallen, ohne dass es jemand gemerkt hätte. Der Text
im Anlegeformular versprach ihn weiter: *„Aktivieren Sie den neuen erst in der Liste unten“* – unten
war nichts. Ein neuer Wettbewerb wird nur dann von selbst aktiv, wenn es überhaupt keinen aktiven
gibt. **Hielt ein anderer Verein den aktiven, blieb deiner für immer offen**, und alle Seiten zeigten
„Du bearbeitest den Wettbewerb …, nicht den aktiven Wettbewerb“. Der Knopf ist wieder da, und
er gehört jetzt dem SuperAdmin.

**Ein Konto konnte einem anderen Verein den aktiven Wettbewerb wegnehmen.** Mit dem Konto eines
Vereins ließ sich „Aktivieren“ abschicken; das schaltet *alle* anderen Wettbewerbe ab. Dem anderen
Verein fehlte danach mitten im Wettbewerbstag die Erfassung, ohne jede Meldung. Jetzt geht das nur
noch für den SuperAdmin – auch dann, wenn das Formular ohne Klick abgeschickt wird.

**Vereine und Modelltypen kann jetzt nur der SuperAdmin ändern.** Vorher genügte ein beliebiges
Konto. Nachgewiesen mit dem Konto „Testverein Nord“: es ließ sich **„Testverein Süd“ umbenennen**,
löschen oder deaktivieren. Ein Modelltyp wirkt auf die Rangliste aller, ein Vereinsname auf die
öffentlichen Seiten und auf die Vereinswertung – beides gehört nicht in die Hand eines einzelnen.

**Jede Sperre nennt jetzt das Richtige.** Der Text „Die Benutzerverwaltung ist dem SuperAdmin
vorbehalten“ stand fest in der gemeinsamen Sperre und erschien deshalb auch auf Aktualisierung,
Vereinen, Modelltypen und Stammdaten.

**Die Leiste im Wettkampfbüro ist kürzer und in der richtigen Reihenfolge.** „Resultate erfassen“
steht hinter „Piloten“ – es ist der letzte Schritt am Wettbewerbstag und stand vorne. Stammdaten,
Vereine und Modelltypen sind aus der Leiste ins Menü oben rechts gewandert, wo sie nur der
SuperAdmin sieht.

## 2.0.6

**Die SMV-Nummer ist jetzt Pflicht beim Anmelden.** Wer sich ohne Nummer anmeldet, wird
abgelehnt, mit einem Satz, wo sie steht. Vorher war die Nummer freiwillig, und daraus
entstanden Doppeleinträge: ein Stammsatz aus dem alten Bestand ohne Nummer und ein zweiter,
als dieselbe Person sich später mit ihrer echten Nummer angemeldet hat. In der Regiowertung
zählt das als zwei Piloten.

**Zwei Löschwege für den SuperAdmin, die vorher fehlten.**

- **„Löschen samt Ergebnissen“** bei einem einzelnen Wettbewerb. Bisher war ein Wettbewerb
  mit Ergebnissen oder im abgeschlossenen Zustand nicht zu löschen; dafür gibt es jetzt
  diesen zweiten, ausdrücklichen Knopf. Die Rückfrage nennt vorher die Zahlen.
- **„Alles aus dem Wettbewerbsbetrieb löschen“** am Ende der Wettbewerbsseite, falls ihr
  erst später offiziell anfangt und alles davor Testmüll ist. Das nimmt Wettbewerbe,
  Durchgänge, Startlisten, Resultate, die ganzen Stammsätze und alle Anmeldungen weg.
  **Konten, Vereine und Modelltypen bleiben.** Danach legt ihr einen Wettbewerb an, und die
  Stammliste baut sich von selbst wieder auf – aus den Anmeldungen.

**Nach der Datenbankaktualisierung führt der Weg zurück zur Aktualisierung.** Vorher
schickte die Seite zu den Vereinen – ein Rest aus einer Zeit, als die Startnummern dort
standen. Wer dort nichts mehr sucht, lief im Kreis.

## 2.0.5

**Die Datenbankaktualisierung steht jetzt unter `admin/upgrade.php` und läuft nur für den
SuperAdmin.** Vorher lag die Seite im Hauptverzeichnis und war für jeden erreichbar, der die
Adresse kannte – und wer sie aufrief, hat an der Datenbank Migrationen ausgelöst. Das war kein
Werkzeug, sondern ein offener Ablauf. Jetzt:

- **Ohne Konto** geht es wie überall zur Anmeldung und danach an diese Seite zurück.
- **Ein Vereinskonto** bekommt *„Die Datenbankaktualisierung ist dem SuperAdmin vorbehalten“*
  und landet im Wettkampfbüro.
- **Der SuperAdmin** kann wie bisher.
- Sie darf liegen bleiben. Die Meldung nach dem Lauf sagt das auch – vorher stand dort
  „lösche danach upgrade.php vom Server“, was nur deshalb nötig war, weil die Seite offen war.

**Zwei Texte kürzer.**

- Auf der **Regiorangliste-Kachel** stand „4 Starts zählen“ und darunter „0 Piloten in der
  Wertung“ – zwei Zahlen, die sich widersprachen. Die 4 sind keine Zahl von Starts, sondern die
  Regel: je Wettbewerb zählen die besten vier, der schlechteste nicht. Auf der Kachel steht
  jetzt **„beste 4 Starts zählen“**.
- Der **Hinweis am Anmeldeformular** endet an der Mitgliederkarte. Nach „Mitgliederkarte“ stand
  noch „sie hat bis zu sechs Ziffern“ und „Kennst du sie nicht, lass das Feld einfach leer.“ Das
  ist Zusatz, den das Feld nicht braucht. **„Wir kennen dich schon“** steht jetzt auf einer
  eigenen Zeile statt mitten im Satz.

## 2.0.4

**Ein roter Punkt in der Diagnose ist weg.** Gefunden auf der Live-Seite: die
Diagnose meldete *„Keine abgelösten Strafpunktschlüssel mehr – upgrade.php
ausführen“*. Ein Aufruf von `upgrade.php` hat nichts gebracht, und genau das war
der eigentliche Fund.

**Warum der Aufruf nichts gebracht hat.** Der Aufraeumteil der Migration zu den
Strafpunktregeln ist erst später dazugekommen, die Migrationsnummer aber war zu
diesem Zeitpunkt schon vergeben. Auf einer Anlage, deren Wettbewerbsstruktur
längst die kanonische ist – also genau auf deiner –, trägt `upgrade.php` alle
nummerierten Migrationen als erledigt ein, **ohne sie zu starten**. Eine neue
Nummer hätte dort also nie gelaufen.

Der Aufraeumer ist deshalb kein nummerierter Schritt, sondern ein **bedingter**:
Er läuft, solange die alten Schlüssel wirklich noch dastehen, und meldet sich
sonst nicht mehr.

Die Schlüssel heißen `penalty_per_second_over`, `penalty_per_second_under`,
`penalty_not_flown`, `max_time_penalty` und `max_landing_penalty`. Sie werden
seit der Umstellung der Strafpunktregeln nicht mehr gelesen und stehen nur noch
herum. Die geltenden Regeln werden **nicht** angefasst.

## 2.0.3

Zwei Dinge am Anmeldeformular, beides aus der Benutzung heraus.

**Die Nummer muss nicht mehr in einen Link.** Bisher kam der Name nur dann von
selbst, wenn die SMV-Nummer als Adresse übergeben wurde. Wer die Nummer von
Hand eingetippt hat, tippte auch den Namen. Jetzt kommt der Name, sobald man
das Feld verlässt oder mit der Tabulatortaste weiterspringt.

- **Ein eingetragener Name wird nie überschrieben.** Was der Besucher selbst
  getippt hat, gehört ihm.
- **Leert man die Nummer wieder, verschwindet auch der Name** – aber nur, wenn
  er von der Nummer kam. Ein selbst getippter Name bleibt stehen.
- Eine unbekannte Nummer lässt die Felder leer und gibt **keine** Fehlermeldung.
  Eine Meldung würde verraten, welche Nummern es gibt.
- Das Formular geht **auch ohne JavaScript**: dann eben wie bisher über den Link
  oder durch Abtippen des Namens.

**Die Zahl 999999 ist vom Anmeldeformular verschwunden.** Sie stand dort als
Satz: *„Dann bist du in der Regiowertung unter der Nummer 999999 geführt.“* Das
ist eine Anzeigeregel des Programms und sagt einem Besucher nichts. Schlimmer:
wer sie wörtlich nimmt, tippt 999999 ein – und als gespeicherter Wert wäre das
ein Unicum, das den zweiten Piloten ohne Nummer zurückweist.

- **Wer 999999 eintippt, meint „keine Nummer“**, und so wird es auch gespeichert.
  Damit ist es gleichgültig, ob sich jemand die Zahl abschreibt.
- Der Hinweis nennt jetzt, **wo die Nummer steht**: auf der Mitgliederkarte.

## 2.0.2

**Am Ende der Saison lässt sich die Regiorangliste öffentlich machen** –
unter **Regiocup** im Wettkampfbüro.

- Der Knopf **erscheint erst, wenn kein Wettbewerb des Jahres mehr offen ist**.
  Steht noch einer offen, steht statt des Knopfes die Zahl und der Name. Wer zu
  früh freigeben will, weiß damit auch, was er zuerst tun muss – beenden oder
  als abgesagt markieren. Ein abgesagter Wettbewerb gilt als abgeschlossen.
- **Danach sieht ein Besucher nur dieses eine Jahr.** Die Jahresknöpfe
  verschwinden für ihn. Vorjahre bleiben hier im Wettbewerbsbüro stehen, sind
  aber nicht öffentlich. Ein zweites Jahr freizugeben nimmt dem ersten die
  Freigabe – wie gewünscht ist nur das laufende Jahr sichtbar.
- **Die Ranglisten der einzelnen Wettbewerbe sind nicht betroffen.** Die gibst
  du je Wettbewerb frei, wie bisher.
- Mit **„Freigabe zurücknehmen"** ist alles wieder so wie vorher. Der
  eingestellte Verein und der SuperAdmin sehen die Liste weiterhin.

## 2.0.1

Kleinigkeiten und ein Fehler, der im Betrieb aufgefallen ist.

**Ein Wettbewerb ließ sich nicht beenden.** Der Knopf „Beenden" war da – und hat
nichts getan. Betroffen waren Wettbewerbe ohne einen einzigen Piloten in der
Startliste, unter anderem der Erlencup. Das ist behoben.

**Stammsätze lassen sich jetzt löschen** (Stammdaten, nur für den SuperAdmin).
Das hilft, wenn sich jemand zweimal angemeldet hat: einmal mit der richtigen
SMV-Nummer und einmal mit einer ausgedachten. Bisher standen dann zwei Sätze in
der Stammliste und einer davon war nicht mehr wegzubekommen.

- Gelöscht werden können nur Piloten, die **noch nie geflogen** sind. Wer
  Ergebnisse hat, bleibt stehen und ist als „geschützt" gekennzeichnet – mit
  der Angabe, wie viele Ergebnisse es sind. Sonst würde beim Löschen eine
  Wertung verschwinden, ohne dass man es merkt.
- Eine Anmeldung, die zu einem gelöschten Piloten gehörte, geht wieder **auf
  offen** und kann abgelehnt werden. Sonst stünde sie auf „angenommen", obwohl
  es den Piloten nicht mehr gibt.

**Die Regiorangliste steht oben auf der Startseite**, rechts neben dem Kasten
„Anmelden in drei Schritten". Vorher stand sie unter den Wettbewerben und
wechselte mit jedem neuen Wettbewerb ihren Platz. Jetzt hat sie dort immer
dieselbe.

## 2.0.0

Der Umbau der Piloten. Vorher stand der Name an jedem Startlisteneintrag für
sich. Wer in drei Jahren dreimal flog, hatte dreimal denselben Namen im System
und nichts darüber, dass es dieselbe Person ist. Genau daran scheiterte die
Regiowertung.

**Was der Pilot jetzt einmal für alle Jahre hat:** SMV-Nummer und Name, an
einer Stelle. Alles, was je Wettbewerb verschieden sein kann – Startnummer,
Verein, Modell, Modelltyp –, steht weiter am einzelnen Startlisteneintrag.
Wer den Verein wechselt, behält seine Historie beim alten.

- **Die SMV-Nummer ist das erste Feld im Anmeldeformular** und kann über den
  Link vorausgefüllt werden: `anmeldung.php?smv=123456`. Steht die Nummer schon
  in den Stammlisten, kommt der Name gleich mit. Das ist beabsichtigt: die
  Nummer ist öffentlich, und wer sie kennt, sieht damit auch den Namen. Ohne
  das müsste jeder Pilot seinen Namen bei jedem Wettbewerb neu eintippen.
- **Wer keine Nummer hat, steht in der Regiowertung unter 999999.** Das ist nur
  eine Anzeige, keine echte Nummer – zwei Piloten ohne Nummer sind also nicht
  über ihre Nummer zu unterscheiden. Das steht auch so auf der Seite.
- **Wer sich unter einer falschen Nummer anmeldet**, bekommt keine zweite
  Startlistenzeile mehr, sondern eine Meldung, die sagt, mit welcher
  Startnummer er schon dasteht.

**Neue Seite „Stammdaten"** in der Leiste, nur für den SuperAdmin: die
Stammliste mit SMV-Nummer, Vor- und Nachname, Zahl der Starts und dem letzten
Jahr. Eine Wettkampfleitung sieht ihre eigene Startliste, aber nicht die
Stammliste aller Piloten.

**Die Regiowertung erkennt Piloten jetzt an der Nummer** statt am Namen. Vorher
wurden zwei verschiedene Menschen mit gleichem Namen zu einer Person
gezählt, und wer seinen Namen änderte, tauchte in zwei Jahren als zwei
verschiedene Piloten auf.

**Die Startliste, der Startlisten-Export und der Einzelergebnis-Export zeigen
die SMV-Nummer** als eigene Spalte.

### Wettbewerbe können ausfallen

Ein Wettbewerb kann ausfallen, etwa wegen Wetter, und es findet sich kein
Ersatztermin. Bisher gab es dafür keinen Zustand: „beendet" hieß immer, dass
**alle** Ergebnisse da sind. Ein solcher Wettbewerb war damit nie zu schließen
– und am Saisonende blieb er einfach aktiv stehen.

- **Neuer Knopf „Abgesagt"** auf der Wettbewerbskarte. Er bedeutet: *fand nicht
  statt*.
- **Er ist danach gesperrt**, genau wie ein beendeter Wettbewerb. Ergebnisse,
  Startliste und Anmeldungen lassen sich nicht mehr ändern.
- **Für den Regiocup zählt er gar nicht**, auch nicht teilweise. Wäre er an zwei
  von fünf Durchgängen ausgefallen, bringen diese zwei Starts nichts in die
  Regioliste. Sonst hinge der Punktestand eines Piloten davon ab, an welchem
  Tag abgesagt wurde, statt davon, ob er geflogen ist. Was schon erfasst wurde,
  bleibt sichtbar – nur wird es nicht gewertet.
- **Wird er doch nicht abgelehnt**, wenn schon alle Ergebnisse vorliegen: dann
  hat der Wettbewerb stattgefunden.
- **Zurück geht es auf zwei Wegen**: „Fand doch statt", falls er doch geflogen
  wurde, und „Wieder öffnen", falls doch ein Ersatztermin gesucht wird.
- **Rangliste und Teilnehmerliste sagen oben deutlich, dass der Wettbewerb
  abgebrochen wurde**, und wie viele von wie vielen Ergebnissen vorliegen. Ohne
  diesen Hinweis hielte man die Tabelle für ein vollständiges Ergebnis.
- Er steht überall als „abgesagt" statt „beendet": auf der Startseite, in der
  Wettbewerbsauswahl, im Seitenkopf, im Wettkampfbüro und am Laufzettel.

### Am Ende der Saison

Der Knopf „Beenden" verschwand ersatzlos, sobald für einen Piloten ein Ergebnis
fehlte. Damit war ein Wettbewerb, dem ein Pilot oder ein Durchgang fehlte, nie
zu schließen – und genau dann steht man am Saisonende da.

- **Neuer Knopf „Trotzdem beenden"** als zweiter Weg. Er sagt vorher, wie viele
  Ergebnisse fehlen, und schließt den Wettbewerb trotzdem. Die Lücken bleiben
  unausgewertet und lassen sich danach nicht mehr nachtragen.
- **Ein Wettbewerb ohne einen einzigen Piloten** zeigt wieder „Beenden": da
  fehlt nichts.

### Die Startseite

- **Der Kasten „Anmelden in drei Schritten" ist genau so breit wie zwei
  Wettbewerbskacheln** und steht bündig über den ersten beiden. Vorher war er
  schmaler und stand für sich mittig; links blieb eine breite Lücke.
- **Die Kachel der Regiorangliste heißt nur noch „Regiorangliste".** Das Jahr
  stand vorher im Namen und sagte nichts über den Inhalt. Es steht jetzt eine
  Zeile tiefer, zusammen mit den Wettbewerben, zu denen es gehört:
  „2027 · 3 Wettbewerbe · 4 Starts zählen".
- Eine unvollständige letzte Kachelreihe steht mittig. Das bleibt so.


## 1.9.23

- **Die Punkteliste der Regiowertung ist einstellbar.** Bisher stand sie fest
  in `lib/region.php`: 1 → 100, 2 → 80, 3 → 60 … bis Platz 30 mit einem Punkt.
  Der SuperAdmin stellt sie jetzt unter **Regiocup → Punkteliste** ein – welcher
  Rang wie viele Punkte bekommt.
  - **Ohne gespeicherte Liste gilt weiter die FIS-Vorgabe.** Wer nichts
    einstellt, sieht nichts, das sich von vorher unterscheidet.
  - **Es ist nichts nachzurechnen.** Die Regiorangliste wird bei jedem Aufruf
    frisch berechnet und steht nirgends gespeichert. Die alte Wertung ist nach
    dem Speichern einfach die neue Wertung.
  - **Abgelehnt wird mit Grund.** Steigen die Punkte von unten nach oben, ist
    ein Feld leer oder steht Text darin, nennt die Meldung den Rang und den
    Fehler: *„Rang 3 bekommt mehr Punkte als Rang 2. Der beste Platz muss die
    meisten Punkte bekommen.“* Eine halb gelesene Liste wäre schlimmer als
    keine – dann wären die Punkte eine Mischung aus Vorgabe und Gespeichertem.
  - Ein Komma wird als Dezimalzeichen gelesen, nicht als Zahlentrenner.
  - **3 bis 60 Ränge**, und es werden nie weniger Zeilen gezeigt, als gerade
    eingestellt sind. Eine Liste, die länger ist als das, was man sieht, wäre
    ein Versteck.

- **Der ausrichtende Verein stand im Anmeldeformular nirgends.** Die Überschrift
  lautete *„Anmeldung – Schwarzbubenfliegen 2027“*; wer den Wettbewerb
  ausrichtet, war nirgends zu lesen. Bei einem Wettbewerb, der nur *Erlencup*
  heißt, ist damit nicht zu erkennen, wer ihn veranstaltet. Die Überschrift nennt
  jetzt den Verein: **„Anmeldung – MFV Brislach Schwarzbubenfliegen 2027“**.
  - Steht der Verein bereits im Namen, wird er **nicht wiederholt**.
  - Ohne Verein bleibt der Name, wie er ist.

## 1.9.22

- **In der Benutzerverwaltung war der Knopf „Löschen“ nicht erreichbar.** Die
  Tabelle war 1214 Pixel breit, ihr Kasten nur 1098 – die letzte Spalte stand
  einfach außerhalb. Zwei Ursachen: die Spalte **Angelegt** war zu breit, und
  die drei Eingabefelder hatten eine Mindestbreite, die sie nicht brauchten.
  - Die Spalte **Angelegt** ist weg. Sie stand ohnehin nur als Datum da.
  - Die Felder gehen auf `min-width: 9rem`; der Platzhalter im Passwortfeld
    hieß „unverändert lassen“ und wäre bei der schmaleren Breite ohnehin
    abgeschnitten worden. Er heißt jetzt „unverändert“.
  - Die Tabelle misst jetzt genau 1098 Pixel und passt damit ohne Rest in
    1280. Geprüft: **die letzte Spalte wird nicht mehr abgeschnitten**.

- **Die Ankreuzfelder waren auf 32 Pixel gestreckt.** Die Regel
  `.dense input { height: 32px }` hat auch Checkboxen erwischt. Dadurch war
  jede Zeile mit einem Kästchen 9 Pixel höher als eine ohne – in der
  Benutzerverwaltung sichtbar daran, dass die Zeile mit den Abzeichen
  niedriger war als die mit den Kästchen, was verkehrt aussieht. Die Kästchen
  sind jetzt 18×18 wie vorgesehen.
  Betroffen waren **alle** dichten Tabellen: Benutzer, Vereine, Modelltypen,
  Durchgänge und Erfassung.

- **Weiterer Leerraum auf der Benutzerverwaltung entfernt:** ein `margin-top`
  von 26px über dem SuperAdmin-Kästchen, ein Einleitungstext, der jede
  Verwaltungsseite aufzählte, obwohl sie in der Leiste steht, und ein
  vierzeiliger Hinweis am Passwortfeld, der die ganze Formularzeile hochzog.
  Die Seite ist dadurch von 1137 auf 1074 Pixel gekommen.

- **Ein neuer Wettbewerb war sofort „aktiv“ – und hat damit den laufenden verdrängt.**
  Wer mitten in der Erfassung den Wettbewerb für das nächste Jahr anlegte, stand
  danach plötzlich in einem leeren Wettbewerb und musste ihn erst wieder aktivieren.
  Neu angelegt ist er jetzt **offen**; aktiviert wird ausdrücklich in der Liste der
  Wettbewerbe. Eine Ausnahme bleibt und wird auch so gemeldet: gibt es überhaupt
  keinen aktiven Wettbewerb, muss einer her, sonst zeigt jede Seite ins Leere.

- **Ein beendeter Wettbewerb konnte weiter „aktiv“ dastehen.** Nicht der Cache –
  es waren zwei Stellen: das Beenden selbst hat das Kennzeichen nicht mitgenommen,
  und die Anzeige prüfte „aktiv“ vor „beendet“, also gewinnte das falsche Etikett.
  Behoben an beiden, und `current_competition()` weist einen beendeten Wettbewerb
  auch dann ab, wenn die Spalte noch so dasteht – damit berichtigt sich ein
  Altbestand von selbst, ohne Migration. Beim Beenden übernimmt der neueste noch
  offene Wettbewerb; ist keiner offen, bleibt kurz keiner aktiv.

- **Der Regiocup ist eine eigene Seite: `admin/regiocup.php`, im Benutzermenü.**
  Vorher stand er als Sektion im Profil, und dort war er nur für den SuperAdmin
  sichtbar – obwohl die Einstellung gerade für einen *anderen* Verein gedacht ist.
  Jetzt erreicht die Seite auch die Mitglieder des eingestellten Vereins, mit der
  Liste und ohne das Formular. Alle anderen sehen dieselbe Sperre wie `region.php`.

- **Der eingestellte Verein muss nicht für die Anmeldung freigeschaltet sein.**
  Das war die eigentliche Überraschung beim Testen: `clubs.active` steuert nur das
  Anmeldeformular, und ein Verein, der lediglich zusieht, soll dafür nicht dort
  auftauchen müssen. Beide Listen sind unabhängig; in der Auswahl steht so ein
  Verein als *„(nicht in der Anmeldung)“*, damit man die beiden nicht verwechselt.

## 1.9.21

- **Der Regiocup war unsichtbar.** Die Rechnung lag fertig in `lib/region.php` –
  aber keine Seite rief sie auf. Wer den Regiocup im Wettbewerb angeklickt hatte,
  bekam davon nichts zu sehen: keine Liste, keine Kachel, keinen Export.
  - **`region.php`** ist neu: die Regiorangliste über alle Wettbewerbe eines
    Jahres, die den Regiocup-Knopf tragen – dazu ein CSV-Export mit den
    Punkten je Start. Für Besucher und Mitglieder des eingestellten Vereins.
  - **Eine Kachel auf der Startseite** zeigt die Regiorangliste mit Jahr,
    Wettbewerbszahl, Piloten in der Wertung und den drei Vordersten. Sie
    erscheint nur, wenn sie auch wirklich erreichbar ist.
  - **Auf der Profilseite** kann der SuperAdmin den Verein einstellen **und
    die Liste ansehen, bevor sie veröffentlicht ist**. Die Kachel dort ist eine
    Vorschau, kein zweiter Ort mit eigenen Zahlen: beide benutzen dieselbe
    Funktion `region_table()`.

- **Die Einstellung „Verein, der die Regiorangliste sehen darf“ ist umgezogen.**
  Sie stand in den **Wettbewerbseinstellungen** – und der Regiocup läuft über
  ein ganzes Jahr, also über mehrere Wettbewerbe. Bei zwei Wettbewerben im
  selben Jahr hätte jeder seinen anderen Verein freischalten können, und
  sichtbar wäre trotzdem nur der gerade aktive. Sie steht jetzt beim
  SuperAdmin unter dem Profil und gilt für das ganze Programm.
  - Damit niemand seine Freigabe verliert, werden die alten Werte aus den
    Wettbewerbseinstellungen weiterhin gelesen – **aus allen Wettbewerben**,
    nicht nur aus dem gerade aktiven. Beim Speichern werden sie mitgelöscht,
    damit „niemand“ auch wirklich niemand bedeutet.
  - Das Formular in den Wettbewerbseinstellungen ist weg. Wer dort noch einen
    Wert findet, hat eine Fassung vor 1.9.21.

- **Die Sichtbarkeit ist geprüft, nicht behauptet.** Vier Fälle werden
  gegeneinander getestet: SuperAdmin, eingestellter Verein, anderer Verein,
  Besucher ohne Konto. Nur die ersten beiden sehen die Liste – und erhalten
  den CSV-Export mit 200; die anderen bekommen den Sperrhinweis, eine
  Umleitung statt der Datei und **keine** Kachel auf der Startseite.

## 1.9.20

- **Eine frische Einrichtung war unmöglich.** Zwei Fehler auf demselben Weg,
  beide gefunden beim Wiederaufsetzen einer leeren Datenbank. Beide sind alt.
  - **Die Tabellen standen in der falschen Reihenfolge.** `competitions.club_id`
    verweist auf `clubs`, und `competitions` stand in `schema.sql` **vor**
    `clubs`. MySQL und MariaDB lehnen das ab: „errno: 150 Foreign key
    constraint is incorrectly formulated“. Nachgewiesen: die alte Fassung
    bricht bei `competitions` ab, die neue lädt 11 Tabellen und 11
    Fremdschlüssel. Nur verschoben, jeder Block byteweise unverändert.
  - **Ein Semikolon im Kommentar hat die Anweisung zerschnitten.**
    `install.php` trennt `schema.sql` an jedem Semikolon, und zwei Zeilen
    tragen eins im Kommentar: `-- Vereinszugehörigkeit; NULL = …`. Die
    `CREATE`-Anweisung wurde mitten drin abgeschnitten. Die Kommentare werden
    jetzt entfernt, **bevor** getrennt wird.

- Warum es nie aufgefallen ist: bestehende Installationen bekommen ihr Schema
  über die Migrationen, nicht über `schema.sql`. Die Datei wird nur bei der
  Ersteinrichtung gelesen – und die hat seit `club_id` (Migration 7, Fassung
  1.9.0) niemand gebraucht.

## 1.9.19

- **Die Meldung nach dem Aktualisieren bietet den Weg zu `upgrade.php` an**,
  statt ihn nur zu erwähnen. Vorher stand dort „Danach bitte upgrade.php
  aufrufen." – der Weg war beschrieben, aber man musste ihn sich heraussuchen.
  Jetzt steht am Ende der Meldung **Jetzt upgrade.php aufrufen**, unterstrichen
  und kräftiger als der Fliesstext.
  - Nur wenn er nötig ist. `schema_hinter_programm()` entscheidet das, und ohne
    offene Migration erscheint kein Link.
  - `flash()` kann jetzt einen Link mitgeben. Der Text wird weiterhin maskiert,
    damit kein HTML hineinkommt; der Verweis wird aus zwei getrennt maskierten
    Feldern gebaut, damit auch dort nichts einzuschleusen ist. Beides geprüft:
    ein Text mit spitzen Klammern und ein Ziel mit Anführungszeichen und
    `<script>` kommen maskiert heraus, nichts wird zu HTML.
  - Aufrufer ohne Link bleiben unangetastet: die Ausgabe einer einfachen Meldung
    ist byteweise dieselbe wie vorher. Geprüft.

## 1.9.18

- **Ein benutzter Modelltyp lässt sich nicht mehr löschen.** Modelltypen sind wie
  Vereine global und zwischen den Wettbewerben geteilt. Bisher genügte der
  Blick auf abgeschlossene Wettbewerbe: ein in einem laufenden Wettbewerb
  benutzter Typ war löschbar, und der Fremdschlüssel `fk_pilot_type` setzte die
  Piloten **still auf NULL**. Kein Fehler, keine Meldung – die Startliste verlor
  ihre Gruppierung, und die Meldung danach sprach nur davon, dass nun „n Piloten
  ohne Typ" dastehen. Genau wie bei den Vereinen wird jetzt gesperrt, mit
  genauer Angabe:
  > Dieser Modelltyp wird noch benutzt und kann nicht gelöscht werden: 19
  > Piloten in der Startliste und 1 Anmeldung. Soll er nur nicht mehr zur Auswahl
  > stehen, dann das Kästchen „aktiv" wegnehmen statt zu löschen.
  Geprüft an „Elektrisch" (19 Piloten, 1 Anmeldung): gesperrt, nichts verloren.
  Ein unbenutzter Typ lässt sich weiterhin löschen.

- **Wenn statt des gewünschten Wettbewerbs ein anderer gezeigt wird, steht das
  jetzt da.** Bisher geschah das still: wer `?competition=<fremde Nummer>` in die
  Adresse schrieb, sah einfach einen anderen Wettbewerb und bekam keinen
  Hinweis. Die Daten fremder Wettbewerbe waren dabei nie zu sehen – der Wechsel
  schützt davor –, aber man konnte leicht glauben, den gewünschten Wettbewerb
  vor sich zu haben und im falschen zu arbeiten. Jetzt:
  > Der Wettbewerb „MFV Brislach - Schwarzbubenfliegen 2026" gehört einem
  > anderen Verein. Angezeigt wird „MG Breitenbach - Erlencup 2027".
  Bewusst eine Meldung und keine Umleitung: eine Umleitung auf jeder
  Verwaltungsseite liefe in eine Schleife, sobald der aktive Wettbewerb einem
  fremden Verein gehört.

- **Zu einem gemeldeten Verdacht: es gab keine Informationslücke.** Nachgemessen
  mit zwei Wettbewerben an verschiedenen Veranstaltern und einem Konto, das nur
  den eigenen Verein steuert: die Adresse `anmeldungen.php?competition=<fremd>`
  zeigt **keinen** fremden Namen und **keine** fremde Bemerkung. Der
  Verwaltungsschalter von `resolve_competition_param()` greift – er tauscht den
  Wettbewerb, statt zu sperren. Was fehlte, war die Ansage, und die gibt es jetzt.

## 1.9.17

- **Die Meldung nach dem Aktualisieren kam noch in der alten Fassung.** Nach dem
  Sprung von 1.9.13 auf 1.9.16 stand dort wieder „Der Stand ist damit gemischt“,
  obwohl die Korrektur mit 1.9.14 drin war. Die Ursache war die Reihenfolge:
  `update_ausfuehren()` schreibt die Dateien, und der Text entsteht danach im
  selben Request – aus dem Code, der beim Programmstart in den Speicher
  geladen wurde. Gemessen: **195 Zeichen zwischen dem Schreiben und dem Text**.
  Eine Korrektur kann sich so nicht selbst ankündigen.
  - Jetzt merkt der Absender nur die Zahlen, und der Text entsteht beim
    nächsten Aufruf – mit dem Code, der gerade installiert wurde. Gemessen: im
    schreibenden Request steht kein Text mehr.
  - Damit ist auch die Liste der stehengebliebenen Dateien aus dem neuen Code:
    sie nennt jetzt alle geschützten Dateien mit dem Zusatz, ob sie zum
    Repository passen, statt nur der abweichenden eine.

- **`diagnose.php` sagt, wenn der Opcode-Cache den alten Stand festhält.** Nach
  einem Update kann eine Seite den Stand von vorher zeigen, obwohl die Datei
  schon die neue ist. Ist `opcache.validate_timestamps` aus, merkt der Cache das
  nie und läuft endgültig weiter. Das sieht aus wie ein Fehler im Update und ist
  keiner – die Prüfung holt den laufenden Bestand gegen die Datei.

- **Die erste Zeile der Anmeldung nennt nur den Titel**: „Anmeldung –
  Schwarzbubenfliegen 2027“. Das „hinzufügen“ war zuviel.
- **Die drei Schritte im Kasten der Startseite sind linksbündig**, während
  Überschrift und Hinweis mittig bleiben. Eine mehrzeilige Liste mittig sieht
  zerklüftet aus, weil die Nummern mitten im Satz stehen statt an einem Rand.

## 1.9.16

- **Der öffentliche Bereich hat keine Navigationsleiste mehr.** Zur Startseite
  führt der Titel „Ziellandekonkurrenz" in der Kopfzeile; zwischen den
  Wettbewerben führt die Kachelleiste. Beides hat man ohnehin benutzt, statt der
  Leiste mit fünf Punkten. `install.php` und `upgrade.php` behalten ihre Leiste,
  damit man von dort nicht in eine Sackgasse läuft.

- **Die Wettbewerbsauswahl über der Seite ist weg** – die Zeile „Wettbewerb –
  Erlencup 2027 – Schwarzbubenfliegen 2027 – …“ auf Rangliste, Teilnehmerliste
  und Anmeldung. Sie war die Navigation des öffentlichen Teils und wird durch
  die Kacheln ersetzt. Der Knopf **Anmelden** auf der Kachel führt mit
  `competition=` direkt zur Anmeldung des richtigen Wettbewerbs; geprüft.
  - `competition_choices()` hat damit keinen Aufrufer mehr und ist entfernt,
    mitsamt der zugehörigen Stile `.pick-row` und `.pick-chip` (33 Zeilen).
  - `vereinswertung.php` bleibt als Weiterleitung auf `rangliste.php`, damit alte
    Links und Lesezeichen nicht ins Leere laufen. Wettbewerb und wandern mit.

- **Die Vereinswertung steht am Ende der Rangliste**, unter demselben Filter wie
  die Piloten. Sie hatte eine eigene Seite, weil es eine Leiste gab, in der sie
  liegen konnte. „Gesamtwertung“ heißt bei den Vereinen dasselbe wie „alle
  Modelltypen“; „Elektrisch“ und „Schlepp“ schränken jetzt beide Wertungen
  gleichzeitig ein.
- **Der Knopf „Drucken“ ist von der Rangliste und von der Vereinswertung
  verschwunden.** Auf dem iPad druckt man ohnehin nicht, und am Wettbewerbsplatz
  gibt es den Laufzettel als PDF.

- **Die erste Zeile der Anmeldung ist ein Satz in einer Größe**: „Anmeldung –
  Schwarzbubenfliegen 2027 hinzufügen“. Vorher stand dort die Überschrift
  „Anmeldung“ in eigener Schriftgröße und darunter ein Absatz – zwei Grade
  übereinander für eine einzige Aussage.

- **„Wettkampfbüro“ steht jetzt im Menü hinter dem Benutzersymbol.** Mit der
  weggefallenen Leiste wäre es sonst aus dem öffentlichen Teil verschwunden.

- **Ein Fehler, den ich beim Testen selbst gemacht habe:** im Test habe ich das
  Datum unquotiert gesetzt. `2027-08-15` rechnet PHP als `2027 - 8 - 15 = 2004`,
  ein Datum von 2004 liegt in der Vergangenheit, und keine Kachel zeigte einen
  Anmelde-Knopf. Ich suchte erst im Programm statt im Test. Derselbe Fehler wie
  bei `isset('0')`: ein Wert, der aussieht wie eine Zahl, ist keiner.

## 1.9.15

- **Die Knöpfe auf den Karten waren unterschiedlich hoch.** Auf der Live-Seite
  gemessen: „Rangliste" 52 Pixel hoch, „Anmelden" 44 Pixel – in derselben Zeile,
  mit derselben Oberkante. Ursache war eine Zeile im Stil:
  `.pick-go .btn:first-child { height: var(--ctl-h-lg) }`. Sie wollte den ersten
  Knopf hervorheben und machte stattdessen die Zeile unruhig. Jetzt sind alle
  Knöpfe einer Karte gleich hoch.

- **Die Startseite ist kürzer geworden.** „So liest man die Rangliste" und
  „Wie die Punkte entstehen" sind weg, ebenso die Überschrift „Wettbewerb wählen"
  und der Satz darunter. Der Platz war nicht auf den ersten Blick nötig: die
  Ranglistenseite erklärt dieselben Zeichen direkt unter der Tabelle
  („Durchgestrichene Werte sind Streichresultate…"), wo man sie braucht.

- **„Anmelden in drei Schritten" steht jetzt oben**, als Kasten über den
  Wettbewerben, mittig auf der Seite und mit mittigem Text. Wer die Seite
  öffnet, will sich entweder eintragen oder Ergebnisse sehen. Beides war
  gleich wichtig, beides stand aber unten hinter drei Erklärblöcken – wer sich
  eintragen lassen wollte, musste an der Erklärung der Rangliste vorbei, um
  zum Anmeldeweg zu kommen.

- **Die Wettbewerbe stehen als geschlossener Block in der Mitte.** Die Kacheln
  wachsen auf die vorhandene Breite, höchstens 380 Pixel, und schrumpfen nie
  unter 300 Pixel. Dadurch stehen auf dem Laptop drei nebeneinander, auf dem
  iPad im Querformat ebenfalls drei – nur schmaler.
  - Das Raster ist dafür durch Flex ersetzt. Ein Raster zentriert mit
    `justify-content` nur die Spalten: eine einzelne Kachel auf der zweiten
    Zeile saß linksbündig. Mit Flex steht jede Zeile mittig, auch wenn sie
    nur eine Kachel hat. Auf 1024 Pixeln sind es drei Kacheln zu 316 Pixeln
    nebeneinander, vorher waren es zwei zu 360 und eine allein darunter.

- Die mitgelieferten Stile für die entfernten Blöcke (`.hero`, `.why`,
  `.legende`) sind mitentfernt; niemand verwendet sie noch.

## 1.9.14

- **Nach jedem Update eine rote Warnung, die nichts beanstandete.** Die Meldung
  lautete: „1 Datei(en) sind stehen geblieben, weil sie von Hand geaendert oder
  geschuetzt sind. Der Stand ist damit gemischt." Beides ist falsch.
  - Geschuetzte Dateien wurden mit von Hand geaenderten in eine Summe gepackt.
    `install.php` steht in der Bestandsliste, wird vom Knopf aber nie
    ausgeliefert. Wer sie aelter hat, ist deshalb kein Ausnahmefall, sondern der
    Normalfall – und der Knopf behauptete, die Datei sei von Hand geaendert.
  - Jetzt bekommen geschuetzte Dateien eine eigene Gruppe. Steht eine davon
    hinter dem Repository, steht in der Meldung: sie ist aelter und wurde
    deshalb nicht mitgeschrieben, das ist so vorgesehen und kein Fehler. Die
    Meldung bleibt gruen. Rot wird sie nur noch, wenn wirklich eine Datei von
    Hand geaendert wurde oder ohne Eintrag in der Bestandsliste ist.
  - Aufgetreten ist das seit **1.9.12** – damals kam `active = 1` in die
    Anweisung von `install.php`, und seitdem wich die Datei auf jedem Server vom
    Repository ab.

- **`diagnose.php` meldete dauerhaft einen Fehler, den niemand beheben konnte.**
  Es verglich alle Dateien der Bestandsliste, auch die geschuetzten. Ergebnis
  auf jedem Server, der schon einmal aktualisiert hat: „FEHLT – 1 Datei(en)
  weichen ab: install.php". Kein Update kann das beheben, weil es die Datei
  grundsaetzlich nicht liefert. Jetzt stehen geschuetzte Dateien in einer eigenen
  Zeile mit dem Hinweis, dass sie nie mitgeliefert werden – kein Fehler, nur
  eine Auskunft. Von Hand geaenderte Dateien werden weiterhin als Fehler
  gemeldet, mit Namen.

- Geprueft an vier Faellen: alles aktuell (gruen), eine geschuetzte Datei
  aelter (gruen, mit Erklaerung), eine Datei wirklich von Hand geaendert (rot,
  mit Namen), und dass keine geschuetzte Datei unter den zu schreibenden
  landet. Fuer `diagnose.php` zusaetzlich mit nachgebautem Zustand: geschuetzte
  Datei aelter ergibt „OK“ mit Hinweis, von Hand geaenderte ergibt weiterhin
  „FEHLT“.

## 1.9.13

- **Aktualisierung und Benutzer verwalten stehen im Menü hinter dem Benutzersymbol**,
  beim SuperAdmin, direkt untereinander. Beide sind aus der Navigationsleiste
  verschwunden. Sie betreffen nur den SuperAdmin; in der Leiste, die alle Konten
  sehen, nahmen sie zwei Plätze für einen Bruchteil der Benutzer ein.

- **Die Navigationsleiste passt jetzt wirklich auf eine Zeile.** Nach dem Wegfall
  der beiden Punkte fehlten ihr noch **7 Pixel** – sie umbrach trotzdem, und
  „Rangliste" rutschte auf eine zweite Zeile. Gemessen: 1147 Pixel Bedarf bei
  1140 Pixeln nutzbarer Breite. Der waagerechte Innenabstand der Einträge geht
  deshalb von 14 auf 9 Pixel; das sind rund 50 Pixel Luft, und jetzt sind es
  1107 Pixel bei 1140. Ohne Puffer entscheidet die Nachkommastelle der Schrift,
  und die unterscheidet sich je nach Betriebssystem.
  - Beim Messen habe ich zweimal falsch gelegen: Erst zählte ich die
    Zwischenräume um den Leerraum nicht mit. Dann setzte ich `nowrap`, worauf
    die Einträge auf die Leiste schrumpften und der Bedarf zu klein aussah –
    gemessen wurde am Ende nur noch die `max-width`. Erst als ich auch die
    `max-width` aufhob, kam die wahre Zahl heraus. Beim ersten Messen lag ich
    um 7 Pixel daneben und schloss daraus, es passe.
  - Auf 1280, 1366, 1440, 1600 und 1920 Pixeln jetzt je eine Zeile. Die
    öffentliche Seite war nie betroffen.

- **Die Infobox „Konten" auf der Einstellungsseite ist weg.** Sie zählte die
  Konten auf und verwies auf die Benutzerverwaltung – beides steht jetzt dort,
  wo es hingehört: das Konto im Profil, die anderen Konten in der
  Benutzerverwaltung. Der zugehörige Datenbankaufruf ist mit entfallen, er
  hatte sonst keinen Zweck mehr.

- **Auf dem iPad lief die Seite 6 Pixel zu breit.** Das Namensfeld auf der
  Wettbewerbskarte hatte `min-width: 220px`; zusammen mit dem Knopf "Speichern"
  (99px) und dem Abstand waren das 325px Mindestbreite, eine Karte bietet bei
  1024px aber nur rund 291px. Der Knopf ragte ueber den Bildschirmrand. Jetzt
  darf das Feld schrumpfen.
  - Der Knopf war nur `visibility: hidden`, als sei er weg - und nahm dem
    Namensfeld trotzdem 99px plus Abstand weg, obwohl er nichts anzeigt. Jetzt
    ist er `display: none`, und das Feld hat die volle Kartenbreite: 282px
    statt 186px. Beim Bearbeiten kommt der Knopf zuverlaessig hoch, auch per
    Tabulatortaste - geprueft.

- **Zielgeraete sind jetzt festgehalten: Laptop und iPad.** Ein Smartphone ist
  bewusst kein Ziel; am Wettbewerbsplatz steht ein Laptop oder ein iPad. Vorher
  stand das nirgends, und es lohnte sich, ueber die Lesbarkeit auf 360 Pixeln zu
  streiten. 108 Kombinationen aus 9 Breiten (768 bis 1920 Pixel) und 12 Seiten
  geprueft: kein Querlauf.

- **Ab 2.0.0 gilt eine Regel fuer die Versionsnummer:** nur Dateien geaendert,
  dann steigt die dritte Stelle; Datenbank geaendert, dann steigt die zweite
  Stelle und die dritte faellt auf 0. Sie steht in der README, damit sie nicht
  im Kopf von jemandem bleiben muss. 1.9.12 etwa hat den Vorgabewert von
  `users.active` geaendert und steht trotzdem auf der dritten Stelle - die
  Nummern bis 1.9.13 bleiben, wie sie sind.

## 1.9.12

- **Ein neu angelegtes Konto war sofort gesperrt** und liess sich mit keinem Passwort
  anmelden. Nur ein zweites Bearbeiten in der Kontenliste brachte es wieder
  hervor – dann mit der grössten Älfte „Passwort neu gesetzt" in der Meldung, was
  den Verdacht auf das Passwort lenkte. Das Passwort war nie falsch.
  - Ursache: `users.active` hatte den Vorgabewert `0`. `admin/benutzer.php` und
    `install.php` fügen neue Konten ohne `active` ein und erbten damit
    **gesperrt**. Die Spalte steht in `sql/schema.sql` auf `1` – die Migration 6
    hat sie 2018 mit `DEFAULT 0` angelegt und danach nur die **vorhandenen**
    Konten auf 1 gesetzt, den Vorgabewert aber stehen lassen.
  - Warum es erst jetzt auffiel: die Anlage meldet grün „Konto angelegt",
    während in der Liste gleich darunter „gesperrt" steht. Beides ist richtig,
    zusammen ergibt es eine Meldung, die sich widerspricht.
  - Behoben an drei Stellen: beide Einfügungen nennen `active` jetzt
    ausdrücklich, Migration 6 legt die Spalte mit dem richtigen Vorgabewert an
    (`active` 1, `is_superadmin` 0 – die beiden sind nicht gleich), und ein
    Migrationsschritt stellt den Vorgabewert bei schon migrierten Installationen
    auf 1. **Bestehende Konten werden nicht angefasst:** gesperrt heisst
    gesperrt, auch absichtlich.
  - Prüfung ergänzt: `diagnose.php` meldet eine Spalte `users.active` mit
    anderem Vorgabewert. Geprüft habe ich sie, indem ich die Spalte absichtlich
    wieder auf 0 gesetzt habe – sie schlägt an, und nach der Migration schweigt
    sie.

- **Für diese Fassung bitte `upgrade.php` einmal laufen lassen.** Das Anlegen
  funktioniert auch ohne; nur der Vorgabewert in der Datenbank bleibt dann auf
  der alten Zahl stehen. Der Schritt ändert keine Konten, nur eine Spalten-
  eigenschaft, und darf beliebig oft wiederholt werden.

- **Erst deuten, dann heilen.** Am Anfang habe ich im Passwort gesucht, weil
  dort die Wirkung sichtbar war. Der Test, der den Fehler brachte, prüfte
  zuerst nur, ob das Passwort in der Datenbank stimmt, und meldete „alles gut" –
  er hat die Anmeldung gar nicht versucht. Erst als beides nebeneinanderstand
  (Passwort stimmt, Anmeldung geht trotzdem nicht) war klar, dass gesucht wird an
  der falschen Stelle.

## 1.9.11

- **Der Regiocup-Schalter liess sich nicht mehr zurücksetzen.** Ein Wettbewerb
  liess sich in den Regiocup aufnehmen, aber nie wieder herausnehmen: der Knopf
  blieb auf „nimmt mit", der Tooltip sagte dauerhaft „zum Entfernen klicken".
  - Ursache: `isset($_POST['region'])` prüft, ob ein Feld vorhanden ist, und der
    Wert `0` **ist** vorhanden – `isset('0')` ist wahr. Das versteckte Feld
    schickte also die `0`, und der Server machte daraus trotzdem die `1`. Jetzt
    wird der Wert selbst gelesen: `post('region', '0') === '1'`.
  - Das war die einzige Stelle mit einem versteckten „0". Alle anderen
    `isset($_POST[...])` sitzen auf Ankreuzfeldern, die gar nichts schicken,
    wenn sie aus sind – dort ist `isset` genau richtig.
  - Geprüft mit sechs Klicks hintereinander: der Zustand schaltet jedes Mal
    wirklich um, und Anzeige und Datenbank sagen dasselbe.

- **Der Regiocup-Zustand steht jetzt auf der Karte**, als goldenes Abzeichen
  neben „offen" oder „beendet". Vorher stand er nur im Knopftext, zwischen fünf
  anderen Knöpfen.
- **Vor dem Umschalten kommt eine Rückfrage**, wie beim Beenden und Löschen auch;
  **ohne Bestätigung ändert sich nichts**. Vorher wirkte ein Verklicken sofort.
  Damit ist auch der blasse Tooltip weg: er sagte nur „zum Entfernen klicken" und
  war ausser beim Überfahren sichtbar, auf dem Telefon gar nicht. Jetzt steht im
  Dialog, was passiert – „Wettbewerb X aus dem Regiocup nehmen? Er zählt dann nicht
  mehr zur Regiorangliste seines Jahres. Wieder aufnehmen geht jederzeit."
- Die Trophäe bleibt, wo sie war: 🏆 am Knopf und als Abzeichen auf der Karte.
  Ich hatte sie entfernt, weil in **meinen** Bildern ein leeres Kästchen stand –
  in meinem Container ist keine Emoji-Schrift installiert (`fc-list | grep -ci
  emoji` ergibt 0). Das war ein Mangel meiner Prüfumgebung, nicht der Seite.

## 1.9.10

- **Ein Benutzersymbol oben rechts**, wie auf den üblichen Seiten. Ohne Konto
  ein Verweis auf die Anmeldung, mit Konto ein Menü mit Name, Rolle, Profil
  und Abmelden. Umgesetzt als `<details>`, damit es ohne JavaScript aufgeht und
  mit der Tastatur bedienbar bleibt.
  - Der Knopf **Anmelden** in der Navigationsleiste ist dafür weg. Er stand
    direkt neben dem Punkt **Anmeldung** und wurde dauernd verwechselt – ein
    Buchstabe Unterschied, ein Klick daneben.
  - Name und „Abmelden" standen vorher als Text in der Kopfzeile und sind
    damit verschwunden.
- **Neue Seite *Profil*** (`admin/profil.php`) für alles, was die Person
  betrifft: Kontoangaben, Anzeigename, Passwort ändern. Der Benutzername und
  die Rechte bleiben beim SuperAdmin; das steht so auch auf der Seite.
  - Der Block **Eigene Einstellungen** ist bewusst noch leer. Er ist für das
    vorgesehen, was nur die Person betrifft und später dazukommt.
- **Passwort aus den Wettbewerbseinstellungen entfernt.** Dort ging es um
  Strafpunkte, Anmeldung und Freischaltung; wer dort ein Passwort ändern
  wollte, lief an Strafpunkten vorbei. Unter *Einstellungen* steht jetzt ein
  Verweis aufs Profil.
- *Einstellungen* nennt die Konten nur noch als Übersicht und verweist für das
  eigene Passwort aufs Profil.

## 1.9.9

- **Neue Startseite.** `index.php` war bisher die Rangliste. Jetzt beantwortet
  die Startseite erst die zwei Fragen, die ein Besucher hat: welcher
  Wettbewerb, und wofür stehen die Zahlen. Die Rangliste ist nach
  `rangliste.php` gewandert, die Navigation beginnt mit *Start*.
- **Die Wettbewerbswahl ist zum Hauptelement geworden**, wie gewünscht. Grosse
  Karten, ein Klick irgendwo auf die Karte führt zur Rangliste – vorher stand
  ein Auswahlfeld in einer Ecke, das man bedienen musste. Kein Formular und
  kein JavaScript mehr auf den öffentlichen Seiten, nur Verweise.
  - Nach **Wettbewerbsdatum** sortiert, nicht nach Nummer: wer einen
    Wettbewerb nachmacht, bekommt eine höhere Nummer als das ältere, später
    stattgefundene. Ohne Datum steht er hinten.
  - Auf der Karte stehen Datum, Ort, Verein, Zahl der Piloten und Durchgänge.
  - Ohne Wettbewerbsabzeichen im Seitenkopf, denn dort wird der Wettbewerb
    erst gewählt.
- **Rangliste für jeden Wettbewerb, Anmeldung nur für offene.** Beides steht
  getrennt auf der Karte: *Rangliste* bei freigegebener Rangliste, *Anmelden*
  nur, wenn noch angemeldet werden kann.
  - `competition_nimmt_anmeldungen_an()`: nicht beendet, Anmeldung nicht
    abgeschaltet, Tag noch nicht vorbei. Das Datum zählt mit – eine
    abgeschaltete Anmeldung allein genügt nicht, sonst stünde ein
    Wettbewerb vom letzten Juni noch monatelang in der Auswahl.
  - Dieselbe Regel gilt für die Karten, für die Auswahl auf der Anmeldeseite
    und für den Hinweis dort.
  - Wer einen vergangenen Wettbewerb wählt, bekommt auf der Anmeldeseite die
    Liste der Wettbewerbe, für die es noch geht – oder den Satz, dass gerade
    für keiner offen ist, mit einem Knopf zurück zu allen Wettbewerben.
  - Der gerade gezeigte Wettbewerb bleibt in der Auswahlleiste, auch wenn er
    beendet ist; er trägt dann den Vermerk *beendet*. Sonst stünde man vor
    einer leeren Auswahl und wüsste nicht, wofür die Seite spricht.
- **Kurze Erklärung auf der Startseite**, bewusst ohne feste Punktzahlen, weil
  die je Verein verschieden sind: wie man die Rangliste liest (durchgestrichen,
  rote Zahl, gleiche Summe), wie die Punkte entstehen und wie man sich anmeldet.
- `competitions_uebersicht()` bringt Datum, Ort und die Schalter in zwei
  Abfragen für alle Wettbewerbe statt einer je Wettbewerb.

## 1.9.8

- **Bei Punktegleichheit entscheidet das bessere Streichresultat – und sonst
  nichts, was den Ausgang eines Fluges betrachtet.** Bisher lag dazwischen noch
  die Anzahl „gültiger Flüge“, und dort zählte ein Flug mit Motor nicht mit. Ein
  Pilot, der ein Los gleich aufgegeben und den Motor angelassen hat, landete
  dadurch einen Platz weiter hinten, obwohl er dieselbe Summe und dasselbe
  Streichresultat hatte.
  - Die Kette lautet jetzt: Summe, dann das kleinere Streichresultat, dann das
    beste Einzelresultat, dann der Name.
  - Der Flugzähler ist aus der Wertung und aus dem Rankschlüssel entfernt. Er
    wurde nirgends angezeigt, er war nur zum Sortieren da.
  - Gleiche Reihenfolge in der Vereinswertung.
  - Gegenprobe mit drei Szenarien, die mit dem Stand von 1.9.7 noch anders
    ausgingen: gleiche Summe mit und ohne Motor, und gleiche Summe mit
    gleichem Streichresultat, wobei der Motor im gestrichenen Flug steckt.

## 1.9.7

- **Die vier Ankreuzfelder sind unabhängig geworden.** Bisher stand in der
  Datenbank ein einziges Statusfeld, und wer Aussenlandung **und** Bruchlandung
  ankreuzte, bekam nur eine der beiden Feststrafen. Landet ein Modell neben die
  Piste und verliert dort Teile, trifft aber beides zu.
  - Migration 11 legt `scores.not_started`, `scores.outlanding` und `scores.crash`
    an und entfernt `scores.status`. Die bisherigen Ausgänge werden übernommen,
    danach ist nichts mehr doppelt belegt.
  - Aussenlandung und Bruchlandung werden **addiert**, jede für sich.
  - Bei der Kombination aus beiden zählt der **Landewert wieder**: dann ist er
    die Landung im Feld. Bei der Aussenlandung allein bleibt er null.
  - Die Reihenfolge der Anzeige lautet `nicht angetreten`, `Aussenlandung`,
    `Bruchlandung`, `Motor`. In der Rangliste erscheint eine Kombination als
    „Aussenlandung & Bruchlandung“ und so weiter.
- **„nicht angetreten“ schliesst die anderen Felder aus.** Wer nicht angetreten
  ist, hat nicht geflogen. Beim Ankreuzen springt die Flugzeit auf **0:00**,
  der Landewert auf 0, und die übrigen drei Kästchen werden gesperrt und
  abgehakt. Beim Abwählen kommt der vorher eingetragene Wert zurück, damit ein
  Fehlklick nichts vernichtet. Beim Speichern gilt „nicht angetreten“ auch dann,
  wenn das Formular von Hand eine unmögliche Kombination mitsendet.
- Die Zeit und der Landewert sind `readonly` statt `disabled`, damit 0:00
  gespeichert wird und nach dem Neuladen noch dasteht.
- `diagnose.php` prüft jetzt die vier Spalten und meldet, wenn `scores.status`
  noch vorhanden ist.

## 1.9.6

- **Die Strafpunkte setzen sich jetzt zusammen, statt sich auszuschliessen.** Vorher
  hat jede Feststrafe Zeit und Landewert ersatzlos gestrichen, und die beiden
  schlossen einander aus: eine Aussenlandung kostete pauschal, egal welche Zeit
  geflogen wurde. Jetzt gilt:
  - Die **Zeitabweichung zählt immer** – auch bei einer Bruchlandung, denn der
    Flug hat eine Zeit und die wird gemessen.
  - Bei einer **Aussenlandung ist der Landewert null**: das Landen ausserhalb des
    Feldes ist gerade das Ereignis, ein zusätzlicher Landewert würde es doppelt
    bestrafen.
  - Bei einer **Bruchlandung zählt der Landewert dazu**, weil sie im Landefeld
    passieren kann.
  - Beim **Nichtantritt sind Zeit und Landewert null**, es wurde nicht geflogen.
  - Die drei **Feststrafen bleiben** und kommen dazu. Sie stehen weiterhin unter
    *Einstellungen → Strafpunkte* und sind je Verein einstellbar.
  - Der **Motor** ersetzt nichts mehr, er kommt zu allem dazu. Bisher hat er beim
    geflogenen Flug Zeit und Landewert ersetzt.
  - Bei mehreren Kästchen gewinnt: Bruchlandung, dann Aussenlandung, dann
    nicht angetreten. Wie bisher.
- **Wichtig für bereits erfasste Resultate:** Die neuen Punkte gelten erst ab dem
  nächsten Speichern. Unter *Durchgänge → Punkte neu berechnen* werden sie für
  einen ganzen Durchgang neu gerechnet – aber nur aus dem, was gespeichert ist.
  Resultate, die vor Fassung 1.9.5 als Aussenlandung, Bruchlandung oder
  Nichtantritt ohne Zeit erfasst wurden, enthalten keine Flugzeit, und die kann
  niemand nachrechnen. Sie müssen neu eingetragen werden, sonst bleibt die alte
  Punktesumme stehen.
- **Unverändert:** Bei Punktegleichheit im Wettbewerb zählt ein Flug mit Motor
  weiterhin nicht als gültiger Flug für den Vergleich der Flugzahl. Das ist eine
  andere Frage als die Wertung und wurde nicht angefasst.
- Neu: `tools/regel_pruefen.php` vergleicht die Anzeige beim Erfassen mit der
  Datenbank, beide über dieselben 17 Fälle. Ohne diesen Abgleich fiel eine
  Abweichung erst beim Nachladen der Seite auf, und ein Wettbewerb sähe beim
  Speichern auf einmal anders aus. Braucht `node`.

## 1.9.5

- **Flugzeit und Landewert lassen sich immer eintragen**, auch wenn ein Kästchen
  gesetzt ist. Vorher wurden die beiden Felder gesperrt und der eingegebene Wert
  beim Speichern verworfen – wer eine Aussenlandung nach 3:20 Landewert 15 hatte,
  konnte beides nicht festhalten. Die Strafpunkte rechnen weiterhin nach der
  festen Regel; gespeichert wird der nachgemessene Flug jetzt trotzdem.
  Beim freien Flug bleibt die Flugzeit Pflicht, sonst gibt es nichts zu rechnen.
- **Der Wettbewerb aus der Adresse wird wieder beachtet**, und zwar überall.
  `anmeldung.php`, `index.php`, `teilnehmer.php` und `vereinswertung.php` zeigten
  bei `?competition=` immer den aktiven Wettbewerb. Ursache war, dass die
  Wettbewerbsauswahl die *Verwaltungs*prüfung benutzte, und die beantwortet für
  Besucher ohne Anmeldung immer mit „nein“. Wer zwei Wettbewerbe zur Anmeldung
  offen hatte, sah deshalb bei beiden denselben Anmeldetext, dieselbe Rangliste
  und dieselbe Teilnehmerliste – und ein Lesezeichen auf einen bestimmten
  Wettbewerb brachte nichts.
  Sichtbarkeit und Zugriff sind jetzt getrennt: jeder Wettbewerb ist öffentlich
  sichtbar, die Verwaltungsseiten fragen den Zugriff ausdrücklich ab und werden
  auf einen eigenen Wettbewerb umgeleitet, wenn sie den nicht führen dürfen.
  Geprüft mit zwei Vereinen, zwei Wettbewerben und einem Konto des einen Vereins:
  auf keiner Verwaltungsseite erscheint etwas vom Wettbewerb des anderen, auf den
  öffentlichen Seiten dagegen jeder Wettbewerb – angemeldet wie abgemeldet.

## 1.9.4

Der erste Schritt zum Regiocup. Die Regiorangliste selbst kommt erst mit
Fassung 2.0 – hier wird nur die Grundlage gelegt und erprobt.

- Beim Anlegen eines Wettbewerbs kann jetzt angeklickt werden, ob er zum
  Regiocup zählt. Auf der Wettbewerbsseite lässt sich das jederzeit umschalten
  (🏆 Regiocup / ○ Regiocup), zum Beispiel wenn sich ein Termin ändert oder ein
  Wettbewerb doch nicht zur Region gehört.
- Das Jahr für die Regiowertung kommt aus dem **Wettbewerbsdatum**, nicht aus
  dem Namen. Ein Wettbewerb namens „Erlencup 2027“ mit dem Datum 19.06.2026
  zählt also für 2026. Vorher wäre das beim Kopieren eines Wettbewerbs mit
  kopiert worden, und die beiden Wettbewerbe eines Jahres könnten auseinanderlaufen.
- Unter *Einstellungen → Regiocup* lässt sich der Verein wählen, dessen
  Mitglieder die Regiorangliste sehen und exportieren dürfen, auch wenn die
  Ergebnisse sonst nicht öffentlich sind. Der Verein steht als Einstellung
  `region_club_id`; fest im Code verankert wäre er beim nächsten Verein falsch.
- Die Rechenlogie der Regiowertung liegt fertig in `lib/region.php` und ist
  einzeln nachprüfbar: FIS-Punkte je Rang (1. = 100 … 30. = 1, danach 0), die
  besten vier von fünf Starts, der schlechteste Rang fällt weg. Sie verändert
  die Wertung der einzelnen Wettbewerbe nicht, sondern liest sie nur.
- Wie Gleichstände in der Regiowertung behandelt werden, ist an den echten
  Ranglisten von 2026 nachgemessen: In den drei Wettbewerben Erlencup,
  Bauschtu Cup und Wangen Cup standen 13 Platzierungen im Gleichstand – alle
  bekamen einen eigenen Rang. Die Wettbewerbswertung löst Gleichstände über das
  Streichresultat auf, deshalb bekommt in der Regioliste niemand einen doppelten
  Rang.
- Neue Migration 9 legt die Spalte `competitions.region` an. Sie ist
  wiederholbar und lässt sich auch nachholen, wenn die Wettbewerbsstruktur
  schon steht.
- Die Änderungsliste reicht jetzt bis 1.1.0 zurück und ist damit für jeden
  Altstand vollständig. Vorher fehlten die Fassungen 1.1.0 bis 1.9.0, wer von
  1.8.0 kam, hätte die Vereinszugehörigkeit nicht als Änderung gesehen.
- Fehlerbehebung: Die Änderungsliste zeigte jeden Punkt nur bis zum ersten
  Zeilenumbruch. Grund war, dass die Fortsetzungszeile an eine Kopie des
  Punktes angehängt wurde – PHP kopiert Arrays beim Zuweisen. Wer über eine
  längere Änderung nachlas, bekam einen abrupten Satz.
- Die Kontaktdaten der Piloten sind weg. Das Anmeldeformular speichert die
  Adresse seit längerem nicht mehr, aber die Spalten waren noch vorhanden, die
  Startliste führte sie als Spalte *Kontakt* weiter, und beim Freigeben einer
  Anmeldung wanderten die Angaben in den Wettbewerb. Jetzt gilt es durchgehend:
  - Migration 10 entfernt `pilots.email`, `pilots.phone`, `registrations.email`
    und `registrations.phone`, mitsamt der Altbestände aus dem früheren Formular.
    Das Löschen der Werte ist unwiderruflich.
  - Die Spalten *E-Mail* und *Telefon* sind aus dem Pilotenerfassungsformular
    und die Spalte *Kontakt* aus der Startliste und der Anmeldungsliste entfernt.
  - Die **Absenderadresse** bleibt: sie gehört dem Verein und wird für die
    Bestätigung gebraucht.
- Fehlerbehebung: Die Migration für den Regiocup war in der Liste der
  Migrationen ohne Parameter aufgerufen. Bei einer Installation aus der
  Saisonszeit wäre das Update mit einem Absturz abgebrochen – dieser Weg ist nur
  bei sehr alten Installationen beschritten worden und deshalb nicht aufgefallen.
- **Startnummern neu vergeben** vergibt wieder eine lückenlose Reihe. Aus drei
  Piloten wurden 02, 04 und 05, weil jede alte Nummer eines noch nicht
  umnummerierten Piloten die Zählung nach vorn schob. An sieben Fällen geprüft,
  darunter drei und sechs Piloten sowie Nummern über 99.
  - Nummern, die an **inaktiven** Piloten hängen, bleiben gesperrt: der
    eindeutige Index über Wettbewerb und Startnummer lässt keine Doppelung zu.
    Entsteht dadurch eine Lücke, nennt die Meldung jetzt die betroffene Nummer,
    den Piloten und den Weg, sie freizugeben. Vorher war die Lücke stumm.
  - Die Nummern werden in zwei Schritten gesetzt: erst leer, dann neu. Ein
    einzelnes Überschreiben wäre mitten in der Runde doppelt belegt. Die
    Vergabe nutzt die Transaktion, die ohnehin den ganzen Vorgang umschliesst –
    eine eigene darum herum lehnte PDO ab, und die Vergabe lief ins Leere.

## 1.9.3

- Die Vereinsliste ist schmaler: Die Spaltenüberschrift heisst nur noch
  „Piloten" statt den ganzen Wettbewerbsnamen zu wiederholen.
- Der Knopf **Löschen** bei den Vereinen ist nicht mehr gesperrt und trägt
  keinen Infotext mehr. Wer ihn drückt, bekommt die genauen Anzahlen der
  Verknüpfungen und den Weg, der aufräumt – je Verein anders. Vorher stand
  jeder Verein mit seinen Piloten und Konten im Raster, auch wenn niemand
  etwas zu tun hatte.
- Das Nachprüfen der Verwendungen kostet 28 Abfragen pro Seitenaufruf
  weniger.

## 1.9.2

- Wenn GitHub eine veraltete Bestandsliste und zugleich ein frisches Archiv
  liefert, meldet die Aktualisierungsseite das jetzt ausdrücklich, statt an
  einer scheinbar unbeteiligten Datei zu scheitern. Vorher wurde der
  Widerspruch nur an der Fassungsnummer erkannt.

## 1.9.1

- Startnummern neu vergeben und CSV-Export stehen jetzt dort, wo sie hingehören:
  unmittelbar über der Startliste in *Piloten*, statt weit oben im Seitenkopf.
- Die Startliste lässt sich als CSV holen (Startnummer, Name, Verein, Modelltyp,
  Modell) – gedacht zum Drucken von Aufklebern. Die erste Zeile nennt den
  Wettbewerb, damit mehrere Bogen auseinanderzuhalten sind.
- Beim Anlegen eines Wettbewerbs wählt der SuperAdmin den Veranstalter aus.
  Alle anderen Konten bekommen automatisch ihren eigenen Verein und können
  daran nichts ändern.
- Diese Aktualisierungsseite zeigt jetzt die Änderungsliste statt einer
  Aufzählung der ausgetauschten Dateien.
- Die Anmeldeseite erklärt, was die Sitzung ist. Es wird kein
  Einwilligungs-Banner eingeblendet, weil die Anwendung kein Tracking-Cookie
  setzt und nichts von fremden Servern lädt – es gibt nichts einwilligen.
- Ein Verein lässt sich nur noch löschen, wenn nirgends mehr etwas an ihm
  hängt. Geprüft werden Piloten, Anmeldungen, Wettbewerbe als Veranstalter und
  Konten; vorher wurden die Piloten beim Löschen still verwaist.
- Das Update bringt `install.php` und `config.sample.php` nicht mehr mit. Beide
  gehören zur Ersteinrichtung und werden auf einem betriebenen Server nicht
  gebraucht. Im Repository bleiben sie, damit eine frische Installation
  weiterhin gelingt.

## 1.9.0

- Vereinszugehörigkeit: Ein Konto kann einem Verein zugeordnet werden, und ein
  neu angelegter Wettbewerb gehört automatisch dem Verein dessen Erstellers.
  Nur Benutzer des gleichen Vereins steuern und bearbeiten einen Wettbewerb.
  SuperAdmins sehen und ändern weiterhin alles.
- Die Anmeldung bleibt unverändert öffentlich: sie nimmt zu jedem Wettbewerb
  Anmeldungen an. Die Sperre gilt allein der Verwaltung.
- Bestehende Wettbewerbe bleiben zunächst ohne Verein und sind für alle sichtbar,
  bis der SuperAdmin sie zuweist. Nichts wird dadurch ausgesperrt.
- Bruchlandung als eigener Ausgang mit eigener Feststrafe, einstellbar je Verein.
- Beim Erfassen entfällt das Auswahlfeld: die vier Ankreuzfelder des Laufzettels
  übernehmen die Ausgänge direkt. Kein Feld heißt „geflogen". Das Bemerkungsfeld
  ist weg.
- Fehlerbehebung: Nach dem Einspielen der Dateien, aber vor dem Lauf der
  Migrationen, wurden die Konten als abgemeldet behandelt. Damit war der Weg zur
  Aktualisierung der Datenbank versperrt.

## 1.8.0

- Die beiden Werkzeuge `tools/manifest.php` und `tools/aufrufe_pruefen.php` sind
  im README beschrieben: wozu sie dienen und warum sie nur auf der Kommandozeile
  laufen. Vorher standen sie nur als Dateinamen im Verzeichnis.

## 1.7.0

- Die Werkzeuge unter `tools/` weisen sich jetzt mit 403 ab, wenn sie vom Browser
  aus aufgerufen werden. Sie lesen und schreiben in `manifest.json` und gehören
  nicht in eine Webanfrage.

## 1.6.0

- Die Sperrdatei wird nicht mehr in jedes Unterverzeichnis gelegt, sondern nur
  dorthin, wo sie gebraucht wird. Vorher entstanden bei jedem Update zusätzliche
  Dateien, die in der Bestandsliste mitschlleppten.

## 1.5.0

- Ein Absturz beim Aktualisieren wurde behoben und die Fehlerklasse abgesichert:
  ein HTTP-Antwortcode, den GitHub nicht liefert, gilt jetzt als Fehler und nicht
  mehr als leere Antwort.
- `tools/aufrufe_pruefen.php` ist neu. Es findet Aufrufe von Namen, die es weder
  im Projekt noch in PHP gibt, und Aufrufe über eine Variable, der im File nie
  etwas zugewiesen wird. Beides sieht beim Lesen korrekt aus und `php -l` meldet
  nichts – es scheitert erst zur Laufzeit.

## 1.4.0

- Wenn GitHub eine veraltete Bestandsliste liefert, benennt die Aktualisierungsseite
  das jetzt ausdrücklich, statt an einer scheinbar unbeteiligten Datei zu scheitern.

## 1.3.0

- Die Bestandsliste scheitert nicht mehr an geschützten Dateien wie `config.php`
  oder dem Vereinslogo. Sie werden gezählt, aber nicht zum Ersetzen vorgemerkt.
- Die Aktualisierungsseite sammelt die Antwort von GitHub selbst und zeigt sie im
  Selbsttest an, statt sie zu erraten. Dadurch schlägt der Selbsttest schneller
  und genauer fehl.

## 1.2.0

- Jede geschriebene Datei wird nach dem Einspielen auch auf Syntaxfehler geprüft.
  Ein halb geschriebenes Skript fällt so sofort auf und nicht erst, wenn der
  Wettbewerbstag eine Seite aufruft.

## 1.1.0

- Die Aktualisierung von GitHub für den SuperAdmin. `admin/aktualisieren.php`
  holt die ver öffentlichte Fassung, vergleicht sie mit dem Serverstand und
  spielt nur die Dateien ein, die sich geändert haben.
- Jede Datei wird mit einer Prüfsumme geführt (`manifest.json`). Eine Datei, die
  jemand von Hand angefasst hat, bleibt beim Update stehen und wird gemeldet,
  statt stillschweigend überschrieben zu werden.
- `lib/version.php` hält `APP_VERSION`, `APP_REPO` und `APP_BRANCH` an einer Stelle.
- `tools/manifest.php` erzeugt die Bestandsliste samt Prüfsummen.
