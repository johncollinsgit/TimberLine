# Wiki Authoring Guide

## Where wiki content lives
- Categories and article metadata live in `config/wiki.php`.
- Route/controller wiring lives in `routes/web.php` and `app/Http/Controllers/WikiController.php`.
- Data-backed legacy pages remain in `resources/views/wiki/*.blade.php` and are linked via `path` entries in metadata.

## Add a new category
1. Open `config/wiki.php`.
2. Add a new entry under `categories` with:
- `slug`: URL-safe key (example: `quality-control`)
- `title`: display name
- `description`: short summary
- `subcategories` (optional): array of child category slugs
3. Category page will auto-appear at `/wiki/category/{slug}` and in `/wiki/categories`.

## Add a new wiki page
1. Open `config/wiki.php`.
2. Add a new entry under `articles`.
3. Required fields:
- `slug`
- `title`
- `excerpt`
- `category`
- `updated_at` (YYYY-MM-DD)
- `published` (true/false)
4. If the page should render with the generic article template, add `sections`:
- Each section supports `id`, `title`, and optional `paragraphs`, `checklist`, `quicklinks`, `templates`.
5. If page already has a custom route/view, set `path` (example: `/wiki/oil-blends`).

## Internal links
Use wiki-link syntax inside section text:
- `[[article-slug]]`
- `[[article-slug|Custom Label]]`

Example:
- `See [[wholesale-account-deactivation|deactivation workflow]].`

## Featured / home behavior
- Featured article is selected by:
1. `featured: true`
2. else first `pinned: true`
3. else most recently updated article
- Recently updated sorts by `updated_at`.
- Most viewed falls back to recently edited if no `views` metrics are set.

## Wholesale process index
- Page route: `/wiki/wholesale-processes`
- The tile list is generated from category slugs:
- `wholesale-processes` (excluding the index page itself)
- `wholesale-special-cases`

## Navigation and breadcrumbs
- Main app sidebar includes:
- `Backstage Wiki`
- `Wholesale Processes`
- Article breadcrumbs render as:
- `Wiki -> {Category} -> {Page}`

## Notes
- Keep excerpts short so cards stay readable.
- Prefer adding links through `related` slugs to keep cross-navigation strong.
- For placeholders, set `needs_details: true` and add clear section headings.

## Admin Editing UI
- Admin users can edit and delete wiki categories and articles directly from wiki pages using `Edit` / `Delete` pills.
- Admin users can create new categories and articles from:
- Wiki home hero actions
- Left wiki nav (`New Article`, `New Category`)
- Category pages (`New Article` prefilled to current category)
- Runtime edits are stored in `storage/app/wiki/content.json`.
- Config defaults in `config/wiki.php` remain the baseline; admin edits overlay and can hide default entries.
- Article editor supports:
- Title, excerpt, category, date, optional path
- Related slugs (comma-separated)
- Full sections JSON for structured content blocks
