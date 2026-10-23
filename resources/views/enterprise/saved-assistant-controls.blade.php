<section id="saved-assistants" class="stack" hidden aria-labelledby="saved-assistants-title">
  <h4 id="saved-assistants-title">Your saved assistants</h4>
  <label>Use an assistant<select id="saved-assistant-choice"><option value="">Default current-work reader</option></select></label>
  <p class="caption" id="saved-assistant-summary">A saved purpose and instructions never add permissions or tools.</p>
  <div class="actions"><button type="button" class="button" id="saved-assistant-new">Make an Everbranch Assistant</button><button type="button" class="button" id="saved-assistant-edit" hidden>Inspect or edit assistant</button><button type="button" class="button" id="saved-assistant-refresh">Refresh saved assistants</button></div>
  <p id="saved-assistant-status" role="status" aria-live="polite"></p>
  <form id="saved-assistant-form" class="stack" hidden aria-label="Private assistant configuration">
    <h4 id="saved-assistant-edit-title">Make an Everbranch Assistant</h4>
    <label>Name<input id="saved-assistant-name" maxlength="80" required></label>
    <label>Purpose<textarea id="saved-assistant-purpose" maxlength="250" rows="2" required></textarea></label>
    <label>Instructions<textarea id="saved-assistant-instructions" maxlength="500" rows="3" required></textarea></label>
    <fieldset><legend>Read-only checks</legend><p class="caption">Only checked, currently available checks are saved.</p><label><input type="checkbox" value="overview" data-saved-assistant-check> Current work</label><label><input type="checkbox" value="building" data-saved-assistant-check> Saved building work</label><label><input type="checkbox" value="costs" data-saved-assistant-check> Recorded costs and estimates</label></fieldset>
    <p class="caption">Private to your account and this department. Save style and focus here, never passwords, keys or facts you need kept current. Memory uses a separate explicit choice. No provider, model, send or work-write tool can be added.</p>
    <label class="saved-assistant-confirm"><input type="checkbox" id="saved-assistant-confirm"> I reviewed this private configuration. It changes no work or access.</label>
    <div class="actions"><button type="submit" class="button primary" id="saved-assistant-save">Save private assistant</button><button type="button" class="button" id="saved-assistant-cancel">Close configuration</button><button type="button" class="button" id="saved-assistant-delete" hidden>Delete saved assistant</button></div>
    <p class="caption" id="saved-assistant-provenance"></p>
  </form>
  <div id="saved-assistant-delete-confirm" class="stack" hidden><p>Delete this saved assistant's name, purpose and instructions? Work records, conversation content and spend receipts stay unchanged.</p><div class="actions"><button type="button" class="button" id="saved-assistant-delete-yes">Delete this private assistant</button><button type="button" class="button" id="saved-assistant-delete-no">Keep assistant</button></div></div>
</section>
