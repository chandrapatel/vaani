# PHASE_PROMPTS — Vaani

Copy-paste prompts for building Vaani one phase at a time. Start each phase in a
**new Claude Code session** with `CLAUDE.md` present in the project, then paste the
matching prompt below. Each phase ends by stopping for your review — see the working
rule in `CLAUDE.md` section 0.

---


Start each phase in a **new Claude Code session** with `CLAUDE.md` in the project.
Copy-paste the matching prompt below. Each ends with a stop instruction.

### Prompt — Phase 0
```
Read CLAUDE.md. Build ONLY Phase 0 (Scaffold & settings).

Deliver:
- Plugin main file with header and activation/deactivation hooks.
- Scaffold the folder structure from CLAUDE.md "Project structure" (src/ with Core/ and feature
  folders, assets/, dist/, templates/, languages/). Create only the dirs/files this phase needs;
  the rest is a map for later phases.
- Composer PSR-4 autoloading: a composer.json mapping namespace Vaani\ to src/, with the
  main file requiring vendor/autoload.php. Run composer dump-autoload -o and COMMIT the
  vendor/ directory so the GitHub zip works without composer install.
- Set up the 10up-toolkit build: npm install --save-dev 10up-toolkit, a package.json in project
  mode with an entry object and build/start/lint scripts (see CLAUDE.md "Asset build"). No
  front-end assets to build yet — just establish the pipeline so later phases use it. Commit dist/.
- Settings page via the Settings API: Sarvam API key, default source language,
  post-types toggle (posts on by default, pages opt-in).
- A Sarvam client skeleton at src/Core/Sarvam/Client.php (Vaani\Core\Sarvam\Client) with a
  test_connection() method.
- "Test connection" button on the settings page; admin notice when no key is set.
- Follow all conventions in CLAUDE.md "Tech conventions" (prefixes, nonces, capabilities, text domain).

Do NOT build language config, CPT, translation, audio, or anything from later phases.
When done: list every file changed and give me step-by-step manual test instructions,
then STOP and wait for my review. Do not commit or push.
```

### Prompt — Phase 1
```
Read CLAUDE.md. Phase 0 is complete and committed. Build ONLY Phase 1 (Language config).

Deliver:
- A central supported-languages config array (code → label).
- Global enabled-target-languages multi-select on the settings page.
- A block-editor sidebar panel (PluginDocumentSettingPanel) on posts AND pages listing
  enabled languages as checkboxes ("translate into ___"), saved to post meta
  (_vaani_target_langs).

Do NOT build the CPT, translation engine, front-end, audio, or billing.
When done: list every file changed and give me manual test instructions, then STOP and
wait for my review. Do not commit or push.
```

### Prompt — Phase 2
```
Read CLAUDE.md. Phases 0–1 are complete and committed. Build ONLY Phase 2
(Translation engine + storage) — this is the core phase.

Deliver:
- Build the canonical language registry first (seam #2): one config mapping
  code → label → Sarvam param → hreflang value. All later code reads from it.
- Register private CPT vaani_translation (public=false, show_ui=true; excluded from
  search/feeds/sitemaps). Include a currently-unused translated-slug field (seam #3).
- Vaani\Core\Sarvam\Client::translate($blocks, $target_lang): parse post_content with parse_blocks(),
  translate text per block, reserialize. Skip non-translatable blocks (code/embed/
  shortcode/html); handle translatable attributes (image alt, button text, headings).
- Admin "Translate now" action, QUEUED via Action Scheduler (do not block the request).
  Create/update the linked translation post; set meta _vaani_source_id, _vaani_lang,
  _vaani_source_hash, _vaani_status. Enforce ONE translation per (source_id, lang) —
  check-before-create + guard in the job (seam #1). Link via meta only, never post_parent.
- Admin UI on the original (column or meta box): per-language status + edit link + stale
  badge when current source hash != stored hash.

Verify Sarvam's current translation endpoint/model/language codes against docs.sarvam.ai
before implementing the API call. Do NOT build front-end rendering, URLs, audio, or billing.
When done: list every file changed and give me manual test instructions (including how to
confirm the translation opens as native blocks), then STOP and wait for review. Do not commit.
```

