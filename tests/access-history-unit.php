<?php

declare(strict_types=1);

// « Mes accès » : validation serveur et verrou du composant vulnérable (phases 4 et 5), sans connexion.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Access\AccessHistory;
use App\Security\LabMode;

$count = 0;
$verify = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) { fwrite(STDERR, 'FAIL ' . $label . PHP_EOL); exit(1); }
    $count++;
};

// Validation serveur : seules des dates réelles AAAA-MM-JJ passent.
foreach (['2026-01-01', '2000-01-01', '2024-02-29', '1970-01-01', '2100-12-31'] as $ok) {
    $verify(AccessHistory::parseDate($ok) === $ok, 'Date valide acceptée : ' . $ok);
}
$payloads = ["'", "2000-01-01' OR '1'='1", "2999-01-01' UNION SELECT login, password_hash, NULL, NULL FROM accounts -- -",
    '2026-01-01; DROP TABLE accounts', "2026-01-01\n", ' 2026-01-01', '2026-1-1', '2025-02-29', '2026-13-01',
    '1969-12-31', '2101-01-01', '２０２６-01-01', '2026-01-01%00', ''];
foreach ($payloads as $bad) {
    $verify(AccessHistory::parseDate($bad) === null, 'Saisie refusée : ' . json_encode($bad, JSON_UNESCAPED_UNICODE));
}

// La version sécurisée n'assemble jamais la saisie dans le texte SQL.
$source = file_get_contents(dirname(__DIR__) . '/app/Access/AccessHistory.php');
$verify(preg_match("/->prepare\\('([^']*)'\\)/", $source, $sql) === 1 && str_contains($sql[1], 'ra.valid_from >= ?')
    && !str_contains($sql[1], '$') && str_contains($source, '->execute([$accountId, $since'),
    'Requête préparée : texte SQL constant, saisie transmise comme valeur liée');

// Verrou : jamais actif en ligne de commande, ni sans drapeau, ni hors d'une instance /tmp/securepayroll-lab-*.
putenv(LabMode::FLAG);
$verify(!LabMode::vulnerableAccessHistory(), 'Sans drapeau : composant sécurisé');
$lab = sys_get_temp_dir() === '/tmp' ? '/tmp/securepayroll-lab-unit-' . bin2hex(random_bytes(6)) : null;
if ($lab !== null) {
    mkdir($lab, 0700);
    putenv('APP_RUNTIME_DIR=' . $lab);
    putenv(LabMode::FLAG . '=access-history');
    $verify(!LabMode::vulnerableAccessHistory(), 'Drapeau + instance de laboratoire, mais hors de php -S : refusé');
    putenv(LabMode::FLAG . '=autre-chose');
    $verify(!LabMode::vulnerableAccessHistory(), 'Valeur de drapeau inconnue : refusé');
    rmdir($lab);
}
putenv(LabMode::FLAG);

// Phase 5 : variantes à contrôle unique, liste fermée.
putenv(LabMode::VARIANT);
$verify(LabMode::variant() === 'vulnerable', 'Variante par défaut : aucun contrôle (version de la phase 4)');
$ok = [];
foreach (LabMode::VARIANTS as $variant) {
    putenv(LabMode::VARIANT . '=' . $variant);
    $ok[] = LabMode::variant() === $variant;
}
$verify(!in_array(false, $ok, true) && LabMode::VARIANTS === ['vulnerable', 'prepared-only', 'validation-only', 'generic-errors'],
    'Quatre variantes reconnues');
$refused = [];
foreach (['corrige', 'vulnerable;', '../app', 'PREPARED-ONLY'] as $bad) {
    putenv(LabMode::VARIANT . '=' . $bad);
    $refused[] = LabMode::variant() === null;
}
$verify(!in_array(false, $refused, true), 'Variante inconnue : refusée (le verrou désactive alors le composant)');
putenv(LabMode::VARIANT);
$root = dirname(__DIR__);
$verify(!is_file(LabMode::vulnerableFile()), 'La variante de laboratoire est absente de la copie de publication');

// bin/init-local.py retire les variables SECUREPAYROLL_LAB_* de l'environnement de l'application.
$verify(str_contains(file_get_contents($root . '/bin/init-local.py'), "'SECUREPAYROLL_LAB_'"),
    'Lanceur quotidien : drapeau de laboratoire retiré');

echo $count . " contrôles de « Mes accès » et du verrou de laboratoire réussis, sans connexion.\n";
