# Coachwood Marketing Creative app

A standalone app where the marketing team types what they need, picks the platforms, and gets back a reviewed shortlist of finished ads, clean images, videos and copy for BioSteel and Canadian Protein. Laravel 13, PHP 8.4, MariaDB, Redis + Horizon, Livewire 4 + Alpine, Tailwind 4, Pest.

Read before working:
- `docs/brief.md`: the original brief. Never edited; changes go in `context.md`.
- `context.md`: decisions, open questions and the build log. Update it at the end of every piece of work: a log entry for what changed and was added (data, settings, permissions, screens, commands and scheduled tasks, plugins, environment and server, core changes, tests, after deploying), and a To do item for anything Lesley must do after deploying it.
- `docs/deploy.md`: how production is set up and what each deploy needs.
- `plugins/README.md`: how providers and integrations are packaged.

Relationship to the CS app (`/home/lesley/PhpstormProjects/Coachwood-AI-CS`):
- This app is 100% standalone: its own codebase and database, no shared modules, no shared tokens.
- Code may be lifted from the CS app and modified here. Never change the CS app.
- It works the same general way: settings registry with per-brand values (`App\Settings`), connections per brand with encrypted secrets (`App\Plugins\Connections`), navigation and permissions registered from service providers, providers as plugins.

Rules:
- Never commit or push. Lesley commits.
- No hard-coded operational values: budgets, shortlist sizes, limits, schedules and platform specs are settings or library rows with defaults.
- Plain language in the UI; technical detail only behind an "Advanced" toggle.
- Permissions are enforced server-side (policies); hiding UI is never the only check.
- Every build step leaves the app working and tested (`./vendor/bin/pest`, serially, never `--parallel`), and Pint clean (`./vendor/bin/pint`).
- Deterministic checks (pixel size, file size, copy limits, safe zones on composed ads) are code, not AI review.
- Text, logos, prices and CTAs on composed ads are typeset by the composer, never generated. Products are real pack shots composited in, never generated.
- Sidebar items register through `App\Navigation\Navigation`, settings through `SettingsRegistry` with a `SettingsArea` home, never by editing the views.
