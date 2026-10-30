# Deploying to marketing.biosteel.com

Production runs on the same DigitalOcean Plesk server as the CS app (cs.biosteel.com), as its own site with its own database, Horizon service and Redis prefixes. Nothing is shared with the CS app except the server, MariaDB and Redis.

Code deploys through Plesk's Git integration from the `production` branch. Nobody pushes to `production` directly: every push to `main` runs the tests on GitHub (`.github/workflows/release.yml`: every PHP file parses, Pint, the full Pest suite against MariaDB), and only a commit that passes is moved to `production`. Plesk then pulls it and runs `deploy/plesk-deploy.sh` (Composer, npm build, a check that every route loads, migrations, caches, Horizon restart). A push that fails stays on `main`; the site keeps running the last good commit. See the run on GitHub under Actions.

`SITE_PATH` below is the site's folder, `/var/www/vhosts/marketing.biosteel.com/httpdocs`. `SITE_SYSTEM_USER` is the Plesk system user that owns it (Websites & Domains → the site → Hosting settings). Plesk's PHP is `/opt/plesk/php/8.4/bin/php`.

**Before the first deploy**, read "What each deploy needs" at the end: `context.md` lists, per build step, the settings and set-up steps to do once the code is on the server.

## One-time server set-up

1. **Site.** Add `marketing.biosteel.com` in Plesk (DNS: an A record to the server, like cs.biosteel.com), turn on Let's Encrypt SSL, and set the document root to `httpdocs/public`.
2. **PHP.** Hosting settings → PHP 8.4 (FPM), as the CS site. The extensions needed are `pdo_mysql`, `redis`, `mbstring`, `xml`, `curl`, `zip`, `intl`, `bcmath`, `gd`, `exif`, `fileinfo`. `gd` and `exif` matter here more than on the CS app: the app reads and composes images.
3. **Node.** Plesk's Node.js extension with Node 24 is already there for the CS app; the deploy script uses it to build CSS and JS.
4. **Database.** Databases → add a MariaDB database `coachwood_marketing` and a user for it. Don't reuse the CS app's user.
5. **Redis.** Already running for the CS app (`redis-cli ping` answers `PONG`). The two apps share it: this app keeps every key apart with `CACHE_PREFIX=coachwood_marketing` and `REDIS_PREFIX=coachwood_marketing_` (set in step 7; both are in `.env.example`). Never leave them empty.
6. **Git.** Websites & Domains → Git → add `https://github.com/Dh42/Coachwood-AI-Marketing.git`, branch `production`, deploy automatically into `httpdocs`. Plesk shows a webhook URL; add it on GitHub (repo Settings → Webhooks, content type JSON, push events). Deployment actions: `bash deploy/plesk-deploy.sh`. The `production` branch is created by the first GitHub Actions run that passes (step 10), so add the Git integration after that, or press Deploy in Plesk once the branch exists.
7. **Environment.** Create `SITE_PATH/.env` from `.env.example` and set:
   - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://marketing.biosteel.com`, `LOG_STACK=daily`, `LOG_LEVEL=info`, `SESSION_LIFETIME=1440`.
   - The database from step 4.
   - `CACHE_PREFIX` and `REDIS_PREFIX` as in step 5.
   - `TRUSTED_PROXIES=127.0.0.1,::1`. Plesk's nginx hands each request to PHP from `127.0.0.1` and adds the visitor's address to `X-Forwarded-For`; only that header, from those addresses, is believed, never the host or scheme. Every per-address limit (sign-in attempts, password resets) depends on it. To check nginx adds the header: `grep -ri x-forwarded-for /etc/nginx/plesk.conf.d/vhosts/marketing.biosteel.com.conf` should show `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;`. If the site ever goes behind Cloudflare, Cloudflare's addresses have to be added here too (the CS app has a switch for it; this app doesn't yet).
   - With an `https://` `APP_URL`, the session cookie is HTTPS-only and browsers are told to use HTTPS only for a year (`SESSION_SECURE_COOKIE` and `HSTS_SECONDS` override either).
   - Google sign-in, email and Spaces: the sections below.
   - Then run `php artisan key:generate` once. Never copy `APP_KEY` from the CS app: it decrypts this app's stored secrets (API keys, tokens), so it's this app's own.
8. **Scheduler.** Add a cron job for `SITE_SYSTEM_USER` (Plesk: Tools & Settings → Scheduled Tasks, or `crontab -u SITE_SYSTEM_USER -e`):
   ```
   * * * * * cd SITE_PATH && /opt/plesk/php/8.4/bin/php artisan schedule:run >> /dev/null 2>&1
   ```
   **Platform → Scheduled tasks** in the app shows whether this is running, every task's last run, and lets an admin run one now. If nothing runs for longer than Settings → "Count the scheduled tasks as stopped after" (10 minutes by default), a warning shows across the app and platform admins are emailed; the check runs from Horizon's queue, so it works after the cron entry has stopped.
