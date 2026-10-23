<section class="panel stack" id="read-only-assistant" aria-labelledby="read-only-assistant-title" hidden>
  <h3 id="read-only-assistant-title">Read-only COO assistant</h3>
  <p>Check saved work, linked building requests or recorded costs. Each answer includes the records it used.</p>
  <p class="caption" id="read-only-label"></p>
  <form id="read-only-form" class="stack">
    <label>What would you like to check?<select id="read-only-intent"><option value="overview">Current work overview</option><option value="building">What's happening in this building?</option><option value="costs" id="read-only-cost-option">Recorded costs versus estimates</option></select></label>
    <label id="read-only-building-field" hidden>Saved building<select id="read-only-building"></select></label>
    <p class="caption" id="read-only-building-coverage" hidden></p>
    <button class="button primary" type="submit" id="read-only-submit">Check current records</button>
  </form>
  <p id="read-only-status" role="status" aria-live="polite"></p>
  <div id="read-only-answer" class="stack"></div>
  <p class="caption">No approved budget or staffing capacity is inferred. This assistant does not generate AI text, remember conversations, change work or send messages.</p>
</section>
