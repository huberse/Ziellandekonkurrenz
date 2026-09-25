# Segelflug-Wettbewerb

Erfassung und Auswertung eines Wettbewerbs, bei dem Modellsegelflugzeuge eine Zielzeit treffen
und auf den Punkt landen sollen. PHP und MySQL, ohne Framework und ohne externe Bibliotheken.

Die Zeitnehmer schreiben wie bisher auf Papier. Die App druckt die Laufzettel dafür aus und
nimmt die Zahlen danach im Wettkampfbüro auf. Mehrere Wettbewerbe eines Vereins oder mehrerer
Vereine können in derselben Installation geführt werden; die Wettbewerbe bleiben vollständig
getrennt.

## Installation

1. Dateien auf den Webserver laden (PHP 7.3 oder neuer, MySQL 5.7 / MariaDB 10.3 oder neuer).
2. Leere Datenbank anlegen, Zeichensatz `utf8mb4`.
3. `config.sample.php` nach `config.php` kopieren, Zugangsdaten eintragen und unter
   `site_name` den Namen der Plattform sowie unter `logo` die Logo-Datei aus `assets/` eintragen.
4. Im Browser `install.php` aufrufen. Das legt die Tabellen, den ersten Wettbewerb, die
   Durchgänge und das erste Konto an.
5. `install.php` vom Server löschen.

`site_name` und `logo` gehören zur Installation und nicht zu einem Wettbewerb: Oben links im
Kopf steht der Name der Plattform, der ausgewählte Wettbewerb daneben im Abzeichen – jeder
Wettbewerb bekommt seinen Namen also nur einmal zu sehen. Fehlen die beiden Angaben in einer
älteren `config.php`, gelten `Ziellandekonkurrenz` und `logo_nordwest.jpg`.

Die ersten Einstellungen werden für den ersten Wettbewerb gespeichert. Weitere Wettbewerbe
können danach unter **Wettbewerbe** angelegt werden.

Wer schon eine ältere Version dieser App im Einsatz hatte: siehe **Aktualisieren** unten,
statt `install.php` zu benutzen.

### Bei Problemen: diagnose.php

Zeigt eine Seite einen 500-Fehler, `diagnose.php` hochladen und im Browser aufrufen. Es prüft
PHP-Version, nötige Erweiterungen (vor allem `pdo_mysql`), Dateirechte, die Datenbankverbindung
und ob alle Tabellen vorhanden sind. Für die eigentliche Fehlermeldung mit Datei und Zeile hilft
oft nur das PHP-Fehlerprotokoll des Hosters. Danach die Diagnosedatei löschen.

## Aktualisieren einer bestehenden Installation

Neue Dateien hochladen (bestehende überschreiben), `config.php` nicht anfassen und vorher ein
Datenbank-Backup erstellen. Danach einmal `upgrade.php` im Browser aufrufen und **Jetzt
aktualisieren** klicken.

Die Migrationen laufen versioniert und werden erst nach erfolgreicher Ausführung in
`schema_migrations` protokolliert. Ein abgebrochener Lauf kann nach Beheben der gemeldeten
Datenprobleme erneut gestartet werden. Die Wettbewerbs-Migrationen:

- benennt `seasons` in `competitions` um,
- benennt alle `season_id`-Spalten in `competition_id` um,
- übernimmt bestehende Wettbewerbe, Piloten, Durchgänge, Anmeldungen und Results,
- legt `competition_settings` an und kopiert die bisherigen Einstellungen in jeden Wettbewerb,
- normalisiert die alten Index- und Foreign-Key-Namen,
- ergänzt die Zuordnung von Anmeldungen zu Piloten,
- ergänzt den Abschlussstatus und die benötigten Resultatfelder für Wettbewerbe,
- lässt den bisherigen aktiven Wettbewerb aktiv,
- ergänzt als Migration 5 das Kennzeichen `scores.motor` und die neuen Strafpunktregeln.

Nach dem Upload muss für die bestehende Installation einmal `upgrade.php` laufen; die Abschluss-Spalte
wird als Migration 4, die Motorstrafe als Migration 5 ergänzt. Danach sollte `MAX(version)` in
`schema_migrations` mindestens `5` sein.

