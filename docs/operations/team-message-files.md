# Team message photos and PDFs

Files are stored in private local storage under `team-messages/{tenant}/{channel}`. Do not publish this directory or reuse workspace asset download URLs. Every mobile download resolves current tenant and conversation access and requires an attached, non-deleted message.

Initialize is replayable by uploader and client UUID; chunks are at most 512 KiB, offset checked and SHA-256 checked. Completion checks full size, SHA-256 and detected PDF/JPEG/PNG/WebP MIME. Selection limit is five, file limit 50 MB, pending uploader quota 250 MB. Unattached uploads expire in two hours and are reclaimed on the uploader's next initialization. Temporary device PDFs are cleaned after successful send or leaving the thread.

Messages and uploaded files survive a code rollback. Do not run destructive table rollback or delete attached files. Notifications contain a generic alert and conversation ID, never file contents. The native release must include Photo/PDF composer actions and authenticated previews.

Large uploads use the same 600 requests/minute chunk throttle as workspace PDF uploads. APNs delivery requires an Apple Push Notification service key, HTTP/2 and a valid ES256 JWT; App Store Connect API keys cannot send APNs alerts.
