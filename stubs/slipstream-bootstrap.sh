#!/bin/sh
# Bring a brand-new instance up with Slipstream, from a launch script.
#
# Before the first release exists there is no artisan to run, so this makes a
# throwaway checkout with its dependencies, runs slipstream:bootstrap from it
# (tables, registry, mirror, the deploy ref deployed and health-checked), and
# removes the checkout. The release bootstrap builds is a normal one; the
# second composer install it does mostly comes from composer's local cache.
#
# The checkout is the deploy ref itself, read from the shared .env: the branch
# or tag SLIPSTREAM_DEPLOY_REF names (SLIPSTREAM_BRANCH when it is unset), or,
# for a tag pattern, the highest version tag matching it. Composer's scripts
# and artisan run with the production .env, so nothing but released code runs
# before the first release. BOOTSTRAP_BRANCH (a branch or tag) overrides it.
#
# Run it as the deploy user — the PHP-FPM pool user — never as root, or the
# releases and the mirror it creates belong to the wrong user:
#
#   sudo -u deploy APP_ROOT=/var/www/app REPO=git@github.com:org/app.git sh slipstream-bootstrap.sh
#
# Options for slipstream:bootstrap, such as --no-fallback, go after the script
# name or in BOOTSTRAP_ARGS.
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
BOOTSTRAP_BRANCH="${BOOTSTRAP_BRANCH:-}"
BOOTSTRAP_ARGS="${BOOTSTRAP_ARGS:-}"

if [ ! -f "$APP_ROOT/.env" ]; then
    echo "slipstream-bootstrap: no shared .env at $APP_ROOT/.env" >&2
    exit 1
fi

# The last value the shared .env gives a variable, unquoted; empty for none.
env_value() {
    tr -d '\r' < "$APP_ROOT/.env" \
        | sed -e 's/^[[:space:]]*export[[:space:]][[:space:]]*//' \
        | sed -n "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*//p" \
        | tail -n 1 \
        | sed -e 's/[[:space:]][[:space:]]*#.*$//' -e 's/[[:space:]]*$//' \
              -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/"
}

# What to check out: BOOTSTRAP_BRANCH, else what the deploy ref names now.
checkout="$BOOTSTRAP_BRANCH"
if [ -z "$checkout" ]; then
    ref=$(env_value SLIPSTREAM_DEPLOY_REF)
    if [ -z "$ref" ]; then
        branch=$(env_value SLIPSTREAM_BRANCH)
        ref="refs/heads/${branch:-main}"
    fi

    name=${ref#refs/heads/}
    name=${name#refs/tags/}

    case "$name" in
        *\**|*\?*|*\[*)
            # A tag pattern: the highest version tag matching it, by the rules
            # slipstream:bootstrap picks with (pre-releases only when allowed).
            checkout=$(git ls-remote --tags --refs "$REPO" \
                | PATTERN="$name" PRERELEASES="$(env_value SLIPSTREAM_DEPLOY_PRERELEASES)" "$PHP" -r '
                    $best = null;
                    foreach (preg_split("/\R/", stream_get_contents(STDIN)) as $line) {
                        if (preg_match("#^[0-9a-f]{40}(?:[0-9a-f]{24})?\s+refs/tags/(\S+)$#i", trim($line), $m) !== 1
                            || ! fnmatch(getenv("PATTERN"), $m[1])) {
                            continue;
                        }
                        $version = ltrim(preg_replace("#^.*/#", "", $m[1]), "vV");
                        if (preg_match("/^\d+(\.\d+)*([.-].*)?$/", $version) !== 1
                            || (! filter_var(getenv("PRERELEASES"), FILTER_VALIDATE_BOOLEAN)
                                && preg_match("/-|alpha|beta|rc|dev|pre/i", $version) === 1)) {
                            continue;
                        }
                        if ($best === null || version_compare($version, $best[1], ">")) {
                            $best = [$m[1], $version];
                        }
                    }
                    echo $best[0] ?? "";
                ')
            if [ -z "$checkout" ]; then
                echo "slipstream-bootstrap: no tag on $REPO matches $name" >&2
                exit 1
            fi
            ;;
        *)
            checkout=$name
            ;;
    esac
fi

work=$(mktemp -d "$APP_ROOT/.bootstrap.XXXXXX")
trap 'rm -rf "$work"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

echo "slipstream-bootstrap: checking out $checkout"
git clone --quiet --depth 1 --branch "$checkout" "$REPO" "$work/app"

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
# BOOTSTRAP_ARGS is a list of options: split on purpose, never globbed.
set -f
# shellcheck disable=SC2086
SLIPSTREAM_APP_ROOT="$APP_ROOT" "$PHP" artisan slipstream:bootstrap --repo="$REPO" --no-interaction $BOOTSTRAP_ARGS "$@"
