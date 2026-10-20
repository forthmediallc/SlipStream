#!/bin/sh
# Bring a brand-new instance up with Slipstream, from a launch script.
#
# Before the first release exists there is no artisan to run, so this makes a
# throwaway checkout, runs slipstream:bootstrap from it (tables, registry, the
# deploy ref deployed and health-checked), and removes the checkout.
#
# Git mode (the default): the checkout is the deploy ref itself, read from the
# shared .env — the branch or tag SLIPSTREAM_DEPLOY_REF names (SLIPSTREAM_BRANCH
# when it is unset), or, for a tag pattern, the highest version tag matching it
# — with its dependencies installed. Composer's scripts and artisan run with
# the production .env, so nothing but released code runs before the first
# release. BOOTSTRAP_BRANCH (a branch or tag) overrides it.
#
# Artifact mode (SLIPSTREAM_RELEASE_SOURCE=artifact in the shared .env): the
# checkout is the release CI built, fetched from ARTIFACTS and verified with
# slipstream-release.php — the signed pointer, the signed manifest, the
# archive's hash and every file's — before anything in it runs. No git, no
# composer, no deploy key. If the pointer's release does not verify, the
# pointer's previous release is used instead, unless --no-fallback is given.
#
# Run it as the deploy user — the PHP-FPM pool user — never as root, or the
# releases and the mirror it creates belong to the wrong user:
#
#   sudo -u deploy APP_ROOT=/var/www/app REPO=git@github.com:org/app.git sh slipstream-bootstrap.sh
#   sudo -u deploy APP_ROOT=/var/www/app ARTIFACTS=s3://app-releases/production sh slipstream-bootstrap.sh
#
# Options for slipstream:bootstrap, such as --no-fallback, go after the script
# name or in BOOTSTRAP_ARGS.
#
# Exits non-zero unless the node can serve, so a lifecycle hook can keep the
# instance out of the load balancer until it succeeds. Safe to run again.
#
# Needs: php and the shared .env already at $APP_ROOT/.env. Git mode: git,
# composer, the deploy key for $REPO in ~/.ssh. Artifact mode: the AWS CLI for
# an s3:// store, and slipstream-release.php beside this script (RELEASE_TOOL).
set -eu

: "${APP_ROOT:?set APP_ROOT to the layout root, e.g. /var/www/app}"
PHP="${PHP:-php}"
COMPOSER="${COMPOSER:-composer}"
BOOTSTRAP_BRANCH="${BOOTSTRAP_BRANCH:-}"
BOOTSTRAP_ARGS="${BOOTSTRAP_ARGS:-}"
RELEASE_TOOL="${RELEASE_TOOL:-$(dirname "$0")/slipstream-release.php}"

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

# The deploy ref, as the shared .env names it.
ref=$(env_value SLIPSTREAM_DEPLOY_REF)
if [ -z "$ref" ]; then
    branch=$(env_value SLIPSTREAM_BRANCH)
    ref="refs/heads/${branch:-main}"