Migration 5 rechnet die bisherigen Strafpunkte auf das neue Format um, damit erfasste Resultate
gültig bleiben: aus den getrennten Sätzen für „zu lang" und „zu kurz" wird ein gemeinsamer Satz
(bei unterschiedlichen Werten wird der höhere übernommen und die Änderung im Protokoll
ausgewiesen), und der bisherige Sammelwert für „Aussenlandung oder fehlendes Resultat" wird für
Aussenlandung, Nichtantritt und Motorstart übernommen. Die Obergrenzen für Zeit- und Landestrafe
entfallen; sie standen überall auf 0 und haben nie gewirkt, deshalb ändert sich kein
bereits gespeichertes Resultat. **Danach die Werte unter Einstellungen → Strafpunkte kurz prüfen**
– jeder Verein sollte seine eigenen Zahlen setzen, insbesondere den Betrag für den Motorstart.

Die alten Spalten und Tabellen werden danach nicht mehr von der Anwendung verwendet. Die
Dateien `admin/saisons.php` und `lib/season.php` bleiben nur als Kompatibilitätspfade für alte
Lesezeichen bzw. Erweiterungen erhalten. Neue URLs verwenden `competition`.

Ruft man eine Seite auf, bevor `upgrade.php` gelaufen ist, leitet die App automatisch dorthin um,
statt einen Datenbankfehler zu zeigen.

## Wettbewerbe

Unter **Wettbewerbe** wird ein Wettbewerb mit einem aussagekräftigen Namen angelegt, zum Beispiel:

- `MFV Brislach - Schwarzbubenfliegen 2027`
- `MG Breitenbach - Erlencup 2027`

Ein neuer Wettbewerb erhält eigene Durchgänge, eine leere Startliste und eigene Einstellungen.
Er kann sofort aktiviert werden. Der aktive Wettbewerb ist der Default für Erfassung,
Anmeldung, Export und die öffentlichen Seiten. Ältere Wettbewerbe bleiben vollständig erhalten;
der Name lässt sich direkt in der Wettbewerbsliste ändern. Ein Wettbewerb mit Anmeldungen oder
Resultaten wird aus Sicherheitsgründen nicht gelöscht; abgeschlossene Wettbewerbe bleiben als Archiv erhalten.

Sobald für jeden aktiven Piloten in jedem gewerteten Durchgang ein Resultat vorhanden ist – einschliesslich
Aussenlandung oder „nicht gestartet“ –, kann der Wettbewerb unter **Wettbewerbe** beendet werden.
Nicht gewertete Durchgänge müssen dafür nicht ausgefüllt werden.
Danach bleiben Startliste, Resultate, PDF und Export sichtbar; Resultate, Anmeldungen, Wettbewerbs-
einstellungen und die Aktivierung sind gesperrt. Der bisherige aktive Wettbewerb bleibt aktiv.
Der Wettbewerbsname bleibt auch nach dem Abschluss änderbar. Bei einem Korrekturfehler kann ein Admin ihn ausdrücklich wieder öffnen.

Die öffentlichen Seiten bieten bei mehreren Wettbewerben eine Auswahl. Ein gezielter Aufruf
funktioniert zum Beispiel mit `index.php?competition=2`. Im Wettkampfbüro steht dieselbe Auswahl
unter **Piloten** und **Einstellungen**; Durchgänge, Resultate, Export und PDF-Laufzettel
arbeiten mit dem aktiven Wettbewerb. Die alte Form `?season=...` wird beim Lesen noch akzeptiert.

Die Auswahl ist ein Dropdown und gruppiert nach **Offene Wettbewerbe** und **Abgeschlossene
Wettbewerbe**. Der aktive Wettbewerb steht oben, dahinter die neuesten; hinter dem Namen stehen
Zustand und Anzahl der Piloten. Damit bleibt die Auswahl auch lesbar, wenn viele Vereine über
die Jahre je einen Wettbewerb anlegen.

Aktuell gibt es **noch kein Mandantenmodell**: Benutzer, Vereine und Modelltypen sind global.
Vereins- und Modelltypennamen können deshalb weiterhin global gepflegt werden; destruktive Änderungen
an Kategorien, die in abgeschlossenen Wettbewerben verwendet werden, werden blockiert.
Die Wettbewerbe sind aber datenseitig getrennt. Wenn später mehrere Vereine mit getrennten
Konten und strikter Zugriffstrennung arbeiten sollen, kann ein Mandantenmodell auf dieser
Wettbewerbsstruktur aufgebaut werden, ohne die Wettbewerbsdaten neu zu modellieren.

## Einstellungen

Unter **Einstellungen** werden die Einstellungen des ausgewählten Wettbewerbs bearbeitet:

- Name, Datum und Ort,
- Standard-Zielzeit und Anzahl der Durchgänge,
- Strafpunkte: die zwei Faktoren für einen gelungenen Flug und die drei festen Beträge für
  Aussenlandung, Nichtantritt und Motorstart, jeweils mit kurzer Erklärung und einem
  Rechenbeispiel darunter,
