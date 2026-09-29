<?php
declare(strict_types=1);

/**
 * Angaben zum Programmstand.
 *
 * APP_VERSION wird bei jeder veroeffentlichten Aenderung hochgezaehlt. Der
 * Wert steht zugleich in manifest.json; beide muessen zusammenpassen, sonst
 * meldet diagnose.php einen Widerspruch.
 *
 * Das Manifest fuehrt jede Datei samt Pruefsumme. Beim Update wird eine Datei
 * nur dann ersetzt, wenn der Serverstand die Pruefsumme des installierten
 * Standes hat. Eine Datei, die jemand von Hand angefasst hat, bleibt stehen
 * und wird gemeldet.
 */

// Version des Programms.
//
// Wichtig fuer die Aktualisierung: zu einem Zeitpunkt darf es nur EINEN Commit
// mit dieser Fassung geben. Waere dieselbe Nummer mehrfach vergeben, koennte
// GitHub eine veraltete Bestandsliste und ein frisches Archiv liefern, ohne
// dass der Versionsvergleich es merkt - der Knopf scheitert dann an einer
// scheinbar unbeteiligten Datei. Nach dem Hochladen deshalb keine weitere
// Aenderung mit derselben Nummer; notfalls auf 2.0.0 hochzaehlen.
const APP_VERSION = '1.9.9';

// Quelle der Aktualisierungen. Feste Angaben, keine Eingabe aus dem Formular:
// sonst wuerde die Seite zur Bruecke fuer beliebige Ziele.
const APP_REPO   = 'huberse/Ziellandekonkurrenz';
const APP_BRANCH = 'main';
