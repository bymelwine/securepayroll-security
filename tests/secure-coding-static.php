<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/*
 * Phase 5 — garde-fou de codage sécurisé, sans connexion.
 *
 * Analyse lexicale (token_get_all) de tout le PHP de app/ et public/ :
 *  1. chaque appel ->query(), ->exec() ou ->prepare() reçoit soit du SQL entièrement littéral,
 *     soit une construction dynamique REVUE et inscrite ci-dessous avec sa justification ;
 *     toute nouvelle construction non revue fait échouer le test ;
 *  2. aucune variable de requête ($_GET, $_POST, $_COOKIE, $_REQUEST, $_SERVER) dans un argument SQL ;
 *  3. aucun message d'exception ni trace (getMessage, getTraceAsString, var_dump…) dans app/ ou public/ ;
 *     le texte SIGNAL des procédures (errorInfo[2]) n'est lu que par 5 contrôleurs revus, qui le
 *     comparent à des codes fixes sans jamais l'afficher ni le journaliser ;
 *  4. configuration PDO et PHP : préparations natives, exceptions, multi-requêtes refusées,
 *     display_errors désactivé ;
 *  5. le composant vulnérable n'est chargé que par le verrou de laboratoire.
 */
$root = dirname(__DIR__);
$count = 0;
$verify = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) { fwrite(STDERR, 'Échec : ' . $label . "\n"); exit(1); }
    ++$count;
};

/** Constructions SQL dynamiques revues : « fichier|argument normalisé » → justification. */
const REVIEWED = [
    'app/Database/SessionRoles.php|\'SET ROLE \'.implode(\', \',array_map(staticfn(string$name):string=>"\'".$name."\'",$names))'
        => 'Noms de rôles contrôlés juste avant contre la liste fermée $allowed (SET ROLE n’accepte pas de paramètre lié).',
    'app/Auth/IAMRepository.php|$sql' => 'Paramètre de call(), toujours un CALL littéral à marqueurs ? (iam_overview, iam_grant, iam_revoke).',
    'app/Compensation/CompensationRepository.php|$sql' => 'Paramètre de call(), toujours un CALL littéral à marqueurs ? (compensation_submit, compensation_decide).',
    'app/Payroll/Repository.php|$sql' => 'Paramètre de call()/command(), toujours un CALL littéral à marqueurs ?.',
    'app/Compensation/CompensationRepository.php|\'SELECT COUNT(*)\'.$from'
        => '$from : texte constant ; son WHERE vient d’un match fermé sur le statut, la valeur passe par ?.',
    'app/Compensation/CompensationRepository.php|\'SELECT c.*,e.employee_number,e.first_name,e.last_name\'.$from.\' ORDER BY e.employee_number,c.starts_on DESC LIMIT 20 OFFSET \'.(($page-1)*20)'
        => '$from constant ; OFFSET calculé à partir d’un entier typé int.',
    'app/Compensation/CompensationRepository.php|"SELECT q.*,e.employee_number,e.first_name,e.last_name,c.reference, COALESCE(d.decision,\'pending\') AS status".$from.\' ORDER BY q.id DESC LIMIT 20 OFFSET \'.(($page-1)*20)'
        => '$from constant (WHERE d’un match fermé, statut par ?) ; OFFSET entier.',
    'app/Compensation/CompensationRepository.php|"SELECT s.*,e.employee_number,e.first_name,e.last_name,c.reference, NULLIF(LEAST(COALESCE(c.effective_ends_on,\'9999-12-31\'), COALESCE( (SELECT DATE_SUB(MIN(n.effective_on),INTERVAL 1 DAY) FROM salary_conditions n WHERE n.contract_id=s.contract_id AND n.effective_on>s.effective_on),\'9999-12-31\')),\'9999-12-31\') AS ends_on".$from.\' ORDER BY s.effective_on DESC,s.id DESC LIMIT 20 OFFSET \'.(($page-1)*20)'
        => '$from constant, filtre salarié par ? ; OFFSET entier.',
    'app/HR/EmployeeRepository.php|\'SELECT COUNT(*) FROM employees\'.$where'
        => '$where constant ; le motif de recherche passe par ? avec ESCAPE.',
    'app/HR/EmployeeRepository.php|\'SELECT id, employee_number, first_name, last_name, created_at, archived_at FROM employees\'.$where.\' ORDER BY last_name, first_name, id LIMIT 20 OFFSET \'.(($page-1)*20)'
        => '$where constant ; OFFSET calculé à partir d’un entier typé int.',
    'app/HR/PeriodRepository.php|"SELECT id,code,$label AS label,archived_at FROM $table".($activeOnly?\' WHERE archived_at IS NULL\':\'\').\' ORDER BY label,id\''
        => '$table issu d’un match fermé ; $label vaut name_en ou name_fr.',
    'app/HR/PeriodRepository.php|"SELECT COUNT(*) FROM $table".$where'
        => '$table issu de la constante TABLES (sinon exception) ; $where constant, valeur par ?.',
    'app/HR/PeriodRepository.php|$this->projection($kind).($employeeId===null?\'\':\' WHERE rec.employee_id=?\').\' ORDER BY rec.starts_on DESC,rec.id DESC LIMIT 20 OFFSET \'.(($page-1)*20)'
        => 'projection() : table de la constante TABLES et libellé name_en/name_fr ; OFFSET entier.',
    'app/HR/PeriodRepository.php|$this->projection($kind).\' WHERE rec.id=?\'' => 'projection() revue ci-dessus ; identifiant par ?.',
    'app/HR/PeriodRepository.php|$assignment?\'CALL hr_create_assignment(?,?,?,?,?,?)\':\'CALL hr_create_contract(?,?,?,?,?,?)\''
        => 'Choix entre deux littéraux.',
    'app/HR/PeriodRepository.php|$kind===\'assignments\'?\'CALL hr_close_assignment(?,?,?)\':\'CALL hr_close_contract(?,?,?)\''
        => 'Choix entre deux littéraux.',
    'app/Web/Controller.php|\'SET @audit_actor = \'.(int)$user[\'account_id\']'
        => 'Phase 7 : identifiant du compte relu en base par currentUser(), forcé en entier par (int) ; aucune saisie.',
];

