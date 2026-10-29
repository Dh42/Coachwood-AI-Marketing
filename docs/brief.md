# Marketing Creative App: Brief

Oct 28, 2026 · @Lesley Paone

## Overview

A standalone app where the marketing team types what they need, picks the platforms, and gets back a reviewed shortlist of finished ads, clean images, videos and copy. It is a new project with its own codebase and database. The CS app is not touched, and no code is shared with it.

**Goals**

- One brief produces correctly sized creative for every selected platform in one run.
- Real products come straight from the Shopify stores, so packaging is never invented.
- An AI reviewer filters out weak results, so the team only sees the best ones.
- Providers (Gemini, OpenAI, Higgsfield) are plugins, so a better model can be swapped in with no code changes.
- Spending is controlled with monthly budgets for the company and for each user.

**Not in v1:** pushing assets to ad platforms, scheduling posts, and performance reporting. Assets are download only.

## Decisions

| Decision | Setting |
| --- | --- |
| Relationship to the CS app | Fully separate project. New codebase, nothing shared, CS app untouched. |
| Image providers | Gemini, OpenAI and Higgsfield as plugins, more added over time |
| Products | Pulled from the brand's Shopify store; the app asks for size and flavour when the brief is unclear |
| Product in the creative | The app decides per request from the brief and platform; the user can override |
| Output types | Finished ads with text, logo and CTA, or clean images with no text, chosen per request |
| Copy | Captions, headlines and ad copy generated alongside visuals |
| People | AI-generated people only |
| Claims | No claims filter. Marketing owns what it publishes. |
| Platforms | All mainstream North American ad and social platforms, in an editable library |
| Video | Length and format adjustable per request, with its own prompt |
| AI review | An editable review prompt, changed by hand or by describing the problem to AI |
| Prompts | Stored in the database with revisions and rollback; no git |
| Users | 5 to 6, Google sign-in; each generation public or private, switchable anytime |
| Delivery | Download only |
| Budgets | Monthly company budget plus a monthly budget per user |

## Architecture

Same stack you build on today, as a new app: PHP 8.4, Laravel, MySQL, Redis queues, Tailwind, Google sign-in restricted to the company domain.

**Three kinds of AI work**, each behind its own plugin interface so providers can be swapped per brand or per request:

| Plugin type | Job | First providers |
| --- | --- | --- |
| Language model | Reads the brief, asks clarifying questions, plans the run, writes image prompts and copy, runs the AI review | Claude |
| Image | Text-to-image, image editing, and product-reference generation | Gemini, OpenAI, Higgsfield |
| Video | Text-to-video and image-to-video | Higgsfield, Gemini |

Each plugin exposes the same calls (`generate`, `edit`, `status`, `cost_estimate`) and declares what it supports: aspect ratios, max resolution, reference-image count, video lengths. The planner only picks models that can do what a platform needs.

**Other parts:**

- **Shopify connector**, read-only, one connection per brand store: products, variants (size and flavour), images and metafields. Synced nightly and on demand.
- **Composer**: renders finished ads by placing image, text, logo and CTA on HTML layout templates, then exports PNG or JPG at exact pixel sizes.
- **Video tools**: ffmpeg on the server for trimming, resizing, adding captions and music, and pulling frames for review.
- **File storage**: DigitalOcean Spaces (S3-compatible) rather than the server disk, because video fills a disk quickly.
- **Queue workers**: every generation is a queued job. Provider calls are asynchronous; the app polls or takes webhooks, and the UI updates live.

## The brief and clarifying questions

A request starts as plain English, for example "marketing image for a sale on whey protein isolate, 20% off this weekend." Before anything is generated, the planner turns it into a structured brief and asks about anything it needs but can't work out.

**The request screen:**

- Brand (each user can be limited to some brands).
- Free-text brief.
- Platforms and placements, as checkboxes from the platform library.
- Output type: finished ad, clean image, video, or a mix.
- Copy wanted: none, captions, ad copy, or all.
- Optional: reference images, the product (if already known), the number of variants, and the image or video model to use.
- Public or private.

