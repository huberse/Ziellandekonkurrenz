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

Die Dateien `admin/saisons.php` und `lib/season.php` bleiben nur als Kompatibilitätspfade für
alte Lesezeichen erhalten. Neue URLs verwenden `competition`.

## Ablauf an einem Wettbewerbstag

1. **Einstellungen** – Name, Datum, Ort und die Strafpunkt-Regeln prüfen.
2. **Modelltypen** – Segler, Elektro, weitere. Die Rangliste wird je Modelltyp ausgewertet.
3. **Vereine** – alle teilnehmenden Vereine, für Auswahl und Vereinswertung.
4. **Piloten** – nur für den gewählten Wettbewerb: einzeln, als Liste aus Excel oder über
   freigegebene Anmeldungen. *Startnummern zufällig neu vergeben* nummeriert alle aktiven
   Piloten dieses Wettbewerbs nach Modelltyp und Zufall neu.
5. **Durchgänge** – Anzahl einstellen, Zielzeit je Durchgang anpassen, Wertung aktivieren.
6. **Laufzettel als PDF** – ausdrucken. Je Durchgang zwei Blätter, eines für die ungeraden und
   eines für die geraden Startnummern, jeweils mit Flugzeit, Landewert und drei Ankreuzfeldern.
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
| **SuperAdmin** | alles, was ein Benutzer darf, **plus** die Benutzerverwaltung |
| **Benutzer** | den gesamten Wettbewerb steuern: Erfassung, Startliste, Durchgänge, Vereine, Modelltypen, Anmeldungen, Export, Laufzettel und die Einstellungen des jeweiligen Wettbewerbs – und das eigene Passwort ändern |

Alle Benutzer steuern also denselben Wettbewerb; der Unterschied betrifft nur die Konten selbst.
Ein Benutzer kann **nicht** anlegen, ändern, sperren oder löschen – auch nicht mit einem
abgefangenen oder manipulierten Aufruf. Die Seite **Benutzer** ist für ihn weder erreichbar noch
in der Navigation zu sehen.

Unter **Benutzer** kann der SuperAdmin je Konto:

- den **Anzeigamen** ändern (erscheint oben im Kopf),
- die Rolle zwischen Benutzer und SuperAdmin umstellen,
- das Konto **sperren** oder wieder freigeben – ein gesperrtes Konto kann sich nicht anmelden,
  und eine noch laufende Sitzung endet beim nächsten Aufruf, nicht erst beim Abmelden,
- ein **neues Passwort** setzen, etwa wenn jemand das eigene vergessen hat,
- das Konto **löschen**.

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
| Strafe nicht angetreten | `penalty_not_started` | fester Betrag |
| Strafe Motor angelassen | `penalty_motor` | fester Betrag, bei einer Aussenlandung zusätzlich |

Es gibt **keine Obergrenze**: eine grosse Zeitabweichung oder ein weit entfernter Landepunkt
kostet unbegrenzt Punkte. Die festen Strafen wirken ohnehin als Gesamtbetrag.

Zwei Regeln, die man leicht falsch liest:

**Die Zeitabweichung ist ein Betrag.** Zwei Sekunden zu lang und zwei Sekunden zu kurz kosten
gleich viel – es gibt nur einen Satz je Sekunde.

**Der Motor ist eine Zusatzstrafe, keine eigene Ergebnisart.** Bei einem geflogenen Flug ersetzt
sie Zeit und Landewert, gezählt wird allein die Motorstrafe. Bei einer Aussenlandung oder einem
Nichtantritt kommt sie zur jeweiligen Feststrafe dazu.

| Auswahl beim Erfassen | Punkte |
| --- | --- |
| geflogen | Zeitabweichung + Landepunkte |
| nicht angetreten | `penalty_not_started` |
| Aussenlandung | `penalty_outlanding` |
| Motor angelassen | `penalty_motor` |
| Aussenlandung & Motor angelassen | `penalty_outlanding` + `penalty_motor` |
| kein Eintrag | die Zeile bleibt ohne Resultat |

