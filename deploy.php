<?php

/*
 * Deployment recipe for Deployer 7 (https://deployer.org), run by the `deploy`
 * job of .github/workflows/ci.yml after the test suite passed on `main`.
 *
 * This file is public on purpose and holds nothing about the server: host,
 * user and path come from the environment, which the workflow fills from the
 * secrets of the GitHub Environment `deployment`. To deploy by hand, export the
 * same variables:
 *
 *   DEPLOY_HOST=… DEPLOY_USER=… DEPLOY_PATH=… dep deploy prod
 *
 * Server preparation, the PHP-FPM pool and the nginx vhost: DEPLOYMENT.md and
 * the examples in deploy/.
 */

declare(strict_types=1);

namespace Deployer;

require 'recipe/symfony.php';

/**
 * A setting that only the environment may provide. Failing loudly beats
 * connecting to an empty hostname.
 */
function fromEnv(string $name, ?string $default = null): string
{
    $value = getenv($name);
    if (false !== $value && '' !== $value) {
        return $value;
    }

    if (null !== $default) {
        return $default;
    }

    throw new \RuntimeException(sprintf('Ustaw zmienną środowiskową %s (sekret Environment "deployment" w GitHub).', $name));
}

// The repository is public, so the server clones it over HTTPS without a deploy key.
set('repository', 'https://github.com/luklewluk/taxcalc.git');
set('keep_releases', 3);

// PHP-FPM runs as the deploy user, which therefore already owns var/.
set('writable_mode', 'skip');

set('bin/php', fn (): string => fromEnv('DEPLOY_PHP', '/usr/bin/php8.4'));
// Composer wherever the server has it (/usr/bin from a package, /usr/local/bin
// from the installer), run with the PHP chosen above.
set('bin/composer', fn (): string => fromEnv('DEPLOY_COMPOSER', '{{bin/php}} '.which('composer')));
set('composer_options', '--verbose --prefer-dist --no-progress --no-interaction --no-dev --optimize-autoloader --classmap-authoritative');

// shared_files ['.env.local'] and shared_dirs ['var/log'] come from the Symfony recipe.
// No database, so no migrations; `composer install` clears and warms the prod cache.

host('prod')
    ->setHostname(fromEnv('DEPLOY_HOST', '-'))
    ->setRemoteUser(fromEnv('DEPLOY_USER', '-'))
    ->setDeployPath(fromEnv('DEPLOY_PATH', '-'))
    ->set('labels', ['stage' => 'prod']);

// The hostname is read lazily above so `dep tree` works anywhere; a real run
// must have every value.
task('deploy:check_env', static function (): void {
    foreach (['DEPLOY_HOST', 'DEPLOY_USER', 'DEPLOY_PATH'] as $name) {
        fromEnv($name);
    }
})->desc('Checks that the server settings came from the environment');
before('deploy:info', 'deploy:check_env');

// No PHP-FPM reload and no sudo: nginx passes $realpath_root, so every release
// is a new path and opcache compiles it fresh even with validate_timestamps
// off. Old releases' entries stay in opcache memory until PHP-FPM restarts.

after('deploy:failed', 'deploy:unlock');
