#!/usr/bin/env bash
# Runs after every pull on the server. Set as the Plesk Git "additional
# deployment actions":  bash deploy/plesk-deploy.sh
# Paths default to Plesk's PHP 8.4 and Node 24; override with env vars if the
# server differs.
set -euo pipefail

# Plesk only shows "Done" for this script, whatever happens in it. So everything it
# prints also goes to storage/logs/deploy.log (Plesk File Manager, or the app's logs),
# with when each deploy started, and whether it finished or which command failed.
LOG="storage/logs/deploy.log"
mkdir -p storage/logs
[ -f "$LOG" ] && tail -n 3000 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"
exec > >(tee -a "$LOG") 2>&1
echo "=== $(date -u '+%Y-%m-%d %H:%M:%S UTC') deploy started"
trap 'echo "=== $(date -u "+%Y-%m-%d %H:%M:%S UTC") deploy FAILED at line $LINENO: $BASH_COMMAND"' ERR
trap 'status=$?; [ "$status" -eq 0 ] && echo "=== $(date -u "+%Y-%m-%d %H:%M:%S UTC") deploy finished"' EXIT

PHP="${PHP:-/opt/plesk/php/8.4/bin/php}"
# Plesk keeps composer in different places by version; the first one found is used.
if [ -z "${COMPOSER:-}" ]; then
    for candidate in /usr/lib/plesk-9.0/composer.phar /usr/lib64/plesk-9.0/composer.phar /usr/local/psa/var/modules/composer/composer.phar /opt/plesk/composer/composer.phar "$(command -v composer 2>/dev/null || true)"; do
        if [ -n "$candidate" ] && [ -f "$candidate" ]; then COMPOSER="$candidate"; break; fi
    done
fi
NODE_BIN="${NODE_BIN:-/opt/plesk/node/24/bin}"
export PATH="$NODE_BIN:$PATH"

# Without composer the deploy carries on (migrations and the caches still run), but
# say so: new packages then have to be installed from Plesk's PHP Composer screen.
if [ -n "${COMPOSER:-}" ]; then
    "$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress
else
    echo "WARNING: composer wasn't found, so packages weren't installed. Run Composer install from Plesk if composer.json changed." >&2
fi
npm ci --no-audit --no-fund
npm run build

# Stop before changing the database if the app can't even start (a file that
# doesn't parse, a missing class): every route and its controller has to load.
# GitHub only releases tested commits to production, so this is a last guard.
"$PHP" artisan route:list --json > /dev/null

"$PHP" artisan migrate --force
"$PHP" artisan optimize

# Horizon finishes its current jobs and exits; systemd starts it again on the
# new code. Replies waiting for their due time live in the database and Redis,
# so nothing is lost.
"$PHP" artisan horizon:terminate
