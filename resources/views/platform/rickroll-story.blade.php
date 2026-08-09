<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', [
        'title' => 'Everbranch story',
        'description' => 'A private Everbranch story link.',
    ])
    <meta name="robots" content="noindex, nofollow, noarchive">
</head>
<body class="eb-story-unlisted" data-rickroll-story>
    <main>
        <p class="eb-story-unlisted__label">Everbranch story · private link</p>
        <div class="eb-story-unlisted__player" data-rickroll-player>
            <video data-rickroll-intro controls autoplay playsinline preload="metadata" poster="{{ asset('media/everbranch-story-poster.jpg') }}">
                <source src="{{ asset('media/everbranch-story-rickroll-intro.mp4') }}" type="video/mp4">
                Your browser does not support the Everbranch story video.
            </video>
            <div class="eb-story-unlisted__handoff" data-rickroll-handoff hidden aria-live="polite">
                <iframe title="Rick Astley — Never Gonna Give You Up" src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&amp;rel=0" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>
            </div>
        </div>
        <p class="eb-story-unlisted__note">The video switches after ten seconds.</p>
    </main>
</body>
</html>
