<?php

declare(strict_types=1);

// Configuration privée : aucune valeur de connexion par défaut.
return [
    'host' => getenv('DB_HOST'),
    'port' => getenv('DB_PORT'),
    'name' => getenv('DB_NAME'),
    'user' => getenv('DB_USER'),
    'password' => getenv('DB_PASSWORD'),
];