9. **Horizon.** As root, copy `deploy/horizon.service` to `/etc/systemd/system/coachwood-marketing-horizon.service`, replace `SITE_SYSTEM_USER` and `SITE_PATH`, then:
   ```
   systemctl daemon-reload
   systemctl enable --now coachwood-marketing-horizon
   ```
   systemd restarts Horizon if it stops, including after each deploy (the deploy script tells it to finish its jobs and exit). It's a separate service from `coachwood-cs-horizon`; each app's Horizon only sees its own queues thanks to the Redis prefix.
   - Workers: `supervisor-1` runs the default queue (`HORIZON_WORKERS` in `.env`, default 6 in production) and `supervisor-long` runs one low-priority worker (`nice 10`) for the long jobs (catalogue reads now; video assembly later), so a long job never starves the CS app or this app's own quick jobs. The long queue's `retry_after` is 1900 seconds; keep it above the longest job if you change it.
10. **First deploy.** Push to `main` and wait for the GitHub Actions run to pass (it creates `production`), then add or press Deploy in Plesk's Git screen (step 6), then open `https://marketing.biosteel.com/up`. It should say the application is up.

## Sign-in (Google)

The app uses the CS app's Google OAuth client (Google Cloud project `cloud-tools-506215`), which already has `https://marketing.biosteel.com/auth/google/callback` and `http://localhost:8000/auth/google/callback` as authorised redirect URIs. Sign-in is limited to company Google accounts (Settings → "Company email domains"); everyone else is invited from the Users screen and signs in with a password.

