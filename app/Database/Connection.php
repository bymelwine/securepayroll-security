<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;
use RuntimeException;

final class Connection
{
    /** Vérifie la configuration sans ouvrir de connexion. */
    public static function validateConfiguration(): void
    {
        self::configuration();
    }

    public static function connect(): PDO
    {
        $config = self::configuration();
        $multiStatements = class_exists(\Pdo\Mysql::class)
            ? \Pdo\Mysql::ATTR_MULTI_STATEMENTS
            : PDO::MYSQL_ATTR_MULTI_STATEMENTS;

        try {
            return new PDO(
                isset($config['socket']) ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $config['socket'], $config['name']) : sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $config['host'],
                    $config['port'],
                    $config['name']
                ),
                $config['user'],
                $config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    // Défense en profondeur : une seule instruction par appel, même si une préparation était émulée.
                    $multiStatements => false,
                ]
            );
        } catch (PDOException) {
            // Ne pas propager le message du pilote ni chaîner l’exception originale.
            throw new RuntimeException('Connexion à la base de données indisponible.');
        }
    }

    /** @return array{host: string, port: string, name: string, user: string, password: string, socket?: string} */
    private static function configuration(): array
    {
        $config = require dirname(__DIR__, 2) . '/config/database.php';

        foreach ($config as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new RuntimeException('Configuration de base de données absente ou invalide.');
            }
        }

        // Interdire l’injection de paramètres DSN ; accepter les noms DNS, IPv4 et IPv6.
        if (
            strtolower(trim($config['user'])) === 'root'
            || preg_match('/\A[a-zA-Z0-9_.:\-]+\z/D', $config['host']) !== 1
            || preg_match('/\A[a-zA-Z0-9_\-]+\z/D', $config['name']) !== 1
            || preg_match('/\A[0-9]{1,5}\z/D', $config['port']) !== 1
            || (int) $config['port'] < 1
            || (int) $config['port'] > 65535
            || str_contains($config['user'], "\0")
            || str_contains($config['password'], "\0")
        ) {
            throw new RuntimeException('Configuration de base de données absente ou invalide.');
        }

        $socket = getenv('DB_SOCKET');
        if ($socket !== false && $socket !== '') {
            if (strlen($socket) > 103 || preg_match('~\A/[a-zA-Z0-9_./-]+\z~D', $socket) !== 1
                || in_array('..', explode('/', $socket), true)) {
                throw new RuntimeException('Configuration de base de données absente ou invalide.');
            }
            $config['socket'] = $socket;
        }
        return $config;
    }
}
