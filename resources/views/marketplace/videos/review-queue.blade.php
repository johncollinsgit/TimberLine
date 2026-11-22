@extends('marketplace.management-layout', ['consoleRole'=>'operator', 'consoleContext'=>'Markets & Makers', 'consoleBase'=>'/marketplace/operations'])
@section('title', 'Watch review queue')
@section('content')
<div class="page-shell">
    <h1>Watch review queue</h1>
    <section class="panel"><h2>Pilot makers</h2>
        <form method="post" action="{{ route('marketplace.operator.videos.invite') }}" class="form-grid">@csrf
            <label>Approved maker<select name="maker_id" required><option value="">Choose a maker</option>@foreach($makers as $maker)<option value="{{ $maker->id }}">{{ $maker->name }}{{ in_array($maker->id, $invited) ? ' · Studio enabled' : '' }}</option>@endforeach</select></label>
            <div class="inline-actions"><button class="button" name="action" value="invite">Enable Studio</button><button class="button secondary" name="action" value="remove">End Studio access</button></div>
        </form>
    </section>
    <details class="panel"><summary>Last 60 days of the pilot</summary>
        <p>{{ $pilot['makers'] }} participating makers · {{ $pilot['repeat_makers'] }} with repeat submissions · {{ $pilot['videos'] }} videos submitted</p>
        <p>{{ $pilot['orders'] }} verified marketplace orders after refunds · {{ '$'.number_format($pilot['merchandise']/100, 2) }} net merchandise</p>
        <p>{{ '$'.number_format($pilot['margin']/100, 2) }} marketplace margin after maker earnings and discounts · {{ '$'.number_format($pilot['known_processing']/100, 2) }} recorded processing costs on fully attributed orders</p>
        <p>{{ number_format($pilot['minutes']/60, 1) }} team production hours logged for {{ $pilot['hours_logged'] }} of {{ $pilot['videos'] }} submissions · {{ '$'.number_format($pilot['cost']/100, 2) }} in production costs recorded for {{ $pilot['cost_logged'] }}.</p>
        @if($pilot['hours_logged'])<p>{{ number_format($pilot['minutes']/60/$pilot['hours_logged'], 1) }} production hours per video with recorded time.</p>@endif
        @if($pilot['cost_logged'] === $pilot['videos'] && !$pilot['incomplete_processing'])
            <p>{{ '$'.number_format(($pilot['margin'] - $pilot['known_processing'] - $pilot['cost']) / 100, 2) }} after recorded processing and production, before other overhead.</p>
        @else
            <p>Net earnings are incomplete. {{ $pilot['incomplete_processing'] }} orders have unknown or shared processing costs.</p>
        @endif
    </details>
    <section><h2>Submissions</h2>
        @forelse($videos as $video)
            <article class="panel"><a class="text-link" href="{{ route('marketplace.operator.videos.show', $video->id) }}">{{ $video->title }}</a>
                <p>{{ $video->maker->name }} · {{ ucfirst($video->status) }} @if($video->publish_at) · {{ $video->publish_at->timezone(config('marketplace.timezone'))->format('M j, Y g:i a T') }}@endif</p>
            </article>
        @empty <p>No submissions yet.</p> @endforelse
        {{ $videos->links() }}
    </section>
</div>
@endsection
