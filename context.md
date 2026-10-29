# Project context log

Running log of progress, decisions and changes for the Marketing Creative app. The original brief is in [`docs/brief.md`](docs/brief.md); anything that changes or clarifies the brief is recorded here instead of editing the brief.

Newest entries at the top of each section.

## Current status

Updated 2026-10-28. Brief reviewed with Lesley in two rounds; decisions below. **Phase 1, step 1 (foundation) built, not committed:** Laravel 13 app with users, roles, Google sign-in and invitations, brands, the settings registry (platform, brand and user scopes), connections per brand with encrypted secrets, the plugin registry, the LLM manager, the activity log, scheduled tasks, Gmail sending, the app shell and the deploy pipeline. **Step 2a, the catalogue, built:** the Shopify plugin, the brand's product copy with packaging sizes per variant, the Products page, nightly and on-demand reads. 198 tests pass on MariaDB.

**Next up:** step 2b, the brand kit (logos, colours, fonts, voice, visual direction, style references, CTAs, legal lines, pack shots; bilingual text). Then the platform library, the AI provider plugins (Gemini, OpenAI), and the request flow.

## To do (Lesley)

- [ ] **Shopify custom app per store**, read-only: in Shopify, Settings → Apps and sales channels → Develop apps → Create an app ("Coachwood Marketing"), Configuration → Admin API scopes: `read_products` only, Install, then copy the Admin API access token. In this app: Connections → Add connection → Shopify, the brand, `store.myshopify.com` and the token. Then Settings → Store on the brand: choose it, and press "Read the store now" on Products.
- [ ] The first admin: start the app (`php artisan serve`, port 8000), sign in with Google once, then `php artisan users:grant-admin lesley@dh42.com` (or whichever company address you signed in with).

## Open questions

Waiting on Lesley:

- From the brief's own list: the app's name (the subdomain is `marketing.biosteel.com`); brands at launch; a read-only Shopify custom app per store (products only); API accounts and billing owner for Gemini, OpenAI, Higgsfield (prepaid API balance) and Anthropic; starting company and per-user budgets; licensed music for video; who owns each platform's AI-labelling rules.
- **Background removal service.** A provider plugin like the others. Candidates: a hosted API (Photoroom, remove.bg) or Higgsfield's remover if the API has it. Volume is tiny (one cutout per variant image, cached), so cost barely matters; pick whichever account is easiest.

## Decisions

Decisions made after the brief. Format: date, decision, reason.

### 2026-10-28 — The catalogue (phase 1, step 2a)

- **Store providers are a capability.** `App\Plugins\Contracts\StoreProvider::catalogue(Connection)` yields products in one plain shape (options, images, variants with their option values and image, text details). `PluginRegistry::providing(StoreProvider::class)` finds the plugins that implement it; the brand's Store setting (`store.connection`, brand scope only) offers those plugins' connections the brand may use. The Shopify plugin (`plugins/shopify`) is the first: a read-only custom app per store with `read_products` only, paged GraphQL reads, throttling handled, the API version a platform setting under "For every brand" in the Store entry.
- **The copy** (`products`, `product_variants`, `product_images`, `catalogue_syncs`): products gone from the store are marked removed, never deleted, so old requests keep their product; a variant keeps its packaging size across reads; a photo whose address changes loses its cut-out (to be made again). `App\Catalogue\Catalogue::sync()` counts added, changed and removed and logs it; failures are recorded with the reason, including "no store chosen".
- **Packaging sizes** are typed in centimetres on the Products page (width across the front, height, depth), stored in millimetres, per variant, with "Copy to same size" for the other flavours of that size. The page shows how many variants still have none.
- **Search** for the planner: `Catalogue::search($brand, $text)` matches words from a brief against title, handle, type, tags and variant options, live products first. JSON columns are lowered before LIKE (MariaDB compares them case-sensitively).
- **When it's read:** `catalogue.sync_time` (platform and brand, default 03:00 in the platform's time zone) via `catalogue:sync-due` hourly, and "Read the store now" on the Products page, both on the long queue, one read at a time per brand. The page polls while a read is running.
- Products is the first brand screen in the sidebar, so a new brand lands there.

### 2026-10-28 — Local set-up and the production address

