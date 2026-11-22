# Distributing an approved episode

The operator publishes the PC's approved public package to the existing RSS feed with `publish_episode.py`. The episode UUID identifies the episode. Its revision UUID and content-addressed media files identify the exact cut that the editors approved. The distribution page reads these records from the workspace's configured HTTPS feed. Earlier episodes without Studio revision metadata remain in the subscriber feed.

## Publishing from Everbranch Craft on Windows

Render the episode, review the exported audio, video and captions, and approve the public revision in Craft. Select **Publish to FRH** for that revision. If the connection drops, retry the same revision. To correct a published episode, review and approve the replacement export and explicitly supply the UUID of the revision currently in the feed.

The equivalent operator command from the Craft installation is `python frh_publish.py "G:\Everbranch Craft\Your project" --publish-revision REVISION_UUID --confirm-publish`. Include `--previous-revision CURRENT_UUID` only for an intentional correction. The PC uses its existing `everbranch-prod` SSH identity and the verified `.ssh/frh-known-hosts` entry for `frh-web-1`. Only the approved manifest and its listed exports cross the connection. The server checks file hashes in private staging and applies the active `frh-public` release's publisher to the podcast feed served by Caddy. All publishers targeting this feed must share its private lock at `/srv/frh-podcast/.studio/publish.sqlite`.

## Workspace setup

1. Set `STUDIO_DISTRIBUTION_FEEDS` to a JSON object mapping the actual FRH workspace ID to `https://freerangehumanity.com/podcast/rss.xml`. Each additional workspace must have its own approved feed. Enable Marketing Desk and its messaging module for the participating tenant. Add its public media hostname to the existing `MARKETING_DESK_MEDIA_HOSTS` list. The public web server must serve HTTP HEAD and exact byte ranges for Studio media.
2. Enable YouTube Data API v3 in the creator's Google Cloud OAuth project. Configure a Web application client with a redirect URI ending in `/integrations/studio/youtube/callback` on the signed-in application origin. Set `STUDIO_YOUTUBE_CLIENT_ID`, `STUDIO_YOUTUBE_CLIENT_SECRET` and the exact HTTPS `STUDIO_YOUTUBE_CALLBACK`. Register the provider's consent and publication requirements for the intended channels. The creator signs into the desired Google/Brand Account on **Connect a YouTube channel**. Google can restrict unverified projects to private uploads. [YouTube upload requirements](https://developers.google.com/youtube/v3/docs/videos/insert)
3. Run a supervised worker with `php artisan queue:work studio_distribution --queue=studio-distribution --timeout=145`. Its database lease is 240 seconds. Preserve the application's encryption key and queue database across releases. A shared cache lock coordinates parallel workers.
4. Connect the workspace's Facebook Page and Instagram professional account in Marketing Desk. Enable its existing social publisher and delivery settings for the tenant when ready to publish.

## Operator workflow

Open **Episode distribution** in the workspace store navigation, or visit `/website/studio/distribution` on its signed-in Everbranch origin. Select the full video, trailer, or specific short.

- **YouTube.** Review the channel, title, description, visibility, audience designation, and optional UTC publication time. Submit the upload. Long videos transfer in 8 MiB chunks with a provider-confirmed offset. A repeated request for the same channel, episode revision and asset reuses its receipt. **Resume upload** checks the existing session. A session marked **review** has expired. Check YouTube Studio for a finished upload before preparing another revision. The video link appears when the provider confirms an ID. It may still be processing, private, or scheduled. [Resumable upload protocol](https://developers.google.com/youtube/v3/guides/using_resumable_upload_protocol)
- **Facebook and Instagram.** Save a draft with the selected media and editable caption, then select accounts, a future time, and approve in the existing Marketing Desk calendar. The publisher records each destination's status. Reopening the same asset and revision reuses its draft.
- **Apple Podcasts and Spotify.** Their existing public RSS subscriptions can ingest the episode audio. Open the creator dashboard to manage the show's feed registration or upload a platform-specific video. The feed renderer preserves the existing episode GUID.
- **Rumble.** Download the episode or clip from the selected revision and use **Rumble upload**. The editor can copy the episode description and download its captions from the same page.

This workflow accepts public releases. Private or subscriber-only assets must stay in their entitled distribution path.