**Product lookup.** The planner searches that brand's synced Shopify catalogue by product title, type, tags and variant options. It uses a match only if one product and one variant fit. Otherwise it asks:

- More than one product fits → "Which product: Whey Protein Isolate or Whey Isolate Plus?" with product thumbnails.
- More than one variant fits → "Which size and flavour?" with the store's actual size and flavour options as buttons, plus "Any" when the image doesn't need a specific one.
- Nothing fits → shows the closest matches, or lets the user continue with no product.

The chosen variant's Shopify images become references for generation, and its title and price are available to copy and overlays.

**Other questions the planner may ask**, only when the answer changes the output: the offer details (percent off, dates, code), the audience, or the CTA. It asks at most three questions in one round, then shows the plan (a row per platform with size, output type, model and estimated cost) for the user to confirm.

## Platforms and specs

Platforms live in an editable library, not in code: when a platform changes a size, someone updates one row. Each placement records pixel size, aspect ratio, a safe zone (the area platform UI covers, such as the caption and buttons on Reels and TikTok), file type, max file size, video length range, and copy limits (headline and primary text characters).

Starting values below are the common specs, written from general knowledge, not checked against each platform's current docs. Verify them when seeding the library.

| Platform | Placement | Size (px) |
| --- | --- | --- |
| Instagram | Feed | 1080 × 1350 (4:5), 1080 × 1080 |
| Instagram | Stories, Reels | 1080 × 1920 (9:16) |
| Facebook | Feed | 1080 × 1350, 1080 × 1080 |
| Facebook | Stories, Reels | 1080 × 1920 |
| Meta ads | Feed, Stories, Reels | Same as above, plus primary text and headline |
| TikTok | In-feed and ads | 1080 × 1920 |
| YouTube | Shorts | 1080 × 1920 |
| YouTube | In-stream, thumbnails | 1920 × 1080, 1280 × 720 |
| Google Ads | Display and Performance Max | 1200 × 628, 1200 × 1200, 960 × 1200 |
| Pinterest | Standard pin | 1000 × 1500 (2:3) |
| LinkedIn | Feed | 1200 × 627, 1080 × 1080 |
| X | Feed | 1600 × 900, 1080 × 1080 |
| Snapchat | Ads | 1080 × 1920 |
| Amazon Ads | Sponsored Brands and Display custom images | 1200 × 628 |
| Email (Klaviyo) | Hero | 1200 × 600 |

Platforms can be grouped into presets such as "Meta full set" or "All vertical video" so the team ticks one box instead of six.

## Generation pipeline

Every request runs the same path; clean images skip the compose step, and video adds its own steps (see Video).

&#91;embedded content: generation pipeline · brief to shortlist\]

The planner keeps asking until the brief is clear, nothing is spent until the plan fits the budget, and the team only sees outputs that pass review.

Each step is a queued job, and the request page updates live as outputs arrive, are composed and get scored.

## Image and video providers

Each provider is a plugin holding its API key, its list of models, and what each model can do. Admins turn models on or off and set a default per output type. The planner can pick a model per platform, or the user can choose one on the request screen.

| Provider | Images | Video | Notes |
| --- | --- | --- | --- |
| Google Gemini | Nano Banana 2.1 (fast, cheaper), Nano Banana Pro (highest quality, best text) | Yes, through the Gemini API | Also the best option for reviewing video (see Video) |
| OpenAI | gpt-image-2: up to 16 reference images per request, strong text rendering, up to 2K | No | OpenAI shut down its Sora video API on September 24, 2026 and has announced no replacement |
| Higgsfield | Several third-party image models through one API | Several third-party video models through one API | Separate API key and prepaid balance; the existing subscription does not cover API use |
| Anthropic Claude | No image generation | No | Planning, prompts, copy and image review |

Models change every few months, so model names are data in the plugin, never hard-coded. When a provider adds a model, an Admin adds a row.

**Picking a model.** Each model in the plugin records: supported aspect ratios, max resolution, reference-image limit, whether it renders text well, typical cost per output, and for video, clip length range and whether it does image-to-video. The planner uses these to choose: for example, a model with strong reference-image support when a product shot is involved.

