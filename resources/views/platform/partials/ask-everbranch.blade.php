@once
<link rel="stylesheet" href="{{ asset('css/ask-everbranch.css') }}">
<button class="eb-ask-launch" type="button" data-ask-everbranch-open aria-haspopup="dialog">Ask Everbranch <span aria-hidden="true">↗</span></button>
<dialog class="eb-ask-dialog" id="ask-everbranch" aria-labelledby="ask-everbranch-title" data-endpoint="{{ route('platform.bud.conversations', [], false) }}">
    <div class="eb-ask-heading"><h2 id="ask-everbranch-title">Ask Everbranch</h2><button type="button" data-ask-everbranch-close aria-label="Close help">×</button></div>
    <p class="eb-ask-intro">A little help finding your next step.</p>
    <div class="eb-ask-prompts" aria-label="Suggested questions">
        <button type="button" data-ask-question="Which branches are included?">Branches</button>
        <button type="button" data-ask-question="What is the pricing?">Pricing</button>
        <button type="button" data-ask-question="How do I build a website?">Websites</button>
        <button type="button" data-ask-question="I need setup help">Setup</button>
    </div>
    <div class="eb-ask-thread" role="log" aria-live="polite" aria-label="Conversation"></div>
    <form class="eb-ask-form">
        <label for="ask-everbranch-question">Your question</label>
        <textarea id="ask-everbranch-question" name="question" rows="2" required maxlength="2000" placeholder="What would you like to know?"></textarea>
        <button type="submit">Ask Everbranch <span aria-hidden="true">↗</span></button>
    </form>
    <p class="eb-ask-status" role="status"></p>
    <p class="eb-ask-foot">Guided product help. Please keep passwords and payment details private. <a href="{{ route('platform.contact', ['intent' => 'support']) }}">Contact a person</a></p>
</dialog>
<script src="{{ asset('js/ask-everbranch.js') }}" defer></script>
@endonce