- Streichresultat und Ranglisten-Ansicht,
- Öffentlichkeit der Resultate,
- Vereinswertung und Anmeldeformular.

Beim Anlegen eines neuen Wettbewerbs werden die Einstellungen des aktuell ausgewählten
Wettbewerbs als Vorlage kopiert. So können zwei Vereinswettbewerbe unterschiedliche Regeln
haben, ohne die bestehenden Wettbewerbe zu verändern. Ausgenommen ist die Absenderadresse der
Anmeldebestätigung: sie gehört jedem Verein selbst und wird bewusst nicht vererbt.

## Ablauf an einem Wettbewerbstag

1. **Einstellungen** – Name, Datum, Ort und Strafpunkt-Regeln prüfen.
2. **Modelltypen** – Segler, Elektro, weitere. Die Rangliste wird je Modelltyp ausgewertet.
3. **Vereine** – alle teilnehmenden Vereine, für Auswahl und Vereinswertung.
4. **Piloten** – nur für den ausgewählten Wettbewerb. Einzeln erfassen, als Liste aus Excel
   einfügen oder über Anmeldungen freigeben. `Startnummern zufällig neu vergeben` nummeriert
   alle aktiven Piloten dieses Wettbewerbs nach Modelltyp und Zufall neu.
5. **Durchgänge** – Anzahl einstellen, Zielzeit je Durchgang anpassen und die Wertung aktivieren.
6. **Laufzettel als PDF** – je Durchgang zwei A4-Seiten: eine für ungerade und eine für gerade
   Startnummern. Jede Seite enthält alle Piloten des jeweiligen Zeitnehmers sowie Flugzeit,
   Landewert, drei Ankreuzfelder (nicht angetreten, Aussenlandung, Motor angelassen) und eine
   Übergabequittung. `admin/erfassung.php` bietet den direkten PDF-Download an; die HTML-Ansicht
   ist nur noch ein Druck-Fallback.
7. **Resultate erfassen** – ein Durchgang pro Seite, eine Zeile pro Pilot. Flugzeit als `2:58`
   oder `178`; die Wertung nennt geflogen, nicht angetreten, Aussenlandung, Motor angelassen
   und Aussenlandung & Motor angelassen. Die Strafpunkte stehen live in der letzten Spalte.
8. **Rangliste** – öffentlich unter `index.php`, je Modelltyp oder alle zusammen.
9. **Vereinswertung** – öffentlich unter `vereinswertung.php`.
10. **Wettbewerb beenden** – auf der Seite **Wettbewerbe** steht der Fortschritt als Balken in der
    Karte des Wettbewerbs. Sobald alle Resultate erfasst sind, erscheint dort **Beenden**; danach
    bleibt der Wettbewerb als Archiv erhalten und lässt sich mit **Wieder öffnen** zurückholen.
    Ein beendeter Wettbewerb meldet auf der Übersicht auch keinen laufenden Durchgang mehr – dort
    steht „Wettbewerb beendet, es läuft kein Durchgang“.

### Wettbewerbe-Seite

Oben steht das Formular **Neuen Wettbewerb anlegen**, darunter eine Karte je Wettbewerb – auf
einer Bildschirmbreite neben- statt untereinander, sodass nichts vertikal durchlaufen werden muss.
Jede Karte zeigt Zustand (`aktiv`, `offen`, `beendet`), Anzahl der Durchgänge und offene
Anmeldungen, den Fortschritt der Resultate sowie die Knöpfe **Erfassen**, **Durchgänge**,
**Rangliste** und je nach Zustand **Beenden**, **Aktivieren**, **Wieder öffnen** oder **Löschen**.
Der Name ist direkt in der Karte änderbar; das Feld sieht dabei wie eine Überschrift aus und der
Knopf **Speichern** erscheint erst, wenn der Cursor darin ist.

## Wertung

Wenige Punkte sind gut. Die Regeln stehen pro Wettbewerb unter **Einstellungen → Strafpunkte**,
damit jeder Verein seine eigenen Zahlen haben kann. Es gibt genau fünf Bausteine:

| Baustein | Einstellung | Bedeutung |
| --- | --- | --- |
| Zeitabweichung | `penalty_per_second` | Punkte je Sekunde Abweichung von der Zielzeit |
| Landepunkte | `penalty_per_meter` | Punkte je Landewert-Einheit |
| Strafe Aussenlandung | `penalty_outlanding` | fester Betrag |
| Strafe nicht angetreten | `penalty_not_started` | fester Betrag |
| Strafe Motor angelassen | `penalty_motor` | fester Betrag, bei einer Aussenlandung zusätzlich |