**Comparing providers.** A request can be sent to two or three image models at once. The AI review then ranks all results together, which also builds a record of which model wins for which kind of work.

## Finished ads, clean images and copy

Every request picks an output type. Both are always available.

**Clean image.** The generated image only, no text or logo. For the team to finish elsewhere, or for organic posts.

**Finished ad**, built in one of two ways, chosen per request (default: composed):

- **Composed.** The app generates a clean image, then the composer places the headline, offer, logo and CTA on top using a layout template. Text is exact, fonts are the brand's, and the same layout adapts to every size. Best for prices, percentages and dates.
- **Generated.** The image model renders the text inside the image. Looks more natural for stylized or editorial work, but the review checks every word, since even the newest models still make occasional spelling mistakes.

**Layout templates.** Stored per brand, editable in the app: where the image, headline, sub-line, logo, CTA button and price badge sit for each aspect ratio, plus safe zones. The marketing team can duplicate and adjust them. Templates are HTML and CSS rendered to PNG, so they support any web font and exact pixel sizes.

**Product shots.** When the brief needs the product, the variant's Shopify images go to the model as references. If the model's output doesn't hold the packaging accurately, the fallback is compositing: the real pack shot, background removed, is placed into the generated scene. The review compares the result against the Shopify image and rejects any version where the label is altered.

**Copy.** For each platform, written to that platform's limits from the platform library: primary text, headline, description, caption and hashtags where relevant. Several versions per placement, so the team can test. Copy follows the brand's voice from the brand kit and has no claims filter.

## Video

Video has its own editable prompt and its own length setting per request, defaulting from the platform library.

**How long video ads usually run.** Guidance varies by source, so these are starting defaults, adjustable per platform:

| Placement | Typical length | Default |
| --- | --- | --- |
| TikTok in-feed ad | 9 to 15 s is the common advice; TikTok's own ad guide says 21 to 34 s performs best | 15 s |
| Instagram and Facebook Reels ad | 15 to 30 s | 15 s |
| Instagram and Facebook Stories ad | 5 to 15 s | 10 s |
| Instagram feed video ad | 10 to 15 s, up to 30 s | 15 s |
| YouTube Shorts | Same as Reels | 15 s |

Whatever the length, the first 1 to 2 seconds carry the hook, so the video prompt puts the product or offer up front.

**How video is made.** Video models generate short clips per run, so a 15 to 30 second ad is usually several clips joined together:

1. The planner writes a short shot list (hook, product moment, offer, end card).
2. Each shot is generated, either from an approved still (image-to-video, which keeps product and look consistent) or from text.
3. ffmpeg joins the clips, adds captions, a music track from a library the team uploads, and an end card with logo and CTA rendered from the brand's layout template.
4. Exports go out at each platform's size and length.

**Reviewing video (your question).** Claude cannot take video as input. Google Gemini can: it takes the video file directly and reads both the picture and the soundtrack. By default it samples 1 frame per second, which can miss detail in fast motion; the sample rate can be raised for short ads. So video review runs as two passes:

- **Gemini** watches the full clip: pacing, whether the hook lands in the first seconds, motion glitches, audio, and whether the story matches the brief.
- **Claude** checks frames the app pulls with ffmpeg at a higher rate: brand look, packaging accuracy against the Shopify image, on-screen text spelling, and the end card.

Both passes use the same editable review prompt (see AI review), and their scores are combined. The video reviewer is a plugin setting like any other provider.

## AI review

Every output is scored before anyone sees it. The team gets a shortlist; everything else stays in a "rejected" tab with the reason, in case the reviewer was wrong.

**The review prompt.** The system builds a starting review prompt per brand from the brand kit, with separate sections for images, finished ads, video and copy. Each section lists criteria and a weight, for example:

- Matches the brief and the offer.
- Brand look: colours, mood, people, settings.
- Product accuracy: the label and packaging match the Shopify image exactly.
- Text: every word spelled right, nothing cut off, nothing inside platform safe zones.
- Technical: no distorted hands or faces, no artifacts, correct size.
- Platform fit: reads well at phone size, hook visible early (video).

