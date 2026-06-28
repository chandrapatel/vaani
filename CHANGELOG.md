# Changelog

All notable changes to **Vaani – AI Translation & Audio for Indian Blogs** are documented
here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While Vaani is in the `0.x` series the public surface (settings, meta keys, URL structure)
may still change between releases as the plugin is tested in the wild.

## [0.1.0] - 2026-06-29

First public, experimental release. Vaani adds per-post, reader-facing translations and
per-language audio to an English-primary WordPress site using [Sarvam AI](https://www.sarvam.ai/).
The original English post stays canonical; an admin opts individual posts and pages into
Indian-language translations that edit as native Gutenberg blocks.

> **Try-it-out release.** This version is functional but not exhaustively tested across every
> block, theme, and content type. Run it on a staging site or a few posts first and review the
> results. Your original posts and pages are never modified, so it is safe to experiment.

### Added

- **Settings & Sarvam connection** — *Settings → Vaani* for the Sarvam API key, default source
  language, and translatable post types (posts on by default, pages opt-in), with a
  **Test connection** check.
- **Translation methods** — choose **Translation** (meaning) with the **Mayura** or
  **Sarvam Translate** model, or **Transliteration** (script). Per-method options for tone
  (Mayura), speaker gender, numerals, and spoken form.
- **Block-aware translation** — `post_content` is parsed block by block; translatable text,
  image `alt`, and link `title` are translated and reserialized, while code, HTML, embed,
  preformatted, and shortcode blocks pass through untouched. Post **title** and **excerpt** are
  translated too.
- **Editor sidebar** — a **Vaani** panel in the block editor lists every enabled language with
  per-language **Translate now** / **Re-translate**, status (Translated / stale / Failed), an
  **Edit** link, and a per-post running cost estimate. Work runs in the background via Action
  Scheduler and never blocks the editor.
- **Translation storage** — each translation is one private `vaani_translation` post per
  (source × language) with real `post_content` (native blocks + revision history), linked to the
  source via meta (`_vaani_source_id`, `_vaani_lang`, `_vaani_source_hash`, `_vaani_status`) and
  never via `post_parent`. Exactly one translation per (source, language) is enforced.
- **Staleness detection** — editing the English source flags its translations with a **(stale)**
  badge until re-translated.
- **Bulk translation** — a *Posts*/*Pages* list action queues "translate to all enabled
  languages" for the selected rows.
- **Front-end rendering & URLs** — published translations are served at clean path-prefixed URLs
  (e.g. `/hi/about/`), with the `/<lang>/` prefix guarded against top-level page collisions. The
  original permalink is never altered.
- **Language switcher** — a **Vaani Language Switcher** block and matching widget link to the
  languages a post is available in. Selection is per-post (not sticky).
- **Audio (text-to-speech)** — generate an MP3 per language via Sarvam TTS, stored in the media
  library as `{post_type}-{source_id}-{lang}.mp3` (overwrite-in-place). A **Vaani Audio Player**
  block placed once on the original renders that page's audio on every language version.
- **Dynamic, language-aware blocks** — the switcher and audio player are placed once on the
  original and render automatically at the same position on every language version; opt-in
  `the_content` filters (`vaani_append_switcher`, `vaani_append_audio_player`) are also available.
- **SEO** — `hreflang` alternate tags for all available languages plus `x-default` on the
  original. When Yoast SEO is active, optionally translate its title, meta description, and
  social (OG/Twitter) fields through a thin Yoast adapter.
- **Usage & cost** — every Sarvam call is logged to a `wp_vaani_usage` table (indexed on
  `source_id` and `created_at`); a *Dashboard → Vaani usage* widget shows this month's
  translations, audio count, and an estimated INR spend.
- **Lifecycle handling** — trashing/restoring/deleting a source post mirrors onto its
  translations; deleting also removes the generated audio attachments. Uninstalling the plugin
  removes all Vaani data (settings, translation posts, audio, `_vaani_*` meta, and the usage
  table) while leaving original posts untouched.
- **Distribution** — self-contained zip with `vendor/` (Composer + Action Scheduler) and `dist/`
  (compiled assets) committed, so no build step is needed to run it.

### Requirements

- WordPress 6.4+
- PHP 8.0+
- A Sarvam AI API key (free credits on signup)

[0.1.0]: https://github.com/chandrapatel/vaani/releases/tag/v0.1.0
