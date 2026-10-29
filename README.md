# Coachwood Marketing Creative

AI marketing creative for BioSteel and Canadian Protein: one brief in, a reviewed shortlist of finished ads, clean images, video and copy out. See `CLAUDE.md` for orientation, `docs/brief.md` for the brief and `context.md` for decisions and progress.

## Local set-up

Needs PHP 8.4, Composer, Node 22, MariaDB and Redis.

```bash
composer install
npm install
cp .env.example .env    # then fill in DB_PASSWORD and the Google OAuth client
php artisan key:generate
php artisan migrate
npm run build
php artisan serve               # http://localhost:8000 (the Google sign-in redirect is registered for this port)
php artisan horizon             # queue worker, in a second terminal
```

Tests run against the `coachwood_marketing_test` database: `./vendor/bin/pest`.
