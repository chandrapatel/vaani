# Vaani – AI Translation & Audio for Indian Blogs

Vaani adds **per-post, reader-facing translations** to an English-primary WordPress site
using [Sarvam AI](https://www.sarvam.ai/). The original English post stays canonical; an admin
opts individual posts/pages into Indian-language translations that edit as **native Gutenberg
blocks** and get revision history for free.

> Vaani (वाणी) means *"voice, speech"* — the name spans both the text-translation and the
> per-language audio features.

This is **not** a locale-first multilingual solution (WPML/Polylang). The site itself stays
English — navigation, archives, and the default experience are English — and a reader chooses
to read *a specific post* in, say, Tamil. Language selection is per-post, not sticky.

## Project status

Vaani is a young, experimental project. It works well for typical block-editor posts and pages,
but it hasn't been exhaustively tested across every kind of blog content, block, and theme yet —
so try it on a staging site or a few posts first and review the results before rolling it out
widely. Your original posts and pages are never modified, so it's safe to experiment.

Bug reports and feedback are very welcome — please [open an issue](https://github.com/chandrapatel/vaani/issues).
This is an experimental side project with no guaranteed maintenance schedule, so responses and
fixes may be delayed — but every report genuinely helps and is appreciated.

## Requirements

- WordPress 6.4+
- PHP 8.0+
- A Sarvam AI API key (free credits on signup at [sarvam.ai](https://www.sarvam.ai/))

## Installation

The distributed zip is self-contained — `vendor/` (Composer + Action Scheduler) and `dist/`
(compiled JS/CSS) are committed, so no build step is required to run it.

1. Copy the `vaani` folder into `wp-content/plugins/` (or install the zip).
2. Activate **Vaani** in *Plugins*.
3. Go to *Settings → Vaani*, add your Sarvam API key, and click **Test connection**.

## Usage

1. **Enable languages** — in *Settings → Vaani*, choose the target languages to offer and which
   post types are translatable (posts on by default, pages opt-in).
2. **Choose a method** — *Settings → Vaani → Translation*:
   - **Translation** — converts *meaning* into the target language. Pick a model:
     - **Mayura** — supports translation **tone** (formal / colloquial / code-mixed) and
       **speaker gender**; up to 1000 characters per request.
     - **Sarvam Translate** — formal tone only, **speaker gender** supported; up to 2000
       characters per request and more languages.

     Speaker gender applies to both models; tone applies to Mayura only.
   - **Transliteration** — converts *script*, not meaning: the original words are written in
     the target script, spelled phonetically (e.g. English → Devanagari). Useful for technical
     posts with English terms that have no native equivalent. Options: **numerals**
     (international `0-9` vs native script) and an optional **spoken form**.
3. **Translate** — in the block editor, open the **Vaani** sidebar (the translation icon in the
   editor toolbar). Its **Translations** section lists every enabled target language; click
   **Translate now** for one. The work runs in the background (Action Scheduler) and never blocks
   the editor — click **Refresh** to pull the result. When it finishes, the row shows
   **Translated** with an **Edit** link; the translation opens in the block editor as native blocks.
4. **Re-translate** — once a translation exists the button reads **Re-translate**. It
   regenerates from the latest saved source content and **overwrites in place** (same post, so
   revision history is preserved). When you edit the English source, its translations show a
   **(stale)** badge until re-translated. A failed run shows **Failed** with the Sarvam error.
5. **Translate in bulk** — from the *Posts*/*Pages* list, select rows and choose
   **Vaani: Translate to all enabled languages** to queue them at once.

Settings changes (method/model/tone/gender) apply to the *next* translation; existing
translations are not auto-regenerated.

### Audio (text-to-speech)

In the **Audio** section of the **Vaani** sidebar, click **Generate** for a language to
synthesize an MP3 via Sarvam TTS. It runs in the background and is stored in the media library;
click **Refresh** to pull the result.

To show a "Listen" player, add the **Vaani Audio Player** block to your **original** post where
you want it (it's also available as an opt-in `the_content` prepend). You add it once on the
original; it renders automatically on each language version, playing that page's audio at the same
position. See [Placing the switcher & audio blocks](#placing-the-switcher--audio-blocks).

### Reading translations (front end)

- Published translations are served at clean **path-prefixed URLs** — e.g. `/hi/about/` for the
  Hindi version of `/about/`. The original post is never altered.
- A **language switcher** (the **Vaani Language Switcher** block, or a widget of the same name)
  links to the languages a post is available in. Selection is per-post: switching applies to the
  current post only, not the whole site.
- **`hreflang`** alternate tags are emitted for search engines, with `x-default` on the original.
- **SEO meta (optional):** when Yoast SEO is active, enable *Settings → Vaani → SEO →
  Yoast SEO meta* to translate the Yoast title, meta description, and social (OG/Twitter) fields
  alongside content.

#### Placing the switcher & audio blocks

The **Vaani Language Switcher** and **Vaani Audio Player** are added to your **original** English
post in the block editor, wherever you want them to appear. They are dynamic, language-aware
blocks: each one renders the current page's language automatically, so you place a block **once on
the original** and it shows up at the same position on every language version — `/`, `/hi/`,
`/gu/`, and so on.

Because the original is the single source of truth for these blocks, adding, moving, or removing
one updates all languages immediately — **no need to re-translate**, and your reviewed translation
edits are never touched. (Prefer not to use blocks? A **Vaani Language Switcher** widget and opt-in
`the_content` filters — `vaani_append_switcher` / `vaani_append_audio_player` — are available too.)

### Usage & cost

*Dashboard → Vaani usage* shows this month's translations, audio count, and an estimated INR
spend; the **Vaani** sidebar shows each post's own running estimate. Estimates are based on a
local log of every Sarvam call.

## What gets translated

- Post **title**, block-editor `post_content` — text within blocks, plus image `alt` and link
  `title` — and the post **excerpt**.
- Non-translatable blocks are passed through untouched: **code, HTML, embed, preformatted,
  shortcode**.
- Optionally, **Yoast SEO** title/description/social fields (when Yoast is active and enabled).

Out of scope (v1): custom fields / page-builder content, WooCommerce data, taxonomy/menu/widget
strings, and on-the-fly reader-triggered translation (everything is pre-generated by the admin).

## AI Disclosure

Vaani relies on **[Sarvam AI](https://www.sarvam.ai/)**, a third-party AI service, to produce
translations and text-to-speech audio. It is **not** an officially affiliated or bundled service —
you must supply your own Sarvam API key, and your use is governed by Sarvam's terms.

- **What is sent:** when an admin triggers a translation or audio generation, the relevant content
  (post title, block content, excerpt, and — if enabled — Yoast SEO title/description/social
  fields) is sent to Sarvam's API over HTTPS for processing. Nothing is sent during normal
  front-end page views; translations and audio are pre-generated and served from your own site.
- **When it happens:** only on an explicit admin action (*Translate now*, *Re-translate*,
  *Generate audio*, or the bulk action). Vaani never sends content automatically on publish.
- **AI-generated output:** translations and audio are machine-generated and may contain
  inaccuracies. Review them — they open as editable Gutenberg blocks — before relying on them.
- **Third-party terms & privacy:** see Sarvam's
  [Terms of Service](https://www.sarvam.ai/terms-of-service),
  [Privacy Policy](https://www.sarvam.ai/privacy-policy), and
  [Trust Center](https://www.sarvam.ai/trust-center) for how your data is handled. Verify these
  links against Sarvam's site, as the URLs can change.

### Development disclosure

This plugin was developed with the assistance of Claude (Anthropic's AI). All code, structure,
and documentation were generated through a collaborative session between the author and Claude
Code. The implementation has been reviewed and is maintained by the author.

## How it works

- Each translation is stored as one **private `vaani_translation` post** per
  (source post × language) — real `post_content`, so it edits as native blocks and gets
  revisions. It links back to the source via meta (`_vaani_source_id`, `_vaani_lang`,
  `_vaani_source_hash`, `_vaani_status`) — never `post_parent`.
- A single **language registry** maps each internal code to its Sarvam API code and hreflang
  value (e.g. Odia `or → od-IN`), so language codes never drift across the plugin.
- All Sarvam HTTP goes through one client with centralized auth, timeout, and retry/backoff.
- Staleness is detected by hashing the source content at translation time and comparing on
  later edits.
- Trashing or deleting a source post mirrors onto its translations (trashed/restored/deleted),
  and deleting removes the generated audio attachments too.

## Uninstalling

Deleting Vaani from *Plugins* is **destructive**: it removes the plugin's settings, every
generated translation post, the generated audio attachments, all `_vaani_*` post meta, and the
`wp_vaani_usage` table. Your original posts and pages are never touched. Deactivating (rather than
deleting) leaves all data in place.

## Development

PHP autoloading is Composer PSR-4 (`Vaani\` → `src/`); front-end/editor assets are built with
10up-toolkit (`assets/` → `dist/`).

```bash
composer install          # PHP deps (Action Scheduler)
composer dump-autoload -o  # after adding classes
npm install                # JS toolchain
npm run build              # compile assets to dist/ (or: npm run start to watch)
```

Commit both `vendor/` and `dist/` so the GitHub zip works without a build step.

## License

GPL-2.0-or-later
