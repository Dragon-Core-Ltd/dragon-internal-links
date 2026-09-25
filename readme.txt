=== Dragon Internal Links ===
Contributors: dragoncoreltd
Tags: internal links, seo, orphan content, link building, site structure
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.1.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find internal linking opportunities, detect orphan content, and improve your site's SEO structure.

== Description ==

Dragon Internal Links helps you build a stronger internal link structure for better SEO and user navigation.

**Features:**

* **Link Scanner** - Automatically scans all posts and pages for internal links
* **Orphan Detection** - Find content with no internal links pointing to it
* **Smart Link Suggestions** - Contextual linking opportunities ranked by real document similarity (TF-IDF), with optional AI re-ranking using your own OpenAI, Anthropic, or Google key — stored encrypted, no extra account
* **Link Health** - Detect broken internal links to deleted or draft posts
* **Dashboard** - Visual overview of your site's internal link structure

**Why Internal Links Matter:**

* Help search engines discover and index your content
* Pass authority between pages
* Keep visitors engaged longer
* Improve site navigation

== External services ==

This plugin works entirely on your own site by default. Link suggestions are
ranked locally, and no data leaves your server.

AI re-ranking is an optional feature that is off until you enable it and enter
your own API key. When it is on, the plugin asks a third-party AI provider to
score the link candidates it has already found locally. This happens while
suggestions are being generated for a post.

Each request contains the source post's title and an extract of its text
(shortened to roughly 1,500 characters), plus the title, excerpt (roughly 240
characters) and proposed anchor text of each candidate page, along with the
model you selected and your API key (sent in a request header for every
provider, never in the URL). Full post content is never sent, no user data is
sent, and nothing is sent to Dragon Core. If the provider is unreachable the plugin falls back to its local
ranking.

You choose one provider, and only that provider is contacted:

* **OpenAI** — Terms: https://openai.com/policies/terms-of-use/ ·
  Privacy: https://openai.com/policies/privacy-policy/
* **Anthropic Claude** — Terms: https://www.anthropic.com/legal/consumer-terms ·
  Privacy: https://www.anthropic.com/legal/privacy
* **Google Gemini** — Terms: https://policies.google.com/terms ·
  Privacy: https://policies.google.com/privacy

Your provider may charge for these requests and applies its own data-retention
policy. Review the terms above before enabling the feature.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/dragon-internal-links/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Internal Links → Dashboard to run your first scan

== Frequently Asked Questions ==

= How often should I scan my site? =

The plugin automatically scans posts when they're saved. A full site scan runs daily by default (or weekly, under Settings > Full Scan Frequency), and you can trigger a manual scan anytime.

= What counts towards a post's inbound links? =

Only links from published posts of the types you scan. When a post is unpublished, made private, trashed or deleted, or is in a category you exclude, its links stop counting, so the pages it linked to can show up as orphans again. Links pointing at a deleted or unpublished post are still listed as broken.

= Will a dismissed suggestion come back? =

No. Dismissing a suggestion stops that page being suggested for that post again, including after the daily regeneration.

= Does this slow down my site? =

No. Scanning happens in the background and link data is cached. The plugin adds no overhead to your frontend.

= Does it work with custom post types? =

Yes! Configure which post types to scan in Settings.

== Changelog ==

= 1.1.11 =
* Fixed: links to trashed, deleted or draft posts disappeared from Link Health after the next scan. They are now reported on every scan until fixed.
* Suggestions come from each post's distinctive topic phrases, not only its exact title.
* Links to media files are no longer reported as broken.
* Retired AI models move to current ones with a notice, and AI errors show on the Settings screen.
* Titles with punctuation or non-Latin letters get suggestions, and scan warnings stay on screen.
* Multisite: tables are created and removed per site.

= 1.1.10 =
* Unpublished, trashed and deleted posts leave the link index.
* Dismissed suggestions stay dismissed, and the Exclude Categories and Scan Frequency settings now take effect.
* Suggestions are only offered where they can be applied, and links are never placed inside words, shortcodes or captions.

