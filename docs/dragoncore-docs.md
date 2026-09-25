# Dragon Internal Links

Find orphan content and get link suggestions ranked by real relevance - not just keyword overlap.

## Getting started
1. **Tools → Internal Links → Dashboard**: run the first scan. It indexes every internal link on the site.
2. **Orphan Posts**: content nothing links to - your quickest SEO wins.
3. **Suggestions**: for each post, ranked opportunities to link to related content, with the anchor text found in your actual copy. **Apply** inserts the link at the first occurrence in the post's visible text (never inside image alt text, captions, block settings or an existing link), without touching anything else. If the post cannot be saved, the suggestion stays pending and the error is shown. Keywords are matched as whole words only ("cat" never matches inside "category"), in any language. **Dismiss** is remembered: that page is not suggested for that post again when suggestions are regenerated.

## How ranking works
Suggestions are scored by **TF-IDF document similarity** across your content - the genuinely related page outranks one whose title merely appears in the text. Optionally, add your **own AI API key** (OpenAI, Anthropic or Google) under Settings and the front-runners are re-ranked for editorial relevance: one small request per post, your key stored encrypted, no account with us needed. Without a key, the built-in similarity scoring applies.

## Settings
- **Post types to scan** and **auto-scan on save**. Only published posts of these types count: a post that is unpublished, made private, trashed or deleted drops out of the link counts straight away, and the pages it linked to are recounted.
- **Full Scan Frequency**: daily or weekly. Changing it moves the background scan to the new schedule, with the first run one day or one week later.
- **Minimum Keyword Words**: the fewest words a suggested anchor may have. It applies to both phrases taken from a target's title (the full title, and a shorter phrase of its main words), so at the default of 3, two-word phrases are no longer suggested.
- **Exclude Categories**: posts in these categories are left out of scanning, suggestions (as the post being linked from or the page being linked to), the orphan and low-outbound lists, and the dashboard's orphan count. The lists and the count change straight away; the link counts of pages those posts linked to catch up at the next full scan (or run one from the Dashboard). The same applies when you remove a post type.
- The AI ranking section, and the delete-data-on-uninstall opt-in.

## Data & privacy
The link index and suggestions live in your database. With AI ranking enabled, post titles/excerpts of candidate pages go to the provider you chose - and nowhere else. **Uninstall keeps your data by default.**

## Uninstall
Deleting the plugin keeps all its data by default, so a reinstall picks up where you left off. To remove everything on uninstall, tick **Delete all data on uninstall** in the plugin's settings first (this sets the `dragoninternallinks_delete_data_on_uninstall` option).