$files = [];
foreach (['app', 'public'] as $folder) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $folder, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
}
sort($files);
$verify(count($files) >= 30, count($files) . ' fichiers PHP analysés dans app/ et public/');

$sinks = 0;
$literal = 0;
$dynamic = 0;
$tainted = [];
$seen = [];
$unreviewed = [];
foreach ($files as $path) {
    $tokens = token_get_all((string) file_get_contents($root . '/' . $path));
    $n = count($tokens);
    for ($i = 0; $i < $n; ++$i) {
        if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue;
        }
        $j = $i + 1;
        if (!isset($tokens[$j], $tokens[$j + 1]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING
            || !in_array(strtolower($tokens[$j][1]), ['query', 'exec', 'prepare'], true) || $tokens[$j + 1] !== '(') {
            continue;
        }
        // Premier argument : jetons jusqu'à la virgule ou la parenthèse fermante de niveau 0.
        $depth = 0;
        $argument = [];
        for ($k = $j + 2; $k < $n; ++$k) {
            $token = $tokens[$k];
            $text = is_array($token) ? $token[1] : $token;
            if (in_array($text, ['(', '[', '{'], true) || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                ++$depth;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                if ($depth === 0) { break; }
                --$depth;
            } elseif ($text === ',' && $depth === 0) {
                break;
            }
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $argument[] = $token;
            }
        }
        ++$sinks;
        $kinds = array_unique(array_map(static fn ($t) => is_array($t) ? $t[0] : $t, $argument));
        if ($kinds !== [] && array_diff($kinds, [T_CONSTANT_ENCAPSED_STRING, '.']) === []) {
            ++$literal;   // SQL entièrement littéral : aucune donnée ne peut y entrer
            continue;
        }
        ++$dynamic;
        $text = '';
        foreach ($argument as $t) {
            $text .= is_array($t) ? $t[1] : $t;
        }
        if (preg_match('/\$_(GET|POST|COOKIE|REQUEST|SERVER|FILES)\b/', $text) === 1) {
            $tainted[] = $path;
        }
        // Normalisation : espaces hors chaînes supprimés, espaces multiples des chaînes réduits.
        $key = $path . '|' . preg_replace('/\s+/', ' ', $text);
        $key = preg_replace('/\s+(?=(?:[^\'"]|\'[^\']*\'|"[^"]*")*$)/', '', $key);
        $seen[$key] = true;
        if (!isset(REVIEWED[$key])) {
            $unreviewed[] = $key;
        }
    }
}
$verify($tainted === [], 'Aucune donnée de requête HTTP ($_GET, $_POST…) dans un argument SQL : ' . implode(', ', $tainted));
$verify($unreviewed === [], "Construction SQL dynamique non revue :\n  " . implode("\n  ", $unreviewed)
    . "\nVérifier qu'aucune donnée utilisateur n'y entre, puis l'inscrire dans REVIEWED avec sa justification.");
