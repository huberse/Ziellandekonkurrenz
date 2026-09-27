# Änderungen

Was sich zwischen den Fassungen geändert hat. Die Aktualisierungsseite zeigt
diese Liste an, wenn sie ein Update anbietet.

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
