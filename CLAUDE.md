# Vaani — Project Spec

> My universal AI rules live in the **global `~/.claude/CLAUDE.md`** and apply to all work
> automatically. This file is the **Vaani-specific** spec only — it does not repeat the
> universal rules. Where both touch the same topic, the global rules set the baseline and
> this spec adds detail; they are written not to conflict.

Project spec and working context for building **Vaani**, a WordPress plugin that uses
Sarvam AI to translate posts/pages and generate per-language audio for Indian-language
blogs. Keep this file open in Claude Code as the source of truth. Update the "Progress"
checklist as phases land.

Plugin display name: **Vaani – AI Translation & Audio for Indian Blogs**
("Vaani" / वाणी = "voice, speech" — chosen because it spans both the text-translation
and audio features.)

---

## 0. WORKING RULE FOR CLAUDE CODE — read first

**Build ONE phase at a time, then STOP.** Do not start the next phase.

After completing a phase:
1. Implement only the tasks listed for that phase. Do not pull work forward from later phases.
2. Summarize exactly what changed (files added/modified) and how to test it manually.
3. **Stop and wait.** The author will review, test, and commit to the repo themselves.
4. Do not run `git commit` or `git push` — the author handles version control.
5. The next phase will be run in a **separate chat session** using its own prompt (see the
   "Per-phase build prompts" section, kept in `PHASE_PROMPTS.md`). Treat each phase as
   self-contained; this `CLAUDE.md` is the shared context across sessions.

If you finish early, suggest improvements but do not implement them without approval.

---

## 1. What we're building

### Product model (the mental model — read this first)

Vaani adds **per-post, reader-facing translations to an English-primary site**. The original
English post is the canonical content; translations are an **opt-in convenience for individual
posts/pages**. The site itself stays English — its navigation, archives, categories, front
page, and default experience are all English. A reader lands on the English site and chooses
to read *a specific post* in, say, Tamil.

This is **not** a locale-first multilingual site (the WPML/Polylang model), where the whole
site exists in parallel language versions with translated menus, archives, and a language that
"sticks" across the entire session. That distinction is load-bearing: it's *why* per-post
admin-triggered translation, the private CPT, and the no-taxonomy/menu/string-translation scope
are all correct rather than incomplete.

**Language selection is per-post, NOT sticky (v1 decision):** when a reader switches post #482
to Tamil, they see *that post* in Tamil and return to English everywhere else. There is no
cookie/preference that auto-serves Tamil across the site. (A sticky/preferred-language mode is
a possible later enhancement — explicitly out of scope for v1.)

A **deliberately simple** WordPress plugin that lets a site admin:

1. Translate **post and page content** into admin-selected languages using Sarvam AI.
2. Edit the AI translations as **native Gutenberg blocks** (not a custom editor).
3. Generate **audio (TTS)** of a post/page per language, stored in the media library.
4. Let readers view translations at clean **path-prefixed URLs** (`/hi/about/`).
5. See **estimated Sarvam usage/cost** inside the WP admin.

### Positioning / scope guardrails (read before adding anything)

This is **not** a full multilingual solution (not WPML/Polylang). The wedge is
*simplicity for small blogs that manage content in the block editor*. Resist scope creep.

**In scope (v1):**
- `post` and `page` post types only.
- Block-editor (`post_content`) content only.
- Yoast SEO meta translation **if Yoast is active** (optional, guarded).
- `hreflang` alternate links for search-engine discovery.

**Explicitly OUT of scope (v1) — do not build, do not "helpfully" add:**
- Custom fields / ACF / page-builder (Elementor, Divi, etc.) content.
- WooCommerce product data.
- Taxonomy, menu, widget, or theme-string translation.
- Auto-translation on publish (admin always triggers manually in v1).
- On-the-fly reader-requested translation (everything is pre-generated + cached).
- Sticky/preferred-language sessions — language selection is per-post only (see Product model).

---

## 2. Locked architecture decisions

These were debated and decided. Do not silently revisit them.

