<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/Database/Connection.php';

try {
    \App\Database\Connection::validateConfiguration();
    fwrite(STDOUT, "Configuration valide. Aucune connexion effectuée.\n");
} catch (Throwable) {
    fwrite(STDERR, "Configuration absente ou invalide. Vérifier les cinq variables DB_* en privé.\n");
    exit(1);
}
