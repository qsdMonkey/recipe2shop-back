<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Met la base de test (recipe2shop_test) au niveau des migrations avant de lancer les tests.
passthru(sprintf(
    'php %s/bin/console doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration --quiet',
    escapeshellarg(dirname(__DIR__)),
), $exitCode);
if (0 !== $exitCode) {
    exit($exitCode);
}

// Référentiel d'ingrédients (data/ingredients.csv).
passthru(sprintf('php %s/bin/console app:ingredients:sync --env=test --quiet', escapeshellarg(dirname(__DIR__))), $exitCode);
if (0 !== $exitCode) {
    exit($exitCode);
}