Each output gets a score per criterion, an overall score, and a one-line reason. Some criteria can be set as hard fails (a misspelled price, an altered label), which reject an output whatever its other scores.

**Changing the review prompt, two ways:**

1. **Edit it directly** in a prompt editor.
2. **Describe the problem to AI.** For example: "it keeps passing images where the shaker is the wrong colour" or "it rejects good lifestyle shots because the product is small." The AI drafts an edited prompt and shows a side-by-side diff; the user accepts, adjusts, or discards it.

The same two ways apply to every prompt in the app (planner, image, video, copy), not just the review.

**Shortlist settings** (per request, with brand defaults): how many to generate per platform, how many to deliver, and a minimum score. Example: generate 8, deliver the best 3, nothing under 70.

**Learning from the team.** Users can thumbs-up or thumbs-down any delivered or rejected output, with an optional note. The weekly summary shows where the reviewer and the team disagreed, and the "describe the problem" flow can use those disagreements to suggest prompt changes.

## Brand kits

Each brand gets a brand kit page in the app that the marketing team fills in. This is the list to give them; items marked required are needed before the brand can generate.

| Item | What to provide | Required |
| --- | --- | --- |
| Logos | Full logo, icon-only mark, light and dark versions; SVG or transparent PNG | Yes |
| Colours | Primary, secondary and accent colours as hex codes, with which to use for text and backgrounds | Yes |
| Fonts | Font files (or Google Fonts names) for headlines and body, with licences that allow use in ads | Yes |
| Voice | Three to five sentences on tone, plus words and phrases to use and avoid | Yes |
| Visual direction | Mood, lighting, settings, types of people and activities, what to avoid | Yes |
| Style references | 10 to 20 images that look right: past campaigns, photography, competitors' work you admire | Yes |
| Best-performing ads | Past ads that worked, with a note on why | Recommended |
| Bad examples | Images that are off-brand, with a note on why | Recommended |
| CTA list | Approved calls to action ("Shop now", "Get 20% off") | Recommended |
| Legal lines | Standard fine print, trademark symbols, disclaimers to add when relevant | Optional |
| Music | Licensed tracks the brand can use in video | For video |
| Pack shots | Only where Shopify's product images aren't clean enough (transparent background, front-facing) | Optional |

From these, the app builds the brand's starting prompts (planner, image, video, copy, review), which the team can then edit.

## Prompts and revisions

All prompts live in the database. There is no git.

**Prompt types per brand:** planner, image, video, copy, and review (with its image, finished-ad, video and copy sections). Each platform in the library can also add a short platform-specific addition, for example "TikTok: native, unpolished feel."

**Revisions.**

- Every save creates a new revision with author, time, an optional note, and how it was made (hand edit or AI-assisted, with the plain-English request that produced it).
- A history view shows each revision with a diff against the one before.
- **Roll back** makes an old revision the current one, as a new revision, so nothing is lost.
- Every generation records which prompt revisions it used, so a bad batch can be traced to the change that caused it.

**Test before saving.** The prompt editor has a "try it" button that runs the edited prompt on a sample brief without saving, using a small test budget.

## Users, visibility and budgets

**Users.** 5 to 6 people at launch, invited by email, signing in with Google restricted to the company domain.

| Role | Can do |
| --- | --- |
| Admin | Everything: providers and API keys, budgets, users, brands, platform library |
| Creator | Generate, edit prompts and brand kits, download, set own work public or private |
| Viewer | Browse public work and download it |

Users can be limited to certain brands.

**Public or private.** Every request is public (whole team sees it in the shared gallery) or private (only its creator and Admins). The creator can switch it at any time, before or after generating. Admins see everything, because budgets and audit need it.

**Budgets.**

- **Company monthly budget** in Admin settings, with an alert at a set percentage (for example 80%) and a hard stop at 100%.
- **Per-user monthly budget**, set by Admins. When a user hits theirs, they are stopped and an Admin can raise it.
- **Cost before you run.** The plan screen shows the estimated cost of the run and what remains in the user's and the company's budget. Runs that would go over are blocked before anything is spent.
- **Actual cost** is logged per output from each provider's billing data or price table, so estimates can be checked against real spend.
- A spend page shows totals by user, brand, provider and output type, for the month and over time.

