<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Security\LoginLimiter;

$count = 0;
function verify(bool $ok, string $label): void
{
    global $count;
    if (!$ok) { fwrite(STDERR, 'Limiter assertion failed: ' . $label . "\n"); exit(1); }
    ++$count;
}
$directory = '/tmp/securepayroll-limiter-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
putenv('APP_RUNTIME_DIR=' . $directory);
$fail = static fn (string $login): bool => LoginLimiter::allow($login); // tentative admise puis échouée

// Les connexions réussies ne consomment rien.
$_SERVER['REMOTE_ADDR'] = 'lab-success';
for ($i = 0; $i < 10; ++$i) { verify(LoginLimiter::allow('alice'), 'succès répétés'); LoginLimiter::succeeded(); }

// T-18 : cinq échecs sur un compte bloquent ce compte seul, pas les autres utilisateurs de la même adresse.
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
for ($i = 0; $i < LoginLimiter::ACCOUNT_LIMIT; ++$i) { verify($fail('employee.demo'), 'échec admis ' . $i); }
verify(!LoginLimiter::allow('employee.demo') && LoginLimiter::blockedScope() === 'account', 'compte bloqué');
verify(!LoginLimiter::allow('EMPLOYEE.demo'), 'variante de casse également bloquée');
verify(LoginLimiter::allow('hr.demo') && LoginLimiter::blockedScope() === null, 'autre compte, même adresse : admis');
LoginLimiter::succeeded();

// Un compte inexistant est limité exactement comme un compte réel (pas d'énumération).
for ($i = 0; $i < LoginLimiter::ACCOUNT_LIMIT; ++$i) { verify($fail('inconnu.demo'), 'inexistant admis ' . $i); }
verify(!LoginLimiter::allow('inconnu.demo') && LoginLimiter::blockedScope() === 'account', 'inexistant bloqué');

// Un refus sans réservation ne peut effacer aucun échec.
LoginLimiter::succeeded();
verify(!LoginLimiter::allow('employee.demo'), 'succeeded() sans réservation sans effet');

// Une réussite efface les échecs antérieurs du compte, sans rendre leur crédit aux autres tentatives.
for ($i = 0; $i < LoginLimiter::ACCOUNT_LIMIT - 1; ++$i) { verify($fail('payroll.demo'), 'échecs avant réussite'); }
verify(LoginLimiter::allow('payroll.demo'), 'cinquième tentative admise');
LoginLimiter::succeeded();
for ($i = 0; $i < LoginLimiter::ACCOUNT_LIMIT; ++$i) { verify($fail('payroll.demo'), 'compteur du compte remis à zéro'); }

// Pulvérisation : une tentative sur beaucoup de comptes différents est plafonnée par adresse.
$_SERVER['REMOTE_ADDR'] = 'lab-spray';
for ($i = 0; $i < LoginLimiter::ADDRESS_LIMIT; ++$i) { verify($fail('spray-' . $i), 'pulvérisation admise ' . $i); }
verify(!LoginLimiter::allow('spray-new') && LoginLimiter::blockedScope() === 'address', 'adresse plafonnée');
$files = count(glob($directory . '/login-account-*.json'));
verify(!LoginLimiter::allow('spray-encore') && count(glob($directory . '/login-account-*.json')) === $files,
    'adresse plafonnée : aucun nouveau fichier de compte');
$_SERVER['REMOTE_ADDR'] = 'lab-other';
verify(LoginLimiter::allow('spray-new'), 'autre adresse non affectée');

// Un compte bloqué ne consomme pas le quota de l'adresse.
$_SERVER['REMOTE_ADDR'] = 'lab-locked';
for ($i = 0; $i < LoginLimiter::ACCOUNT_LIMIT; ++$i) { verify($fail('cible'), 'cible ' . $i); }
for ($i = 0; $i < 100; ++$i) { LoginLimiter::allow('cible'); }
verify(LoginLimiter::allow('autre') , 'tentatives sur un compte bloqué hors quota de l\'adresse');

// Aucun identifiant ni adresse en clair sur disque.
$content = implode('', array_map('file_get_contents', glob($directory . '/login-*.json')))
    . implode('', glob($directory . '/*'));
verify(!str_contains($content, 'employee.demo') && !str_contains($content, '127.0.0.1'), 'aucun identifiant en clair');

echo "$count limiter checks passed; temporary fixtures retained outside project.\n";
