<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Abmelden ist nur per Formular mit CSRF-Token möglich.');
}
csrf_check();
logout();
redirect('../index.php');