- Databases `coachwood_marketing` and `coachwood_marketing_test` created by Lesley; migrated; the suite runs on MariaDB.
- Google sign-in uses the CS app's OAuth client (project `cloud-tools-506215`), which Lesley gave `https://marketing.biosteel.com/auth/google/callback` and `http://localhost:8000/auth/google/callback`. So locally the app runs on port 8000 (the CS app's port too: run one at a time, or add a redirect for another port). Production address: **marketing.biosteel.com**.

### 2026-10-28 — Higgsfield's API, checked with Lesley's key

- Lesley prefers the API over the CLI. The key is in the local `.env` (git-ignored), never in the repo. It becomes a connection in the app once the Higgsfield plugin exists.
- `GET /models` lists the catalogue (91 models, saved in the scratchpad): 18 image, 73 video. `POST /estimate/{slug}` prices a request before it runs (authoritative, in credits and US dollars). `GET /models/{slug}` doesn't exist; input schemas come from each model's docs page.
- **Not on the API:** gpt-image, Nano Banana, product-photoshoot, a prompt enhancer, a background remover. Those are the website/CLI product.
- **Image models and estimated cost per image:** Soul 2 $0.004 (text-to-image and image-to-image), Qwen Image 3 $0.04 (text-to-image and edit: 1 to 3 reference URLs, seed, negative prompt, 1k or 2k, ten aspect ratios), Z-Image Turbo $0.015, Ideogram 4.0 $0.06 (text-to-image and edit, strong text rendering), Grok Imagine 2.0 $0.07, Recraft 4.1 Pro $0.21, Marketing Studio Image $0.45 (text-to-image and edit), Ads Studio $0 base with per-token pricing (text in $5/M, image in $8/M, image out $30/M; reconciled on completion).
- **Video:** Kling 2.5 to 4.0 (text, image, reference, first-last frame, motion control, edit), Seedance 2.0 and 2.5, Wan 2.6 to 3.0, MiniMax, LTX 2.5, PixVerse 6, Happy Horse, Grok. Kling 3.0 Pro image-to-video about $0.84 a clip; Seedance 2.5 priced per second ($0.21 at 480p, $0.46 at 720p, $1.14 at 1080p).
- Inputs are public URLs or a presigned upload (`POST /files/generate-upload-url`, then PUT; JPEG, PNG, WebP, GIF, MP4, WAV). Outputs stay seven days (`expires_at`); the app downloads them to Spaces at once.
- Decision stands: Gemini and OpenAI direct for phase 1 images; Higgsfield's plugin can expose Ideogram and Qwen for images and is the phase 3 video provider. The brief's "Higgsfield: several third-party image models through one API" is right; "gpt-image through Higgsfield" is not.

### 2026-10-28 — Foundation built (phase 1, step 1)

Lifted from the CS app and reworked for brands; nothing shared with it.

- **Brands** (`App\Models\Brand`: slug as the route key, name, colour; `App\Brands\Brands` creates and updates them with the activity log). `{brand}` in every brand URL. `CurrentBrand` remembers the last one opened.
- **Roles:** Admin, Creator, Viewer, platform-wide or per brand (`role_assignments.brand_id`, 0 = platform). Permissions: `brands.view` (everyone), `brands.create` (Admin), `brands.manage` (Admin, Creator), `requests.create` (Admin, Creator), `requests.view_all` (Admin), `prompts.edit` (Admin), `prompts.personal` (Admin, Creator), `budgets.manage`, `connections.manage`, `users.manage`, `settings.manage`, `activity.view`, `scheduler.manage` (Admin).
- **Settings** resolve the person's own (`SettingScope::User`), then the brand, then the platform, then the code default. No channel or bot scopes, no Git sync. Settings areas list down the side of a brand's Settings under the headings Generation, Review and quality, Budgets and spend; "AI models" is the first area (planning and copy model, review model, effort; the Anthropic key under "For every brand").
- **Connections** belong to one brand (`brand_id`) or are shared (null); no brand labels, no action grants. `Connections::usedBy()` lets a feature say where a connection is in use so it can't be removed from under it.
- **Plugins:** `IntegrationPlugin` keeps the connection contract (fields or OAuth, health check, settings, account label); actions are gone. `PluginRegistry::providing($contract)` will find the enabled plugins that implement a capability interface (`App\Plugins\Contracts`, to come with the first provider).
- Kept as they were: Google sign-in limited to company domains, invitations, password sign-in for outsiders, the LLM manager and Anthropic provider with priced models, the activity log, scheduled tasks with the stall watchdog, Gmail API sending, the save bar, quick find, light and dark themes, security headers, the GitHub Actions release workflow and the Plesk deploy script (Horizon service renamed `coachwood-marketing-horizon`).
- Horizon: `supervisor-1` (default queue) and `supervisor-long` (one low-priority worker for video assembly and syncs). Redis and cache prefixes keep this app apart from the CS app on the same server.
- Spaces: the `s3` disk with `AWS_ENDPOINT` set to the Spaces region.
- Tests: 184 pass, Pint clean. Pest runs serially (`./vendor/bin/pest`).

