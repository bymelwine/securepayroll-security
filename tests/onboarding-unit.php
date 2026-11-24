<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Auth\Onboarding;
use App\Auth\PasswordPolicy;
use App\Database\SessionRoles;
use App\Mail\Outbox;

/** Embauche et changement de mot de passe : règles PHP, boîte d'envoi et cohérence des scripts SQL, sans connexion. */
$count = 0;
$verify = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) { fwrite(STDERR, 'Échec : ' . $label . "\n"); exit(1); }
    ++$count;
};
$throws = static function (callable $work): bool {
    try { $work(); } catch (\Throwable) { return true; }
    return false;
};

// Identifiant professionnel.
foreach ([['Élodie', 'Benali-Tazi', 'elodie.benali-tazi'], ['Nadia', 'El Amrani', 'nadia.el-amrani'],
          ['  Jean ', "O'Neil", 'jean.o-neil'], ['Ñoño', 'Çelik', 'nono.celik'], ['محمد', 'Alaoui', 'alaoui'],
          ['محمد', 'علي', 'salarie'], ['A', str_repeat('b', 200), 'a.' . str_repeat('b', 138)]] as [$first, $last, $expected]) {
    $verify(Onboarding::loginBase($first, $last) === $expected, 'Identifiant ' . $expected);
}
foreach (["x'); DROP TABLE accounts; --", '<script>', "a\nb", 'Ünïcödé'] as $name) {
    $verify(preg_match('/\A[a-z0-9]+([.-][a-z0-9]+)*\z/', Onboarding::loginBase($name, $name)) === 1,
        'Identifiant réduit au jeu [a-z0-9.-], quelle que soit la saisie');
}

// Adresse personnelle.
$verify(Onboarding::personalEmailError('elodie.perso@example.org') === null, 'Adresse personnelle valide');
foreach (['', 'sans-arobase', 'a@b', "x@example.org\r\nBcc: victime@example.org", 'a b@example.org',
          str_repeat('a', 250) . '@example.org'] as $email) {
    $verify(Onboarding::personalEmailError($email) === 'onboarding.email_invalid', 'Adresse refusée : ' . json_encode($email));
}
$verify(Onboarding::personalEmailError('nadia@SecurePayroll.ma') === 'onboarding.email_professional',
    'Adresse professionnelle refusée comme adresse personnelle');

// Mot de passe temporaire : 16 caractères tirés de 56 symboles (≈ 92,9 bits), sans ambiguïté visuelle.
$seen = [];
$wellFormed = 0;
for ($i = 0; $i < 200; ++$i) {
    $temporary = Onboarding::temporaryPassword();
    $wellFormed += preg_match('/\A[a-km-zA-HJ-NP-Z2-9]{4}(-[a-km-zA-HJ-NP-Z2-9]{4}){3}\z/', $temporary);
    $seen[$temporary] = true;
}
$verify($wellFormed === 200, 'Format xxxx-xxxx-xxxx-xxxx sans 0, O, 1, l ni I (200 tirages)');
$verify(count($seen) === 200, '200 mots de passe temporaires distincts');
$verify(abs(16 * log(56, 2) - 92.9) < 0.1, 'Entropie ≈ 92,9 bits');

// Politique du nouveau mot de passe.
$temporary = 'abcd-efgh-jkmn-pqrs';
$personal = ['elodie.benali-tazi@securepayroll.ma', 'Élodie', 'Benali-Tazi'];
$cases = [
    ['court', 'password.length'],
    [str_repeat('x', 129), 'password.length'],
    ["Phrase longue\x01ok", 'password.invalid'],
    ["\xC3\x28 invalide utf8", 'password.invalid'],
    ['abcd-efgh-jkmn-pqrs', 'password.same'],
    ['SecurePayroll2026', 'password.common'],
    ['aaaaaaaaaaaaaaaa', 'password.common'],
    ['Mon-élodie-du-matin', 'password.personal'],
    ['tazi-et-les-collines', 'password.personal'],
    ['Cheval-Orange-Lampadaire-7', null],
];
foreach ($cases as [$candidate, $expected]) {
    $verify(PasswordPolicy::error($candidate, $candidate, $temporary, $personal) === $expected,
        'Politique : ' . ($expected ?? 'accepté'));
}
$verify(PasswordPolicy::error('Cheval-Orange-Lampadaire-7', 'Cheval-Orange-Lampadaire-8', $temporary, $personal) === 'password.mismatch',
    'Confirmation différente refusée');

// Rôles MySQL : un compte en attente d'activation n'a que sp_session, quels que soient ses rôles.
$pending = ['roles' => ['employee' => 'x', 'hr_manager' => 'y'], 'payroll_conflict' => false, 'password_change_required' => 1];
$verify(SessionRoles::names($pending) === ['sp_session'], 'Compte non activé : aucun rôle métier MySQL');
$verify(SessionRoles::names(['password_change_required' => 0] + $pending) === ['sp_session', 'sp_employee', 'sp_hr_manager'],
    'Compte activé : rôles métier restaurés');

