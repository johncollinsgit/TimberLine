<section id="contractors" class="next-panel" hidden>
  <h2>Contractors &amp; plans</h2><p class="subtle" id="planning-status">Planning is not enabled for this workspace. No contractor access is granted here.</p>
  <div class="planning-grid" id="planning-work" hidden><div class="panel"><h3>Department records</h3><div id="planning-list"></div></div>
  <form id="planning-form" class="panel stack" hidden><h3>Record a plan</h3>
    <label>Record type<select name="kind" id="planning-kind"><option value="contractor">Contractor</option><option value="schedule">Planned date block</option><option value="cost" id="cost-option">Cost record</option></select></label>
    <label>Record title<input name="title" maxlength="100" required></label>
    <label id="planning-project-field" hidden>Project<select name="project_id" id="planning-project"></select></label>
    <div id="planning-schedule-fields" class="stack" hidden><label>Contractor<select name="contractor_id" id="planning-contractor"></select></label><div class="field-pair"><label>Start date<input name="starts_on" type="date"></label><label>End date (inclusive)<input name="ends_on" type="date"></label></div></div>
    <div id="planning-cost-fields" class="stack" hidden><label>Amount in minor units (USD cents)<input name="amount_minor" type="number" min="0" max="1000000000" step="1"></label><label>Currency<select name="currency"><option>USD</option><option>CAD</option><option>EUR</option><option>GBP</option></select></label><label>Basis<select name="basis"><option value="estimate">Estimate</option><option value="recorded_cost">Manually recorded cost</option></select></label></div>
    <p class="caption">Date blocks check overlaps for the same contractor in this department. No account, purchase, reservation, payment or notification is created. Costs are private to organization owners/admins.</p>
    <button class="button primary" type="submit" id="planning-save">Save record</button><button class="button" type="button" id="planning-retry" hidden>Retry the same record</button>
  </form></div>
</section>
<section id="ask" class="next-panel" hidden>
  @if($enterpriseSample)
  <div id="reviewer-ai-coming-soon" aria-labelledby="reviewer-ai-heading">
    <h2 id="reviewer-ai-heading">AI tools</h2>@unless(($enterprisePresentation['reviewerAi']['enabled'] ?? false) === true)<p class="subtle">Coming soon. Continue exploring projects, tickets and team updates in this demo.</p>@endunless
    @if(($enterprisePresentation['reviewerAi']['enabled'] ?? false) === true)
    @include('enterprise.proposal-ai-chat')
    @endif
    <div class="planning-grid" style="grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr))">
      @unless(($enterprisePresentation['reviewerAi']['enabled'] ?? false) === true)<article class="panel"><h3>Chats</h3><p class="badge">Coming soon</p><p>Ask about your workspace in a conversation.</p></article>@endunless
      <article class="panel"><h3>Assistants</h3><p class="badge">Coming soon</p><p>Keep a focused assistant for recurring work.</p></article>
      @unless(($enterprisePresentation['reviewerAi']['enabled'] ?? false) === true)<article class="panel"><h3>Voice</h3><p class="badge">Coming soon</p><p>Talk with Everbranch about your work.</p></article>@endunless
    </div>
  </div>
  @endif
  <div id="native-ask-workspace" @if($enterpriseSample) hidden inert @endif>
  <h2>Ask Everbranch</h2><p class="subtle">Signed in as {{ $enterprisePresentation['identity']['userName'] }} · {{ $enterpriseRole ?? 'current authorized role' }}. Answers use your selected workspace and current permissions.</p>
  <p class="caption" id="ask-model-caption">Answers use server-checked records and links. Approved model access can prioritize those sources. Chat cannot send email or perform work changes.</p>
  @include('enterprise.read-only-assistant')
  <div class="ask-grid"><div class="panel" id="generated-ask-panel">
  @include('enterprise.conversation-controls')
  <div id="chat-log" class="chat-log" role="log" aria-live="polite"><p class="caption">Ask about visible requests, project status or recorded plans. Each turn checks current access. Chat is per page; old answers are not reused as evidence.</p></div>
  <form id="ask-form" class="stack ask-composer"><label><span id="ask-question-label">Message Ask Everbranch</span><textarea id="ask-question" maxlength="1500" required placeholder="What is planned in this workspace?"></textarea></label><button class="button primary" type="submit" id="ask-submit">Ask</button></form></div>
  @include('enterprise.voice-pane')
  <div class="panel stack" id="email-context-panel" @if($enterpriseSample) hidden @endif><h3>Email in your context</h3><p class="caption" id="mailbox-status">No delivery transport connected. No mailbox messages are read or sent.</p>
  <section class="stack" aria-label="Your Google Workspace connection"><p class="caption" id="gmail-status">Google Workspace setup and your explicit consent are required. Inbox contents are never read.</p><div class="actions"><button class="button" type="button" id="gmail-connect" disabled>Connect Google Workspace</button><button class="button" type="button" id="gmail-disconnect" hidden>Disconnect Gmail</button></div></section>
  <form id="email-form" class="stack" hidden><label>Your authorized sender<select id="email-mailbox"></select></label><label>Exact recipient<input id="email-to" type="email" maxlength="254" required></label><label>Subject<input id="email-subject" maxlength="200" required></label><label>Message body<textarea id="email-body" maxlength="10000" rows="6" required></textarea></label><button class="button" type="submit">Preview exact draft</button></form>
  <div id="email-preview" hidden></div><button class="button primary" id="email-confirm" hidden>Confirm this exact email</button>
  <p class="caption">Sending requires your own explicitly authorized mailbox, the exact recipient/subject/body preview and a separate confirmation. Connecting Gmail does not send a message. Disconnecting revokes this application's Google access and may require reconnecting your other departments using the same Google account. Provider acceptance is distinct from delivery.</p></div></div>
  </div>
</section>
