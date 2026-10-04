# Segelflug-Wettbewerb

Wertung und Ergebnisverwaltung für Modellsegelflug-Wettbewerbe: Zielzeit treffen, auf den Punkt
landen, und daraus eine Rangliste, die alle nachvollziehen können.

**PHP und MySQL, ohne Framework, ohne Composer, ohne externe Bibliotheken.** Die Dateien werden
auf einen Webserver gelegt und sind danach lauffähig.

Die Zeitnehmer schreiben wie bisher auf Papier. Die App druckt dafür die Laufzettel aus und
nimmt die Zahlen danach im Wettkampfbüro auf. Mehrere Wettbewerbe eines Vereins oder mehrerer
Vereine laufen in derselben Installation; die Wettbewerbe bleiben vollständig getrennt.

---

## Inhalt

- [Was die App kann](#was-die-app-kann)
- [Anforderungen](#anforderungen)
- [Installation](#installation)
- [Aktualisieren einer bestehenden Installation](#aktualisieren-einer-bestehenden-installation)
- [Aktualisierung von GitHub](#aktualisierung-von-github)
- [Ablauf an einem Wettbewerbstag](#ablauf-an-einem-wettbewerbstag)
- [Die Startseite](#die-startseite)
- [Benutzer und Rechte](#benutzer-und-rechte)
- [Anmelden und das eigene Profil](#anmelden-und-das-eigene-profil)
- [Wertung](#wertung)
- [Einstellungen](#einstellungen)
- [Wettbewerbe](#wettbewerbe)
- [Anmeldung](#anmeldung)
- [Vereinswertung](#vereinswertung)
- [Aufbau des Projekts](#aufbau-des-projekts)
- [Gestaltung](#gestaltung)
- [Sicherheit und Betrieb](#sicherheit-und-betrieb)
- [Fehlersuche](#fehlersuche)
- [Lizenz](#lizenz)

---

## Was die App kann

- **Öffentliche Seiten** ohne Konto: Startseite mit Wettbewerbswahl und kurzer Erklärung,
  Rangliste je Modelltyp, Vereinswertung, Teilnehmerliste, Anmeldeformular.
- **Wettkampfbüro** mit Login, erreichbar die Erfassung, Startliste, Durchgänge, Vereine,
  Modelltypen, Anmeldungen, Export und Einstellungen.
- **Benutzerverwaltung** mit zwei Rollen: alle steuern den ganzen Wettbewerb, nur der SuperAdmin
  verwaltet die Konten.
- **Laufzettel als PDF**, je Durchgang zwei A4-Blätter für die geraden und die ungeraden
  Startnummern, mit Ankreuzfeldern für jede mögliche Abweichung.
- **Eigene Strafpunktregeln je Wettbewerb** – fünf Bausteine, pro Verein einstellbar.
- **Anmeldung im Internet** mit Bestätigung per E-Mail, ohne dass eine Adresse gespeichert wird.
- **Wettbewerbe über die Jahre**, abgeschlossen bleibt alles als Archiv erhalten und
  jederzeit wieder zu öffnen.

## Anforderungen

| | |
| --- | --- |
| PHP | 7.3 oder neuer, mit `pdo_mysql` und `mbstring` |
| Datenbank | MySQL 5.7 oder MariaDB 10.3 oder neuer, `utf8mb4` |
| Webserver | Apache mit `mod_rewrite` (siehe `.htaccess`) oder nginx |
| Sonstiges | nichts – kein Composer, kein Build-Schritt, keine externen Schriften oder Skripte |

Die Oberfläche lädt keine externen Ressourcen. Die App läuft damit auch dann, wenn am
Wettkampfort kein Internet zur Verfügung steht.

## Installation

1. **Datenbank anlegen.** Leere Datenbank mit Zeichensatz `utf8mb4` anlegen und einen Benutzer
   mit Rechten darauf einrichten.

2. **`config.php` anlegen.** `config.sample.php` nach `config.php` kopieren und die
   Zugangsdaten eintragen:

   ```php
   return [
       'db_host' => 'localhost',
       'db_name' => 'segelflug',
       'db_user' => 'segelflug',
       'db_pass' => 'geheim',
       'db_port' => 3306,
       'timezone' => 'Europe/Zurich',
       'site_name' => 'Ziellandekonkurrenz',
       'logo'      => 'logo_nordwest.jpg',   // Datei in assets/
   ];
   ```

   `site_name` und `logo` gehören zur Installation und nicht zu einem Wettbewerb. Oben links im
   Kopf steht der Name der Plattform, der ausgewählte Wettbewerb daneben im Abzeichen – jeder
   Wettbewerb bekommt seinen Namen also nur einmal zu sehen. Fehlen die beiden Angaben in einer
   älteren `config.php`, gelten `Ziellandekonkurrenz` und `logo_nordwest.jpg`.

3. **`install.php` aufrufen.** Die Dateien hochladen und `install.php` im Browser öffnen. Das legt
   die Tabellen, den ersten Wettbewerb, die Durchgänge und das erste Konto an.

4. **`install.php` löschen.** Die Datei wird nach der Einrichtung nicht mehr gebraucht und
   gehört nicht auf einen Server im Internet.

Falls eine Seite mit einem 500-Fehler antwortet, hilft [`diagnose.php`](#fehlersuche).

Wer bereits eine ältere Version dieser App im Einsatz hatte, nimmt statt `install.php` den Weg
über [Aktualisieren](#aktualisieren-einer-bestehenden-installation) – sonst gingen die
bisherigen Daten verloren.

## Aktualisieren einer bestehenden Installation

Es gibt zwei Wege. Der bequemere ist der Knopf **Aktualisierung** im Wettkampfbüro; der
handfestere ist das Hochladen der Dateien.

### Über den Aktualisierungs-Knopf

Nur der SuperAdmin sieht den Punkt. Er vergleicht den Serverstand mit dem Repository und
schreibt nur, was hier unverändert ist – siehe [Aktualisierung von GitHub](#aktualisierung-von-github).

### Durch Hochladen der Dateien

Neue Dateien hochladen und bestehende überschreiben. **`config.php` nicht anfassen.** Vorher ein
Datenbank-Backup erstellen. Danach einmal `upgrade.php` aufrufen und **Jetzt aktualisieren**
klicken, anschliessend `upgrade.php` löschen.

Die Migrationen laufen versioniert und werden erst nach erfolgreicher Ausführung in
`schema_migrations` protokolliert. Ein abgebrochener Lauf kann nach Beheben der gemeldeten
Datenprobleme erneut gestartet werden.

| Version | Was passiert |
| --- | --- |
| 1 | altes Schema auf Vereine, Modelltypen und Wettbewerbe vorbereiten |
| 2 | Wettbewerbs- und Startnummern-Constraints für Piloten und Resultate ergänzen |
| 3 | Saisons in Wettbewerbe umbenennen, Einstellungen pro Wettbewerb speichern |
| 4 | Abschlussstatus und Resultatfelder ergänzen |
| 5 | Strafpunktregeln je Wettbewerb und das Kennzeichen für den Motorstart |
| 6 | Benutzerrechte: SuperAdmin für die Benutzerverwaltung, Konten sperren |

Danach sollte `MAX(version)` in `schema_migrations` mindestens `6` sein. Ruft man eine Seite auf,
bevor `upgrade.php` gelaufen ist, leitet die App automatisch dorthin um, statt einen
Datenbankfehler zu zeigen.

**Nach Migration 5 die Strafpunkte kurz prüfen.** Die bisher getrennten Sätze für „zu lang" und
„zu kurz" werden zu einem gemeinsamen Satz zusammengefasst (bei unterschiedlichen Werten wird
der höhere übernommen und die Änderung im Protokoll ausgewiesen), und der bisherige Sammelwert
für „Aussenlandung oder fehlendes Resultat" wird für Aussenlandung, Nichtantritt und Motorstart
übernommen. Bereits gespeicherte Resultate bleiben gültig. Den Betrag für den Motorstart gab es
bisher nicht – er ist jetzt gesetzt und sollte je Verein angepasst werden, ebenso alle anderen
Werte unter **Einstellungen → Strafpunkte**.

**Migration 6 betrifft nur die Konten.** Bestehende Benutzer bleiben mit Passwort und
Anzeigename erhalten und dürfen weiterhin den gesamten Wettbewerb steuern. Neu ist allein die
Rolle: Das älteste Konto wird SuperAdmin, damit die Benutzerverwaltung erreichbar ist. Siehe
[Benutzer und Rechte](#benutzer-und-rechte).

**Migration 7 führt die Vereinszugehörigkeit ein.** Bestehende Konten und Wettbewerbe bleiben
zunächst ohne Verein (siehe [Der Veranstalter eines Wettbewerbs](#der-veranstalter-eines-wettbewerbs));
der SuperAdmin weist sie unter **Benutzer** zu. Neu angelegte Wettbewerbe gehören dem Verein
dessen, der sie angelegt hat; ab 1.9.1 wählt beim Anlegen allein der SuperAdmin den Veranstalter.

**Migration 8 ergänzt die Bruchlandung und vereinfacht das Erfassen.** Die Spalte `scores.status`
erhält den neuen Wert `crash`, und jeder Wettbewerb bekommt den Betrag `penalty_crash` – zuerst
übernommen aus dem bisherigen Sammelwert für „Aussenlandung oder fehlendes Resultat", also in der
Regel 100 Punkte. **Nach dem Update den Betrag unter Einstellungen → Strafpunkte je Verein
prüfen.** Bereits gespeicherte Resultate bleiben gültig und behalten ihre Punkte.

Die Dateien `admin/saisons.php` und `lib/season.php` bleiben nur als Kompatibilitätspfade für
alte Lesezeichen erhalten. Neue URLs verwenden `competition`.

## Aktualisierung von GitHub

Unter **Aktualisierung** prüft der SuperAdmin, ob im Repository eine neuere Fassung liegt, und
spielt sie mit einem Knopf ein. Nichts davon braucht FTP, git oder eine Shell.

### Was der Knopf ersetzt – und was nicht

`manifest.json` nennt jede ausgelieferte Datei mit ihrer Prüfsumme (SHA-256). Beim Update gilt
für jede einzelne Datei:

| Zustand auf dem Server | Was passiert |
| --- | --- |
| Datei fehlt | wird neu angelegt |
| Datei stimmt mit `manifest.json` überein | wird ersetzt |
| Datei wurde von Hand geändert | **bleibt stehen**, wird gemeldet |
| Datei steht in keiner Bestandsliste | **bleibt stehen**, wird gemeldet |

Eine von Hand geänderte Datei geht also nie verloren. Sie steht danach in der Liste der Dateien
für Handarbeit und lässt sich einzeln von GitHub holen.

Diese Dateien fasst der Knopf nie an: `config.php` (Zugangsdaten), `.htaccess` und `.gitignore`
(das gehören dem Server, nicht dem Programm) sowie `assets/logo*` (die Logos sind Eigenheiten des
Vereins). Ändern sie sich auf GitHub, erscheinen sie in der Liste der Dateien für Handarbeit.

### Was sich ändert

Statt 19 Dateipfaden zeigt die Seite **Was sich aendert** – einen Satz je Änderung, aus
`CHANGELOG.md` im Repository. Die Seite liest daraus genau die Abschnitte zwischen der installierten
und der angebotenen Fassung, ein Sprung über mehrere Versionen fasst also alles zusammen.

Gelesen wird absichtlich nur eine eigene, sehr einfache Form, damit aus der Liste kein HTML in die
Seite gelangen kann:

```markdown
## 1.9.1
- Punkt eins
  Fortsetzung mit zwei Leerzeichen eingerückt
- Punkt zwei
```

Die Fortsetzungszeile ist wichtig: ohne die zwei Leerzeichen wäre der Punkt beim ersten
Zeilenumbruch abgeschnitten. Eine Zeile ohne Aufzählungszeichen wird zu einem Absatz und
steht dann ohne Aufzählungszeichen da – so lassen sich einleitende Sätze schreiben, ohne
dass sie als Punkt erscheinen. Text vor der ersten `##`-Überschrift wird nicht gelesen und
eignet sich für Vorbemerkungen.

`## 1.9.1 – 27.09.2026` erlaubt zusätzlich ein Datum, das neben der Fassung steht. Fehlt die
Liste, passt sie nicht zum Sprung, oder ist GitHub nicht erreichbar, fällt die Seite auf die
Aufzählung der Dateien zurück – eine leere Anzeige gibt es nicht.

Die Liste reicht zurück bis **1.1.0**, weil dort die Aktualisierung dazukam. Wer von einem
sehr alten Stand kommt, sieht deshalb eine lange Liste. Das ist beabsichtigt: sonst ginge
die Vereinszugehörigkeit aus 1.9.0 unter, und wer sie nicht kennt, sperrt sich beim
Zuordnen der Wettbewerbe selbst aus.

Die Liste für **Dateien, die stehen bleiben**, bleibt bestehen: dort braucht der SuperAdmin den
Namen der Datei, die er von Hand übernehmen muss.

### Was der Knopf nicht mitbringt

Neben `config.php`, `.htaccess`, `.gitignore` und den Logos lässt der Knopf auch
`install.php` und `config.sample.php` stehen. Beide gehören zur **Ersteinrichtung**: die
Datenbank ist längst angelegt und `config.php` liegt längst ausgefüllt da. Vor einem Update
gehört eine Installationsdatei auf einen betriebenen Server nicht, und nach dem gelungenen Setup
soll sie ohnehin gelöscht werden.

Im Repository bleiben beide Dateien, damit eine frische Installation über das Hochladen der
Dateien weiterhin gelingt. Ändern sie sich auf GitHub, erscheinen sie in der Liste der Dateien
für Handarbeit – mit dem Hinweis, dass sie beim Update nicht mitgeliefert werden.

### Reihenfolge und Sicherung

1. Bestandsliste von GitHub holen und mit dem Server vergleichen. Die Seite zeigt vorher an, was
   passieren würde.
2. Die Datei mit der neuen Fassung holen, **alle** Inhalte gegen die Bestandsliste prüfen. Stimmt
   eine Prüfsumme nicht oder ist eine Datei kein gültiges PHP, wird **nichts** geschrieben.
3. Die zu ersetzenden Dateien in `.update/sicherung-<Zeitstempel>/` kopieren.
4. Dateien einsetzen, die Bestandsliste zuletzt schreiben.
5. Bei einem Fehler beim Einsetzen wird alles aus der Sicherung zurückgeholt.

Die Syntaxprüfung braucht kein `exec()`: `token_get_all($inhalt, TOKEN_PARSE)` parbt vollständig
und wirft bei einem Syntaxfehler einen `ParseError`, ohne den Code auszuführen.

Die Sicherungen bleiben liegen, damit sich ein Update mit **Neueste Sicherung zurückholen** wieder
rückgängig machen lässt. `.update/` steht in `.gitignore` und sperrt sich selbst gegen direkten
Abruf, sowohl über die Regel in `.htaccess` als auch über eine eigene Sperrdatei im Verzeichnis.

### Die Meldung schreibt immer die gerade installierte Fassung

Nach dem Aktualisieren zeigt die Seite, was passiert ist. Dieser Text entsteht
**beim nächsten Aufruf**, nicht im Aufruf, der die Dateien schreibt. Grund:
PHP lädt die Seite beim Programmstart in den Speicher – eine Seite, die gerade
`admin/aktualisieren.php` ersetzt hat, läuft in diesem Request noch mit der alten
Fassung. Eine Korrektur an der Meldung kann sich auf diese Weise nicht selbst
ankündigen; das ist beim Sprung von 1.9.13 auf 1.9.16 passiert.

Deshalb merkt der schreibende Aufruf nur die Zahlen (`update_bericht_merken()`),
und der Text entsteht beim Aufruf danach (`update_bericht_holen()`).

**Wenn eine Seite nach dem Update den alten Stand zeigt**, ist meist der
Opcode-Cache schuld: `opcache.validate_timestamps = 0` lässt den Bytecode
endgültig im Speicher. `diagnose.php` sagt das. Abhilfe: einmal `opcache_reset()`
aufrufen oder den PHP-Dienst neu starten, danach die Seite neu laden.

### Geschützte Dateien: was davon zu halten ist

| Datei | Grund | Wird mitgeliefert |
| --- | --- | --- |
| `config.php` | Zugangsdaten | nein |
| `.htaccess`, `.gitignore` | Serveranweisungen, Repository-Regeln | nein |
| `assets/logo.png`, `assets/logo_nordwest.jpg` | Vereinslogo | nein |
| `install.php`, `config.sample.php` | nur zur Ersteinrichtung | nein |

Steht eine dieser Dateien in der Bestandsliste und ist auf dem Server älter als
im Repository, meldet das **keinen Fehler**. Sie gehört dem Server, und der Knopf
darf sie nicht ersetzen – das ist so vorgesehen. `diagnose.php` weist darauf als
eigene Zeile hin, statt es als Abweichung zu führen; wer eine davon wirklich
braucht, nimmt sie aus dem Archiv der Fassung.

Seit 1.9.12 weicht `install.php` auf jedem bereits aktualisierten Server vom
Repository ab. Vorher stand sie in der Meldung nach dem Update als „von Hand
geändert“ und in `diagnose.php` als Fehler – beides war falsch und nicht
behebbar.

### Die Datenbank bleibt unberührt

Der Knopf schreibt nur Programmdateien. Ändert eine neue Fassung auch das Datenbankschema, meldet
er das nach dem Einspielen und verweist auf `upgrade.php`. Das ist Absicht: Migrationen können
Daten umschreiben und gehören nicht in einen Knopf, den man im Ernstfall anklickt.

### Voraussetzungen auf dem Server

Die Seite **Aktualisierung** zeigt einen Selbsttest an. Nötig sind:

| Voraussetzung | Wofür |
| --- | --- |
| PHP 7.4 oder neuer | `hash_equals`, `Throwable` |
| `ZipArchive` | die neue Fassung entpacken |
| `curl` oder `allow_url_fopen` | GitHub erreichen |
| Schreibrecht im Programmverzeichnis | Dateien einsetzen, `.update/` anlegen |

Fehlt eine, bleibt die Seite bedienbar und zeigt den Grund. Die restliche Anwendung läuft
unbeeinflusst weiter.

### Nach dem Einspielen

`diagnose.php` prüft, ob `manifest.json` zur Fassung in `lib/version.php` passt und ob die Dateien
zu den eingetragenen Prüfsummen passen. Beides sollte in Ordnung sein; Abweichungen sind in der
Regel Dateien, die jemand von Hand angefasst hat.

### Beim Entwickeln

Nach jeder Änderung an einer ausgelieferten Datei:

```
php tools/manifest.php            # manifest.json neu erzeugen
php tools/manifest.php --pruefen  # nur vergleichen, Rückgabe 1 bei Abweichung
```

Neue Dateien müssen vorher mit `git add` erfasst sein – das Skript meldet sich sonst mit
„Diese Dateien liegen im Verzeichnis, stehen aber nicht im Manifest“. Ohne diesen Hinweis würde
eine neue Datei beim Update stillschweigend fehlen.

Zugleich mit `manifest.json` wird `APP_VERSION` in `lib/version.php` **von Hand** hochgezählt – das
Skript liest die Fassung nur und schreibt sie nicht. Für jede veröffentlichte Änderung gehören
`APP_VERSION` und ein Abschnitt in `CHANGELOG.md` zusammen.

### Wie die Fassungsnummer zu wählen ist

**Ab 2.0.0** gilt diese Regel. Sie ist Absicht, keine Empfehlung: sie macht aus einer Fassung
ablesbar, ob beim Aktualisieren etwas zu beachten ist.

| Art der Änderung | zweite Stelle | dritte Stelle | Beispiel |
| --- | --- | --- | --- |
| nur Dateien (Oberfläche, Texte, Regeln ohne Speicherung) | bleibt | **steigt** | 2.0.0 → 2.0.1 → 2.0.2 |
| Datenbank ändert sich (neue Spalte, neuer Vorgabewert, neue Tabelle) | **steigt** | fällt auf 0 | 2.0.2 → 2.1.0 |

Der Grund für den Sprung: der Aktualisierungs-Knopf schreibt nur Programmdateien. Ändert sich
das Schema, bleibt `upgrade.php` zu tun. Steht das nicht schon in der Versionsnummer, sieht man
es erst, wenn eine Seite einen Datenbankfehler zeigt. **Vorher `Aktualisierungsseite`, dann
`upgrade.php`.**

Bis einschließlich 1.9.13 wurde durchgehend die dritte Stelle gesteigert, auch bei
Datenbankänderungen – 1.9.12 etwa änderte den Vorgabewert von `users.active`. Diese Nummern
bleiben, wie sie sind; rückwärts umzubenennen würde veröffentlichte Fassungen und ihre
Anschreibungen in der Historie verdrehen.

Wird die Strafpunktregel geändert, gehört dazu:

```
php tools/regel_pruefen.php   # Bildschirmrechnung gegen Datenbank, Rückgabe 1 bei Abweichung
```

Beim Erfassen rechnet die Seite die Punkte selbst, damit die Anzeige ohne Verzögerung mitläuft.
Diese zweite Rechnung in `admin/erfassung.php` kann von der Datenbank in `lib/scoring.php`
abweichen – dann zeigt der Bildschirm beim Tippen einen anderen Wert, als gespeichert wird, und
der Wettbewerb wird nach dem Speichern scheinbar auf einmal anders. Das Skript holt die echte
Seite, führt die dortige Funktion unter `node` aus und vergleicht sie mit `calc_penalty()` über
20 Fälle: sauberer Flug, Motor, Aussenlandung, Bruchlandung, Nichtantritt sowie alle
Kombinationen der Kästchen.

Es braucht `node` (`apt-get install nodejs`) und eine Datenbank. Der Test legt einen
Wettbewerb mit zwei Piloten an, setzt das Passwort des ersten SuperAdmins auf ein bekanntes und
stellt danach Wettbewerb und Passwort wieder her – auch wenn er abbricht. Auf einer Installation
mit echten Wettkämpfen deshalb nur mit einer Kopie der Datenbank fahren.

`tools/aufrufe_pruefen.php` sucht Aufrufe von Namen, die es weder im Projekt noch in PHP gibt, und
Aufrufe über eine Variable, der im File nie etwas zugewiesen wird. Beides fällt beim Lesen nicht auf
und `php -l` meldet nichts – ein Aufruf wie `$name($x)` sieht aus wie ein Funktionsaufruf und
scheitert erst zur Laufzeit. Beide Werkzeuge laufen nur auf der Kommandozeile; vom Browser aufgerufen
weisen sie sich mit 403 ab.

## Ablauf an einem Wettbewerbstag

1. **Einstellungen** – Name, Datum, Ort und die Strafpunkt-Regeln prüfen.
2. **Modelltypen** – Segler, Elektro, weitere. Die Rangliste wird je Modelltyp ausgewertet.
3. **Vereine** – alle teilnehmenden Vereine, für Auswahl und Vereinswertung. Ein Verein mit
   Piloten, Anmeldungen, Wettbewerben oder Konten lässt sich nicht löschen; der Knopf nennt den
   Grund.
4. **Piloten** – nur für den gewählten Wettbewerb: einzeln, als Liste aus Excel oder über
   freigegebene Anmeldungen. Über der Startliste liegen *Startnummern neu vergeben* (nummeriert
   alle aktiven Piloten dieses Wettbewerbs nach Modelltyp und Zufall neu) und *Startliste als CSV*
   (Startnummer, Vor- und Nachname, Verein, Modelltyp, Modell – für die Aufkleber; die erste
   Zeile nennt den Wettbewerb, damit sich mehrere Bogen auseinanderhalten lassen).
5. **Durchgänge** – Anzahl einstellen, Zielzeit je Durchgang anpassen, Wertung aktivieren.
6. **Laufzettel als PDF** – ausdrucken. Je Durchgang zwei Blätter, eines für die ungeraden und
   eines für die geraden Startnummern, jeweils mit Flugzeit, Landewert und vier Ankreuzfeldern.
7. **Resultate erfassen** – ein Durchgang pro Seite, eine Zeile pro Pilot. Flugzeit als `2:58`
   oder `178`. Die Strafpunkte stehen live in der letzten Spalte.
8. **Rangliste** – öffentlich unter `rangliste.php`, je Modelltyp oder alle zusammen.
   Von der Startseite aus wählt man den Wettbewerb per Klick.
9. **Vereinswertung** – steht am Ende der öffentlichen Rangliste, unter demselben
   Filter wie die Piloten. `vereinswertung.php` leitet dorthin weiter.
10. **Wettbewerb beenden** – sobald alle Resultate erfasst sind. Der Wettbewerb bleibt als
    Archiv erhalten und lässt sich mit *Wieder öffnen* zurückholen.

## Die Startseite

`index.php` ist die Startseite. Sie beantwortet zwei Fragen: **wie melde ich mich an** und
**welcher Wettbewerb**. Beides auf einer Seite, ohne dass man sich anmelden muss.

Ganz oben steht der **Anmeldeweg in drei Schritten**, als schmaler Kasten in der Mitte der Seite
und mit mittigem Text. Er war vorher der dritte von drei Erklärblöcken unter den Karten; wer sich
eintragen lassen wollte, musste an der Ranglistenerklärung und der Punkteerklärung vorbei, um
dort anzukommen, wo es losgeht.

Darunter stehen die Wettbewerbe als **geschlossener Block in der Mitte der Seite**: Kacheln mit
höchstens 380 Pixel Breite, die auf die vorhandene Breite wachsen und nie unter 300 Pixel
schrumpfen. Auf dem Laptop stehen drei nebeneinander, auf dem iPad im Querformat ebenfalls
drei, nur schmaler. Das Raster ist dafür Flex, weil nur so auch eine unvollständige letzte
Zeile mittig steht – ein Raster zentriert nur die Spalten, eine einzelne Kachel darunter säße
linksbündig.

Die Karte nennt Datum, Ort, Verein und die Zahl der Piloten und Durchgänge. Auf der ganzen Fläche
ist ein Knopf; angeklickt wird nicht auf ein Wort, sondern auf die Fläche. Alle Knöpfe einer
Karte sind gleich hoch – vorher war der erste 52 Pixel hoch und der zweite 44, in derselben
Zeile. Steht ein
Wettbewerb unter *Rangliste noch nicht frei*, gibt es keinen Knopf zur Rangliste – nach der
Ausschaltung erscheint er von selbst, ohne dass die Karte manuell freigegeben werden muss.

**Rangliste für jeden Wettbewerb, Anmeldung nur für offene.** Beides steht auf jeder Karte, aber
nur getrennt: der Knopf *Rangliste* erscheint zu jedem Wettbewerb, dessen Rangliste freigegeben
ist, der Knopf *Anmelden* nur dort, wo noch angemeldet werden kann. Ein Wettbewerb nimmt
Anmeldungen an, wenn er nicht beendet ist, die Anmeldung nicht abgeschaltet wurde und sein Tag
noch nicht vorbei ist – `competition_nimmt_anmeldungen_an()` entscheidet das, und dieselbe
Funktion gilt für die Karten, für die Auswahl auf der Anmeldeseite und für den Hinweis dort.
Wichtig dabei: eine abgeschaltete Anmeldung allein genügt nicht, es zählt auch das Datum. Sonst
stünde ein Wettbewerb vom letzten Juni noch monatelang in der Auswahl, nur weil ihn niemand
rechtzeitig abgeschlossen hat.

Wer auf der Startseite einen vergangenen Wettbewerb wählt und dann zur *Anmeldung* geht, bekommt
kein Formular, sondern die Liste der Wettbewerbe, für die es noch geht – oder den Satz, dass
gerade für keiner die Anmeldung offen ist, mit einem Knopf zurück zu allen Wettbewerben. Die
Auswahlleiste darüber bleibt stehen, und der gerade gezeigte Wettbewerb bleibt darin, auch wenn
er beendet ist: er trägt dann den Vermerk *beendet*. So weiss man, wofür die Seite gerade
spricht, statt vor einer leeren Auswahl zu stehen.

Darunter steht die Erklärung in drei Blöcken: **wie man die Rangliste liest** (durchgestrichen,
rote Zahl, gleiche Summe), **wie die Punkte entstehen** und **wie man sich anmeldet**. Sie
nennt absichtlich keine festen Punktzahlen – die sind je Verein verschieden und stehen über der
Rangliste.

Die Unterseiten haben die Auswahl als schmale Knopfleiste über dem Inhalt. Kein Formular, kein
JavaScript, ein Klick führt zum Wettbewerb. Ein beendeter Wettbewerb ist auf der Anmeldeseite
nicht mehr wählbar, der gerade gezeigte bleibt aber sichtbar.

## Benutzer und Rechte

Es gibt genau zwei Rollen:

| Rolle | Darf |
| --- | --- |
| **SuperAdmin** | alles, was ein Benutzer darf, **plus** die Benutzerverwaltung – und **alle** Vereine sehen |
| **Benutzer** | die Wettbewerbe **seines Vereins** steuern: Erfassung, Startliste, Durchgänge, Vereine, Modelltypen, Anmeldungen, Export, Laufzettel und die Einstellungen des jeweiligen Wettbewerbs – dazu das [eigene Profil](#anmelden-und-das-eigene-profil) mit Anzeigename und Passwort |

Die Zugehörigkeit zum Verein entscheidet, wer welchen Wettbewerb steuern darf. Ein Benutzer
kann **nicht** anlegen, ändern, sperren oder löschen – auch nicht mit einem abgefangenen oder
manipulierten Aufruf. Die Seite **Benutzer** ist für ihn weder erreichbar noch zu sehen – sie
steht nur im Menü des Benutzersymbols, und dort erscheint der Punkt nur beim SuperAdmin.

Unter **Benutzer** kann der SuperAdmin je Konto:

- den **Anzeigamen** ändern (erscheint oben im Kopf),
- den **Verein** zuweisen, in dem das Konto arbeitet,
- die Rolle zwischen Benutzer und SuperAdmin umstellen,
- das Konto **sperren** oder wieder freigeben – ein gesperrtes Konto kann sich nicht anmelden,
  und eine noch laufende Sitzung endet beim nächsten Aufruf, nicht erst beim Abmelden,
- ein **neues Passwort** setzen, etwa wenn jemand das eigene vergessen hat,
- das Konto **löschen**.

### Der Veranstalter eines Wettbewerbs

Ein Wettbewerb gehört genau einem Verein. Beim Anlegen wählt **nur der SuperAdmin** den
Veranstalter aus; jedes andere Konto bekommt automatisch den eigenen Verein zugeteilt und kann
daran nichts ändern. Danach steuern nur die Konten dieses Vereins den Wettbewerb, alle anderen
werden bei jedem Zugriff auf ihren eigenen Wettbewerb umgeleitet. SuperAdmins sehen und ändern
alles.

Wettbewerbe aus dem Altbestand haben zunächst **keinen** Verein und sind für alle Konten
sichtbar. Nach der Zuordnung im Wettbewerb gelten sie nur noch für den jeweiligen Verein.

Das eigene Konto und der letzte aktive SuperAdmin lassen sich weder sperren noch löschen und
nicht in eine niedrigere Rolle stufen. Sonst gäbe es niemanden mehr, der die Konten verwalten
kann. `diagnose.php` meldet sich, falls eine Installation keinen aktiven SuperAdmin hat.

**Es gibt bewusst keine Rücksetzung per E-Mail.** Dafür müsste der Server Mails zuverlässig
versenden, es bräuchte einen geheimen Schlüssel, und beides ist beim Betrieb auf einem
Wettbewerbsplatz nicht gegeben – es wäre ein Weg, über den ein Konto unbemerkt neu gesetzt
wird. Ein vergessenes Passwort setzt der SuperAdmin auf der Benutzerseite neu.

Bei der Einrichtung wird das erste Konto als SuperAdmin angelegt. Bei einer bestehenden
Installation wird das **älteste** Konto zum SuperAdmin, damit die Benutzerverwaltung erreichbar
bleibt. Ab dann kann der SuperAdmin weitere SuperAdmins anlegen, etwa wenn ein zweiter Verein
mit eigenem Zugang mitarbeitet.

Ein neu angelegtes Konto ist **sofort anmeldebereit** – Passwort eingeben, fertig. Gesperrt
wird ein Konto erst, wenn das Kästchen *aktiv* in der Kontenliste abgehakt wird. Vor 1.9.12
galt das Gegenteil: frisch angelegte Konten waren gesperrt und liessen sich mit keinem
Passwort anmelden, bis der SuperAdmin sie in der Liste ein zweites Mal bearbeitet hat. Die
Ursache stand in der Datenbank, nicht im Passwort; `upgrade.php` stellt den Vorgabewert der
Spalte `users.active` wieder auf 1.

Die Kontenliste zeigt **Konto, Anzeigename, Verein, SuperAdmin, aktiv, neues Passwort** und die
zwei Knöpfe. Die Spalte *Angelegt* ist seit 1.9.22 weg: sie stand nur als Datum da und war breit
genug, um den Knopf **Löschen** aus der Tabelle zu drücken. Auf einem Laptop mit 1280 Pixeln passt
die Liste jetzt ohne Rest hinein; darunter nimmt der Kasten die Tabelle waagerecht auf.

Die Ankreuzfelder in den dichten Tabellen sind 18×18 Pixel. Vor 1.9.22 hat die Regel
`.dense input { height: 32px }` auch sie gestreckt, wodurch jede Zeile mit einem Kästchen 9 Pixel
höher war als eine ohne – sichtbar daran, dass die Zeile mit den Abzeichen niedriger war. Betroffen
waren Vereine, Modelltypen, Durchgänge, Erfassung und die Kontenliste.

## Anmelden und das eigene Profil

Oben rechts in der Kopfzeile steht ein **Benutzersymbol**, wie man es von den grossen Seiten
kennt. Ohne Konto ist es ein schlichter Verweis auf die Anmeldung; mit Konto öffnet es ein Menü
mit dem Namen, der Rolle, dem Profil und dem Abmelden. Das Menü ist ein `<details>` – es geht
ohne JavaScript auf und lässt sich mit der Tastaste bedienen.

Der Knopf **Anmelden** in der Navigationsleiste ist dafür weg. Er stand direkt neben dem Punkt
**Anmeldung** und wurde dauernd verwechselt – ein Buchstabe Unterschied, ein Klick daneben. Das
Symbol nimmt beiden die Verwechslungsmöglichkeit.

Seit 1.9.16 hat der öffentliche Bereich **gar keine Navigationsleiste mehr**, und das Benutzermenü
trägt zusätzlich den Eintrag **Wettkampfbüro**. Wer die Seite liest, nutzt den Zurück-Knopf des
Browsers oder klickt den Titel in der Kopfzeile; zwischen den Wettbewerben geht es über die
Kacheln der Startseite.

**Was wo hingehört, ist jetzt getrennt:**

| Seite | Gehört dorthin |
| --- | --- |
| **Profil** (`admin/profil.php`) | alles, was die Person betrifft: Anzeigename, Passwort, und später eigene Einstellungen |
| **Einstellungen** | alles, was den **Wettbewerb** betrifft: Strafpunkte, Anmeldung, Freischaltung |
| **Benutzer** | nur der SuperAdmin: fremde Konten anlegen, Rolle, Verein, Sperre |

Das Passwort stand früher unter *Einstellungen* und war dort schwer zu finden – wer dort
Strafpunkte ändern wollte, lief an einem Passwortfeld vorbei und umgekehrt. Es steht jetzt im
Profil, erreichbar über das Benutzersymbol.

**Benutzer verwalten** und **Aktualisierung** sind im selben Menü, beim SuperAdmin, und
stehen nicht mehr in der Navigationsleiste. Beide betreffen nur den SuperAdmin; in der
Leiste, die alle Konten sehen, nahmen sie zwei Plätze für einen Bruchteil der Benutzer ein.
Seit 1.9.13 hat die Leiste deshalb wieder eine ruhige Zeile.

Unter *Profil* steht ein eigener Block **Eigene Einstellungen**, der noch leer ist. Er ist für
alles vorgesehen, was nur die Person betrifft, zum Beispiel welche Vereine und Wettbewerbe man
ohne Umweg sehen möchte. Wenn dort etwas hinzukommt, gehört es dorthin und nicht in die
Wettbewerbseinstellungen.

## Wertung

Wenige Punkte sind gut. Die Regeln stehen pro Wettbewerb unter **Einstellungen → Strafpunkte**,
damit jeder Verein seine eigenen Zahlen haben kann. Es gibt genau fünf Bausteine:

| Baustein | Einstellung | Bedeutung |
| --- | --- | --- |
| Zeitabweichung | `penalty_per_second` | Punkte je Sekunde Abweichung von der Zielzeit |
| Landepunkte | `penalty_per_meter` | Punkte je Landewert-Einheit |
| Strafe Aussenlandung | `penalty_outlanding` | fester Betrag |
| Strafe Bruchlandung | `penalty_crash` | fester Betrag |
| Strafe nicht angetreten | `penalty_not_started` | fester Betrag |
| Strafe Motor angelassen | `penalty_motor` | fester Betrag, bei einem festen Ausgang zusätzlich |

Es gibt **keine Obergrenze**: eine grosse Zeitabweichung oder ein weit entfernter Landepunkt
kostet unbegrenzt Punkte. Die festen Strafen wirken ohnehin als Gesamtbetrag.

Zwei Regeln, die man leicht falsch liest:

**Die Zeitabweichung ist ein Betrag.** Zwei Sekunden zu lang und zwei Sekunden zu kurz kosten
gleich viel – es gibt nur einen Satz je Sekunde.

**Der Motor ist eine Zusatzstrafe, keine eigene Ergebnisart.** Er kommt zu allem dazu und ersetzt
nichts.

Beim Erfassen gibt es vier Ankreuzfelder, genau wie im Laufzettel. **Kein Feld heisst „geflogen"**,
der Flug wird dann nach Flugzeit und Landewert gewertet. Die Kästchen sind **unabhängig**: eine
Aussenlandung schliesst eine Bruchlandung nicht aus, denn ein Modell kann neben der Piste gelandet
sein und dort Teile verloren haben. Einzige Ausnahme ist „nicht angetreten" – wer nicht
angetreten ist, hat nicht geflogen, es gibt keine Zeit und keinen Landewert.

| Angekreuzte Felder | Zeit | Landewert | Feststrafen |
| --- | --- | --- | --- |
| keine | ja | ja | – |
| Motor | ja | ja | – (Motor kommt dazu) |
| Aussenlandung | ja | **nein** | `penalty_outlanding` |
| Bruchlandung | ja | ja | `penalty_crash` |
| Aussenlandung + Bruchlandung | ja | **ja** | beide, zusammen |
| nicht angetreten | **nein** | **nein** | `penalty_not_started` |

Zum Motor: er kommt zu allem dazu und ersetzt nichts. Bei jeder Kombination der anderen drei
Kästchen kommt die Motorstrafe obendrauf.

Die Begründung dahinter: **die Zeitabweichung zählt immer**, auch bei einer Bruchlandung – der
Flug hat eine Zeit, und die wird gemessen. **Bei der Aussenlandung ist der Landewert null**, weil
das Landen ausserhalb des Feldes gerade das Ereignis ist. **Bei der Bruchlandung zählt er**, weil
sie im Landefeld passieren kann – und bei der Kombination aus beiden ebenfalls, denn dann ist der
Landewert die Landung im Feld. **Beim Nichtantritt sind beide null.**

**Sobald „nicht angetreten" angekreuzt ist, sind die anderen Felder gesperrt** und die Zeit steht
auf 0:00. Beim Abwählen kommt der vorher eingetragene Wert zurück, damit ein Fehlklick nichts
vernichtet. Der Motor ist ein eigenes Kästchen und wird dabei mit abgehakt – nicht angetreten
und Motor zugleich ergibt keinen Sinn.

**Flugzeit und Landewert bleiben immer bedienbar**, auch neben einem angekreuzten
Feld. Wer eine Aussenlandung nach 3:20 Landewert 15 hatte, trägt beides ein – bei der
Aussenlandung zählt nur die Zeit, der Landewert wird mitgespeichert und geht nicht verloren.
Nur beim freien Flug ist die Flugzeit Pflicht, weil ohne sie nichts zu rechnen wäre.

| Eingabe | Folge |
| --- | --- |
| keine Felder, keine Zeit, kein Landewert | die Zeile bleibt ohne Resultat |
| Feld und leere Zeit | die Feststrafe zählt, die Zeit nicht |

Trifft „nicht angetreten" mit einem anderen Kästchen zusammen, gilt „nicht angetreten": die Seite
lässt die Kombination gar nicht erst zu. Beim Speichern wird sie zusätzlich ausser Betracht
gezogen, weil ein Formular auch von Hand gesendet werden kann.

Der Landewert ist eine Zahl: entweder die Distanz in Metern zum Landepunkt oder direkt eine
Punktzahl aus der Landetabelle.

**Streichresultat.** Das schlechteste Resultat kann ab einer einstellbaren Anzahl geflogener
Durchgänge gestrichen werden. Bei Punktegleichheit entscheidet **zuerst das kleinere
Streichresultat**, danach das beste Einzelresultat, zuletzt der Name.

Dazwischen wird bewusst **nichts geprüft, was den Ausgang eines Fluges betrachtet** – ob der
Motor angelassen wurde, ob es eine Aussen- oder Bruchlandung gab, ob der Pilot gar nicht
angetreten ist. All das steht schon in den Punkten, und ein gleichwertiger Pilot darf nicht
darunter leiden, dass er einmal den Motor angelassen hat. Ein zuschlagendes Kästchen kann
deshalb keinen Platz kosten, aber es kann Punkte kosten – und die sind in der Summe enthalten.

Dieselbe Reihenfolge gilt in der Vereinswertung.

**Nachträgliche Änderungen.** Wird eine Regel oder eine Zielzeit geändert, bleiben bereits
gespeicherte Punkte stehen. Unter **Durchgänge** rechnet *Punkte neu berechnen* einen Durchgang
mit den aktuellen Regeln des Wettbewerbs nach – auch die festen Strafen und der Motorstart.
Nachrechnen lässt sich nur, was gespeichert ist: Resultate, die vor Fassung 1.9.5 ohne Zeit
erfasst wurden, enthalten keine Flugzeit, und die kann niemand nachrechnen. Sie müssen neu
eingetragen werden, sonst bleibt bei ihnen die alte Punktesumme stehen.

### Laufzettel

Je Durchgang entstehen zwei A4-Blätter. Neben Flugzeit und Landewert hat jede Zeile vier
Ankreuzfelder:

- **nicht angetreten**
- **Aussenlandung**
- **Bruchlandung** – das Modell ist beim Landen zerstört
- **Motor angelassen** – bei einem elektrischen Modell wurde der Motor angelassen

Kein Feld angekreuzt heisst „geflogen". Die Felder dürfen einzeln oder zusammen angekreuzt
werden; der Motor kommt zu jedem Ausgang dazu. Die Bezeichnungen stehen in der Kopfzeile, die
Punkte für jedes Feld in der Legende darunter, damit der Zeitnehmer sie nicht nachschlagen muss.
Weil die vier Felder in den Satzspiegel passen müssen, ist die Kopfzeile kurzfomatiert
(„nicht angetr.", „Aussenland.", „Bruchland.", „Motor"); die Legende nennt sie ausgeschrieben.

`admin/laufzettel.php` bietet den PDF-Download an; die HTML-Ansicht ist der Druck-Fallback.

## Einstellungen

Unter **Einstellungen** werden die Einstellungen des ausgewählten Wettbewerbs bearbeitet:

- Name, Datum, Ort und Standard-Zielzeit für neue Durchgänge
- die fünf [Strafpunkt-Bausteine](#wertung) mit Erklärung und Rechenbeispiel
- Streichresultat und Ranglisten-Ansicht
- Öffentlichkeit der Resultate
- Vereinswertung: Anzahl der gewerteten Piloten je Verein
- Anmeldeformular: offen oder geschlossen, Text über dem Formular, Absenderadresse
- Passwort des eigenen Kontos und weitere Zugänge

Der Regiocup steht hier **nicht** mehr: welcher Verein die Regiorangliste sehen
darf, ist keine Sache des einzelnen Wettbewerbs, sondern des ganzen Programms.
Der SuperAdmin stellt es unter **Regiocup** im Benutzermenü ein und sieht dort
gleich die Liste, bevor sie veröffentlicht ist.

Die Einstellungen gelten nur für den Wettbewerb, der oben ausgewählt ist. Beim Anlegen eines
neuen Wettbewerbs werden die Einstellungen des gerade ausgewählten als Vorlage kopiert – so
haben zwei Vereinswettbewerbe unterschiedliche Regeln, ohne dass die bestehenden Wettbewerbe
verändert werden.

Ein abgeschlossener Wettbewerb ist gesperrt: Startliste, Resultate, Anmeldungen und
Wettbewerbseinstellungen bleiben unverändert sichtbar, lassen sich aber nicht mehr ändern. Der
Name des Wettbewerbs selbst bleibt auch nach dem Abschluss änderbar.

## Wettbewerbe

Ein Wettbewerb bekommt einen aussagekräftigen Namen, zum Beispiel
`MFV Brislach - Schwarzbubenfliegen 2027`, eigene Durchgänge, eine leere Startliste und eigene
Einstellungen. Der aktive Wettbewerb ist der Vorgabe für Erfassung, Anmeldung, Export und die
öffentlichen Seiten.

### Drei Zustände, und sie schliessen einander aus

| Zustand | bedeutet |
| --- | --- |
| **offen** | angelegt, nicht aktiv, nicht beendet |
| **aktiv** | offen **und** der, an dem gerade gearbeitet wird |
| **beendet** | abgeschlossen, Ergebnis gespeichert und gesperrt |

**Ein neuer Wettbewerb ist offen, nicht aktiv.** Vor 1.9.22 war er sofort aktiv, und das hat
unbeabsichtigt den laufenden Wettbewerb verdrängt: wer mitten in der Erfassung den Wettbewerb für
das nächste Jahr anlegte, stand plötzlich in einem leeren Wettbewerb und musste ihn erst wieder
aktivieren. Aktiviert wird jetzt ausdrücklich, in der Liste der Wettbewerbe.

Eine Ausnahme bleibt: **gibt es überhaupt keinen aktiven Wettbewerb**, muss einer her, sonst zeigt
jede Seite ins Leere. Dann wird der neue aktiv – und die Meldung sagt es auch so.

**Ein beendeter Wettbewerb ist nie aktiv.** Er bleibt als Archiv stehen, seine Ergebnisse sind
gesperrt. Vor 1.9.22 trug er nach dem Beenden weiter das Kennzeichen „aktiv“: der Seitenkopf zeigte
ihn weiter an, und die Verwaltung bearbeitete ein Archiv. Zwei Stellen haben das begünstigt – das
Beenden selbst und die Reihenfolge der Anzeige, in der „aktiv“ vor „beendet“ geprüft wurde. Beide
sind behoben; `current_competition()` weist einen beendeten Wettbewerb auch dann ab, wenn die
Spalte noch so dasteht, damit ein Altbestand sich selbst berichtigt.

Beim Anlegen werden die Einstellungen des gerade ausgewählten Wettbewerbs als Vorlage kopiert.
So haben zwei Vereinswettbewerbe unterschiedliche Regeln, ohne dass die bestehenden
Wettbewerbe verändert werden. Ausgenommen ist die Absenderadresse der Anmeldebestätigung: sie
gehört jedem Verein selbst und wird bewusst nicht vererbt.

**Zum Regiocup.** Beim Anlegen lässt sich ankreuzen, ob der Wettbewerb zur Regiowertung des
Jahres zählt. Jederzeit änderbar auf der Seite **Wettbewerbe**, an der Karte des
Wettbewerbs:

| | |
| --- | --- |
| **Zum Regiocup hinzufügen** | der Wettbewerb zählt zur Regiorangliste seines Jahres |
| **Aus dem Regiocup nehmen** | er zählt nicht mehr mit; jederzeit wieder hinein |

Der Knopf zeigt den Zustand mit einem Zeichen: **🏆 Regiocup**, wenn er mitzählt,
**○ Regiocup**, wenn nicht. Ist er im Regiocup, trägt die Karte zusätzlich das
goldene Abzeichen **🏆 Regiocup** – man sieht es also ohne den Knopf lesen zu müssen.
Vor dem Umschalten kommt eine Rückfrage wie beim Beenden und Löschen auch; **ohne
Bestätigung ändert sich nichts**. Das war seit 1.9.4 nicht so: dort wirkte ein Verklicken
sofort, und es gab keine Möglichkeit, den Zustand auf einen Blick zu sehen.

Seit 1.9.11 liess sich ein Wettbewerb wieder herausnehmen. Vorher war der Umschalter
kaputt: er konnte nur einschalten, weil `isset()` den Wert `0` als „Feld vorhanden"
las und damit aus dem Herausnehmen ein Hineinmachen machte.
Das Jahr steht im **Wettbewerbsdatum**, nicht im Namen – ein „Erlencup 2027“ mit dem Datum
19.06.2026 zählt also für 2026. Das ist Absicht: das Datum wird beim Kopieren eines
Wettbewerbs ohnehin mitgenommen, während der Name frei ist. Wer einen Wettbewerb umbenennt,
verschiebt ihn damit also nicht versehentlich in ein anderes Jahr.

Auf der Seite **Wettbewerbe** steht eine Karte je Wettbewerb – nebeneinander statt
untereinander, sodass nichts vertikal durchlaufen werden muss. Jede Karte zeigt Zustand,
Anzahl der Durchgänge, offene Anmeldungen und den Fortschritt der Resultate.

**Beenden.** Sobald für jeden aktiven Piloten in jedem gewerteten Durchgang ein Resultat
vorliegt – Aussenlandung und „nicht angetreten" zählen mit –, kann der Wettbewerb beendet
werden. Danach bleiben Startliste, Resultate, PDF und Export sichtbar; gesperrt sind
Resultate, Anmeldungen und Wettbewerbseinstellungen. War er der aktive, hört er auf, aktiv zu
sein: der neueste noch offene Wettbewerb übernimmt, sonst bleibt kurz keiner aktiv. Der Name des
abgeschlossenen bleibt änderbar. Bei einem Korrekturfehler holt ein Administrator ihn mit
*Wieder öffnen* zurück.

Ein Wettbewerb mit Anmeldungen oder Resultaten wird nicht gelöscht, damit keine Daten
verloren gehen.

**Auswahl.** Die öffentlichen Seiten bieten keine Auswahlliste mehr, sondern Knöpfe: auf der
Startseite grosse Karten, auf den Unterseiten eine schmale Leiste darüber. Kein Formular, kein
JavaScript, ein Klick führt zum Wettbewerb. Auf der Anmeldeseite stehen nur Wettbewerbe, die
überhaupt noch Anmeldungen annehmen; der gerade gezeigte bleibt aber sichtbar, auch wenn er
beendet ist, damit ein altes Lesezeichen nicht ins Leere zeigt. Die Startseite sortiert nach
Wettbewerbsdatum, nicht nach Nummer – das neueste Jahr steht oben.

In der Verwaltung bleibt es bei einem Dropdown, gruppiert nach *Offene Wettbewerbe* und
*Abgeschlossene Wettbewerbe*, mit dem aktiven Wettbewerb oben. Dort wird oft mehrmals am
Stück gewechselt, und ein schmales Feld nimmt weniger Platz weg als eine Kartenzeile.

Ein gezielter Aufruf funktioniert mit `rangliste.php?competition=2` – und zwar auch ohne
Anmeldung, denn die öffentlichen Seiten zeigen jeden Wettbewerb. Die frühere Form
`?season=...` wird beim Lesen noch akzeptiert.

Dass *Sichtbarkeit* und *Zugriff* getrennt sind, ist Absicht: jeder Wettbewerb ist öffentlich
sichtbar, und die Verwaltungsseiten fragen den Zugriff selbst ab. Ein gemeinsamer Aufruf hatte
beides vermischt – die Verwaltungsprüfung beantwortet für Besucher immer mit „nein“ und hätte
damit auch die Wettbewerbsauswahl auf den öffentlichen Seiten ausser Kraft gesetzt, sodass dort
immer der aktive Wettbewerb erschien.

Seit 1.9.16 hat ausserdem keine öffentliche Seite eine eigene Auswahlleiste mehr. Die Kacheln auf
der Startseite wählen den Wettbewerb, der Knopf **Anmelden** darauf führt mit `competition=` zur
passenden Anmeldung. `competition_choices()` ist deshalb entfallen.

Deshalb ruft jede Seite unter `admin/` die Auswahl mit einem zweiten Schalter auf
(`resolve_competition_param($roh, true)`). Wer einen Wettbewerb nennt, den das Konto nicht steuern
darf, sieht dort nichts vom fremden: statt des gewünschten Wettbewerbs wird einer gezeigt, den
das Konto steuern darf, und **seit 1.9.18 steht dabei ein Hinweis da**, welcher Wettbewerb
tatsächlich angezeigt wird. Das war vorher still, und man konnte leicht glauben, den gewünschten
Wettbewerb vor sich zu haben. Eine Umleitung wäre in eine Schleife gelaufen, sobald schon der
aktive Wettbewerb einem fremden Verein gehört – deshalb die Meldung. Die öffentlichen Seiten
rufen die Auswahl ohne den Schalter auf.

**Ein Verein, einmal angelegt.** Vereine und Modelltypen bleiben global, die Wettbewerbe sind
datenseitig getrennt. Ein Verein lässt sich deshalb nur löschen, wenn **nirgends** mehr etwas an
ihm hängt – geprüft werden alle vier Verwendungen: Piloten in der Startliste, Anmeldungen,
Wettbewerbe, an denen er als Veranstalter steht, und Konten.

Der Knopf **Löschen** bleibt immer bedienbar; steht nichts dagegen, fragt er wie bisher nach.
Hängt noch etwas am Verein, nennt die Meldung genau die Anzahlen – „3 Piloten in der Startliste
und 1 Anmeldung“ – und den Weg, der aufräumt. Im Raster steht das bewusst nicht: sonst steht
jeder Verein mit seinen Piloten und Konten da, ohne dass man etwas tun muss.

Zwei Einträge für denselben Verein werden über **Doppelten Verein zusammenlegen** zusammengeführt:
die Piloten wandern zum Zielverein, der Doppeleintrag verschwindet. Beides ist für Vereine
blockiert, sobald sie in einem abgeschlossenen Wettbewerb vorkommen – die historische Zuordnung
bleibt erhalten.

**Modelltypen sind genau so abgesichert** (seit 1.9.18). Vorher prüfte die Seite nur abgeschlossene
Wettbewerbe, und ein in einem laufenden Wettbewerb benutzter Typ ließ sich löschen: der
Fremdschlüssel setzte die Piloten still auf NULL, die Startliste verlor ihre Gruppierung, und
es gab keine Meldung. Jetzt wird wie bei den Vereinen gesperrt und die Meldung nennt die
Anzahlen. **Für „nicht mehr anbieten" das Kästchen *aktiv* wegnehmen** – dann bleibt die
Zuordnung stehen und der Typ verschwindet nur aus der Auswahl.

## Die Reihenfolge in `schema.sql` ist Absicht

Jede Tabelle steht **nach** den Tabellen, auf die sie verweist. `competitions`
verweist auf `clubs`, `pilots` auf `competitions` und `clubs`, `scores` auf
`pilots` und `rounds`. MySQL und MariaDB lehnen einen Fremdschlüssel ab, dessen
Ziel noch nicht existiert – bis 1.9.20 stand `competitions` vor `clubs`, und
eine frische Einrichtung brach deshalb ab. Wer eine Spalte mit einem Verweis
ergänzt, muss die neue Tabelle entsprechend einsortieren.

Ein Semikolon **im Kommentar** reicht ebenfalls, um `install.php` zu stoppen:
die Datei wird an jedem Semikolon getrennt, und die Kommentare werden vorher
entfernt. Nach SQL-Regel braucht ein Kommentar Leerraum davor.

## Regiorangliste

**Beschlossen:** in die Regiowertung eines Jahres gehen **alle Wettbewerbe
dieses Jahres ein, die den Regiocup-Knopf haben**. Nicht nur die des
Veranstaltungsvereins, und nicht eine feste Auswahl – wer den Knopf drückt,
nimmt teil. Das Jahr steht im **Wettbewerbsdatum**, nicht im Namen.

**Der Rechenweg steht** in `lib/region.php`, **die Anzeige seit 1.9.21**:

| Funktion | macht |
| --- | --- |
| `region_wettbewerbe_des_jahres($jahr)` | alle Wettbewerbe mit Datum in diesem Jahr |
| `region_wettbewerbe($jahr)` | davon nur die mit dem Regiocup-Kennzeichen |
| `region_rangliste($ids)` | die eigentliche Regiorangliste über mehrere Wettbewerbe |
| `region_fis_punkte()`, `region_piloten_punkte()` | Punkte nach FIS-System |
| `region_club_id()` | welcher Verein die Liste sehen darf |
| `region_darf_sehen()`, `region_darf_bearbeiten()` | wer sie sehen und wer sie ändern darf |

| Datei | macht |
| --- | --- |
| `region.php` | die Liste, öffentlich wie die Rangliste; `?jahr=` wählt, `?csv=1` exportiert |
| `admin/regiocup.php` | Einstellung und Vorschau, für SuperAdmin **und** den eingestellten Verein |
| `lib/layout.php` `region_card()` | die Kachel auf der Startseite |
| `lib/layout.php` `region_table()` | die Tabelle – **eine** für Seite und Vorschau |

Seit 1.9.22 ist der Regiocup eine eigene Seite im Wettkampfbüro,
`admin/regiocup.php`, und steht im Benutzermenü. Vorher stand er als Sektion im
Profil – dort war er nur für den SuperAdmin sichtbar, obwohl die Einstellung
gerade für einen *anderen* Verein gedacht ist.

Die Seite ist erreichbar für:

- den **SuperAdmin** – mit dem Formular, mit dem er den Verein einstellt,
- die **Mitglieder des eingestellten Vereins** – mit der Liste, ohne Formular,
- alle anderen **nicht** – sie sehen dieselbe Sperre wie `region.php`.

Der eingestellte Verein muss dafür **nicht** für die Anmeldung freigeschaltet
sein. `clubs.active` steuert nur das Anmeldeformular; ein Verein, der lediglich
zusieht, soll dafür nicht dort auftauchen müssen. In der Auswahl steht er
deshalb als *„(nicht in der Anmeldung)"* – damit man nicht versehentlich einen
Verein wählt, den man für die Anmeldung braucht, und umgekehrt.

### Wer sie sehen darf

`region_darf_sehen()` entscheidet in dieser Reihenfolge:

1. Sind die Resultate ohnehin öffentlich (`public_results`), darf sie jeder.
2. Der SuperAdmin darf sie immer.
3. Sonst nur die Mitglieder des Vereins aus `region_club_id`.

Die Kachel auf der Startseite folgt derselben Regel. Sie erscheint also **nur,
wenn sie auch erreichbar ist** – eine Kachel, die auf eine gesperrte Seite
zeigt, wäre eine Sackgasse.

### Die Einstellung gehört zum Programm, nicht zum Wettbewerb

`region_club_id` stand bis 1.9.21 in den **Wettbewerbseinstellungen**. Das war
ein Widerspruch: der Regiocup läuft über ein ganzes Jahr und damit über
mehrere Wettbewerbe, jeder hätte seinen eigenen Verein freischalten können –
und sichtbar wäre trotzdem nur der gerade aktive.

Seit 1.9.21 steht sie beim SuperAdmin unter **Profil → Regiocup** und gilt für
das ganze Programm. Zwei Fehler waren dabei zu bedenken und sind gelöst:

- **Die Einrichtung legt den Vorgabewert global an.** `install.php` schreibt
  `region_club_id = 0` in `settings`, also ist „global vorhanden“ kein
  Kennzeichen für „programmgroß gesetzt“. Der Rückfall auf die alten Zeilen
  greift deshalb nur, wenn programmgroß nichts eingestellt ist.
- **Der Rückfall liest alle Wettbewerbe, nicht nur den aktiven.** Sonst hätte
  er nur gegriffen, wenn gerade der Wettbewerb mit dem alten Wert aktiv
  gewesen wäre – und still nichts angezeigt, obwohl der Verein eingestellt
  war. Bei mehreren verschiedenen Altwerten entscheidet der aktive Wettbewerb.

Beim Speichern werden die alten Zeilen **mitgelöscht**. Ohne das würde „niemand“
nicht gelten: der Rückfall würde den alten Verein zurückholen.

Offen und noch nicht entschieden: ob ein Verein, der selbst Wettbewerbe
ausrichtet, seine eigene Rangliste ohne Umweg über die Regioliste sieht.

## Anmeldung

`anmeldung.php` ist das öffentliche Formular. Eingegangene Anmeldungen erscheinen unter
**Anmeldungen** und wandern beim Freigeben mit einer Startnummer in die Startliste. Das Formular
lässt sich pro Wettbewerb schliessen und mit einem eigenen Text versehen; die Auswahl zeigt nur
Wettbewerbe, die noch nicht beendet sind.

Das Formular fragt Vorname, Name, Verein, E-Mail, Modelltyp, Modell und Bemerkung ab – ein
Telefonfeld gibt es nicht.

**Die E-Mail-Adresse wird nicht gespeichert.** Sie dient ausschliesslich der Bestätigung und wird
danach verworfen – die Spalte dazu gibt es in der Datenbank nicht mehr. Auch beim Freigeben der
Anmeldung wird nichts davon in die Startliste übernommen. Verschickt wird die Bestätigung erst,
nachdem die Anmeldung gespeichert *und wieder aus der Datenbank gelesen* wurde – eine Bestätigung
ohne Eintrag kann es dadurch nicht geben. Die Absenderadresse
steht pro Wettbewerb unter **Einstellungen → Anmeldung**; ohne sie wird nichts verschickt, die
Anmeldung geht aber trotzdem ein.

Die Absenderadresse steht zugleich im **CC**. Damit geht jede neue Anmeldung im Postfach der
Wettkampfleitung ein, ohne dass eine Adresse gespeichert werden muss. Meldet sich jemand mit
exakt dieser Adresse an, entfällt das CC, damit er die Mail nicht doppelt bekommt.

Nach dem Absenden leitet die Seite auf eine eigene Bestätigungsseite um; ein Reload oder ein
zweiter Klick sendet deshalb keine weitere Anmeldung ab.

## Vereinswertung

Die besten Piloten eines Vereins ergeben zusammen das Vereinsresultat, der tiefste Wert gewinnt.
Wie viele Piloten zählen, ist pro Wettbewerb einstellbar. Ein Verein mit weniger gewerteten
Piloten erscheint ausser Konkurrenz am Listenende. Für den Verein zählt nur, wer mindestens ein
Resultat hat.

## Aufbau des Projekts

```
index.php              öffentliche Startseite: Wettbewerb wählen, kurze Erklärung
rangliste.php          öffentliche Rangliste
region.php             öffentliche Regiorangliste, mit CSV-Export (seit 1.9.21)
vereinswertung.php     Weiterleitung auf rangliste.php (Stand 1.9.13)
teilnehmer.php         öffentliche Teilnehmerliste
anmeldung.php          öffentliches Anmeldeformular

admin/index.php        Übersicht
admin/wettbewerbe.php  Wettbewerbe anlegen, aktivieren, beenden
admin/erfassung.php    Resultate erfassen
admin/piloten.php      Startliste
admin/durchgaenge.php  Durchgänge und Zielzeiten
admin/vereine.php      Vereine
admin/modelltypen.php  Modelltypen
admin/anmeldungen.php  Anmeldungen freigeben oder ablehnen
admin/laufzettel.php   Laufzettel, HTML und PDF
admin/export.php       CSV-Export
admin/einstellungen.php Einstellungen des gewählten Wettbewerbs
admin/benutzer.php     Benutzerverwaltung, nur für den SuperAdmin
admin/aktualisieren.php Aktualisierung von GitHub, nur für den SuperAdmin
admin/profil.php      Eigenes Profil: Anzeigename, Passwort
admin/regiocup.php    Regiocup: Einstellung und Vorschau (1.9.22)
admin/login.php        Anmeldung des Wettkampfbüros
admin/logout.php       Abmeldung

install.php            Einrichtung – danach löschen
upgrade.php            Aktualisierung – danach löschen
diagnose.php           Fehlersuche – danach löschen

lib/competition.php    Wettbewerbe, Kontext der Einstellungen, Abschlussstatus
lib/scoring.php        Strafpunkte, Rangliste, Vereinswertung
lib/region.php         Regiowertung: FIS-Punkte, beste vier von fünf Starts
lib/db.php             Datenbankzugriff und Einstellungen
lib/mail.php           Anmeldebestätigung
lib/pdf.php            abhängigkeitsfreier PDF-Generator
lib/runsheet_pdf.php   A4-Laufzettel als PDF
lib/layout.php         Kopf, Navigation, Bedienhilfen
lib/helpers.php        Hilfsfunktionen und Eingabeprüfung
lib/auth.php           Anmeldung des Wettkampfbüros
lib/migrations.php     versionierte Datenbankmigrationen
lib/update.php         Aktualisierung von GitHub
lib/version.php        Fassung des Programms
lib/season.php         Kompatibilitätspfad für alte Lesezeichen
sql/schema.sql         Tabellen und Constraints
tools/manifest.php     erzeugt manifest.json
tools/aufrufe_pruefen.php  sucht Aufrufe von Namen, die es nicht gibt
tools/regel_pruefen.php prüft die Strafpunktregel im Browser gegen die Datenbank
manifest.json          jede ausgelieferte Datei mit ihrer Prüfsumme
CHANGELOG.md           was sich je Fassung geändert hat
assets/                Gestaltung und Logos
```

Die Logik liegt in `lib/`, die Seiten liefern das HTML. Seiten sprechen nie direkt mit der
Datenbank, sondern über `lib/`.

## Gestaltung

Farben und Anordnung stehen in `assets/style.css`, Name und Logo der Plattform in `config.php`.

### Für welche Geräte

**Laptop und iPad.** Daran wird gebaut und daran wird geprüft. Ein Smartphone ist kein Ziel –
am Wettbewerbsplatz steht ein Laptop oder ein iPad, und für die Bedienung am Telefon ist
bewusst nichts vorgesehen.

Geprüfte Breiten, jeweils über die hauptgebrauchten Seiten:

| Gerät | Breite |
| --- | --- |
| iPad mini, Hochformat | 768 px |
| iPad Air 10,9", Hochformat | 810 px |
| iPad Pro 11", Hochformat | 834 px |
| iPad Pro 11", Querformat | 1194 px |
| iPad, Querformat | 1024 px |
| Laptop | 1280 bis 1920 px |

Geprüft wird auf **Querlauf**: die Seite darf nie breiter sein als der Bildschirm, sonst muss
man sie seitlich ziehen. Wer eine Tabelle breit baut, legt sie in
`<div class="table-scroll">` – so weitert sich die Tabelle, nicht die Seite.

Alle Bedienelemente teilen sich drei Höhen, damit Knöpfe, Eingabefelder und Auswahllisten in
einer Zeile bündig stehen:

| Grösse | Wert | Wofür |
| --- | --- | --- |
| `--ctl-h` | 44 px | normale Bedienelemente |
| `--ctl-h-sm` | 32 px | kompakt, in dichten Tabellen und Werkzeugleisten |
| `--ctl-h-lg` | 52 px | grosse Hauptaktion wie „Anmeldung senden“ |

Die Markierung für die kompakte Grösse sitzt auf dem **Bereich** (`class="dense"`), nicht auf
dem einzelnen Element. `.dense` verkleinert alles darin, so kann eine Zeile nicht halb kompakt
und halb normal sein. Für einzelne Knöpfe gibt es `.btn.small`.

Für Zeilen aus Beschriftung, Feld und Erklärung gibt es `.rule`: feste Spalten, damit alle Zahlen
in einer Linie stehen, und `align-items: center` statt `baseline` – die Grundlinie eines
`<input>` ist seine Unterkante, mit `baseline` stünde die Beschriftung sichtbar zu tief.

Eine Spaltenbeschriftung steht immer so wie ihr Inhalt darunter. Wer rechtsbündige Zahlen führt,
setzt `class="num"` auch auf die Überschrift (`<th class="num">DG 1</th>`). An einer Überschrift
gilt davon nur die Ausrichtung, nicht die Zahlenschrift:

```css
table.data th.num, table.data th.mid { font-family: inherit; }
```

`.mid` steht für mittig, `.num` für rechtsbündig, alles ohne Klasse für linksbündig. In
Tabellen, deren Ausrichtung über eine CSS-Regel auf die Spaltenposition läuft (`nth-child`),
wie dem Laufzettel, gilt die Klasse nicht als Ausrichtungsangabe.

## Sicherheit und Betrieb

- Anmeldung mit gehashtem Passwort, CSRF-Token auf **allen** Formularen, auch beim Abmelden.
- Prepared Statements für jeden Datenbankzugriff, Ausgabe über `h()` maskiert.
- **HTTPS auf einem öffentlichen Server.** Ohne sind Passwort und Anmeldedaten im Klartext
  mitlesbar.
- `config.php`, `lib/` und `sql/` gehören nicht in ein öffentlich erreichbares Verzeichnis. Die
  mitgelieferte `.htaccess` sperrt `config.php` und `lib/` sowie `sql/` für Apache. Für nginx
  entsprechend selbst konfigurieren.
- `install.php`, `upgrade.php` und `diagnose.php` nach der Einrichtung vom Server löschen.
- Zerstörende Änderungen an Vereinen und Modelltypen werden blockiert, sobald sie in
  abgeschlossenen Wettbewerben verwendet wurden. Ein Verein wird zusätzlich nicht gelöscht,
  solange Piloten, Anmeldungen, Wettbewerbe oder Konten an ihm hängen.

### Cookies und Datenschutz

Die Anwendung setzt **kein Tracking-Cookie** und lädt nichts von fremden Servern: keine
Analyse, keine Werbung, keine eingebetteten Karten oder Videos, keine externen Schriften, kein
Fingerabdruck, keine IP in der Sitzung.

Es gibt genau **ein** Cookie, `PHPSESSID` – die Sitzung, damit die Anmeldung erhalten bleibt.
Sie ist technisch notwendig; ohne sie könnte sich niemand einloggen. Sie wird mit `httponly` und
`samesite=Lax` gesetzt, sowie mit `secure`, sobald die Seite über HTTPS läuft. In der Sitzung
stehen nur die Konto-Nummer, CSRF- und Formular-Token sowie kurze Meldungstexte.

Deshalb gibt es **kein Einwilligungs-Banner**: Es wird nichts einwilligungsbedürftiges gesetzt.
Statt eines nutzlosen „Akzeptieren"-Knopfes steht auf der Anmeldeseite ein kurzer Hinweis, was die
Sitzung ist. Ein Banner wäre erst nötig, wenn Analyse oder Tracking hinzukäme.

**Es werden keine Kontaktdaten gespeichert.** Das Anmeldeformular fragt die E-Mail-Adresse ab, weil
die Anmeldebestätigung verschickt wird – danach wird sie verworfen. In der Datenbank steht sie
nicht. Dasselbe gilt für Telefonnummern: sie werden gar nicht erst erhoben. Auch die Startliste
führt keine Spalte *Kontakt* mehr, und beim Freigeben einer Anmeldung wandern keine Adressen in
den Wettbewerb. Die Adressspalten der Datenbank sind mit Migration 10 entfallen, mitsamt aller
Altbestände, die das frühere Formular übernommen hatte.

Einzig die **Absenderadresse** ist gespeichert: sie gehört dem Verein, wird unter
*Einstellungen → Anmeldung* gesetzt und steht in jeder Bestätigung. Sie ist keine Angabe über
eine Person.

Offen bleibt allein die Webstatistik des Hosters – die gehört zum Betreiber, nicht zum Programm.

## Fehlersuche

Antwortet eine Seite mit einem 500-Fehler, `diagnose.php` hochladen und aufrufen. Die Datei prüft
PHP-Version, benötigte Erweiterungen, Dateirechte, die Datenbankverbindung, alle Tabellen und ob
das Schema dem aktuellen Stand entspricht. Für die eigentliche Fehlermeldung mit Datei und Zeile
hilft meist nur das PHP-Fehlerprotokoll des Hosters. Danach `diagnose.php` löschen.

```
GET https://example.org/diagnose.php
```

Ein gemeldeter Hinweis `scores.motor fehlt` bedeutet: `upgrade.php` wurde noch nicht gelaufen.
Ein Hinweis `Strafpunktregeln je Wettbewerb` bedeutet dasselbe für eine Installation, die
teilweise migriert wurde.

## Lizenz

    Segelflug-Wettbewerb
    Copyright (C) 2026  Serge Huber, Pascal Schmidlin (MFV Brislach)

Dieses Programm ist freie Software: Sie können es unter den Bedingungen der GNU General Public
License, wie von der Free Software Foundation veröffentlicht, weitergeben und/oder verändern.

Dieses Programm wird **ohne jede Gewähr** bereitgestellt, siehe Abschnitt „NO WARRANTY“ der
Lizenz. Die Haftung für Schäden aus der Benutzung ist ausgeschlossen.

Der vollständige Text liegt in [`LICENSE`](LICENSE). GitHub zeigt ihn oben im Repository an.
Wer den Copyright-Namen ändern will, ersetzt die Zeile in der Datei `LICENSE` – der Lizenztext
selbst darf nicht verändert werden.

**Was das praktisch bedeutet:** wer die App benutzt, darf das. Wer sie verändert und weitergibt,
muss das unter den gleichen Bedingungen tun und den Quelltext offenlegen. Wer sie unverändert
weiterverbreitet, muss den Lizenztext und die Copyright-Zeile mitgeben. Eine Vereinswettbewerbs-
Installation darf beliebig betrieben und geändert werden; es gibt keine Einschränkung durch
Lizenzgebühren.
