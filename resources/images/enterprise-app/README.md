# Enterprise install icons

Existing Everbranch repository leaf assets, reused without campus-specific or
new branding. `icon-512.png` is a byte copy of `public/favicon.png`;
`icon-180.png` is a byte copy of `public/apple-touch-icon.png`;
`icon-192.png` is the 512px favicon resized locally with Sharp to 192 x 192 PNG.
No image-generation service or external request was used.

The dedicated application serves only these fixed sizes through its guarded
manifest/icon endpoints. They contain no identity or operational information.
The manifest declares purpose `any`; maskable icon conformance is not claimed.
