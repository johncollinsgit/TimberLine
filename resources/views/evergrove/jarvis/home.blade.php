@extends('evergrove.jarvis.layout')

@section('jarvis-content')
    <section class="jarvis-hero" aria-labelledby="jarvis-title">
        <div class="jarvis-hero-copy">
            <p class="jarvis-kicker">Your work. A little more connected.</p>
            <h1 id="jarvis-title">Make room for<br><em>what’s next.</em></h1>
            <p class="jarvis-lede">Jarvis is a personal desktop assistant for conversations, tasks, and calendar context. Connect the accounts you choose and bring the work ahead into focus.</p>
            <div class="jarvis-actions">
                <a class="jarvis-button" href="#connections">Explore Google connections <span aria-hidden="true">↗</span></a>
                <a class="jarvis-text-link" href="mailto:johncollinsemail@gmail.com">Ask about Jarvis</a>
            </div>
            <p class="jarvis-small">Google access is optional and requires your account’s consent.</p>
        </div>
        <aside class="jarvis-orbit" aria-label="Jarvis assistant">
            <div class="jarvis-orbit-ring" aria-hidden="true"></div>
            <img src="{{ asset('brand/jarvis-mark.svg') }}" alt="Jarvis J logo" width="150" height="150">
            <p>Conversations · Tasks · Calendar</p>
            <span>One place to think through the next step.</span>
        </aside>
    </section>

    <section class="jarvis-section" aria-labelledby="work-title">
        <p class="jarvis-kicker">A practical companion</p>
        <h2 id="work-title">Keep context close.</h2>
        <div class="jarvis-card-grid">
            <article class="jarvis-card"><span class="jarvis-card-number">01</span><h3>Talk it through</h3><p>Use conversation to think through your work, with locally saved chat and task records.</p></article>
            <article class="jarvis-card"><span class="jarvis-card-number">02</span><h3>Read what matters</h3><p>A separately authorized Gmail connection can search and read messages and threads for the context you request.</p></article>
            <article class="jarvis-card"><span class="jarvis-card-number">03</span><h3>See the work ahead</h3><p>Bring calendar and task context into your planning. Available actions depend on the installed version and enabled features.</p></article>
        </div>
    </section>

    <section id="connections" class="jarvis-connection-section" aria-labelledby="connections-title">
        <div><p class="jarvis-kicker">Connect with intention</p><h2 id="connections-title">Your Google account.<br>Your decision.</h2><p>Each account authorizes Jarvis separately through Google. Review the permissions on the consent screen before continuing.</p><a class="jarvis-text-link" href="/jarvis/privacy#google-access">Read the Google data disclosures →</a></div>
        <div class="jarvis-connection-list">
            <article><h3>Gmail reading</h3><p>A separate read-only grant permits message search and reading. The reader extracts bounded plain-text content from selected thread responses. Google’s response can include other message parts; Jarvis does not display HTML or request separate attachment downloads.</p></article>
            <article><h3>Compose and owned calendars</h3><p>The dedicated connection requests Gmail compose and owned-calendar event permissions, calendar-list reading, and account identity. Google’s granted permissions include managing drafts, sending email, and changing or deleting owned events; granting permission does not mean every action is enabled in Jarvis.</p></article>
            <article><h3>Disconnect when you choose</h3><p>Remove Jarvis access in your Google Account connections. Revoking access does not automatically erase records already saved on your computer.</p></article>
        </div>
    </section>

    <section class="jarvis-closing" aria-labelledby="questions-title"><p class="jarvis-kicker">Understand before you connect</p><h2 id="questions-title">Clear expectations.<br>A better starting point.</h2><div class="jarvis-actions"><a class="jarvis-button" href="/jarvis/privacy">Read the privacy notice</a><a class="jarvis-text-link" href="/jarvis/terms">Review terms of use →</a></div></section>
@endsection
