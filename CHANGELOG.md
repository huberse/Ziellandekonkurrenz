# Änderungen

Was sich zwischen den Fassungen geändert hat. Die Aktualisierungsseite zeigt
diese Liste an, wenn sie ein Update anbietet – und zwar nur die Fassungen, die
seit dem eigenen Stand dazugekommen sind.

Die Liste beginnt bei 1.1.0, weil dort die Aktualisierung überhaupt dazukam.
Darunter war eine Aktualisierung nicht möglich; wer eine ältere Fassung im
Betrieb hatte, hat sie durch eine frische Installation ersetzt. Wer von weit
her kommt, liest deshalb eine lange Liste – das ist beabsichtigt, weil sonst
wichtige Änderungen wie die Vereinszugehörigkeit in 1.9.0 untergingen.

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
