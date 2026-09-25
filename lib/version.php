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
const APP_VERSION = '1.8.0';

// Quelle der Aktualisierungen. Feste Angaben, keine Eingabe aus dem Formular:
// sonst wuerde die Seite zur Bruecke fuer beliebige Ziele.
const APP_REPO   = 'huberse/Ziellandekonkurrenz';
const APP_BRANCH = 'main';