### 2026-10-28 — Second round with Lesley

**Iterating without degrading.** Lesley: in practice, repeated edits make results worse and worse. Built in:
- Edits branch from the root, never from the previous edit. "Make a change" takes the original clean generation (full resolution, stored as PNG) as the source, and the planner folds every change asked so far into one instruction, so the fifth change is still one hop from the original. Setting `iterate.max_depth`, default 1.
- The planner sorts each instruction: a local fix (remove the object, fix the hand) goes to the model's edit or inpaint call with a mask where the model supports one; anything global (darker, different setting, different mood) regenerates from the amended prompt, keeping the seed where the provider allows it, instead of pushing the whole frame through image-to-image.
- Pack shots, text, logo, price and CTA never go through the model: the composer puts them back after every iteration, so the product and the words can't drift.
- Every iteration is reviewed with the same rubric and shown beside its parent with both scores; a drop is flagged. The parent is never replaced, and any version can be shortlisted.
- The edit carries "keep" constraints derived from the brief and the original prompt (subject, composition, palette), so the model doesn't redraw what wasn't asked for.
- Setting `iterate.max_changes` (default 3) per output: after that the app suggests a fresh run with the changes folded into the brief. Repeated instructions across users ("less pink") feed the "describe the problem" prompt-change flow.

**Hosting.** Same Plesk server as the CS app. Needs headless Chrome (composer) and ffmpeg (video) installed there, its own Horizon service and database, and video assembly on a single low-priority worker so it never starves the CS app.

**Notifications.** Email from Lesley's address through the Gmail API transport (lifted from the CS app); Lesley's account is the app's main account. In-app notices too. Which events email is a setting per user.

**Retention.** Rejected outputs are deleted nightly (`retention.rejects_days`, default 1). Shortlisted outputs stay until their request is deleted.

**Reference images on the request screen** are optional, not required. When one is uploaded the user says what it is: Style (look and mood for the planner's image prompt) or Product (a pack shot to use instead of, or beside, Shopify's). Default Style, since products come from Shopify.

**Higgsfield, after reading its docs.** Two different surfaces:
- The CLI the `marketing-images` skill uses signs in to the website account, spends subscription credits, and has the product-photoshoot route, Marketing Studio, brand kits, `gpt_image_2`/`gpt_image_2_5`, `nano_banana_*` and `image_background_remover`.
- The public API (`api.higgsfield.ai`, key ID and secret, prepaid US dollar balance) is a different product. Its docs list Soul, Marketing Studio, Ads Studio, Ideogram, Recraft, Qwen, Z-Image and Grok for images, and Kling, Seedance, Wan, MiniMax, LTX, PixVerse and Grok for video; the model catalogue lives in the console, not the docs. Nothing confirms gpt-image, Nano Banana, product-photoshoot, a prompt enhancer or the background remover on the API. It has `POST /estimate/{model}` (authoritative cost before running), per-request webhooks (`hf_webhook`, Standard Webhooks signatures, two-hour retries), an `Idempotency-Key`, statuses queued, in_progress, completed, failed, nsfw, canceled, cancel only while queued, no charge for failed or nsfw, and files kept seven days (download to Spaces at once).
- Decision: phase 1 image providers are Gemini and OpenAI direct, both with official APIs, reference images and usage-based cost. Higgsfield joins when the key shows what its API offers, and is the likely video provider in phase 3 (its video catalogue is confirmed). The plugin contract's `cost_estimate` maps straight onto its estimate endpoint.

### 2026-10-28 — Brief review with Lesley