// Boîte d'envoi : fichiers privés, en-têtes non injectables, jamais sans répertoire d'exécution privé.
putenv('APP_RUNTIME_DIR');
$verify($throws(static fn () => Outbox::send('a@example.org', 'Objet', 'Corps')), 'Boîte d’envoi refusée sans répertoire privé');
$directory = '/tmp/securepayroll-onboarding-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
putenv('APP_RUNTIME_DIR=' . $directory);
$verify($throws(static fn () => Outbox::send("a@example.org\r\nBcc: x@example.org", 'Objet', 'Corps')), 'Injection d’en-tête (destinataire) refusée');
$verify($throws(static fn () => Outbox::send('a@example.org', "Objet\r\nBcc: x@example.org", 'Corps')), 'Injection d’en-tête (objet) refusée');
$name = Outbox::send('a@example.org', 'Votre compte', "Ligne 1\r\nLigne 2\nLigne 3");
$file = $directory . '/outbox/' . $name;
$verify((fileperms($directory . '/outbox') & 0777) === 0700 && (fileperms($file) & 0777) === 0600, 'Dossier 0700, message 0600');
$message = (string) file_get_contents($file);
$verify(str_contains($message, "To: a@example.org\r\n") && str_contains($message, "Ligne 1\r\nLigne 2\r\nLigne 3\r\n")
    && substr_count($message, "\r\n\r\n") === 1, 'Message RFC 5322 : en-têtes, ligne vide, corps');
$verify(preg_match('/\A\d{8}T\d{6}Z-[0-9a-f]{12}\.eml\z/', $name) === 1, 'Nom de fichier non devinable');

// Scripts SQL : l'e-mail personnel ne se relit pas, l'empreinte n'est lisible que par sp_login et le propriétaire.
$root = dirname(__DIR__);
$grants = preg_replace('/--[^\n]*/', '', (string) file_get_contents($root . '/database/setup/onboarding_grants.sql'));
$migration = (string) file_get_contents($root . '/database/migrations/013_account_onboarding.sql');
$migration14 = preg_replace('/--[^\n]*/', '', (string) file_get_contents($root . '/database/migrations/014_account_onboard_matricule.sql'));
$verify(preg_match_all('/personal_email/', $grants) === 1
    && preg_match("/INSERT \\(account_id, personal_email[^)]*\\)\\s+ON secure_payroll.account_invitations TO 'iam_owner'/", $grants) === 1,
    'personal_email : écriture par le seul propriétaire, aucune lecture accordée');
$verify(preg_match_all('/password_hash/', $grants) === 3 && !preg_match("/password_hash[^;]*TO 'sp_/", $grants),
    'password_hash : aucun rôle sp_* supplémentaire');
$verify(str_contains($grants, "REVOKE INSERT (employee_number, first_name, last_name) ON secure_payroll.employees FROM 'sp_hr_manager';"),
    'RH : INSERT direct sur employees retiré');
$verify(str_contains($grants, "GRANT EXECUTE ON PROCEDURE secure_payroll.account_change_password TO 'sp_password';")
    && preg_match_all("/account_change_password TO 'sp_/", $grants) === 1, 'Changement de mot de passe : rôle sp_password seul');
$verify(substr_count($migration, "SQL SECURITY DEFINER") === 2 && substr_count($migration, "DEFINER='iam_owner'@'localhost'") === 2,
    'Deux procédures DEFINER du propriétaire verrouillé iam_owner');
$verify(str_contains($migration14, "CALL payroll_assert(p_actor, 'employees.create', 'hr_manager', v_person);"),
    'Embauche réservée au rôle RH actif, vérifiée en base (procédure effective 014)');
$verify(!str_contains($migration14, 'p_number')
    && preg_match("/REGEXP CONCAT\\('\\^EMP-', v_year, '-\\[0-9\\]\\+\\\$'\\)/", $migration14) === 1
    && str_contains($migration14, "CONCAT('EMP-', v_year, '-', LPAD(v_seq, 3, '0'))"),
    'Matricule EMP-<année>-<numéro> généré par la base, plus saisi (p_number retiré)');
$verify(preg_match_all("/GRANT EXECUTE ON PROCEDURE secure_payroll.account_onboard TO '(sp_hr_manager|'?iam_owner'?[^;]*)'?/", $migration14) === 2,
    'DROP PROCEDURE efface les droits : 014 réaccorde EXECUTE à sp_hr_manager et iam_owner');
$verify(!preg_match('/PREPARE|EXECUTE IMMEDIATE|CONCAT\\([^)]*SELECT/i', $migration14),
    'Aucun SQL dynamique dans la procédure de matricule (014)');
$verify(str_contains($migration, 'a.password_hash = p_current_hash') && str_contains($migration, 'temporary_password_expires_at > CURRENT_TIMESTAMP(6)'),
    'Changement conditionné à l’empreinte actuelle et à la validité du mot de passe temporaire');
$verify(!preg_match('/PREPARE|EXECUTE IMMEDIATE|CONCAT\([^)]*SELECT/i', $migration), 'Aucun SQL dynamique dans les procédures');

echo $count . " contrôles d'embauche et de mot de passe réussis, sans connexion (fichiers de test conservés : $directory).\n";