fi
case "$ref" in
    refs/heads/*|refs/tags/*) ;;
    *\**|*\?*|*\[*) ref="refs/tags/$ref" ;;
    *) ref="refs/heads/$ref" ;;
esac
name=${ref#refs/heads/}
name=${name#refs/tags/}

work=$(mktemp -d "$APP_ROOT/.bootstrap.XXXXXX")
trap 'rm -rf "$work"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

if [ "$(env_value SLIPSTREAM_RELEASE_SOURCE)" = "artifact" ]; then
    : "${ARTIFACTS:?set ARTIFACTS to the store, e.g. s3://bucket/prefix (or file:///dir)}"
    if [ ! -f "$RELEASE_TOOL" ]; then
        echo "slipstream-bootstrap: no slipstream-release.php at $RELEASE_TOOL (RELEASE_TOOL)" >&2
        exit 1
    fi
    case "$name" in
        *\**|*\?*|*\[*)
            echo "slipstream-bootstrap: tag patterns are unsupported in artifact mode; CI publishes one ref ($ref)" >&2
            exit 1
            ;;
    esac
    keys=$(env_value SLIPSTREAM_RELEASE_PUBLIC_KEYS)
    environment=$(env_value SLIPSTREAM_RELEASE_ENVIRONMENT)
    if [ -z "$keys" ] || [ -z "$environment" ]; then
        echo "slipstream-bootstrap: SLIPSTREAM_RELEASE_PUBLIC_KEYS and SLIPSTREAM_RELEASE_ENVIRONMENT must be set in the shared .env" >&2
        exit 1
    fi

    max_archive=$(env_value SLIPSTREAM_ARTIFACTS_MAX_ARCHIVE_BYTES)

    # One object from the store to a local file, refused before the download
    # when it is larger than it can honestly be ($3 bytes).
    fetch() {
        case "$ARTIFACTS" in
            s3://*)
                location=${ARTIFACTS#s3://}
                bucket=${location%%/*}
                prefix=${location#"$bucket"}
                prefix=${prefix#/}
                prefix=${prefix%/}
                size=$(aws s3api head-object --bucket "$bucket" --key "${prefix:+$prefix/}$1" --query ContentLength --output text) || return 1
                ;;
            file://*)
                size=$(wc -c < "${ARTIFACTS#file://}/$1") || return 1
                ;;
            *)
                echo "slipstream-bootstrap: ARTIFACTS must be s3://bucket/prefix or file:///dir" >&2
                return 1
                ;;
        esac
        size=$(echo "$size" | tr -d '[:space:]')
        case "$size" in
            ''|*[!0-9]*) echo "slipstream-bootstrap: cannot tell the size of $1" >&2; return 1 ;;
        esac
        if [ "$size" -gt "$3" ]; then
            echo "slipstream-bootstrap: $1 is $size bytes, more than the $3 it may be" >&2
            return 1
        fi
        case "$ARTIFACTS" in
            s3://*) aws s3 cp "${ARTIFACTS%/}/$1" "$2" --quiet ;;
            file://*) cp "${ARTIFACTS#file://}/$1" "$2" ;;
        esac
    }

    # A release's three objects, checked — the manifest against the hash the
    # pointer names, its statement, the archive, every file — and unpacked
    # into $work/app. Non-zero, with nothing unpacked, when any check fails.
    release() {
        rm -rf "$work/rel" "$work/app"
        mkdir "$work/rel"
        fetch "releases/$1/manifest.json" "$work/rel/manifest.json" 67108864 \
            && fetch "releases/$1/manifest.sig" "$work/rel/manifest.sig" 65536 \
            && fetch "releases/$1/release.tar.gz" "$work/rel/release.tar.gz" "${max_archive:-1073741824}" \
            && "$PHP" "$RELEASE_TOOL" verify-release --sha="$1" --ref="$ref" --manifest-sha256="$2" --environment="$environment" --keys="$keys" \
                --manifest="$work/rel/manifest.json" --signature="$work/rel/manifest.sig" --archive="$work/rel/release.tar.gz" --extract-to="$work/app"
    }

    echo "slipstream-bootstrap: reading the pointer for $ref"
    fetch "$ref.json" "$work/pointer.json" 65536
    # Prints sha=, manifest_sha256=, sequence=, previous_sha=,
    # previous_manifest_sha256= — hex and digits only. The assignment carries
    # the tool's exit status, so a refusal stops the script here.
    facts=$("$PHP" "$RELEASE_TOOL" verify-pointer --ref="$ref" --environment="$environment" --keys="$keys" --pointer="$work/pointer.json")
    eval "$facts"

    no_fallback=
    set -f
    # shellcheck disable=SC2086
    for arg in $BOOTSTRAP_ARGS "$@"; do
        if [ "$arg" = "--no-fallback" ]; then no_fallback=1; fi
    done
    set +f

    echo "slipstream-bootstrap: fetching release $sha"
    if ! release "$sha" "$manifest_sha256"; then
        if [ -n "$no_fallback" ] || [ -z "$previous_sha" ]; then
            echo "slipstream-bootstrap: release $sha did not verify; stopping${no_fallback:+ (--no-fallback)}" >&2
            exit 1
        fi
        # The one other release the signed pointer vouches for, bound to the
        # manifest it names. slipstream:bootstrap, run from it, retries the
        # head and falls back the same way.
        echo "slipstream-bootstrap: release $sha did not verify; falling back to the pointer's previous release $previous_sha" >&2
        release "$previous_sha" "$previous_manifest_sha256"
    fi
    rm -rf "$work/rel"
else
    : "${REPO:?set REPO to the repository, e.g. git@github.com:org/app.git}"

    # What to check out: BOOTSTRAP_BRANCH, else what the deploy ref names now.
    checkout="$BOOTSTRAP_BRANCH"
    if [ -z "$checkout" ]; then
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

    echo "slipstream-bootstrap: checking out $checkout"
    git clone --quiet --depth 1 --branch "$checkout" "$REPO" "$work/app"
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

if [ "$(env_value SLIPSTREAM_RELEASE_SOURCE)" != "artifact" ]; then
    (cd "$work/app" && "$COMPOSER" install --no-dev --no-interaction --prefer-dist --no-progress --quiet)
fi

cd "$work/app"
# BOOTSTRAP_ARGS is a list of options: split on purpose, never globbed.
set -f
# shellcheck disable=SC2086
if [ "$(env_value SLIPSTREAM_RELEASE_SOURCE)" = "artifact" ]; then
    SLIPSTREAM_APP_ROOT="$APP_ROOT" "$PHP" artisan slipstream:bootstrap --no-interaction $BOOTSTRAP_ARGS "$@"
else
    SLIPSTREAM_APP_ROOT="$APP_ROOT" "$PHP" artisan slipstream:bootstrap --repo="$REPO" --no-interaction $BOOTSTRAP_ARGS "$@"
fi
