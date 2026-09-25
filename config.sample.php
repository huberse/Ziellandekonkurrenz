<?php
/**
 * Zugangsdaten zur Datenbank.
 * Diese Datei nach  config.php  kopieren und ausfüllen.
 */
return [
    'db_host' => 'localhost',
    'db_name' => 'segelflug',
    'db_user' => 'segelflug',
    'db_pass' => '',
    'db_port' => 3306,

    // Zeitzone für Zeitstempel
    'timezone' => 'Europe/Zurich',

    // Feste Bezeichnung oben links im Kopf und das Logo dazu.
    // Beides gehört zur Installation, nicht zu einem Wettbewerb.
    'site_name' => 'Ziellandekonkurrenz',
    'logo'      => 'logo_nordwest.jpg', // Datei in assets/
];