1. **Environment.** In `SITE_PATH/.env` set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` and `GOOGLE_REDIRECT_URI=https://marketing.biosteel.com/auth/google/callback`. Then `php artisan config:cache`.
2. **First admin.** Sign in once with Google (you'll see "no access yet"), then on the server:
   ```
   cd SITE_PATH && /opt/plesk/php/8.4/bin/php artisan users:grant-admin lesley@dh42.com
   ```
   After that, admins give roles on the Users screen, platform-wide or per brand.
3. **How long people stay signed in.** `SESSION_LIFETIME` is in minutes; production uses 1440 (a day). A turned-off account is still signed out on its next click.

## Email (Gmail)

Invitations, password resets, notifications and alerts go out through the Gmail API as one connected Google account: Lesley's, the app's main account.

1. **Google Cloud.** In the same OAuth client, add the redirect URI `https://marketing.biosteel.com/settings/email/callback` (the CS app has its own; this one isn't there yet). The Gmail API is already enabled in that project.
2. **Environment.** In `SITE_PATH/.env` set `MAIL_MAILER=gmail_api`, and `MAIL_FROM_ADDRESS` to the address you'll connect (Gmail sends as that account whatever is set here, so keep them the same), with `MAIL_FROM_NAME`. Then `php artisan config:cache`.
3. **Connect.** As an admin, open Settings → Sending email → Connect Gmail, sign in as the sending account and allow it to send. Press "Send a test" to check.

## Files (DigitalOcean Spaces)

Brand kit files (logos, references, fonts, tracks, pack shots), and later generated images, videos and cut-outs, live in a Space, not on the server disk. The brand kit is in the first deploy, so the Space is needed from the start.

1. **Space.** In DigitalOcean, create a Space in the same region as the server (`tor1` is the default in `.env.example`), private (no file listing), with its CDN off for now. Make a Spaces access key (API → Spaces Keys) for this app alone.
2. **Environment.** `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION=tor1`, `AWS_BUCKET=<the Space's name>`, `AWS_ENDPOINT=https://tor1.digitaloceanspaces.com`, `FILESYSTEM_DISK=s3`. Then `php artisan config:cache`.
3. The app serves these files itself to signed-in people with access to the brand (`/brands/{brand}/kit/files/…`); nothing in the Space is public, so leave file listing off.
4. Rejected outputs lose their files every night at 4 AM (`outputs:prune-rejects`, after Settings → Review and quality → "Keep rejected outputs for", 1 day by default), so the Space holds the brand kits, the shortlists and the last day's rejects.

## Finished ads (headless Chrome)

The composer renders each finished ad in headless Chrome at the placement's exact pixel size. Install Google Chrome (or Chromium) on the server so the web user can run it:

```
wget -q -O /tmp/chrome.deb https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb
sudo apt install -y /tmp/chrome.deb
google-chrome --version
```

The app runs it as `google-chrome` with `--headless=new --no-sandbox`; a different command or path goes under Settings → Generation → Advanced → "Chrome, for finished ads". Fonts uploaded to a brand kit are embedded in the page; Google Fonts named without a file are fetched from fonts.googleapis.com, so the server needs outbound HTTPS. Scratch files live in `storage/app/composer` and are removed after each render.

## AI models and providers

Every provider is a plugin, and each account is a connection: Connections → Add connection → the provider, with its API key, for one brand or shared by every brand. Keys are stored encrypted, checked at once ("Check now" repeats the check) and never go in `.env` on the server. A brand's own connection is used before a shared one.

- **Anthropic** (planning, copy and review): the key from console.anthropic.com. Settings → AI models on each brand sets the model for planning and copy (default Claude Opus 5.5), the review model and the effort; the platform's defaults are set from the same screen.
- **Google Gemini** (images): an API key from Google AI Studio on a project with billing set up.
- **OpenAI** (images): an API key from platform.openai.com on a project with credit.
- **Models** (Platform → Models): after the connections, press "Add the starting models". Every image and text model is a row with its prices; check them against the providers' price pages (the OpenAI 2K prices are estimates), switch off what the team shouldn't use, and add a row when a provider releases a model. Settings → AI models on each brand picks the image model and size.
- **Budgets** (Settings → Budgets and spend on any brand, under "For every brand"): the company's monthly limit, the warning line and each person's default. Confirming a run is refused when its estimate would pass what's left; a run already going always finishes.
- **Photoroom** (cut-outs of the store's product photos, for finished ads): an API key from photoroom.com → API on the Basic (Remove Background) plan, about 2 cents an image, billed by Photoroom separately from the app. A `sandbox_` key works but watermarks the result. "Check now" tries the cut-out call with no photo, which costs nothing. Settings → Store → "Cut-outs by" picks the connection when a brand has more than one; Settings → Store → "For every brand" has the price recorded per cut-out and the size. Cut-outs are made after each store read and from "Make the cut-outs" on Products, as queued jobs on the default queue; the files go under `catalogue/<brand>/cutouts/` on the files disk. Note: remove.bg's self-service API closes on 1 December 2026, which is why Photoroom.
- **Higgsfield** arrives with its build step; `context.md` says what's deployed. The Higgsfield API key lives nowhere but its connection.

## Stores (Shopify)

Each brand's catalogue is read from its store through a read-only custom app, one per store:

1. In Shopify: Settings → Apps and sales channels → Develop apps → Create an app ("Coachwood Marketing"), Configuration → Admin API scopes: `read_products` only, Install, then copy the Admin API access token (shown once).
2. In the app: Connections → Add connection → Shopify, pick the brand, enter `store.myshopify.com` and the token. The connection checks the token at once.
3. On the brand: Settings → Store → choose the connection, then Products → "Read the store now". After that the store is read once a day at the time in Settings → Store → "Read the store each day at" (03:00 by default, in the platform's time zone), by the scheduler, on the long queue.

The Shopify API version is a platform setting under Settings → Store → "For every brand" (Advanced); move it forward when Shopify retires the one in use.

## Coming with later steps

These aren't needed until the step that uses them is deployed; its entry in `context.md` says so.

- **Headless Chrome** is needed now: see "Finished ads (headless Chrome)" above.
- **ffmpeg**, for video assembly (phase 3). `apt install ffmpeg`.
- **Cloudflare**, if the site ever goes behind it: the visitor's address then needs Cloudflare's ranges trusted (see step 7).

## What each deploy needs

A deploy runs the migrations and rebuilds the caches on its own. What it can't do is set up accounts, keys and brand settings, so:

- Before deploying, read the log in `context.md`: every build step's entry lists what it added (tables, settings, permissions, screens, commands and scheduled tasks, environment variables, server needs) and anything to do after deploying it.
- The "To do (Lesley)" list in `context.md` has those after-deploy steps, grouped by build step. Tick them off there, or tell Claude, and they move to the log.
- After changing `.env`: `php artisan config:cache`, then `systemctl restart coachwood-marketing-horizon` (workers read the config when they start).

## Checking on it

- Deploy output: `SITE_PATH/storage/logs/deploy.log` (Plesk only shows "Done" for the deployment actions). Each deploy starts with "deploy started" and ends with "deploy finished" or "deploy FAILED at line …" and the command that failed. No new entry after a pull means the actions didn't run: press Deploy in Plesk's Git screen. Whether a push passed its tests: GitHub → the repo → Actions.
- Queue worker: `systemctl status coachwood-marketing-horizon`, or `journalctl -u coachwood-marketing-horizon -f` to follow it.
- App errors: `SITE_PATH/storage/logs/laravel.log` (one file a day with `LOG_STACK=daily`).
- Horizon dashboard: `https://marketing.biosteel.com/horizon`, for platform admins only.
- Scheduled tasks: Platform → Scheduled tasks in the app (last runs, errors, run now). Catalogue reads: each brand's Products page shows the last read and its result, and the activity log keeps every read under "Catalogue".
- Health: `https://marketing.biosteel.com/up`.