= 1.1.9 =
* Every screen, email and alert is now translatable, and translations bundled in the plugin's languages folder now load. Counts use proper plural forms, and numbers and dates follow your site's language.
* Priorities, post types and link statuses show readable labels.

= 1.1.8 =
* Fixed: when one of this plugin's scheduled tasks needed re-creating, it was scheduled before WordPress had finished loading, which made WordPress log "translation loading was triggered too early" notices that named other plugins. Scheduling now waits until WordPress is ready. The notices only appeared with debug logging switched on.

= 1.1.7 =
* Fixed: applying a suggestion no longer inserts the link inside an image's alt text, a caption, a block's settings, an HTML comment, a script, a style block or a form field, or inside an existing link - including a link that spans more than one block, which used to nest a link inside a link and break both. Only visible text is linked, and the link uses the text exactly as written in the post (capitalisation preserved).
* Fixed: if the post could not be saved, applying a suggestion now reports the failure instead of marking the suggestion as applied. Saving no longer strips embeds or other HTML from the rest of the post for users without the unfiltered_html capability.
* Fixed: on sites installed in a subdirectory, links written as /subdirectory/page/ were not recognised as internal, so every post looked orphaned. Relative links (page/, ../page/, ?page_id=12) and links whose host differs only by case or a www. prefix are now recognised too.
* Fixed: failed database writes while generating, applying or dismissing suggestions are now reported instead of being counted as done.
* Fixed: very long post titles are shortened at a word boundary when used as a keyword, and titles with unusual characters no longer produce empty suggestions.
* Fixed: the Context column on the Suggestions tab no longer goes blank for unusual characters.

= 1.1.6 =
* Readme: corrected the AI re-ranking disclosure (the Google API key is sent in a request header, not in the URL) and added the missing 1.1.5 changelog entry. No functional change.

= 1.1.5 =
* Improvement: generating suggestions now pages through all posts and no longer wipes previous results mid-run.
* Security: applying a suggestion now requires edit rights on the post; the AI model setting is constrained to the selected provider.
* Hardening: the BYO AI key uses authenticated encryption at rest.

= 1.1.4 =
* Compatibility: tested up to WordPress 7.1.
* Fix: the first-run guidance panel now shows its intended styling.
* Housekeeping: corrected the contributor name in the plugin readme.

= 1.1.3 =
* Documentation: full external-services disclosure for optional AI re-ranking.
* Fix: a never-scanned site no longer shows "all posts have links" — it now prompts the first scan.
* Reliability: scheduled scans on large sites now continue across cron runs instead of timing out.

= 1.1.2 =
* Data safety: uninstalling the plugin no longer deletes its data unless you explicitly opt in first — a reinstall now picks up exactly where you left off. (New setting.)

= 1.1.1 =
* New look: the Dragon design system arrives — a consistent Dragon Core header, cleaner tables, and unified status colours. Purely visual; no behaviour changes.

= 1.1.0 =
* Smarter suggestions: relevance is now scored by document similarity (TF-IDF) instead of keyword overlap alone, so the best target ranks first.
* Optional AI ranking: add your own OpenAI, Anthropic, or Google API key under Settings and suggestions are re-ranked for editorial relevance — one small request per post, key stored encrypted, no account with us needed.

= 1.0.2 =
* Fix: settings could be lost on a deactivate then reactivate update; the migration now carries each value before removing the old copy.

= 1.0.1 =
* Renamed all option, hook, function and constant prefixes to the unique `dragoninternallinks_` / `DRAGONINTERNALLINKS_` prefix. Existing settings and the scan schedule are migrated automatically on update; scanned link data is unaffected.

= 1.0.0 =
* Initial release
* Link scanning and storage
* Orphan content detection
* Link suggestions based on keyword matching
* Broken internal link detection
* Dashboard with statistics

== Upgrade Notice ==

= 1.1.11 =
Link Health keeps reporting links to removed posts, and suggestions find more relevant links.

= 1.1.10 =
More accurate orphan reports and link suggestions.

= 1.1.9 =
Translation-ready throughout.

= 1.1.7 =
Applying a suggestion no longer breaks image or block markup, save failures are reported, and subdirectory installs now detect internal links correctly.

= 1.0.0 =
Initial release.
