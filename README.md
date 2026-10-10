# Segelflug-Wettbewerb

Wertung und Ergebnisverwaltung für Modellsegelflug-Wettbewerbe: Zielzeit treffen, auf den Punkt
landen, und daraus eine Rangliste, die alle nachvollziehen können.

Die Zeitnehmer schreiben wie bisher auf Papier. Das Programm druckt dafür die Laufzettel aus und
nimmt die Zahlen danach im Wettkampfbüro auf. Mehrere Wettbewerbe eines Vereins oder mehrerer
Vereine laufen in derselben Installation; die Wettbewerbe bleiben vollständig getrennt.

**PHP und MySQL, ohne Framework, ohne Composer, ohne externe Bibliotheken.** Die Dateien werden
auf einen Webserver gelegt und sind danach lauffähig.

Diese Anleitung beschreibt, was das Programm tut, wie man es aufsetzt und wie man damit arbeitet.
Kurzfassung: [Installation](#installation) – [Der Wettbewerbstag](#der-wettbewerbstag) –
[Aktualisieren](#aktualisieren).

---

## Inhalt

- [Was das Programm tut](#was-das-programm-tut)
- [Anforderungen](#anforderungen)
- [Installation](#installation)
- [Das erste Konto](#das-erste-konto)
- [Der Wettbewerbstag](#der-wettbewerbstag)
- [Wettbewerbe](#wettbewerbe)
- [Erfassen und Wertung](#erfassen-und-wertung)
- [Anmeldung](#anmeldung)
- [Die öffentlichen Seiten](#die-öffentlichen-seiten)
- [Benutzer und Rechte](#benutzer-und-rechte)
- [Einstellungen](#einstellungen)
- [Regiocup](#regiocup)
- [Aktualisieren](#aktualisieren)
- [Fehlersuche](#fehlersuche)
- [Sicherheit und Betrieb](#sicherheit-und-betrieb)
- [Datenschutz](#datenschutz)
- [Lizenz und Copyright](#lizenz-und-copyright)

---

## Was das Programm tut

**Für Besucher, ohne Konto:** eine Startseite mit allen Wettbewerben, die Rangliste je Wettbewerb,
die Teilnehmerliste, die Vereinswertung und die Regiorangliste. Nichts davon verlangt eine
Anmeldung.

**Für die Wettkampfleitung, mit Konto:** das Wettkampfbüro. Dort liegen die Startliste, die
Durchgänge, die Erfassung der Resultate, die Anmeldungen, der Laufzettel als PDF, der
CSV-Export und die Einstellungen des Wettbewerbs.

**Punkte statt Platzierungen.** Bewertet wird die Abweichung: je Sekunde neben der Zielzeit und
je Einheit Landepunkt gibt es Punkte, dazu feste Beträge für die besonderen Ausgänge eines
Fluges. **Wenige Punkte sind gut.** Die Regeln stehen je Wettbewerb in den Einstellungen, damit
jeder Verein seine eigenen Zahlen haben kann. Über der Rangliste steht, mit welchen Regeln sie
gerechnet wurde – jeder kann also nachrechnen.

**Anmeldung über das Internet.** Die Mitglieder melden sich selbst an, die Wettkampfleitung gibt
die Anmeldungen frei, und die freigegebenen Piloten stehen mit Startnummer in der Startliste.

**Mehrere Vereine in einer Installation.** Jeder Wettbewerb gehört genau einem Verein, und
jedes Konto sieht und bearbeitet die Wettbewerbe seines Vereins. Was den ganzen Betrieb betrifft,
liegt beim SuperAdmin.

## Anforderungen

| | |
| --- | --- |
| PHP | 7.3 oder neuer, mit `pdo_mysql` und `mbstring` |
| Datenbank | MySQL 5.7 oder MariaDB 10.3 oder neuer, Zeichensatz `utf8mb4` |
| Webserver | Apache mit `mod_rewrite` oder nginx |
| Schreibrecht | im Programmverzeichnis, damit die Aktualisierung Dateien einsetzen kann |
| Sonstiges | nichts – kein Composer, kein Build-Schritt, keine externen Schriften oder Skripte |

Für die **Aktualisierung per Knopf** braucht es zusätzlich PHP 7.4 oder neuer, `ZipArchive` und
`curl` (oder `allow_url_fopen`). Fehlt eine dieser Voraussetzungen, bleibt die Aktualisierungsseite
bedienbar und sagt, woran es liegt; das übrige Programm läuft unbeeinflusst weiter.

Die Oberfläche lädt keine externen Ressourcen. Das Programm läuft damit auch dann, wenn am
Wettkampfort kein Internet zur Verfügung steht. Der Aktualisierungsknopf braucht natürlich
Internet – die übrige Anwendung nicht.

## Installation

### 1. Datenbank anlegen

Eine leere Datenbank mit Zeichensatz `utf8mb4` anlegen und einen Benutzer einrichten, der Rechte
darauf hat.

### 2. `config.php` anlegen

`config.sample.php` nach `config.php` kopieren und die Zugangsdaten eintragen:

```php
return [
    'db_host' => 'localhost',
    'db_name' => 'segelflug',
    'db_user' => 'segelflug',
    'db_pass' => 'geheim',
    'db_port' => 3306,
    'timezone' => 'Europe/Zurich',
    'site_name' => 'Ziellandekonkurrenz',
    'logo'      => 'logo.png',           // Datei in assets/
];
```

`site_name` und `logo` gehören zur Installation und nicht zu einem Wettbewerb. Oben links im Kopf
steht der Name der Plattform, der ausgewählte Wettbewerb daneben im Abzeichen – jeder Wettbewerb
bekommt seinen Namen also nur einmal zu sehen. Fehlen die beiden Angaben in einer älteren
`config.php`, gelten `Ziellandekonkurrenz` und `logo.png`.

**Eigenes Logo:** eigene Datei nach `assets/` legen und den Dateinamen hier eintragen. Der Eintrag
in `config.php` bleibt bei jeder Aktualisierung stehen – ein Aktualisieren überschreibt das Logo
des Vereins nicht.

### 3. `install.php` aufrufen

Die Dateien hochladen und `install.php` im Browser öffnen. Das legt die Tabellen, den ersten
Wettbewerb, die Durchgänge und das erste Konto an.

### 4. Aufräumen

| Datei | danach |
| --- | --- |
| `install.php` | **löschen** – sie gehört nicht auf einen Server im Internet |
| `diagnose.php` | darf bleiben, bis man sie einmal gebraucht hat; danach ebenfalls löschen |
| `admin/upgrade.php` | darf liegen bleiben, siehe [Aktualisieren](#aktualisieren) |

Antwortet eine Seite mit einem 500-Fehler, hilft [`diagnose.php`](#fehlersuche).

Wer bereits eine ältere Fassung dieses Programms betreibt, nimmt **nicht** `install.php`, sondern
den Weg über [Aktualisieren](#aktualisieren) – sonst gingen die bisherigen Daten verloren.

## Das erste Konto

Bei der Einrichtung wird das erste Konto als **SuperAdmin** angelegt. Bei einer bestehenden
Installation wird das älteste Konto zum SuperAdmin, damit die Kontenverwaltung erreichbar bleibt.

**Es gibt genau zwei Rollen.** Oben rechts in der Kopfzeile steht das Benutzersymbol; das öffnet
ein Menü mit dem eigenen Namen, der Rolle und den Punkten, die man dort öffnen kann.

| Rolle | Kommt in das Wettkampfbüro, um … |
| --- | --- |
| **SuperAdmin** | alles zu bedienen, **plus** die Konten, die Vereine, die Modelltypen, die Stammdaten, den Regiocup und die Aktualisierung |
| **Wettkampfleitung** | die Wettbewerbe des eigenen Vereins zu steuern: Erfassung, Startliste, Durchgänge, Anmeldungen, Export, Laufzettel und Einstellungen |

**Die untere Leiste** im Wettkampfbüro führt durch den laufenden Betrieb: **Wettbewerbe**,
**Piloten**, **Durchgänge**, **Resultate erfassen**, **Anmeldungen**, **Einstellungen**.

**Das Benutzermenü** oben rechts führt zurück: **Profil**, **Wettkampfbüro** und – nur für den
SuperAdmin – **Stammdaten**, **Vereine**, **Modelltypen**, **Regiocup**, **Benutzer verwalten**
und **Aktualisierung**. Darunter **Abmelden**.

## Der Wettbewerbstag

1. **Einstellungen** – Name, Datum, Ort und die Punkteregeln prüfen.
2. **Modelltypen** – Segler, Elektro, weitere. Die Rangliste wird je Modelltyp ausgewertet.
3. **Vereine** – alle teilnehmenden Vereine. Ein Verein mit Piloten, Anmeldungen, Wettbewerben
   oder Konten lässt sich nicht löschen; der Knopf nennt den Grund.
4. **Piloten** – nur für den gewählten Wettbewerb: einzeln, als Liste aus einer Tabelle, oder
   über freigegebene Anmeldungen. Über der Startliste liegen *Startnummern neu vergeben*
   (nummeriert alle aktiven Piloten dieses Wettbewerbs nach Modelltyp und zufällig neu) und
   *Startliste als CSV* (für die Aufkleber; die erste Zeile nennt den Wettbewerb, damit sich
   mehrere Bogen auseinanderhalten lassen).
5. **Durchgänge** – Anzahl einstellen, Zielzeit je Durchgang anpassen, Wertung aktivieren.
6. **Laufzettel-PDF** – ausdrucken. Je Durchgang zwei Blätter, eines für die ungeraden und eines
   für die geraden Startnummern.
7. **Resultate erfassen** – ein Durchgang pro Seite, eine Zeile pro Pilot. Flugzeit als `2:58`
   oder `178`. Die Punkte stehen live in der letzten Spalte.
8. **Rangliste** – öffentlich, je Modelltyp oder alle zusammen.
9. **Vereinswertung** – steht am Ende der öffentlichen Rangliste, unter demselben Filter wie die
   Piloten.
10. **Wettbewerb beenden** – sobald alle Resultate erfasst sind. Der Wettbewerb bleibt als Archiv
    erhalten und lässt sich wieder öffnen.

Am Ende der Saison muss **jeder** Wettbewerb zu. Dafür gibt es zwei Knöpfe: **✓ Beenden**, wenn
nichts fehlt, und **✓ Trotzdem beenden**, wenn doch etwas fehlt – mit der Zahl der Lücken in der
Rückfrage.

## Wettbewerbe

Ein Wettbewerb bekommt einen aussagekräftigen Namen, zum Beispiel `Wettbewerbsname 2027`, eigene
Durchgänge, eine leere Startliste und eigene Einstellungen. Der **Vereinsname gehört nicht hinein**:
der Veranstalter steht als eigenes Feld daneben und ist für ein Vereinskonto ohnehin fest eingetragen.

### Die vier Zustände

| Zustand | bedeutet |
| --- | --- |
| **offen** | angelegt, nicht aktiv, nicht beendet |
| **aktiv** | offen **und** der, an dem gerade gearbeitet wird |
| **beendet** | abgeschlossen, Ergebnis gespeichert und gesperrt |
| **abgesagt** | fand **nicht statt**, etwa wegen Wetter ohne Ersatztermin |

**Ein neuer Wettbewerb ist offen, nicht aktiv.** Aktiviert wird ausdrücklich über **◉ Aktivieren**
in der Liste der Wettbewerbe.

**Ein beendeter Wettbewerb ist nie aktiv.** Er bleibt als Archiv stehen, seine Ergebnisse sind
gesperrt.

**Ein abgesagter Wettbewerb ist beendet und gesperrt – er fand nur nicht statt.** Das ist der
Unterschied, und er betrifft die Regiowertung: **ein abgesagter Wettbewerb zählt gar nicht**,
auch nicht anteilig. Sonst hinge der Punktestand eines Piloten davon ab, an welchem Tag abgesagt
wurde, und nicht davon, ob er geflogen ist. Was erfasst wurde, bleibt sichtbar, wird aber nicht
gewertet.

### Aktivieren

**Nur der SuperAdmin darf aktivieren**, über **◉ Aktivieren** in der Liste der Wettbewerbe. Das ist
der einzige Ort im Programm, an dem mehr als ein Verein betroffen ist: Aktivieren schaltet **alle**
anderen Wettbewerbe ab. Deshalb gehört es nicht in die Hand eines einzelnen Vereins – ein Klick,
der einem anderen mitten im Wettbewerbstag die Erfassung wegnimmt, ist kein Versehen, sondern eine
Entscheidung, die jemand treffen muss, der beide Seiten sieht.

Bei einem Vereinskonto steht an derselben Stelle derselbe Knopf als Hinweis: **◉ Aktivieren – der
SuperAdmin**. Er bleibt sichtbar, damit klar ist, dass etwas zu tun ist.

**Beendet wird der aktive Wettbewerb nicht automatisch durch einen anderen ersetzt.** Am Ende der
Saison soll es ohne aktiven Wettbewerb bleiben – das ist gewollt und keine Störung. Gibt es
überhaupt keinen aktiven Wettbewerb, muss einer her, sonst zeigt jede Seite ins Leere; dann wird
der neue aktiv, und die Meldung sagt es auch so.

### Beenden, absagen, wieder öffnen

**✓ Beenden** geht, sobald für jeden aktiven Piloten in jedem gewerteten Durchgang ein Resultat
vorliegt – Aussenlandung und „nicht angetreten“ zählen mit. Danach bleiben Startliste, Resultate,
PDF und Export sichtbar; gesperrt sind Resultate, Anmeldungen und Wettbewerbseinstellungen. Der
Name des Wettbewerbs selbst bleibt auch nach dem Abschluss änderbar.

**☁ Abgesagt** markiert einen Wettbewerb, der **nicht stattgefunden** hat. Der häufigste Fall ist
der Wetterausfall vor dem ersten Durchgang: da fehlt gar nichts, es wurde nur nie geflogen.
Abgelehnt wird die Absage, wenn alle Ergebnisse vorliegen; dann hat der Wettbewerb stattgefunden.

Zurück geht es auf zwei Wegen, weil beides vorkommt: **✓ Fand doch statt** hebt die Absage auf,
**↺ Wieder öffnen** macht einen beendeten Wettbewerb wieder zur Bearbeitung auf.

### Löschen

Ein Wettbewerb **ohne Resultate und nicht beendet** lässt sich ganz normal löschen. Für die
beiden anderen Fälle gibt es je einen **zweiten, ausdrücklichen** Knopf, beide nur für den
SuperAdmin:

- **Löschen samt Ergebnissen** bei einem einzelnen Wettbewerb, mit Ergebnissen oder im
  abgeschlossenen Zustand. Die Rückfrage nennt vorher die Zahlen: Resultate, Durchgänge,
  Startlisteneinträge, Anmeldungen. Die **Stammsätze der Piloten bleiben** stehen.
- **Alles aus dem Wettbewerbsbetrieb löschen** in der **Aktualisierung**, für den Fall, dass ein
  Wettbewerb erst später offiziell anfangt und alles davor Testmüll ist. Das nimmt Wettbewerbe,
  Durchgänge, Startlisten, Resultate, die ganzen Stammsätze und alle Anmeldungen weg. **Konten,
  Vereine und Modelltypen bleiben.**

Warum dafür ein eigener Knopf und nicht einfach keiner: Aufräumen in Schritten sieht brauchbar aus
und ist dann doch blockiert, weil ein Stammsatz nicht wegzuführen ist, solange der Pilot in einer
Startliste steht. Der zweite Knopf räumt deshalb alles auf einmal weg – dafür gibt es vorher eine
Sicherung.

Nach dem Leeren **einen Wettbewerb anlegen** – er wird gleich der aktive, weil kein anderer da ist.
Ohne diesen Schritt passiert gar nichts.

## Erfassen und Wertung

### Der Laufzettel

Je Durchgang entstehen zwei A4-Blätter – eines für die ungeraden, eines für die geraden
Startnummern. Neben Flugzeit und Landewert hat jede Zeile vier Ankreuzfelder:

- **nicht angetreten**
- **Aussenlandung**
- **Bruchlandung** – das Modell ist beim Landen zerstört
- **Motor angelassen** – bei einem elektrischen Modell wurde der Motor angelassen

Kein Feld angekreuzt heisst „geflogen“. Die Felder dürfen einzeln oder zusammen angekreuzt werden;
der Motor kommt zu jedem Ausgang dazu. Die Bezeichnungen stehen in der Kopfzeile, die Punkte für
jedes Feld in der Legende darunter, damit der Zeitnehmer sie nicht nachschlagen muss.

### Die sechs Strafpunkte

Unter **Einstellungen → Strafpunkte** stehen die Regeln des Wettbewerbs. Wenige Punkte sind gut.

| Baustein | Bedeutung |
| --- | --- |
| **Zeitabweichung** | Punkte je Sekunde Abweichung von der Zielzeit |
| **Landewert** | Punkte je Einheit – entweder Meter zum Landepunkt oder direkt Punkte aus der Landetabelle |
| **Aussenlandung** | fester Betrag |
| **Bruchlandung** | fester Betrag |
| **Nicht angetreten** | fester Betrag |
| **Motor angelassen** | fester Betrag, beim gelungenen Flug allein, sonst zusätzlich |

Es gibt **keine Obergrenze**: eine grosse Zeitabweichung oder ein weit entfernter Landepunkt kostet
unbegrenzt Punkte. Die festen Strafen wirken ohnehin als Gesamtbetrag.

Zwei Regeln, die man leicht falsch liest:

**Die Zeitabweichung ist ein Betrag.** Zwei Sekunden zu lang und zwei Sekunden zu kurz kosten
gleich viel – es gibt nur einen Satz je Sekunde.

**Der Motor ist eine Zusatzstrafe, keine eigene Ergebnisart.** Er kommt zu allem dazu und ersetzt
nichts.

Die Einstellungsseite rechnet die gespeicherten Werte direkt als Beispiel vor, damit man sieht,
was die eigenen Zahlen ergeben.

### Die vier Kästchen beim Erfassen

Genau dieselben vier wie auf dem Laufzettel. **Kein Feld heisst „geflogen“**, der Flug wird dann
nach Flugzeit und Landewert gewertet. Die Kästchen sind **unabhängig**: eine Aussenlandung schliesst
eine Bruchlandung nicht aus, denn ein Modell kann neben der Piste gelandet sein und dort Teile
verloren haben.

| Angekreuzte Felder | Zeit | Landewert | Feststrafen |
| --- | --- | --- | --- |
| keine | ja | ja | – |
| Motor | ja | ja | – (Motor kommt dazu) |
| Aussenlandung | ja | **nein** | Aussenlandung |
| Bruchlandung | ja | ja | Bruchlandung |
| Aussenlandung + Bruchlandung | ja | **ja** | beide, zusammen |
| nicht angetreten | **nein** | **nein** | nicht angetreten |

Die Begründung dahinter: **die Zeitabweichung zählt immer**, auch bei einer Bruchlandung – der Flug
hat eine Zeit, und die wird gemessen. **Bei der Aussenlandung ist der Landewert null**, weil das
Landen ausserhalb des Feldes gerade das Ereignis ist. **Bei der Bruchlandung zählt er**, weil sie
im Landefeld passieren kann – und bei der Kombination aus beiden ebenfalls. **Beim Nichtantritt
sind beide null.**

**Sobald „nicht angetreten“ angekreuzt ist, sind die anderen Felder gesperrt** und die Zeit steht
auf 0:00. Beim Abwählen kommt der vorher eingetragene Wert zurück, damit ein Fehlklick nichts
vernichtet.

**Flugzeit und Landewert bleiben immer bedienbar**, auch neben einem angekreuzten Feld. Wer eine
Aussenlandung nach 3:20 Landewert 15 hatte, trägt beides ein – bei der Aussenlandung zählt nur die
Zeit, der Landewert wird mitgespeichert und geht nicht verloren.

### Streichresultat

Das schlechteste Resultat kann ab einer einstellbaren Anzahl geflogener Durchgänge gestrichen
werden – **Einstellungen → Rangliste**. Bei Punktegleichheit entscheidet **zuerst das kleinere
Streichresultat**, danach das beste Einzelresultat, zuletzt der Name. Dieselbe Reihenfolge gilt in
der Vereinswertung.

Dazwischen wird bewusst **nichts geprüft, was den Ausgang eines Fluges betrachtet**. All das steht
schon in den Punkten, und ein gleichwertiger Pilot darf nicht darunter leiden, dass er einmal den
Motor angelassen hat.

### Regeln ändern

Wird eine Regel oder eine Zielzeit geändert, bleiben bereits gespeicherte Punkte stehen. Unter
**Durchgänge** rechnet *Neu berechnen* mit den aktuellen Regeln des Wettbewerbs nach – auch die
festen Strafen und den Motorstart. Für den ganzen Wettbewerb auf einmal gibt es
**Alle Durchgänge neu berechnen**; die Rückfrage nennt vorher, wie viele Resultate betroffen sind,
und sagt, was **nicht** verändert wird: Flugzeit, Landewert und die Kästchen bleiben, nur die
daraus berechneten Punkte ändern sich.

Nachrechnen lässt sich nur, was gespeichert ist. Resultate, die in sehr alten Fassungen ohne Zeit
erfasst wurden, enthalten keine Flugzeit – die müssen neu eingetragen werden.

## Anmeldung

`anmeldung.php` ist das öffentliche Formular. Eingegangene Anmeldungen erscheinen unter
**Anmeldungen** und wandern beim Freigeben mit einer Startnummer in die Startliste. Das Formular
lässt sich je Wettbewerb schliessen und mit einem eigenen Text versehen.

Das Formular fragt **SMV-Nummer**, Vorname, Name, Verein, E-Mail, Modelltyp, Modell und
Bemerkung ab. Ein Telefonfeld gibt es nicht. Die SMV-Nummer steht als erstes Feld.

### Die SMV-Nummer

Die Nummer kommt aus dem Link: der Verein verschickt je Mitglied `anmeldung.php?smv=123456`.
Steht die Nummer in den **Stammdaten**, kommt der Name gleich mit ausgefüllt. Das ist eine
**Ergänzung, kein Ersatz**: wer die Nummer von Hand eintippt, überschreibt nichts, und ein selbst
eingetragener Name wird nie überschrieben.

**Die Nummer ist Pflicht.** Das Formular lehnt eine Anmeldung ohne Nummer ab und sagt, wo sie
steht. Das ist keine Strenge um der Strenge willen: mit einer freiwilligen Nummer entstehen zwei
Stammsätze für dieselbe Person, sobald sie sich einmal ohne und einmal mit ihrer echten Nummer
anmeldet – und die Regiowertung zählt das als zwei Piloten.

Wer aus einem alten Grund keine Nummer hat, kann sie nicht nachreichen. **999999** bleibt darum als
Anzeige stehen: In den **Stammdaten** bedeutet die Zahl „keine Nummer“, und wer sie eintippt, meint
dasselbe. Auf dem Anmeldeformular steht diese Zahl nicht – sie ist eine Anzeigeregel des Programms
und sagt einem Besucher nichts.

**Ob die Nummer den Namen mitbringen darf, ist eine Entscheidung des Vereins.** Die Nummer steht
in jedem Link, den der Verein verschickt; jeder, der sie kennt, sieht damit auch den Namen. Für
einen Verein mit rund fünfzig Mitgliedern ist das in der Regel vertretbar, weil der Pilot seinen
eigenen Namen ohnehin sieht. Für einen anderen Verein gehört die Vorabfüllung abgeschaltet – dann
tippt jeder seinen Namen selbst, und die Nummer bringt nur noch die Wiedererkennung in der
Regiowertung.

### Freigeben und die Stammliste

Der Eintrag in die Stammliste entsteht beim **Freigeben**, nicht beim Abschicken. Eine offene
Anmeldung kann abgelehnt werden, und dann gehört der Name nicht in die Stammdaten.

| | Stammdaten | Startliste |
| --- | --- | --- |
| enthält | SMV-Nummer, Vor- und Nachname | Startnummer, Verein, Modell, Modelltyp, Bemerkung |
| gilt für | alle Wettbewerbe | einen Wettbewerb |

Der **Verein steht bewusst am Eintrag** und nicht am Stamm: wer den Verein wechselt, behält seine
Historie beim alten. Eine Korrektur am Namen im Wettkampfbüro wirkt darum auf **alle** Jahre
dieses Piloten.

**Die E-Mail-Adresse wird nicht gespeichert.** Sie dient ausschliesslich der Bestätigung und wird
danach verworfen. Auch beim Freigeben wandert nichts davon in die Startliste.

Nach dem Absenden leitet die Seite auf eine eigene Bestätigungsseite um; ein Reload oder ein
zweiter Klick sendet deshalb keine weitere Anmeldung ab.

## Die öffentlichen Seiten

Alles hier ist ohne Konto erreichbar.

### Startseite

Die Startseite beantwortet zwei Fragen: **wie melde ich mich an** und **welcher Wettbewerb**. Oben
steht der Anmeldeweg in drei Schritten, daneben die Regiorangliste. Darunter stehen die
Wettbewerbe als Karten mit Datum, Ort, Verein und der Zahl der Piloten und Durchgänge. Die ganze
Fläche einer Karte ist anklickbar.

Jede Karte trägt zwei Knöpfe, aber nur getrennt: **Rangliste** erscheint zu jedem Wettbewerb, dessen
Rangliste freigegeben ist, **Anmelden** nur dort, wo noch angemeldet werden kann. Steht ein
Wettbewerb noch unter *Rangliste noch nicht frei*, gibt es den Ranglisten-Knopf nicht – nach der
Freischaltung erscheint er von selbst.

Ein Wettbewerb nimmt Anmeldungen an, wenn er nicht beendet ist, die Anmeldung nicht abgeschaltet
wurde **und** sein Tag noch nicht vorbei ist. Wer auf der Startseite einen vergangenen Wettbewerb
wählt und dann zur *Anmeldung* geht, bekommt deshalb die Liste der Wettbewerbe, für die es noch
geht – nicht etwa ein abgeschicktes Formular für einen beendeten Wettbewerb.

### Rangliste und Teilnehmerliste

Die Rangliste lässt sich je Modelltyp oder über alle Piloten zusammen filtern, und als CSV
exportieren. Oben steht, mit welchen Regeln sie gerechnet wurde. Die **Vereinswertung** steht am
Ende derselben Seite unter demselben Filter.

**Wie viele Piloten eines Vereins zählen**, ist je Wettbewerb einstellbar. Die besten dieser
Anzahl ergeben zusammen das Vereinsresultat, der tiefste Wert gewinnt. Vereine mit weniger
gewerteten Piloten erscheinen ausser Konkurrenz. Für den Verein zählt nur, wer mindestens ein
Resultat hat.

### Regiorangliste

Die Regiorangliste fasst die Punkte aller Wettbewerbe eines Jahres zusammen, die das
Regiocup-Kennzeichen tragen. Sie ist öffentlich unter `region.php`; das Jahr wählt ein Knopf, ein
CSV-Export ist vorhanden. Die Kachel auf der Startseite nennt dabei immer das **freigegebene**
Jahr, nicht das neueste – ein Besucher ohne Konto soll kein Jahr sehen, das noch nicht frei ist.

## Benutzer und Rechte

### Die Seiten des SuperAdmins

Fünf Seiten sind dem SuperAdmin vorbehalten, und jede ist aus demselben Grund gesperrt: **was dort
geändert wird, wirkt auf alle Vereine, nicht auf den eigenen.**

| Seite | Was dort gilt |
| --- | --- |
| **Vereine** | Der Vereinsname steht auf den öffentlichen Seiten, in der Vereinswertung und in den Kopfzeilen aller Ergebnislisten. |
| **Modelltypen** | Ein Modelltyp entscheidet, für welche Wertung ein Pilot gezählt wird – auch bei anderen Vereinen. |
| **Stammdaten** | Die Piloten aller Wettbewerbe, nicht nur die des eigenen. |
| **Aktivieren** | Ein Klick schaltet **alle** anderen Wettbewerbe ab. |
| **Benutzer verwalten** | Die Konten des ganzen Programms. |

Bei einem Vereinskonto steht an der Stelle von **Aktivieren** derselbe Knopf als Hinweis. Jede
Sperre gilt auch dann, wenn ein Formular **ohne Klick** abgeschickt wird.

**Ein Verein, für den noch kein Wettbewerb geplant ist, ist ein ganz normaler Zustand.** Ein
Verein ist dem Wettkampfbüro beigetreten, hat aber noch keinen Termin. Solange das so ist, gibt es
für dieses Konto nichts zu erfassen, keine Startliste und keine Durchgänge. Das Programm sagt das
auf jeder betroffenen Seite, mit einem Knopf zu den Wettbewerben und einem Hinweis an den
SuperAdmin. In der oberen Leiste steht **„Zur Zeit kein Wettbewerb“**. Sobald der SuperAdmin einen
Wettbewerb anlegt, verschwindet der Hinweis von selbst – es ist nichts einzuschalten.

### Benutzer verwalten

Der SuperAdmin kann je Konto:

- den **Anzeigamen** ändern (erscheint oben im Kopf),
- den **Verein** zuweisen, in dem das Konto arbeitet,
- die Rolle zwischen Wettkampfleitung und SuperAdmin umstellen,
- das Konto **sperren** oder wieder freigeben – ein gesperrtes Konto kann sich nicht anmelden,
  und eine noch laufende Sitzung endet beim nächsten Aufruf, nicht erst beim Abmelden,
- ein **neues Passwort** setzen, etwa wenn jemand das eigene vergessen hat,
- das Konto **löschen**.

Ein neu angelegtes Konto ist **sofort anmeldebereit**. Gesperrt wird ein Konto erst, wenn das
Kästchen *aktiv* in der Kontenliste abgehakt wird.

Das eigene Konto und der letzte aktive SuperAdmin lassen sich weder sperren noch löschen und nicht
in eine niedrigere Rolle stufen – sonst gäbe es niemanden mehr, der die Konten verwalten kann.

**Es gibt bewusst keine Rücksetzung per E-Mail.** Ein vergessenes Passwort setzt der SuperAdmin auf
der Kontenseite neu.

### Das eigene Profil

Unter **Profil** steht alles, was die Person betrifft: **Anzeigename** und **Passwort**. Das
Passwort steht bewusst nicht unter den Einstellungen des Wettbewerbs – wer dort Regeln ändern
will, läuft nicht an einem Passwortfeld vorbei, und wer sein Passwort sucht, muss nicht die ganze
Wettbewerbseinstellungen durchgehen.

Was den **Wettbewerb** betrifft, steht unter **Einstellungen**. Was **fremde Konten** betrifft,
nur unter **Benutzer verwalten**.

## Einstellungen

Unter **Einstellungen** stehen die Einstellungen des ausgewählten Wettbewerbs:

- **Strafpunkte** – die sechs Regeln mit Erklärung und einem Rechenbeispiel aus den gespeicherten
  Werten
- **Rangliste** – Streichresultat, ab wie vielen Durchgängen, Ansicht der öffentlichen Liste, und
  ob die Resultate öffentlich sichtbar sind
- **Vereinswertung** – ob sie angezeigt wird und wie viele Piloten je Verein zählen
- **Anmeldung** – offen oder geschlossen, der Text über dem Formular, und die Absenderadresse der
  Bestätigung

Die Einstellungen gelten nur für den Wettbewerb, der oben ausgewählt ist. Beim Anlegen eines neuen
Wettbewerbs werden die Einstellungen des gerade ausgewählten als Vorlage kopiert – so haben zwei
Vereinswettbewerbe unterschiedliche Regeln, ohne dass bestehende Wettbewerbe verändert werden.
Ausgenommen ist die Absenderadresse: die gehört jedem Verein selbst und wird nicht vererbt.

Ein abgeschlossener Wettbewerb ist gesperrt: Startliste, Resultate, Anmeldungen und
Wettbewerbseinstellungen bleiben sichtbar, lassen sich aber nicht mehr ändern. Der Name des
Wettbewerbs selbst bleibt auch nach dem Abschluss änderbar.

## Regiocup

Die **Regiorangliste** fasst die Punkte aller Wettbewerbe eines Jahres zusammen, die das
**Regiocup-Kennzeichen** tragen. Nicht nur die des Veranstaltungsvereins und nicht eine feste
Auswahl – wer den Knopf drückt, nimmt teil. Das Jahr steht im **Wettbewerbsdatum**, nicht im Namen.

| | |
| --- | --- |
| 🏆 Regiocup / ○ Regiocup | der Wettbewerb zählt zur Regiorangliste seines Jahres, oder er zählt nicht mehr; jederzeit wieder hinein |
| **Zum Regiocup hinzufügen** / **Aus dem Regiocup nehmen** | dasselbe, mit Rückfrage |

Vor dem Umschalten kommt eine Rückfrage; **ohne Bestätigung ändert sich nichts**. Ist ein
Wettbewerb im Regiocup, trägt seine Karte zusätzlich das goldene Abzeichen **🏆 Regiocup**.

Unter **Regiocup** im Benutzermenü kann der SuperAdmin:

- **das Jahr öffentlich freigeben** – am Ende einer Saison. Der Knopf erscheint erst, wenn kein
  Wettbewerb des Jahres mehr offen ist; sonst steht dort die Zahl der offenen Wettbewerbe mit
  ihren Namen. **Es ist immer nur ein Jahr öffentlich**; wird ein weiteres freigegeben, verliert
  das vorherige den Status. Die Ranglisten der einzelnen Wettbewerbe rührt das nicht an.
- **die Punkteliste** einstellen: welcher Rang wie viele Punkte bekommt. Ohne gespeicherte Liste
  gilt die Vorgabe (100, 80, 60, 50, 45 … bis Platz 30 mit einem Punkt). Eingestellt sind **3 bis
  60 Ränge**; der beste Platz muss die meisten Punkte bekommen. Ein Komma ist ein Dezimalzeichen,
  kein Zahlentrenner. Es ist nichts nachzurechnen – nach dem Speichern ist die alte Wertung einfach
  die neue.
- **den Verein einstellen**, der die Regiorangliste sieht, wenn sie nicht ohnehin öffentlich ist.

Diese Seite ist **allein dem SuperAdmin vorbehalten**. Der eingestellte Verein verliert dadurch
nichts, was er braucht: Die Rangliste sieht er ohnehin öffentlich unter `region.php`.

## Aktualisieren

Es gibt zwei Wege. Der bequemere ist der Knopf **Aktualisierung** im Benutzermenü; der
handfestere ist das Hochladen der Dateien.

### Über den Knopf

Nur der SuperAdmin sieht den Punkt. Die Seite prüft, ob im Repository eine neuere Fassung liegt,
und zeigt **Was sich ändert** – einen Satz je Änderung, aus der Änderungsliste des Programms. Die
Seite nennt vorher, was passieren würde, und schreibt erst auf einen Knopf.

So geht es vor sich:

1. Der Server wird mit der Bestandsliste aus dem Repository verglichen. Die Seite zeigt vorher an,
   was passieren würde.
2. Die neue Fassung wird geholt und **alles** gegen die Bestandsliste geprüft. Stimmt eine Prüfsumme
   nicht, wird **nichts** geschrieben.
3. Die zu ersetzenden Dateien werden in einem Sicherungsordner neben dem Programm kopiert.
4. Die Dateien werden eingesetzt, die Bestandsliste zuletzt.
5. Schlägt beim Einsetzen etwas fehl, wird alles aus der Sicherung zurückgeholt.

Die Sicherungen bleiben liegen, damit sich ein Update mit **Neueste Sicherung zurückholen** wieder
rückgängig machen lässt.

### Durch Hochladen der Dateien

Neue Dateien hochladen und bestehende überschreiben. **`config.php` nicht anfassen.** Vorher ein
Datenbank-Backup erstellen. Danach einmal **`admin/upgrade.php`** aufrufen – Wettkampfbüro →
Aktualisierung, oder direkt die Adresse – und **Jetzt aktualisieren** klicken.

Die Migrationen laufen versioniert und werden erst nach erfolgreicher Ausführung protokolliert. Ein
abgebrochener Lauf kann nach Beheben der gemeldeten Datenprobleme erneut gestartet werden.

**Datenbankänderungen bringst der Aktualisierungsknopf nicht mit.** Der Knopf schreibt nur
Programmdateien. Ändert eine neue Fassung auch das Datenbankschema, meldet er das nach dem
Einspielen und verweist auf `admin/upgrade.php`. Das ist Absicht: Migrationen können Daten
umschreiben und gehören nicht in einen Knopf, den man im Ernstfall anklickt.

**Deshalb gilt die Reihenfolge:** erst `admin/upgrade.php`, dann die Aktualisierung. Wer es
umgekehrt macht, sieht erst eine Datenbankmeldung und weiss nicht, woran es liegt.

`admin/upgrade.php` läuft **nur für den angemeldeten SuperAdmin**. Die Seite darf liegen bleiben;
wer sie nicht mehr braucht, kann sie löschen. Ruft man eine andere Seite auf, bevor sie gelaufen
ist, leitet das Programm automatisch dorthin um, statt einen Datenbankfehler zu zeigen.

### Was der Knopf anfasst – und was nicht

Für jede einzelne Datei gilt:

| Zustand auf dem Server | Was passiert |
| --- | --- |
| Datei fehlt | wird neu angelegt |
| Datei stimmt mit dem Repository überein | wird ersetzt |
| Datei wurde von Hand geändert | **bleibt stehen**, wird gemeldet |
| Datei steht in keiner Bestandsliste | **bleibt stehen**, wird gemeldet |

**Eine von Hand geänderte Datei geht also nie verloren.** Sie steht danach in der Liste der Dateien
für Handarbeit und lässt sich einzeln aus dem Repository holen.

Diese Dateien fasst der Knopf **nie** an:

| Datei | Grund |
| --- | --- |
| `config.php` | Zugangsdaten |
| `.htaccess`, `.gitignore` | gehören dem Server, nicht dem Programm |
| `assets/logo.png` | das Logo des Vereins |
| `install.php`, `config.sample.php` | gehören zur Ersteinrichtung, nicht auf einen betriebenen Server |

Steht eine davon in der Bestandsliste und ist auf dem Server älter, meldet das **keinen Fehler** –
sie gehört dem Server, und der Knopf darf sie nicht ersetzen. Wer eine davon wirklich braucht,
nimmt sie aus dem Archiv der Fassung.

Ebenfalls nicht mitgeliefert wird eine **Regionalwappen-Datei**, die ältere Fassungen ausgeliefert
haben. Sie bleibt auf dem Server liegen und wird nur als *nicht mehr gebraucht* gemeldet – das ist
ausdrücklich keine Fehlermeldung. Die Vorgabe für Neuanlagen ist `logo.png`.

### Wenn eine Seite nach dem Update das alte zeigt

Meist ist der Opcode-Cache schuld: Ist er so eingestellt, dass er Dateien nicht mehr auf Änderungen
prüft, bleibt der alte Stand im Speicher. `diagnose.php` sagt das. Abhilfe: den PHP-Dienst neu
starten, danach die Seite neu laden.

### Alles aus dem Wettbewerbsbetrieb löschen

Für den Fall, dass ein Wettbewerb erst später offiziell anfangt, steht in der **Aktualisierung**
der Knopf **Alles aus dem Wettbewerbsbetrieb löschen**. Er nimmt Wettbewerbe, Durchgänge,
Startlisten, Resultate, Stammsätze und Anmeldungen weg und nennt vorher die Zahlen. **Konten, Vereine
und Modelltypen bleiben.** Siehe [Wettbewerbe](#wettbewerbe).

## Fehlersuche

Antwortet eine Seite mit einem 500-Fehler, `diagnose.php` hochladen und aufrufen:

```
https://ihre-domain/diagnose.php
```

Die Datei prüft PHP-Version, benötigte Erweiterungen, Dateirechte, die Datenbankverbindung, alle
Tabellen und ob das Schema dem aktuellen Stand entspricht. Für die eigentliche Fehlermeldung mit
Datei und Zeile hilft meist nur das PHP-Fehlerprotokoll des Hosters. **Danach `diagnose.php`
löschen.**

Ein Hinweis, dass eine Tabelle oder Spalte fehlt, bedeutet: `admin/upgrade.php` wurde noch nicht
gelaufen.

## Sicherheit und Betrieb

- Anmeldung mit gehashtem Passwort. Jedes Formular trägt ein verstecktes Kennzeichen, das nur die
  eigene Seite kennt; ein Formular, das von ausserhalb kommt, wird abgewiesen – auch beim Abmelden.
- Jeder Datenbankzugriff läuft über vorbereitete Anweisungen, jede Ausgabe wird maskiert.
- **HTTPS auf einem öffentlichen Server.** Ohne sind Passwort und Anmeldedaten im Klartext
  mitlesbar.
- `config.php` und die Verzeichnisse `lib/` und `sql/` gehören nicht in ein öffentlich
  erreichbares Verzeichnis. Die mitgelieferte `.htaccess` sperrt sie für Apache. Für nginx
  entsprechend selbst konfigurieren.
- `install.php` und `diagnose.php` nach der Einrichtung vom Server löschen. `admin/upgrade.php`
  ist dem SuperAdmin vorbehalten und schadet deshalb auch dann nicht, wenn sie liegen bleibt.
- Ein Konto kann weder anlegen, ändern, sperren noch löschen – auch nicht mit einem abgefangenen
  oder von Hand gesendeten Formular.
- Zerstörende Änderungen an Vereinen und Modelltypen werden blockiert, sobald sie in
  abgeschlossenen Wettbewerben verwendet wurden. Ein Verein wird zusätzlich nicht gelöscht,
  solange Piloten, Anmeldungen, Wettbewerbe oder Konten an ihm hängen.

## Datenschutz

Die Anwendung setzt **kein Tracking-Cookie** und lädt nichts von fremden Servern: keine Analyse,
keine Werbung, keine eingebetteten Karten oder Videos, keine externen Schriften, kein Fingerabdruck,
keine IP-Adresse in der Sitzung.

Es gibt genau **ein** Cookie, die Sitzung, damit die Anmeldung erhalten bleibt. Sie ist technisch
notwendig; ohne sie könnte sich niemand einloggen. Sie wird mit `httponly` und `samesite=Lax`
gesetzt, sowie mit `secure`, sobald die Seite über HTTPS läuft. In der Sitzung stehen nur die
Konto-Nummer, die Schutzkennzeichen und kurze Meldungstexte.

Deshalb gibt es **kein Einwilligungs-Banner**: Es wird nichts einwilligungsbedürftiges gesetzt.
Statt eines nutzlosen „Akzeptieren“-Knopfes steht auf der Anmeldeseite ein kurzer Hinweis, was die
Sitzung ist. Ein Banner wäre erst nötig, wenn Analyse oder Tracking hinzukäme.

**Es werden keine Kontaktdaten gespeichert.** Das Anmeldeformular fragt die E-Mail-Adresse ab, weil
die Anmeldebestätigung verschickt wird – danach wird sie verworfen, in der Datenbank steht sie
nicht. Dasselbe gilt für Telefonnummern: sie werden gar nicht erst erhoben. Die Startliste führt
keine Spalte *Kontakt*, und beim Freigeben einer Anmeldung wandern keine Adressen in den
Wettbewerb.

Einzig die **Absenderadresse** ist gespeichert: sie gehört dem Verein, wird unter
*Einstellungen → Anmeldung* gesetzt und steht in jeder Bestätigung. Sie ist keine Angabe über eine
Person.

Bei der SMV-Nummer ist es anders: Sie ist öffentlich, weil sie in jedem Link steht, den der Verein
verschickt. Ob die Nummer den Namen mit vorabfüllen darf, entscheidet der Verein – siehe
[Die SMV-Nummer](#die-smv-nummer).

Offen bleibt allein die Webstatistik des Hosters – die gehört zum Betreiber, nicht zum Programm.

## Lizenz und Copyright

    Segelflug-Wettbewerb
    Copyright (C) 2026  Serge Huber, Pascal Schmidlin (MFV Brislach)

Dieses Programm ist freie Software: Sie können es unter den Bedingungen der GNU General Public
License, wie von der Free Software Foundation veröffentlicht, weitergeben und/oder verändern.

Dieses Programm wird **ohne jede Gewähr** bereitgestellt, siehe Abschnitt „NO WARRANTY“ der Lizenz.
Die Haftung für Schäden aus der Benutzung ist ausgeschlossen.

Der vollständige Text liegt in [`LICENSE`](LICENSE). GitHub zeigt ihn oben im Repository an.
Wer den Copyright-Namen ändern will, ersetzt die Zeile in der Datei `LICENSE` – der Lizenztext
selbst darf nicht verändert werden.

**Was das praktisch bedeutet:** wer die App benutzt, darf das. Wer sie verändert und weitergibt,
muss das unter den gleichen Bedingungen tun und den Quelltext offenlegen. Wer sie unverändert
weiterverbreitet, muss den Lizenztext und die Copyright-Zeile mitgeben. Eine Vereinswettbewerbs-
Installation darf beliebig betrieben und geändert werden; es gibt keine Einschränkung durch
Lizenzgebühren.