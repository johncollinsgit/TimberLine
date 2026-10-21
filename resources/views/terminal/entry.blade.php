@extends('terminal.layout')
@section('title', 'Terminal sign-in')
@section('content')
<div class="entry-grid">
    <section class="entry-copy" aria-labelledby="entry-heading">
        <div class="eyebrow">One familiar Everbranch sign-in</div>
        <h1 id="entry-heading">Your workspace.<br>Your counter.</h1>
        <p class="intro">Sign in to Everbranch, then choose a workspace you already have permission to access.</p>
        <a class="button primary entry-cta" href="{{ route('login') }}">Continue to Everbranch sign-in <span aria-hidden="true">→</span></a>
        <p class="helper">Use your existing Everbranch account. Google sign-in is available through the usual Everbranch sign-in screen when configured.</p>
        <div class="access-note"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6l-8-3Z"/><path d="m8 12 3 3 5-6"/></svg><div><strong>Your identity comes first.</strong><p>Signing in does not create a workspace membership, change your role or connect a payment account.</p></div></div>
    </section>
    <aside class="panel entry-panel" aria-labelledby="arrival-heading">
        <div class="terminal-graphic" aria-hidden="true"><div class="graphic-screen"><span class="graphic-mark">e</span><div class="graphic-line"></div><div class="graphic-line short"></div><div class="graphic-status">WORKSPACE ACCESS</div></div><div class="graphic-stand"></div><div class="graphic-base"></div></div>
        <div class="eyebrow">A clear place to begin</div><h2 id="arrival-heading">Three steps to your workspace</h2>
        <ol class="steps"><li><strong>Sign in as yourself</strong><p>Use the existing Everbranch login, including your usual Google account if applicable.</p></li><li><strong>Select your workspace</strong><p>Choose from your active memberships. Your role travels with your account.</p></li><li><strong>Review the terminal shell</strong><p>Confirm the selected workspace and current access. Live payments remain unavailable.</p></li></ol>
    </aside>
</div>
<section class="readiness-note" aria-labelledby="entry-readiness"><div class="status-icon" aria-hidden="true">i</div><div><h2 id="entry-readiness">Sign-in is separate from payment readiness.</h2><p>This terminal entry does not request Stripe credentials, start merchant onboarding or pair a reader. A saved Stripe connection does not verify Terminal or device compatibility.</p></div></section>
@endsection
