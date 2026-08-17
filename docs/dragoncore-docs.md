# Dragon Internal Links

Find orphan content and get link suggestions ranked by real relevance — not just keyword overlap.

## Getting started
1. **Tools → Internal Links → Dashboard**: run the first scan. It indexes every internal link on the site.
2. **Orphan Posts**: content nothing links to — your quickest SEO wins.
3. **Suggestions**: for each post, ranked opportunities to link to related content, with the anchor text found in your actual copy. **Apply** inserts the link at the first occurrence, without touching anything else.

## How ranking works
Suggestions are scored by **TF-IDF document similarity** across your content — the genuinely related page outranks one whose title merely appears in the text. Optionally, add your **own AI API key** (OpenAI, Anthropic or Google) under Settings and the front-runners are re-ranked for editorial relevance: one small request per post, your key stored encrypted, no account with us needed. Without a key, the built-in similarity scoring applies.

## Settings
Post types to scan, auto-scan on save, scan frequency, minimum keyword length, excluded categories, the AI ranking section, and the delete-data-on-uninstall opt-in.

## Data & privacy
The link index and suggestions live in your database. With AI ranking enabled, post titles/excerpts of candidate pages go to the provider you chose — and nowhere else. **Uninstall keeps your data by default.**

## Uninstall
Deleting the plugin keeps all its data by default, so a reinstall picks up where you left off. To remove everything on uninstall, tick **Delete all data on uninstall** in the plugin's settings first (this sets the `dragoninternallinks_delete_data_on_uninstall` option).