$stale = array_diff(array_keys(REVIEWED), array_keys($seen));
$verify($stale === [], "Entrée REVIEWED sans appel correspondant (à retirer) :\n  " . implode("\n  ", $stale));
$verify($sinks === $literal + $dynamic && $sinks >= 30,
    $sinks . ' appels SQL : ' . $literal . ' littéraux, ' . $dynamic . ' dynamiques (' . count(REVIEWED) . ' constructions revues)');

// 3. Gestion sécurisée des erreurs : aucun détail technique ne sort.
// errorInfo[2] porte le texte des SIGNAL des procédures (PAY_FORBIDDEN, IAM_CONFLICT…) : ces contrôleurs revus
// le comparent à des codes fixes pour choisir une clé de traduction, sans jamais l'afficher ni le journaliser.
// PersonalDataRepository (phase 6) reconnaît de la même façon PDATA_NO_RECORD.
const SIGNAL_MAPPERS = ['app/HR/PersonalDataRepository.php', 'app/Web/AdvancedHRController.php', 'app/Web/CompensationController.php', 'app/Web/IAMController.php',
    'app/Web/PayrollController.php', 'app/Web/SettlementController.php'];
$mappers = [];
$leaking = [];
$printing = [];
foreach ($files as $path) {
    $code = '';
    foreach (token_get_all((string) file_get_contents($root . '/' . $path)) as $token) {
        if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $code .= is_array($token) ? $token[1] : $token;
        }
    }
    if (preg_match('/->getMessage\(|getTraceAsString|->getTrace\(|var_dump\(|print_r\(|debug_print_backtrace|var_export\(/', $code) === 1) {
        $leaking[] = $path;
    }
    if (str_contains($code, 'errorInfo[2]')) {
        $mappers[] = $path;
        // Le texte du SGBD ne doit atteindre ni une page, ni une sortie, ni le journal.
        if (preg_match('/(render|echo|print|auditEvent|error_log|printf|header)\s*\([^;]*(errorInfo\[2\]|\$message\b|\$signal\b)/', $code) === 1) {
            $printing[] = $path;
        }
    }
}
$verify($leaking === [], 'Aucun message d’exception ni trace dans app/ et public/ : ' . implode(', ', $leaking));
$verify($printing === [], 'Texte d’erreur SQL jamais affiché ni journalisé : ' . implode(', ', $printing));
sort($mappers);
$verify($mappers === SIGNAL_MAPPERS, 'errorInfo[2] lu uniquement par les 6 fichiers revus : ' . implode(', ', $mappers));
$bootstrap = (string) file_get_contents($root . '/app/bootstrap.php');
$verify(str_contains($bootstrap, "ini_set('display_errors', '0');") && str_contains($bootstrap, 'set_exception_handler('),
    'display_errors désactivé ; gestionnaire d’exceptions global');

// 4. Pilote PDO : préparations natives et multi-requêtes refusées.
$connection = (string) file_get_contents($root . '/app/Database/Connection.php');
$verify(str_contains($connection, 'PDO::ATTR_EMULATE_PREPARES => false'), 'Préparations natives du serveur (EMULATE_PREPARES=false)');
$verify(str_contains($connection, 'PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION'), 'Erreurs SQL levées en exceptions');
$verify(str_contains($connection, '\Pdo\Mysql::ATTR_MULTI_STATEMENTS')
    && str_contains($connection, 'PDO::MYSQL_ATTR_MULTI_STATEMENTS')
    && str_contains($connection, '$multiStatements => false'), 'Multi-requêtes refusées par le pilote');

// 5. Confinement du composant vulnérable.
$references = [];
foreach ($files as $path) {
    $code = '';
    foreach (token_get_all((string) file_get_contents($root . '/' . $path)) as $token) {
        if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $code .= is_array($token) ? $token[1] : $token;
        }
    }
    if (str_contains($code, 'lab/vulnerable') || str_contains($code, 'Lab\\Vulnerable')) {
        $references[] = $path;   // hors commentaires
    }
}
sort($references);
$verify($references === ['app/Security/LabMode.php', 'app/Web/Controller.php'],
    'Composant vulnérable référencé seulement par le verrou et le contrôleur : ' . implode(', ', $references));

echo $count . ' contrôles de codage sécurisé réussis (' . $sinks . ' appels SQL : ' . $literal . ' littéraux, '
    . $dynamic . ' dynamiques issus de ' . count(REVIEWED) . " constructions revues), sans connexion.\n";