| Concern | Decision |
|---|---|
| Translation content storage | Private CPT `vaani_translation`, **one post per (source post × language)**. Real `post_content` so it edits as native blocks + gets revisions for free. |
| Original ↔ translation link | Meta on the translation: `_vaani_source_id`, `_vaani_lang`, `_vaani_source_hash`, `_vaani_status`. |
| Front-end access | **Pre-generated** by admin, served from cache. Reader never triggers an API call. |
| Public URLs | CPT is **private** (not front-end queryable directly). Translations are *rendered* at **path-prefixed URLs** on the original's permalink: `/<lang>/<original-slug>/`. Needs rewrite rules; flush on activate/deactivate. |
| Staleness detection | Store `_vaani_source_hash` = hash of source `post_content` at translation time. On source edit, hash mismatch → mark translation stale + show badge. |
| Audio storage | Media library file, referenced by **post meta on the ORIGINAL post** (no custom table). |
| Audio filename | `{post_type}-{source_post_id}-{lang}.mp3` (e.g. `post-482-hi.mp3`, `page-15-ta.mp3`). **Overwrite-in-place** on regeneration. |
| SEO | Emit `hreflang` alternates pointing at the path-prefixed URLs. If Yoast active, translate its title/meta-desc/OG fields and cooperate with (don't duplicate) Yoast's tags. |
| Usage/billing | Log every API call locally (`wp_vaani_usage` table) with estimated cost. If Sarvam exposes a usage API, show that too; otherwise local log is source of truth. |
| Editor target | Block editor (Gutenberg). Classic Editor still works (it's just `post_content`) but is not a design target. FSE/site-editor not targeted in v1. |

### Why private-CPT + path-prefix URL (the one subtle bit)
`hreflang` needs a crawlable URL per language. The CPT itself stays private (clean admin,
no duplicate-content mess), but a rewrite rule maps `/<lang>/<slug>/` to render the
matching translation's content under the original's public permalink. Storage is private;
*rendering* is public.

### Why NOT same-post-type + `post_parent` (rejected alternative)
Storing translations as real `post`/`page` entries linked by `post_parent` was considered and
**rejected**. Reasons: (1) `post_parent` already has meaning for **pages** (hierarchy + URLs),
so reusing it collides with the user's real page tree — and pages are in v1 scope; (2) real
posts leak into the main loop, archives, search, RSS, REST, sitemaps, counts, and widgets
**by default**, forcing unbounded, never-finished exclusion code; (3) duplicate-content/canonical
risk; (4) polluted post counts. The private CPT inverts all of this: **hidden by default, opt-in
visibility.** Link translations via `_vaani_source_id` + `_vaani_lang` meta — **never via
`post_parent`**, even on the CPT, so the relationship never competes with built-in semantics.

### Future-readiness seams (build these in from the start — cheap now, migration later)
Low-cost decisions that prevent expensive refactors. Numbered for reference.

1. **Uniqueness invariant: exactly one translation per `(source_id, lang)`.** Enforce on
   create (check-before-create + guard in the queued job) so races/double-clicks can't create
   duplicates. Cleaning up duplicate translation posts later is a painful migration.
2. **One canonical language registry.** Define a single config mapping `code → label →
   Sarvam API param → hreflang value`. Every subsystem (meta, filenames, URL routes, hreflang,
   API calls) reads from it. Prevents the most likely refactor: language-code drift
   (`hi` vs `hi-IN` vs `hi_IN`).
3. **Keep a translated-slug field on the CPT even though v1 doesn't use it.** v1 mirrors the
   original slug (`/hi/about/`). Storing (but not yet exposing) a per-translation slug means
   supporting translated slugs (`/hi/parichay/`) later is additive, not a data migration. Also:
   decide the `/{lang}/` prefix conflict strategy now (reserved prefix; guard against an existing
   top-level page named like a lang code) since URLs get indexed and are costly to change.
4. **Usage table grows unbounded — add the seam now, the job later.** Index `wp_vaani_usage`
   on `source_id` and `created_at` from day one so a future prune/rollup job is additive, not a
   schema change on a large table. No retention logic needed in v1.
5. **Audio staleness must hash the right source.** Audio is generated from the **translation's**
   text, so its staleness hash must track the **translation's** `post_content`, not the original's.
   Otherwise editing a translation won't flag its audio as stale. (Design note for Phase 4.)
6. **Thin SEO adapter, not direct Yoast keys.** Route all SEO meta reads/writes through one
   `Vaani\Seo\YoastAdapter` rather than sprinkling `_yoast_wpseo_*` keys across the codebase. Adding
   RankMath/SEOPress later becomes one new adapter instead of a hunt-and-replace. v1 ships only
   the Yoast adapter.

---

## 3. Project structure

**Organizing principle: feature-first, with a shared core.** Each feature is a folder under
`src/`; genuinely cross-cutting code lives in `src/Core/`. Adding a feature later (e.g.
WooCommerce) means adding a new folder, not editing files scattered across layers.

Two rules that keep it coherent across separate Claude Code sessions:
- **Test for `Core/`:** would two different features both use it? Yes → `Core/` (Sarvam client,
  language registry, settings, queue). No → it lives inside its one feature (the Yoast adapter
  is SEO-only, so it's in `Seo/`, not `Core/`).
- **Data access goes through a per-feature `*Repository`.** Callers never touch `WP_Query` or
  `$wpdb` directly. This is the seam that lets storage change later without touching call sites.

```
vaani/
├── vaani.php                  # main file: header, constants, bootstrap (require vendor + dist)
├── composer.json              # PSR-4: Vaani\ -> src/
├── package.json               # 10up-toolkit build config (entry points)
├── vendor/                    # COMMITTED — Composer autoload + Action Scheduler
├── dist/                      # COMMITTED — compiled JS/CSS from 10up-toolkit (see section 5)
├── uninstall.php              # cleanup on plugin delete
├── README.md
│
├── src/                       # all PHP, namespace Vaani\
│   ├── Plugin.php             # orchestrator: wires features, registers hooks
│   ├── Activator.php          # activation: flush rewrites, create tables
│   ├── Deactivator.php        # deactivation: flush rewrites
│   │
│   ├── Core/                  # SHARED cross-cutting — used by 2+ features
│   │   ├── Sarvam/Client.php          # HTTP wrapper (Vaani\Core\Sarvam\Client)
│   │   ├── Sarvam/Response.php         # normalized response value object
│   │   ├── Language/Registry.php       # seam #2: single source of truth for langs
│   │   ├── Language/Language.php        # value object: code,label,sarvam param,hreflang
│   │   ├── Settings.php                # settings storage/accessors
│   │   ├── Queue.php                   # Action Scheduler wrapper
│   │   └── Hash.php                    # staleness hashing helper
│   │
│   ├── Translation/           # FEATURE
│   │   ├── TranslationPostType.php     # registers private CPT vaani_translation
│   │   ├── BlockTranslator.php         # parse_blocks -> translate -> reserialize
│   │   ├── TranslationService.php      # create/update, hash, uniqueness (seam #1)
│   │   ├── TranslationRepository.php   # all queries (find by source+lang)
│   │   └── Admin/TranslationMetaBox.php, Admin/TranslationColumns.php
│   │
│   ├── Audio/                 # FEATURE
│   │   ├── AudioService.php            # generate, save to media library, hash
│   │   ├── AudioRepository.php         # read/write _vaani_audio meta on original
│   │   └── Admin/AudioMetaBox.php
│   │
│   ├── Frontend/              # FEATURE: reader-facing
│   │   ├── Router.php                  # /<lang>/<slug>/ rewrite rules + query var
│   │   ├── ContentRenderer.php         # swap translation content onto the URL
│   │   ├── LanguageSwitcher.php        # block + widget + the_content filter
│   │   └── AudioPlayer.php
│   │
│   ├── Seo/                   # FEATURE
│   │   ├── Hreflang.php                # alternate links
│   │   └── YoastAdapter.php            # seam #6: only place that knows Yoast keys
│   │
│   ├── Usage/                 # FEATURE
│   │   ├── UsageLogger.php             # write row on every API call
│   │   ├── UsageRepository.php         # queries + indexes (seam #4)
│   │   ├── UsageTable.php              # dbDelta schema
│   │   └── Admin/UsageDashboardWidget.php
│   │
│   └── Admin/                 # shared admin scaffolding (not feature-specific)
│       ├── SettingsPage.php
│       └── Notices.php
│
├── assets/                    # SOURCE js/css/blocks (10up-toolkit input)
│   ├── js/   ├── css/   └── blocks/   # block editor panels, switcher block (block.json)
│
├── templates/                 # overridable PHP view partials (switcher, player)
└── languages/                 # .pot / .po / .mo
```

The folders are a map, not a mandate — early phases create only a handful of these files.
Their value is that each fresh Claude Code session places new files in the right home, so the
codebase stays coherent even though phases are built in separate sessions.

---

## 4. Tech conventions

> My global `~/.claude/CLAUDE.md` governs security (sanitize/escape/`$wpdb->prepare()`/
> caps+nonces), hook registration in dedicated methods, DI over singletons, and React
> conventions. The items below are **Vaani-specific** and do not repeat those rules.
> **Project override:** the global baseline is PHP 8.2+, but **Vaani targets PHP 8.0 minimum**
> (wider host compatibility for small blogs) — use only 8.0-compatible syntax.

- **PHP 8.0+ minimum**, **WordPress 6.4+**, current block editor.
- **Plugin naming (use consistently):** PHP **namespace `Vaani\`** for all classes (PSR-4,
  `src/`), e.g. `Vaani\Core\Sarvam\Client`, `Vaani\Seo\YoastAdapter`; function/hook/option prefix `vaani_`; text domain
  `vaani` (slug-matched); meta keys `_vaani_*`; CPT `vaani_translation`; table `wp_vaani_usage`.
  "Sarvam" appears only when referring to the external AI service/API, never as the plugin's
  own prefix.
- Settings via the **Settings API**. API key in options (never hardcoded, never in VCS).
- Capabilities: `edit_posts` for triggering translation/audio, `manage_options` for settings
  (in addition to the universal caps+nonce requirement on every admin/REST/AJAX action).
- All Sarvam HTTP via a single `Vaani\Core\Sarvam\Client` class using `wp_remote_post()` with
  centralized auth, timeout, retry/backoff, and error normalization.
- Long-running work (translation/TTS) runs in the **background** — use Action Scheduler
  (bundle it) or WP-Cron. **Never** block an admin request on a multi-second API call.
  (Consistent with the universal "avoid writes during front-end requests" rule.)
- **Composer PSR-4 autoloading** (`Vaani\` → `src/`). Bundle/commit `vendor/` so the
  GitHub zip needs no `composer install`. Run `composer dump-autoload -o` after adding classes.
- No PHP build step beyond Composer. Front-end/editor JS+CSS build via 10up-toolkit (section 5).

### Sarvam API notes (verify against docs.sarvam.ai before coding Phase 2/4)
- Endpoints assumed: chat/translation, text-to-speech, speech-to-text (STT unused in v1).
- Pricing is per-character (translation/TTS) and per-token (LLM), billed in INR, free
  credits on signup. Confirm current endpoint paths, model names, and supported language
  codes at build time — they change. Centralize the supported-language list in one config array.

---

## 5. Asset build (10up-toolkit)

Front-end/editor JS and CSS are built with **10up-toolkit** (Webpack 5 wrapper). Only needed
when front-end styling or custom/block JS changes — pure-PHP phases don't touch it.

- **Install:** `npm install --save-dev 10up-toolkit`. Run in **project mode** (define the
  `10up-toolkit.entry` object in `package.json`) so multiple entry points + core-js polyfills
  work.
- **Source → output:** source lives in `assets/` (and `assets/blocks/`); compiled output goes
  to `dist/` (`dist/js`, `dist/css`, `dist/blocks`). Enqueue from `dist/`, never from `assets/`.
- **Block assets are automatic:** `useBlockAssets` is on by default — toolkit scans for
  `block.json` files and builds their `script`/`editorScript`/`viewScript`/`style`/`editorStyle`
  entries, moving `block.json` + PHP into `dist/blocks/`. So the language panel and the
  switcher block just need a `block.json`; don't hand-wire their entry points.
- **Scripts (`package.json`):** `"build": "10up-toolkit build"`, `"start": "10up-toolkit
  build --watch"`, plus `lint-js` / `format-js` / `lint-style`.
- **Enqueue with the generated `*.asset.php`** files (dependencies + version hash) rather than
  hardcoding `wp_enqueue_*` deps/versions.
- **COMMIT `dist/` to the repo.** Distribution is a GitHub zip; users won't run `npm run build`
  any more than `composer install`. Both `vendor/` (PHP) and `dist/` (JS/CSS) must be present
  in the zip. Rebuild and commit `dist/` whenever `assets/` changes.
- Don't over-adopt: no need for HMR/fast-refresh, Husky, or TypeScript in v1. Add later if the
  JS surface grows. (`--hot`, lint-staged, etc. are documented but optional.)

---

## 6. Phased build plan

Each phase is independently shippable and testable. Stop after any phase and you have
something that works. Effort bands are focused hours. **A genuine v1 ships after Phase 3**;
audio + billing are v1.1 / v1.2.

### Phase 0 — Scaffold & settings  (~3–5 hrs)
- Plugin header, activation/deactivation hooks, **Composer PSR-4 autoloading**
  (`composer.json` maps `Vaani\` → `src/`; load `vendor/autoload.php` from the main file).
  **Commit `vendor/`** so the GitHub zip works without `composer install`.
- Settings page (Settings API): API key, default source language, post types toggle
  (posts on by default, pages opt-in).
- `Vaani\Core\Sarvam\Client` class skeleton + `test_connection()` method.
- "Test connection" button on settings; admin notice if no key set.
- **Done when:** key saves and Test Connection confirms the API responds.

### Phase 1 — Language configuration  (~2–4 hrs)
- Global enabled-target-languages multi-select (from central language config array).
- Per-post/page editor sidebar panel (block editor `PluginDocumentSettingPanel`) listing
  enabled languages with checkboxes: "translate into ___". Store selections in meta.
- **Done when:** admin enables e.g. Hindi+Tamil globally and ticks which apply per item.

### Phase 2 — Translation engine + storage  (~6–10 hrs) — CORE
- Build the **canonical language registry** first (seam #2): one config mapping
  `code → label → Sarvam param → hreflang value`. All later subsystems read from it.
- Register private `vaani_translation` CPT (`public=false`, `show_ui=true`, excluded
  from search/feeds/sitemaps). Include a (currently unused) translated-slug field (seam #3).
- `Vaani\Core\Sarvam\Client::translate( $blocks, $target_lang )`: **parse `post_content` with
  `parse_blocks()`, translate text within each block, reserialize.** Skip non-translatable
  blocks (code, embed, shortcode, html); handle translatable attributes (image `alt`,
  button text, headings).
- On admin "Translate now" (queued via Action Scheduler): create/update the linked
  translation post; set `_vaani_source_id`, `_vaani_lang`, `_vaani_source_hash`,
  `_vaani_status`. **Enforce one translation per `(source_id, lang)`** — check-before-create
  + guard in the job (seam #1). Link via meta only, **never `post_parent`**.
- Admin column / meta box on original showing translation status per language + link to
  edit each; stale badge when source hash differs.
- Log the call to usage table (forward-declare the table here or in Phase 5).
- **Done when:** "Translate to Hindi" creates a linked Hindi post editable as normal blocks.

### Phase 3 — Front-end rendering, URLs & SEO  (~5–8 hrs) — completes v1
- Rewrite rules for `/<lang>/<original-slug>/`; flush on activate/deactivate. Guard the
  `/<lang>/` prefix against collisions with an existing top-level page (seam #3).
- Render the matching translation's `post_content` under the original's public URL.
  Fallback to original if no published, non-stale translation.
- Language switcher (block + widget + optional `the_content` filter) showing only
  languages with a published, non-stale translation. **Per-post only — no sticky/cookie
  language preference** (see Product model): switching applies to the current post, the
  reader returns to English elsewhere.
- `hreflang` alternate `<link>` tags in `<head>` for all available languages + `x-default`,
  values from the language registry (seam #2).
- **Done when:** visiting `/hi/about/` shows the Hindi translation; switcher works;
  hreflang validates; original untouched.

### Phase 4 — Audio generation (TTS)  (~5–8 hrs) — v1.1
- `Vaani\Core\Sarvam\Client::text_to_speech( $text, $lang )`.
- Save to media library as `{post_type}-{source_post_id}-{lang}.mp3` (e.g. `post-482-hi.mp3`),
  overwrite-in-place on regen.
- Reference via post meta on the **original** post: `_vaani_audio` (array keyed by lang
  → attachment ID) + per-lang `_vaani_audio_hash` for staleness. **Hash the translation's
  `post_content`, not the original's** (seam #5) — audio is generated from the translation.
- Admin "Generate audio (lang)" trigger (queued). Front-end audio player per language.
- **Done when:** admin generates Hindi audio; reader sees a working "Listen" player.

### Phase 5 — Usage & billing dashboard  (~4–7 hrs) — v1.2
- `wp_vaani_usage` table: operation, lang, source_id, char/token count, est_cost,
  created_at. **Index `source_id` and `created_at`** so a future prune/rollup job is
  additive (seam #4). Log on every API call (translation + TTS).
- **Research whether Sarvam exposes a usage/credits API**; if yes, fetch + display
  remaining credits. Local log is primary source of truth regardless.
- Admin dashboard widget: this month's translations, audio count, estimated INR spend.
- **Done when:** admin sees usage + rough cost without leaving WordPress.

### Phase 6 — Polish & hardening  (~4–8 hrs, ongoing)
- Bulk action "translate selected"; retry/backoff already in client — surface failures in UI.
- Cleanup on source delete/trash: remove linked translations + audio attachments.
- Yoast via a thin `Vaani\Seo\YoastAdapter` (seam #6), not direct key access: translate
  `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, OG/Twitter fields when Yoast active;
  store on translation; inject on render; avoid duplicate tags. (RankMath/SEOPress = future adapters.)
- Caps/nonces audit, transient caching of rendered translations, full i18n pass.

---

## 7. Data model summary

**CPT `vaani_translation`** (private) — content lives in `post_content`.
Meta: `_vaani_source_id`, `_vaani_lang`, `_vaani_source_hash`, `_vaani_status`,
(+ translated Yoast fields when applicable).

**Original post meta:** `_vaani_target_langs` (selected langs), `_vaani_audio`
(lang→attachment_id), `_vaani_audio_hash` (lang→hash).

**Table `wp_vaani_usage`:** `id, operation, lang, source_id, units, unit_type,
est_cost_inr, created_at`.

---

## 8. Progress

- [ ] Phase 0 — scaffold & settings
- [ ] Phase 1 — language config
- [ ] Phase 2 — translation engine + storage
- [ ] Phase 3 — front-end URLs + SEO  ← **v1 ships here**
- [ ] Phase 4 — audio (TTS)
- [ ] Phase 5 — usage/billing dashboard
- [ ] Phase 6 — polish & hardening

---

## 9. Open items to confirm at build time
- Sarvam current endpoint paths, model names, supported language codes (docs.sarvam.ai).
- Whether a Sarvam usage/credits API exists (affects Phase 5 only — log locally regardless).
- Action Scheduler bundling vs plain WP-Cron (lean Action Scheduler for reliability).
