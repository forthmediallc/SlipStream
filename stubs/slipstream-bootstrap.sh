#!/bin/sh
# Bring a brand-new instance up with Slipstream, from a launch script.
#
# Before the first release exists there is no artisan to run, so this makes a
# throwaway checkout with its dependencies, runs slipstream:bootstrap from it
# (tables, registry, mirror, the deploy ref deployed and health-checked), and
# removes the checkout. The release bootstrap builds is a normal one; the
# second composer install it does mostly comes from composer's local cache.
#
# Run it as the deploy user — the PHP-FPM pool user — never as root, or the
# releases and the mirror it creates belong to the wrong user:
#
#   sudo -u deploy APP_ROOT=/var/www/app REPO=git@github.com:org/app.git sh slipstream-bootstrap.sh
#
# Exits non-zero unless the node can serve, so a lifecycle hook can keep the
# instance out of the load balancer until it succeeds. Safe to run again.
#
# Needs: git, composer, php, the deploy key for $REPO in ~/.ssh, and the shared
# .env already at $APP_ROOT/.env (SLIPSTREAM_TRIGGER, SLIPSTREAM_DEPLOY_REF,
# SLIPSTREAM_REPOSITORY and the rest).
set -eu

: "${APP_ROOT:?set APP_ROOT to the layout root, e.g. /var/www/app}"
: "${REPO:?set REPO to the repository, e.g. git@github.com:org/app.git}"
PHP="${PHP:-php}"
COMPOSER="${COMPOSER:-composer}"
# Any recent commit with Slipstream installed works for the throwaway
# checkout; bootstrap resolves and deploys the real deploy ref itself.
BOOTSTRAP_BRANCH="${BOOTSTRAP_BRANCH:-}"

if [ ! -f "$APP_ROOT/.env" ]; then
    echo "slipstream-bootstrap: no shared .env at $APP_ROOT/.env" >&2
    exit 1
fi

mkdir -p "$APP_ROOT"
work=$(mktemp -d "$APP_ROOT/.bootstrap.XXXXXX")
trap 'rm -rf "$work"' EXIT INT TERM

if [ -n "$BOOTSTRAP_BRANCH" ]; then
    git clone --quiet --depth 1 --branch "$BOOTSTRAP_BRANCH" "$REPO" "$work/app"
else
    git clone --quiet --depth 1 "$REPO" "$work/app"
fi

ln -s "$APP_ROOT/.env" "$work/app/.env"
if [ -f "$APP_ROOT/auth.json" ]; then
    ln -s "$APP_ROOT/auth.json" "$work/app/auth.json"
fi

# Write where a release writes. The shared storage/ holds the logs and, when
# Slipstream's connection is a SQLite file under storage_path(), the node's
# own database; the checkout's storage/ goes when the checkout does.
if [ ! -d "$APP_ROOT/storage/framework" ] && [ -d "$work/app/storage" ]; then
    mkdir -p "$APP_ROOT/storage"
    cp -R "$work/app/storage/." "$APP_ROOT/storage/"
fi
for dir in framework/cache/data framework/sessions framework/views app/public app/private logs; do
    mkdir -p "$APP_ROOT/storage/$dir"
done
rm -rf "$work/app/storage"
ln -s "$APP_ROOT/storage" "$work/app/storage"

(cd "$work/app" && "$COMPOSER" install --no-dev --no-interaction --prefer-dist --no-progress --quiet)

cd "$work/app"
SLIPSTREAM_APP_ROOT="$APP_ROOT" "$PHP" artisan slipstream:bootstrap --repo="$REPO" --no-interaction
