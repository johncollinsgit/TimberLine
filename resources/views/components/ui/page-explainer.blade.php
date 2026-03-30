@props([
    'title' => 'Page guide',
    'what' => '',
    'why' => '',
    'when' => '',
])

<section class="fb-page-explainer" aria-label="Page explanation">
    <h2>{{ $title }}</h2>
    <div class="fb-page-explainer-grid">
        <article>
            <h3>What this does</h3>
            <p>{{ $what }}</p>
        </article>
        <article>
            <h3>Why it matters</h3>
            <p>{{ $why }}</p>
        </article>
        <article>
            <h3>When to use it</h3>
            <p>{{ $when }}</p>
        </article>
    </div>
</section>