**Relationship to the CS app**
- 100% standalone: own codebase and database, no shared modules, no shared tokens. Claude had suggested shared modules; Lesley doesn't want that.
- Code is lifted from the CS app and modified here, so the app works the same general way: settings registry with per-brand values, connections per brand with encrypted secrets, navigation, permissions and providers registered from service providers, Google sign-in limited to company domains, activity log. The CS app itself is never changed.

**Products: real pack shots, composited (reverses the brief's phase order)**
- The brief had reference-image generation as the main path and compositing as a phase 2 fallback. The Canadian Protein `marketing-images` skill learned the opposite: the label is where AI product imagery fails most visibly, and every approved creative uses real pack shots. So phase 1 composites real pack shots into generated scenes; generated-with-reference moves to phase 2 as an experiment, with the label check.
- **Background removal** is in phase 1: Shopify pack shots have a baked white background. Cutouts are made once per variant image and cached (service to choose; Higgsfield has a background remover).
- **Packaging dimensions per variant.** Sizes differ a lot within a product (2 kg, 1 kg, 1 lb), so when two products or sizes share a frame each is scaled by its real dimensions. Dimensions are entered per variant in the app (Shopify holds weight, not size). A variant without dimensions can't be composited beside another; alone it's fine.

**Bilingual from the start**
- English and French throughout, although nothing is marketed in French today. Copy and finished ads are produced per language chosen on the request; brand kit voice, CTAs and legal lines, layout templates' text and the prompts take both languages (the CS app's `Translated` setting control). A French run is never automatic; the request chooses its languages.

**Iterating on a result** (added; the brief had the `edit` call but no flow)
- Any output gets "Make a change" (an instruction such as "darker background", sent as an edit with the output as the source) and "Run again". The result is a new output linked to its parent, reviewed and counted against budgets like any other. A target can also be re-run with the plan unchanged.

**Generate per aspect ratio, compose per placement**
- One generation per aspect ratio in the run (a 1:1 image serves Instagram feed, Facebook feed, LinkedIn and X). The composer then produces each placement at its exact size with its own safe zone and copy limits. Cuts generation spend by three to four times.

**Budgets**
- All AI spend counts: image and video generation, and the language model's planning, copy and review calls.
- A run that was allowed to start always finishes, even if actuals pass the budget. Budgets block the start of new runs; they're a protection, not a hard cut mid-run.

**Prompts and people**
- Admins see every generation (public or private) and change the brand prompts.
- Each user can keep personal additions to each prompt type for a brand, appended after the brand's main prompt when that user runs. Admins can read them. Every generation records the brand prompt revisions and the user's addition it used, so a result can be traced.

**Viability points agreed**
- Deterministic checks are code, not AI review: pixel size, file size, copy character limits, safe zones on composed ads. Composed text is exact by construction. AI review keeps judgment: brief fit, brand look, product accuracy on generated references, technical defects, platform feel.
- Reviewers are given the brief and the output (and the Shopify image for product checks), never the generation prompt.
- A second reviewer model is a plugin setting (off by default); Gemini is needed for video anyway.
- Actual cost comes from provider usage (tokens, credits) priced by the app's price tables, reconciled against invoices monthly; per-call billing data isn't generally available.
- Data model: per-user budgets live on one table (`budgets`), not also on `users`. The `providers` table in the brief is a connections table (a plugin is code; a row is a connected account with its encrypted key), named `connections` as in the CS app.
- The request screen's "number of variants" is the run's "generate per aspect ratio" shortlist setting, not a separate field.
- Humans approve before anything leaves the app: download only, as the brief says.
- Higgsfield: its API runs on a separate prepaid balance (confirmed). Trademarks in a prompt are rejected (`ip_detected`), so the planner strips brand names from image prompts. Whether its enhancer is reachable through the API is an open question above.

## Working conventions

- Claude never commits or pushes. Lesley commits.
- Every build step ends with the app working and tested, and a log entry here.
- Settings, not constants (see `CLAUDE.md`).

## Log

### 2026-10-28 — Project started, foundation built

- Repo created with a README. Brief reviewed; `docs/brief.md`, `context.md` and `CLAUDE.md` added.
- Laravel 13 scaffolded; the foundation lifted from the CS app (see Decisions, "Foundation built"). `composer install`, `npm install`, `npm run build`.
- Databases created by Lesley; migrated. The catalogue built (see Decisions, "The catalogue"): 198 tests pass on MariaDB, Pint clean.
