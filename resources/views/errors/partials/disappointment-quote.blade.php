@php
    $quotes = [
        [
            'text' => 'Experience is simply the name we give our mistakes.',
            'author' => 'Oscar Wilde',
        ],
        [
            'text' => "It's deja vu all over again.",
            'author' => 'Yogi Berra',
        ],
        [
            'text' => "The future ain't what it used to be.",
            'author' => 'Yogi Berra',
        ],
        [
            'text' => 'Ever tried. Ever failed. No matter. Try again. Fail again. Fail better.',
            'author' => 'Samuel Beckett',
        ],
    ];
    $quote = $quotes[array_rand($quotes)];
@endphp

<div class="mt-6 rounded-2xl border border-white/10 bg-black/20 p-4">
    <div class="text-[11px] uppercase tracking-[0.28em] text-zinc-500">Disappointment Desk</div>
    <blockquote class="mt-2 text-sm italic text-zinc-200">“{{ $quote['text'] }}”</blockquote>
    <div class="mt-2 text-xs text-zinc-400">— {{ $quote['author'] }}</div>
</div>