## Data model

MySQL tables; files in object storage, referenced by path.

| Table | Holds |
| --- | --- |
| `users`, `user_brand_access` | Users, roles, brand limits, monthly budget |
| `brands` | Brand name, Shopify connection, defaults (models, shortlist settings) |
| `brand_kit_items` | Logos, colours, fonts, voice, references, music, legal lines |
| `shopify_products`, `shopify_variants`, `shopify_images` | Nightly synced catalogue: titles, options (size, flavour), prices, image URLs |
| `platforms`, `placements` | The platform library: sizes, safe zones, formats, length ranges, copy limits |
| `layout_templates` | Composer templates per brand and aspect ratio |
| `prompts`, `prompt_revisions` | Every prompt and every revision, with author, method and diff |
| `providers`, `provider_models` | Plugins, keys (encrypted), model capabilities and price tables |
| `requests` | The brief, structured plan, clarifying Q&A, visibility, creator, status |
| `request_targets` | One row per platform placement in a request |
| `generations` | Each provider call: model, prompt revision used, inputs, status, cost |
| `outputs` | Each image, video, finished ad or copy variant: file, size, score, shortlisted or rejected |
| `reviews` | Per-criterion scores, hard fails and reasons, from each reviewer pass |
| `feedback` | Team thumbs up or down with notes |
| `spend_ledger` | Estimated and actual cost per output, by user, brand and provider |
| `budgets` | Company and per-user monthly limits and alert thresholds |

## Build phases

Images first, because that is where most requests will be and it proves the brief, review and budget flow. Video comes after, on the same flow.

1. **Phase 1: images and copy.**
   - Users, roles, Google sign-in, brands, brand kits.
   - Shopify catalogue sync and the clarifying-question flow.
   - Platform library seeded with the main placements.
   - Provider plugins for Gemini, OpenAI and Higgsfield images.
   - Clean images, composed finished ads with layout templates, and copy.
   - AI review with shortlist, both ways of editing prompts, revisions and rollback.
   - Public and private, company and per-user budgets, download.
2. **Phase 2: product accuracy and comparison.**
   - Pack-shot compositing fallback and label-accuracy check.
   - Generated-text finished ads.
   - Multi-model runs ranked together.
   - Team feedback and the weekly reviewer-vs-team summary.
3. **Phase 3: video.**
   - Video provider plugins, shot lists, image-to-video from approved stills.
   - ffmpeg assembly with captions, music library and end cards.
   - Two-pass video review (Gemini plus Claude on frames).

## Open questions

- [ ] App name and subdomain.
- [ ] Hosting: same server as the CS app, or its own? Video assembly with ffmpeg is CPU-heavy, which argues for its own.
- [ ] Which brands at launch?
- [ ] Shopify access: a new read-only custom app per store (products only), so this app shares no token with the CS app.
- [ ] API accounts and billing owner for Google Gemini, OpenAI, Higgsfield (API balance) and Anthropic.
- [ ] Starting company monthly budget and default per-user budget.
- [ ] Where licensed music for video comes from.
- [ ] Who is responsible for each platform's rules on labelling AI-generated ads?

## Sources

- [Gemini API: video understanding](https://ai.google.dev/gemini-api/docs/video-understanding.md.txt)
- [Gemini Nano Banana 2.1 model page](https://ai.google.dev/gemini-api/docs/models/gemini-nano-banana-2.1)
- [OpenAI video generation guide (Sora deprecation notice)](https://developers.openai.com/docs/guides/video-generation)
- [What is GPT Image 2 (MindStudio)](https://mindstudio.ai/blog/what-is-gpt-image-2)
- [Higgsfield API vs plan (help centre)](https://higgsfield.ai/creator-hub/help-center/integrations/what-is-the-higgsfield-api)
- [Social video length by platform (Hootsuite)](https://blog.hootsuite.com/how-long-should-a-social-media-video-be/)
- [Video length benchmark citing TikTok's ad guide (inBeat)](https://inbeat.agency/blog/ugc-video-length-benchmark)