Der Landewert ist eine Zahl: entweder die Distanz in Metern zum Landepunkt oder direkt eine
Punktzahl aus der Landetabelle.

**Streichresultat.** Das schlechteste Resultat kann ab einer einstellbaren Anzahl geflogener
Durchgänge gestrichen werden. Bei Punktegleichheit entscheidet zuerst das kleinere
Streichresultat, danach die Anzahl gültiger Flüge, danach das beste Einzelresultat. Ein Flug mit
Motor zählt dabei nicht als gültiger Flug.

**Nachträgliche Änderungen.** Wird eine Regel oder eine Zielzeit geändert, bleiben bereits
gespeicherte Punkte stehen. Unter **Durchgänge** rechnet *Punkte neu berechnen* einen Durchgang
mit den aktuellen Regeln des Wettbewerbs nach – auch die festen Strafen und der Motorstart.

### Laufzettel

Je Durchgang entstehen zwei A4-Blätter. Neben Flugzeit und Landewert hat jede Zeile drei
Ankreuzfelder:

- **nicht angetreten**
- **Aussenlandung**
- **Motor angelassen** – bei einem elektrischen Modell wurde der Motor angelassen

Kein Feld angekreuzt heisst „geflogen". Aussenlandung und Motor zusammen ergeben
„Aussenlandung & Motor angelassen". Die Bezeichnungen stehen in der Kopfzeile, die Punkte für
jedes Feld in der Legende darunter, damit der Zeitnehmer sie nicht nachschlagen muss.

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
Ein gezielter Aufruf funktioniert mit `index.php?competition=2`. Die frühere Form
`?season=...` wird beim Lesen noch akzeptiert.

**Noch kein Mandantenmodell.** Benutzer, Vereine und Modelltypen sind global; die Wettbewerbe
sind datenseitig getrennt. Vereins- und Modelltypennamen können deshalb global gepflegt
werden, wobei destruktive Änderungen an Kategorien blockiert werden, sobald sie in
abgeschlossenen Wettbewerben verwendet wurden.

## Anmeldung

`anmeldung.php` ist das öffentliche Formular. Eingegangene Anmeldungen erscheinen unter
**Anmeldungen** und wandern beim Freigeben mit einer Startnummer in die Startliste. Das Formular
lässt sich pro Wettbewerb schliessen und mit einem eigenen Text versehen; die Auswahl zeigt nur
Wettbewerbe, die noch nicht beendet sind.

Das Formular fragt Vorname, Name, Verein, E-Mail, Modelltyp, Modell und Bemerkung ab – ein
Telefonfeld gibt es nicht.

**Die E-Mail-Adresse wird nicht gespeichert.** Sie dient ausschliesslich der Bestätigung.
Verschickt wird diese erst, nachdem die Anmeldung gespeichert *und wieder aus der Datenbank
gelesen* wurde – eine Bestätigung ohne Eintrag kann es dadurch nicht geben. Die Absenderadresse
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
admin/login.php        Anmeldung des Wettkampfbüros
admin/logout.php       Abmeldung

install.php            Einrichtung – danach löschen
upgrade.php            Aktualisierung – danach löschen
diagnose.php           Fehlersuche – danach löschen

lib/competition.php    Wettbewerbe, Kontext der Einstellungen, Abschlussstatus
lib/scoring.php        Strafpunkte, Rangliste, Vereinswertung
lib/db.php             Datenbankzugriff und Einstellungen
lib/mail.php           Anmeldebestätigung
lib/pdf.php            abhängigkeitsfreier PDF-Generator
lib/runsheet_pdf.php   A4-Laufzettel als PDF
lib/layout.php         Kopf, Navigation, Bedienhilfen
lib/helpers.php        Hilfsfunktionen und Eingabeprüfung
lib/auth.php           Anmeldung des Wettkampfbüros
lib/migrations.php     versionierte Datenbankmigrationen
lib/season.php         Kompatibilitätspfad für alte Lesezeichen
sql/schema.sql         Tabellen und Constraints
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
  abgeschlossenen Wettbewerben verwendet wurden.

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
