# Migrate a public podcast feed

The Everbranch migration command packages an archived public RSS feed, its MP3 enclosures, and new show branding. It rewrites enclosure URLs for a public static host and keeps the original episode GUIDs, publication dates, titles, duration, season and episode fields. The command does not upload, redirect, or submit a new listing.

## Prepare the archive

Save the original feed XML and enumerate every public episode. If the source has an Atom next link, check every page and consolidate all items into one source snapshot. Download the original RSS enclosures without transcoding and store them beside `episode-manifest.json`. The manifest is an array of records with `guid`, `file` (a unique MP3 basename), and `bytes` (the original enclosure length). For a source without explicit GUIDs, resolve episode identity with the podcast host or directory before using the tool.

Use the original directory listing and keep the old host available. Download any available analytics before migrating. Exclude private or member audio from the source and artifact.

## Build and verify

Use Python 3.9 or newer. Each run writes to a new directory. A versioned cover filename helps apps pick up future artwork changes.

```sh
python scripts/managed-website/podcast-migration.py build \
  --source archive/original.xml --archive archive --output podcast-publish \
  --base-url https://example.com/podcast --website https://example.com/podcast \
  --cover cover.jpg --title 'Show Name' --author 'Host Name' \
  --description 'Public show description.'
python scripts/managed-website/podcast-migration.py verify \
  --source archive/original.xml --candidate podcast-publish/rss.xml
```

Serve the bundle from a dedicated public static directory. For Caddy, scope the route to the feed, art, and media so the existing HTML route still serves the show page. For this example, the public root has a `podcast` directory containing the generated files.

```caddyfile
@podcastFiles path /podcast/rss.xml /podcast/art/* /podcast/media/*
handle @podcastFiles {
    root * /srv/example-podcast/public
    header /podcast/rss.xml Content-Type "application/rss+xml; charset=utf-8"
    file_server
}
```

Publish the files before changing any feed address. Check HTTP MIME types, content lengths and byte ranges, then listen to the first and last episode in a browser.

```sh
python scripts/managed-website/podcast-migration.py verify \
  --source archive/original.xml --candidate https://example.com/podcast/rss.xml --check-http
```

After verification, set a 301 redirect at the prior host and confirm the old feed points directly to the new feed. Check the existing Apple listing after it refreshes. Use its existing Podcasts Connect show record to edit the RSS URL only if needed. Keep the redirect in place and retain the previous release until the cutover is verified.

[Apple hosting migration](https://podcasters.apple.com/support/3965-how-to-change-hosting-providers) · [Apple RSS requirements](https://podcasters.apple.com/support/823-podcast-requirements) · [Caddy static file server](https://caddyserver.com/docs/caddyfile/directives/file_server)