Es gibt keine Obergrenze: eine grosse Zeitabweichung oder ein weit entfernter Landepunkt kostet
unbegrenzt Punkte. Die festen Strafen wirken ohnehin als Gesamtbetrag.

**Die Zeitabweichung ist ein Betrag.** Zwei Sekunden zu lang und zwei Sekunden zu kurz kosten
gleich viel, es gibt nur einen Satz je Sekunde und keine getrennten Werte für oben und unten.

**Der Motor ist eine Zusatzstrafe, keine eigene Ergebnisart.** Bei einem geflogenen Flug ersetzt
sie Zeit und Landewert – gezählt wird allein die Motorstrafe. Bei einer Aussenlandung oder einem
Nichtantritt kommt sie zur jeweiligen Feststrafe dazu.

Die fünf Ausgänge, die `admin/erfassung.php` anbietet:

| Auswahl | Punkte |
| --- | --- |
| geflogen | Zeitabweichung + Landepunkte |
| nicht angetreten | `penalty_not_started` |
| Aussenlandung | `penalty_outlanding` |
| Motor angelassen | `penalty_motor` |
| Aussenlandung & Motor angelassen | `penalty_outlanding` + `penalty_motor` |
| kein Eintrag | die Zeile bleibt leer |

Der Landewert ist eine Zahl: entweder die Distanz in Metern zum Landepunkt oder direkt eine
Punktzahl aus einer Landetabelle. Die App rechnet in beiden Fällen mit dem eingestellten Faktor.

Ein Durchgang gilt als geflogen, sobald mindestens ein Resultat erfasst ist. Piloten ohne
Resultat in einem geflogenen Durchgang erhalten die Punkte für einen Nichtantritt. Wer in keinem
Durchgang ein Resultat hat, erscheint ohne Rang am Listenende. Ein Flug mit Motor zählt nicht
als gültiger Flug für den Gleichstand-Entscheid.

Das Streichresultat lässt sich pro Wettbewerb abschalten und die Schwelle einstellen. Bei
Punktegleichheit entscheidet zuerst das kleinere Streichresultat, danach die Anzahl gültiger
Flüge, danach das beste Einzelresultat.

Wird eine Regel oder eine Zielzeit nachträglich geändert, bleiben bereits gespeicherte Punkte
stehen. Unter **Durchgänge** rechnet `Punkte neu berechnen` einen Durchgang mit den aktuellen
Regeln des Wettbewerbs nach – auch die festen Strafen und der Motor-Flag werden dabei berücksichtigt.

### Laufzettel

Je Durchgang entstehen zwei A4-Blätter, eines für die ungeraden und eines für die geraden
Startnummern. Neben Flugzeit und Landewert hat jede Zeile **drei Ankreuzfelder**:

- **nicht angetreten** – der Pilot ist nicht angetreten
- **Aussenlandung**
- **Motor angelassen** – bei einem elektrischen Modell wurde der Motor angelassen

Kein Feld angekreuzt heisst „geflogen". Aussenlandung und Motor zusammen ergeben
„Aussenlandung & Motor angelassen". Die Bezeichnungen stehen in der Kopfzeile, die Punkte für jedes
Feld in der Legende darunter, damit der Zeitnehmer sie nicht nachschlagen muss.

`admin/laufzettel.php` bietet den PDF-Download an; die HTML-Ansicht ist der Druck-Fallback.

## Vereinswertung

Die besten Piloten eines Vereins ergeben zusammen das Vereinsresultat, der tiefste Wert gewinnt.
Wie viele Piloten zählen, wird pro Wettbewerb unter **Einstellungen** eingestellt. Ein Verein
mit weniger gewerteten Piloten erscheint ausser Konkurrenz am Listenende. Für den Verein zählt
nur, wer mindestens ein Resultat hat.

## Anmeldung

`anmeldung.php` ist das öffentliche Formular. Eingegangene Anmeldungen erscheinen unter
**Anmeldungen** und wandern beim Freigeben mit einer Startnummer in die Startliste des jeweiligen
Wettbewerbs. Das Formular lässt sich pro Wettbewerb schliessen und mit einem eigenen Text versehen.

Die Auswahl oben im Formular zeigt nur Wettbewerbe, die noch nicht beendet sind. Beendete
Wettbewerbe nehmen keine Anmeldungen mehr an und stehen dort nicht mehr zur Wahl; ein altes
Lesezeichen führt weiterhin zur Abschluss-Seite.

