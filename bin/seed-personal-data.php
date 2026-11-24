<?php

declare(strict_types=1);

// Semence des données personnelles de démonstration, CHIFFRÉES (phase 6). Appelé par bin/init-local.py sur une
// instance neuve, avec APP_RUNTIME_DIR (clés), DB_SOCKET, DB_NAME et le mot de passe root en variables
// d'environnement. Le chiffrement et l'index aveugle se font ici (PHP) ; la base ne reçoit que des octets opaques.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

use App\HR\PersonalData;
use App\Security\FieldCipher;
use App\Security\Runtime;

try {
    $dataPath = $argv[1] ?? '';
    if (!is_file($dataPath)) {
        throw new RuntimeException('Fichier de données introuvable : ' . $dataPath);
    }
    // Clés générées au besoin dans le répertoire d'exécution privé, puis chargées.
    FieldCipher::provision(Runtime::directory() . '/keys');
    $cipher = FieldCipher::fromRuntime();

    $records = json_decode((string) file_get_contents($dataPath), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($records)) {
        throw new RuntimeException('Données personnelles de démonstration invalides.');
    }

    $socket = (string) getenv('DB_SOCKET');
    $name = (string) getenv('DB_NAME');
    $password = (string) getenv('PD_ROOT_PWD');
    if ($socket === '' || $name === '' || $password === '') {
        throw new RuntimeException('Connexion root à la base non configurée.');
    }
    $pdo = new PDO('mysql:unix_socket=' . $socket . ';dbname=' . $name . ';charset=utf8mb4', 'root', $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);

    $author = $pdo->query("SELECT id FROM accounts WHERE login = 'hr.demo'")->fetchColumn();
    if ($author === false) {
        throw new RuntimeException('Compte RH de démonstration absent.');
    }

    $insert = $pdo->prepare(
        'INSERT INTO employee_personal_data (employee_id, id_document_type, id_document_number, id_document_index,
            cnss_number, cnss_index, birth_date, marital_status, dependent_children, personal_email, personal_phone,
            address_line, city, postal_code, bank_name, rib, rib_index, created_by)
         VALUES ((SELECT id FROM employees WHERE employee_number = ?), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $pdo->beginTransaction();
    $seeded = 0;
    foreach ($records as $r) {
        $docNormalized = PersonalData::normalizeDocument((string) $r['id_document_type'], (string) $r['id_document_number']);
        if ($docNormalized === null) {
            throw new RuntimeException('Pièce d’identité de démonstration invalide : ' . ($r['employee_number'] ?? '?'));
        }
        $docNumber = strtoupper(trim((string) $r['id_document_number']));

        $cnssNormalized = PersonalData::normalizeCnss($r['cnss_number'] ?? null);
        $hasCnss = $cnssNormalized !== null;
        if (($r['cnss_number'] ?? null) !== null && !$hasCnss) {
            throw new RuntimeException('CNSS de démonstration invalide : ' . ($r['employee_number'] ?? '?'));
        }

        $rib = PersonalData::normalizeRib((string) $r['rib']);
        if ($rib === null) {
            throw new RuntimeException('RIB de démonstration invalide : ' . ($r['employee_number'] ?? '?'));
        }

        $insert->execute([
            $r['employee_number'],
            $r['id_document_type'],
            $cipher->encrypt($docNumber),
            $cipher->blindIndex($docNormalized),
            $hasCnss ? $cipher->encrypt($cnssNormalized) : null,
            $hasCnss ? $cipher->blindIndex($cnssNormalized) : null,
            $r['birth_date'],
            $r['marital_status'],
            (int) $r['dependent_children'],
            $r['personal_email'],
            $r['personal_phone'],
            $r['address_line'],
            $r['city'],
            $r['postal_code'],
            $r['bank_name'],
            $cipher->encrypt($rib),
            $cipher->blindIndex($rib),
            $author,
        ]);
        ++$seeded;
    }
    $pdo->commit();
    fwrite(STDOUT, "Données personnelles chiffrées : {$seeded} fiches.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Échec de la semence des données personnelles : ' . $error->getMessage() . "\n");
    exit(1);
}
