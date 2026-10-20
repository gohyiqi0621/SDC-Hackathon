<?php
/**
 * bin/create-user.php - create a portal account from the command line.
 *
 * Usage:
 *     php bin/create-user.php "Full Name" you@example.com [password]
 *
 * If the password is omitted a random one is generated and printed once.
 * There is no public registration and no admin portal, so this is how the
 * first account (and every other account) is provisioned.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bin/create-user.php is a command-line tool.\n");
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Src\Models\User;

function usage(): never
{
    fwrite(STDERR, "Usage: php bin/create-user.php \"Full Name\" you@example.com [password]\n");
    exit(1);
}

$name     = trim((string) ($argv[1] ?? ''));
$email    = trim((string) ($argv[2] ?? ''));
$password = (string) ($argv[3] ?? '');

if ($name === '' || $email === '') {
    usage();
}

if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
    fwrite(STDERR, "The name must be between 2 and 100 characters.\n");
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "That is not a valid email address.\n");
    exit(1);
}

$generated = false;

if ($password === '') {
    $password  = bin2hex(random_bytes(8)); // 16 hex characters
    $generated = true;
}

$problem = password_problem($password);
if ($problem !== null) {
    fwrite(STDERR, $problem . PHP_EOL);
    exit(1);
}

try {
    $users = new User();

    if ($users->emailExists($email)) {
        fwrite(STDERR, "An account already exists for " . User::normalizeEmail($email) . ".\n");
        exit(1);
    }

    $id = $users->create($name, $email, password_hash($password, PASSWORD_DEFAULT));

    fwrite(STDOUT, "Created user #" . $id . " (" . User::normalizeEmail($email) . ").\n");

    if ($generated) {
        fwrite(STDOUT, "Generated password: " . $password . "\n");
        fwrite(STDOUT, "Store it now; it will not be shown again.\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not create the user: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
