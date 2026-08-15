=== Dragon Internal Links ===
Contributors: dragoncore
Tags: internal links, seo, orphan content, link building, site structure
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find internal linking opportunities, detect orphan content, and improve your site's SEO structure.

== Description ==

Dragon Internal Links helps you build a stronger internal link structure for better SEO and user navigation.

**Features:**

* **Link Scanner** - Automatically scans all posts and pages for internal links
* **Orphan Detection** - Find content with no internal links pointing to it
* **Link Suggestions** - Get contextual suggestions for internal linking opportunities
* **Link Health** - Detect broken internal links to deleted or draft posts
* **Dashboard** - Visual overview of your site's internal link structure

**Why Internal Links Matter:**

* Help search engines discover and index your content
* Pass authority between pages
* Keep visitors engaged longer
* Improve site navigation

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/dragon-internal-links/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Internal Links → Dashboard to run your first scan

== Frequently Asked Questions ==

= How often should I scan my site? =

The plugin automatically scans posts when they're saved. A full site scan runs daily by default, but you can trigger manual scans anytime.

= Does this slow down my site? =

No. Scanning happens in the background and link data is cached. The plugin adds no overhead to your frontend.

= Does it work with custom post types? =

Yes! Configure which post types to scan in Settings.

== Screenshots ==

1. Dashboard showing link statistics
2. Orphan content detection
3. Link suggestions with one-click insertion
4. Settings page

== Changelog ==

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

= 1.0.0 =
Initial release.