### Prompt — Phase 3
```
Read CLAUDE.md. Phases 0–2 are complete and committed. Build ONLY Phase 3
(Front-end rendering, URLs & SEO). This completes v1.

Deliver:
- Rewrite rules for /<lang>/<original-slug>/; flush on activate/deactivate. Guard the
  /<lang>/ prefix against collision with an existing top-level page (seam #3).
- Render the matching translation's post_content under the original's public URL;
  fall back to the original if no published, non-stale translation exists.
- Language switcher: block + widget + optional the_content filter, showing only languages
  that have a published, non-stale translation. Per-post only — NO sticky/cookie language
  preference; switching applies to the current post, reader returns to English elsewhere.
- hreflang alternate <link> tags in <head> for all available languages plus x-default,
  using the language registry from Phase 2.
- Build the switcher block and any front-end CSS via 10up-toolkit (source in assets/, output to
  dist/); enqueue from dist/ using the generated *.asset.php. Run npm run build and commit dist/.

Do NOT build audio or billing. When done: list every file changed and give me manual test
instructions (including a URL to visit and how to validate hreflang), then STOP and wait
for review. Do not commit or push.
```

### Prompt — Phase 4
```
Read CLAUDE.md. Phases 0–3 are complete and committed (v1 shipped). Build ONLY Phase 4
(Audio generation / TTS).

Deliver:
- Vaani\Core\Sarvam\Client::text_to_speech($text, $lang).
- Save audio to the media library as {post_type}-{source_post_id}-{lang}.mp3
  (e.g. post-482-hi.mp3), overwrite-in-place on regeneration.
- Reference via meta on the ORIGINAL post: _vaani_audio (lang → attachment ID) and
  _vaani_audio_hash (lang → source hash) for staleness. No custom table for audio.
- Admin "Generate audio (lang)" trigger, QUEUED. Front-end audio player per language.
- If the player needs custom CSS/JS, build it via 10up-toolkit (assets/ → dist/), enqueue from
  dist/, run npm run build, and commit dist/.

Verify Sarvam's current TTS endpoint/voice/language codes against docs.sarvam.ai first.
Do NOT build the billing dashboard. When done: list every file changed and give manual
test instructions, then STOP and wait for review. Do not commit or push.
```

### Prompt — Phase 5
```
Read CLAUDE.md. Phases 0–4 are complete and committed. Build ONLY Phase 5
(Usage & billing dashboard).

Deliver:
- Create table wp_vaani_usage (id, operation, lang, source_id, units, unit_type,
  est_cost_inr, created_at). Log on every API call (translation + TTS) — wire logging
  into Vaani\Core\Sarvam\Client.
- Research whether Sarvam exposes a usage/credits API; if yes, fetch and display remaining
  credits. The local log is the primary source of truth regardless.
- Admin dashboard widget: this month's translation count, audio count, estimated INR spend.

Do NOT change translation/audio behavior beyond adding logging. When done: list every file
changed and give manual test instructions, then STOP and wait for review. Do not commit.
```

### Prompt — Phase 6
```
Read CLAUDE.md. Phases 0–5 are complete and committed. Build ONLY Phase 6
(Polish & hardening). Confirm each item with me if it risks changing existing behavior.

Deliver:
- Bulk action "translate selected"; surface API failures/retries in the admin UI.
- Cleanup on source delete/trash: remove linked translation posts + audio attachments.
- Yoast (only when active): translate _yoast_wpseo_title, _yoast_wpseo_metadesc, and
  OG/Twitter fields; store on the translation; inject on render; avoid duplicate tags.
- Security audit (caps + nonces), transient caching of rendered translations, full i18n pass.

When done: list every file changed and give manual test instructions, then STOP and wait
for review. Do not commit or push.
```
