<?php

declare(strict_types=1);

/*
 * The entity manager phpstan-doctrine reads the mapping from. Booting the
 * kernel does not connect to the database: the platform comes from the
 * serverVersion in DATABASE_URL.
 */

use App\Kernel;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$kernel = new Kernel((string) $_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

$registry = $kernel->getContainer()->get('doctrine');
assert($registry instanceof ManagerRegistry);

return $registry->getManager();
