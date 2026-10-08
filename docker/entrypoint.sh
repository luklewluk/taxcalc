#!/bin/sh
# Applies pending Doctrine migrations (public NBP rate tables only), then runs
# the container's command. compose.yaml starts this container only once the
# database reports healthy, so a failure here is real and stops the start.
set -eu

php /app/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

exec "$@"
