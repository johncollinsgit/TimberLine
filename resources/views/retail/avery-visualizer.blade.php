<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#173d31">
    <title>Avery · Illustrative knife visualizer</title>
    <link rel="stylesheet" href="/avery-visualizer/demo.css">
    <link rel="stylesheet" href="/avery-visualizer/viewer.css">
</head>
<body class="avery-preview">
    <header class="preview-header">
        <a href="/retail/showroom/concepts/avery-knifeworks" class="brand-link" aria-label="Back to Avery demo">
            <img src="/retail/assets/avery-logo.png" alt="Avery Knifeworks" width="132" height="66">
        </a>
        <a href="/retail/showroom/concepts/avery-knifeworks" class="back-link">← Back to the Avery demo</a>
    </header>
    <main>
        <section class="preview-intro" aria-labelledby="preview-title">
            <div class="preview-copy">
                <span class="eyebrow">Avery · Private concept</span>
                <h1 id="preview-title">A different way<br>to explore a knife.</h1>
                <p class="lead">Turn an illustrative sample in 3D, or choose a camera preview that places it near your hand.</p>
                <div class="demo-disclosure" role="note">
                    <strong>Illustrative demo, not an Avery product model.</strong>
                    <p>This fictional knife and its preset scale do not represent an actual Avery knife, its measurements, fit, comfort, or safe handling. Hand placement is approximate.</p>
                </div>
                <div id="viewer-entry" aria-busy="true">
                    <p id="viewer-status" class="viewer-status" role="status">Loading the self-hosted visualizer…</p>
                    <everbranch-product-viewer id="avery-knife-viewer" data-config='{"version":1,"productId":1,"title":"Illustrative knife · not an Avery product model","placement":"hand","modelUrl":"/avery/visualizer/model.glb","lengthMm":300,"grip":[0.5,0.15,0.5],"rotationDeg":[0,0,0],"variants":[{"id":1,"label":"Fictional sample","modelUrl":"/avery/visualizer/model.glb"}]}'></everbranch-product-viewer>
                </div>
                <noscript><p class="viewer-status">The visualizer needs JavaScript. You can still return to the Avery demo using the link above.</p></noscript>
                <p class="privacy-note">Opening 3D does not start a camera. Camera access begins only after you choose <strong>Start camera</strong>. No microphone is used, and frames stay in this browser.</p>
            </div>
            <figure class="preview-photo">
                <img src="/retail/assets/avery-43753692168355.webp" alt="Public Avery catalog photograph, separate from the fictional 3D sample" width="720" height="720">
                <figcaption>Public Avery catalog photography. The 3D sample is a separate fictional illustration.</figcaption>
            </figure>
        </section>
        <section class="preview-details" aria-label="What to expect">
            <article><span class="step-number">01</span><h2>Explore first</h2><p>Open the sample and drag to turn it. The protected model needs a connection and your current Avery demo access.</p></article>
            <article><span class="step-number">02</span><h2>Your camera, your choice</h2><p>Choose Start camera, then allow it in your browser. If permission or tracking is unavailable, the 3D view remains available.</p></article>
            <article><span class="step-number">03</span><h2>Finish when you want</h2><p>Close the preview or leave the page to stop the camera. Photos and short clips are created only when you explicitly choose them; they are not uploaded.</p></article>
        </section>
        <p class="preview-limit">This is a browser demonstration. Real-phone tracking, camera occlusion, AR, and sharing acceptance remain unverified. It is not a sizing or safety tool.</p>
    </main>
    <footer><span>Avery-only Everbranch concept</span><a href="/retail/showroom/concepts/avery-knifeworks">Return to the showroom →</a></footer>
    <script type="module" src="/avery-visualizer/demo.js"></script>
</body>
</html>
