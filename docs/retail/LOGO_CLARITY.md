# Tablet logo clarity

Studio and Retail now export 192/512/1024 PNGs directly from their SVG masters,
with separate opaque 192/512 maskable icons. Manifests use versioned icon URLs
and preserve installed app IDs. Studio's former jagged PNG did not match its SVG.

Showcase's adaptive foregrounds were 48dp instead of Android's required 108dp,
causing 2.25x enlargement. Density PNGs are corrected. Field retains its existing
leaf. Both native source projects now reference a vector adaptive foreground.
Shared mark/auth/wordmark SVGs contain paths instead of embedded 512px rasters;
the canonical leaf retains 99.55% pixel-mask overlap and original text placement.

Regenerate PWA icons with `scripts/retail/export-icons.mjs`, or native density PNGs
with `scripts/retail/export-android-icons.mjs PROJECT MASTER_SVG FOREGROUND_SVG`.

Both final native APKs were built through the standard web build, Capacitor copy
and JDK 21 Gradle `assembleDebug` workflow after disk space became available.
Field passed 70 tests across 19 files and lint with six existing bundled sample
warnings. Its native build passed in 33 seconds with 461 tasks. Showcase passed
four tests and its bundled-media validator; its native build passed in 17 seconds
with 93 tasks. Final APKs contain the vector adaptive foreground and refreshed
in-app SVG assets. These local debug artifacts are not store releases.

Both APK signatures match the previously installed applications. Galaxy received
the final standard builds through `adb install -r`, preserving app data; the parent
task installs the same final artifacts on Lenovo. The shared certificate SHA-256 is
`13461e64dd98de0bf6397c52c2273de13b52e2b342ed79bad28bcf3f362e8516`.

Galaxy Chrome was updated through the official Play Store from 96.0.4664.104 to
155.0.8059.79. Studio still opens standalone and retains 14 Renfrow records, one
recording and 49,620 audio bytes. The Google-signed Studio/Retail WebAPK launcher
packages have not yet replaced their installed icons. Chrome 96 reported failed
updates; Chrome 155 reported a successful Studio manifest check without changing
the installed APK, while Retail remained pending. Galaxy debugging then dropped
offline. A successful manifest check alone does not prove the installed icon
changed. No PWA uninstall, browser-data reset or IndexedDB clearing was performed.
Final build evidence is `output/retail/logo-review/native-build-results.json`;
older artwork-only APK evidence is historical and superseded by these builds.