Das Formular fragt Vorname, Name, Verein, E-Mail, Modelltyp, Modell und Bemerkung ab – ein
Telefonfeld gibt es nicht. Die E-Mail-Adresse wird **nicht gespeichert**: sie dient ausschliesslich
der Anmeldebestätigung. Verschickt wird diese erst, nachdem die Anmeldung gespeichert und
anschliessend wieder aus der Datenbank gelesen wurde – eine Bestätigung ohne Eintrag kann es
dadurch nicht geben. Die Adresse dafür steht pro Wettbewerb unter **Einstellungen → Anmeldung**
(Absendername und Absenderadresse). Ohne Absenderadresse wird nichts verschickt; die Anmeldung
selbst geht trotzdem ein. Der Versand nutzt die Mail-Funktion von PHP, der Server muss sie also
unterstützen.

Die Absenderadresse steht zugleich im **CC** der Bestätigung. Damit geht jede neue Anmeldung im
Postfach der Wettkampfleitung ein, ohne dass eine Adresse gespeichert werden muss: Der Pilot
bekommt seine Bestätigung, das Wettkampfbüro sieht dieselbe Mail und weiss so, dass etwas
eingegangen ist. Meldet sich jemand mit exakt dieser Adresse an, entfällt das CC, damit er die
Mail nicht doppelt bekommt.

Nach dem Absenden leitet die Seite auf eine eigene Bestätigungsseite um. Ein Reload oder ein
zweiter Klick sendet deshalb keine weitere Anmeldung ab.

## Dateien

    index.php              öffentliche Rangliste
    vereinswertung.php     öffentliche Vereinswertung
    teilnehmer.php         öffentliche Teilnehmerliste
    anmeldung.php          öffentliches Anmeldeformular
    install.php            Einrichtung, danach löschen
    upgrade.php            Aktualisierung einer älteren Installation, danach löschen
    diagnose.php           Fehlersuche bei 500-Fehlern, danach löschen
    admin/wettbewerbe.php  Wettbewerbe verwalten
    lib/competition.php    Wettbewerbe und Kontext der Einstellungen
    lib/mail.php           Anmeldebestätigung per Mail
    lib/scoring.php        Wertung und Ranglisten
    lib/pdf.php            einfacher, abhängigkeitsfreier PDF-Generator
    lib/runsheet_pdf.php   A4-Laufzettel als PDF
    sql/schema.sql         Tabellen und aktuelle Constraints
    assets/                Gestaltung, Logos (Auswahl über `logo` in config.php)

## Design und Sicherheit

Name und Logo der Plattform stehen in `config.php` (`site_name`, `logo`); das Logo wird als
rundes Abzeichen neben dem Namen dargestellt. Farben und Anordnung stehen in
`assets/style.css`, das Logo selbst in `assets/`.

Alle Bedienelemente teilen sich drei Höhen, damit Knöpfe, Eingabefelder und Auswahllisten in
einer Zeile bündig stehen:

| Grösse | Wert | Wofür |
| --- | --- | --- |
| `--ctl-h` | 44 px | normale Bedienelemente |
| `--ctl-h-sm` | 32 px | kompakt, in dichten Tabellen und Werkzeugleisten |
| `--ctl-h-lg` | 52 px | grosse Hauptaktion wie „Anmeldung senden“ |

Die Markierung für die kompakte Grösse sitzt auf dem **Bereich** (`class="dense"`), nicht auf
dem einzelnen Element. `.dense` verkleinert alles darin, so kann eine Zeile nicht halb kompakt
und halb normal sein. Für einzelne Knöpfe gibt es weiterhin `.btn.small`. Wer eine neue Zeile
mit Bedienelementen baut, setzt also `dense` auf den Container und lässt die Kinder ohne
zusätzliche Klasse – dann sind sie automatisch gleich hoch.

Für Zeilen, in denen eine **Beschriftung, ein Feld und eine Erklärung** nebeneinander stehen,
gibt es `.rule`. Die Spalten sind fest (11 rem / 5.5 rem / Rest), damit alle Zahlen in einer
Linie stehen, und die Ausrichtung ist `center` statt `baseline` – die Grundlinie eines
`<input>` ist seine Unterkante, mit `baseline` stünde die Beschriftung sichtbar zu tief. Unter
1100 px Fensterbreite rutscht die Erklärung unter die Beschriftung. Beschriftung und Erklärung
folgen dem Muster „Beschriftung nennt was, Erklärung nennt in welcher Einheit“.

Login mit gehashtem Passwort, CSRF-Token auf allen Formularen (auch beim Abmelden), Prepared
Statements und maskierte Ausgabe. `config.php`, `lib/` und `sql/` gehören nicht in ein
öffentlich erreichbares Verzeichnis. Auf einem öffentlichen Server gehört HTTPS davor.
