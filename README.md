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
- [Benutzer und Rechte](#benutzer-und-rechte)
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

- **Öffentliche Seiten** ohne Konto: Rangliste je Modelltyp, Vereinswertung, Teilnehmerliste,
  Anmeldeformular.
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

Wird die Strafpunktregel geändert, gehört dazu:

```
php tools/regel_pruefen.php   # Bildschirmrechnung gegen Datenbank, Rückgabe 1 bei Abweichung
```

Beim Erfassen rechnet die Seite die Punkte selbst, damit die Anzeige ohne Verzögerung mitläuft.
Diese zweite Rechnung in `admin/erfassung.php` kann von der Datenbank in `lib/scoring.php`
abweichen – dann zeigt der Bildschirm beim Tippen einen anderen Wert, als gespeichert wird, und
der Wettbewerb wird nach dem Speichern scheinbar auf einmal anders. Das Skript holt die echte
Seite, führt die dortige Funktion unter `node` aus und vergleicht sie mit `calc_penalty()` über
17 Fälle: sauberer Flug, Motor allein, Aussenlandung, Bruchlandung, Nichtantritt und mehrere
Ankreuzfelder zusammen.

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
8. **Rangliste** – öffentlich unter `index.php`, je Modelltyp oder alle zusammen.
9. **Vereinswertung** – öffentlich unter `vereinswertung.php`.
10. **Wettbewerb beenden** – sobald alle Resultate erfasst sind. Der Wettbewerb bleibt als
    Archiv erhalten und lässt sich mit *Wieder öffnen* zurückholen.

## Benutzer und Rechte

Es gibt genau zwei Rollen:

| Rolle | Darf |
| --- | --- |
| **SuperAdmin** | alles, was ein Benutzer darf, **plus** die Benutzerverwaltung – und **alle** Vereine sehen |
| **Benutzer** | die Wettbewerbe **seines Vereins** steuern: Erfassung, Startliste, Durchgänge, Vereine, Modelltypen, Anmeldungen, Export, Laufzettel und die Einstellungen des jeweiligen Wettbewerbs – und das eigene Passwort ändern |

Die Zugehörigkeit zum Verein entscheidet, wer welchen Wettbewerb steuern darf. Ein Benutzer
kann **nicht** anlegen, ändern, sperren oder löschen – auch nicht mit einem abgefangenen oder
manipulierten Aufruf. Die Seite **Benutzer** ist für ihn weder erreichbar noch in der Navigation
zu sehen.

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
der Flug wird dann nach Flugzeit und Landewert gewertet. Was dazuzählt, hängt am Ausgang:

| Angekreuzte Felder | Zeit | Landewert | Feststrafe |
| --- | --- | --- | --- |
| keine | ja | ja | – |
| Motor allein | ja | ja | – (Motor kommt dazu) |
| Aussenlandung | ja | **nein** | `penalty_outlanding` |
| Bruchlandung | ja | ja | `penalty_crash` |
| nicht angetreten | **nein** | **nein** | `penalty_not_started` |
| Aussenlandung & Motor | ja | nein | `penalty_outlanding` + `penalty_motor` |
| Bruchlandung & Motor | ja | ja | `penalty_crash` + `penalty_motor` |

Die Begründung dahinter: **die Zeitabweichung zählt immer**, auch bei einer Bruchlandung – der
Flug hat eine Zeit, und die wird gemessen. **Bei der Aussenlandung ist der Landewert null**, weil
das Landen ausserhalb des Feldes gerade das Ereignis ist; ein zusätzlicher Landewert würde es
doppelt bestrafen. **Bei der Bruchlandung zählt er**, weil sie im Landefeld passieren kann. **Beim
Nichtantritt sind beide null**, es wurde nicht geflogen. Die drei Feststrafen bleiben und kommen
dazu; sie stehen unter *Einstellungen → Strafpunkte* und sind je Verein einstellbar.

Bei mehreren angekreuzten Feldern gewinnt in dieser Reihenfolge: Bruchlandung, Aussenlandung,
nicht angetreten.

**Flugzeit und Landewert bleiben immer bedienbar**, auch neben einem angekreuzten
Feld. Wer eine Aussenlandung nach 3:20 Landewert 15 hatte, trägt beides ein – bei der
Aussenlandung zählt nur die Zeit, der Landewert wird mitgespeichert und geht nicht verloren.
Nur beim freien Flug ist die Flugzeit Pflicht, weil ohne sie nichts zu rechnen wäre.

| Eingabe | Folge |
| --- | --- |
| keine Felder, keine Zeit, kein Landewert | die Zeile bleibt ohne Resultat |
| Feld und leere Zeit | die Feststrafe zählt, die Zeit nicht |

Kombiniert werden kann alles; tritt eine Bruch- oder Aussenlandung mit „nicht angetreten"
zusammen auf, zählt die Bruch- beziehungsweise Aussenlandung.

Der Landewert ist eine Zahl: entweder die Distanz in Metern zum Landepunkt oder direkt eine
Punktzahl aus der Landetabelle.

**Streichresultat.** Das schlechteste Resultat kann ab einer einstellbaren Anzahl geflogener
Durchgänge gestrichen werden. Bei Punktegleichheit entscheidet zuerst das kleinere
Streichresultat, danach die Anzahl gültiger Flüge, danach das beste Einzelresultat. Ein Flug mit
Motor zählt dabei nicht als gültiger Flug.

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
- Regiocup: welcher Verein die Regiorangliste sehen und exportieren darf, auch wenn
  die Ergebnisse sonst nicht öffentlich sind
- Passwort des eigenen Kontos und weitere Zugänge

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
Einstellungen. Er kann sofort aktiviert werden. Der aktive Wettbewerb ist der Vorgabe für
Erfassung, Anmeldung, Export und die öffentlichen Seiten.

Beim Anlegen werden die Einstellungen des gerade ausgewählten Wettbewerbs als Vorlage kopiert.
So haben zwei Vereinswettbewerbe unterschiedliche Regeln, ohne dass die bestehenden
Wettbewerbe verändert werden. Ausgenommen ist die Absenderadresse der Anmeldebestätigung: sie
gehört jedem Verein selbst und wird bewusst nicht vererbt.

**Zum Regiocup.** Beim Anlegen lässt sich ankreuzen, ob der Wettbewerb zur Regiowertung des
Jahres zählt; auf der Wettbewerbsseite schaltet der Knopf **🏆 Regiocup** das jederzeit um.
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
Resultate, Anmeldungen und Wettbewerbseinstellungen. Der bisherige aktive Wettbewerb bleibt
aktiv, der Name des abgeschlossenen bleibt änderbar. Bei einem Korrekturfehler holt ein
Administrator ihn mit *Wieder öffnen* zurück.

Ein Wettbewerb mit Anmeldungen oder Resultaten wird nicht gelöscht, damit keine Daten
verloren gehen.

**Auswahl.** Die öffentlichen Seiten bieten bei mehreren Wettbewerben ein Dropdown, gruppiert
nach *Offene Wettbewerbe* und *Abgeschlossene Wettbewerbe*, mit dem aktiven Wettbewerb oben.
Ein gezielter Aufruf funktioniert mit `index.php?competition=2` – und zwar auch ohne
Anmeldung, denn die öffentlichen Seiten zeigen jeden Wettbewerb. Die frühere Form
`?season=...` wird beim Lesen noch akzeptiert.

Dass *Sichtbarkeit* und *Zugriff* getrennt sind, ist Absicht: jeder Wettbewerb ist öffentlich
sichtbar, und die Verwaltungsseiten fragen den Zugriff selbst ab. Ein gemeinsamer Aufruf hatte
beides vermischt – die Verwaltungsprüfung beantwortet für Besucher immer mit „nein“ und hätte
damit auch die Wettbewerbsauswahl auf den öffentlichen Seiten ausser Kraft gesetzt, sodass dort
immer der aktive Wettbewerb erschien.

Deshalb ruft jede Seite unter `admin/` die Auswahl mit einem zweiten Schalter auf
(`resolve_competition_param($roh, true)`). Wer einen Wettbewerb nennt, den das Konto nicht steuern
darf, wird dort auf einen eigenen umgeleitet und sieht dort nichts vom fremden. Die öffentlichen
Seiten rufen die Auswahl ohne den Schalter auf.

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
index.php              öffentliche Rangliste
vereinswertung.php     öffentliche Vereinswertung
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
