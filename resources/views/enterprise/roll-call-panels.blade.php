<section id="roll-call-panel" class="stack" aria-label="Roll Call and meetings" hidden>
    <style>
        .roll-toolbar{display:flex;gap:14px;align-items:end;flex-wrap:wrap}.roll-toolbar label{display:grid;gap:6px;font-size:12px;min-width:160px}.roll-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:20px}.roll-priority{border-bottom:1px solid var(--line);padding:18px 0;overflow-wrap:anywhere}.roll-priority h3{font-size:17px;margin:8px 0}.roll-priority dl{display:grid;grid-template-columns:90px 1fr;gap:6px;font-size:12px}.roll-priority dt{color:#657364}.roll-priority dd{margin:0}.roll-note{border:1px solid var(--line);padding:14px;border-radius:9px;min-width:0}.roll-note textarea{min-height:78px;width:100%;resize:vertical}.roll-note input,.roll-note select{width:100%;min-height:44px}.roll-note-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.roll-meeting-list{display:flex;flex-wrap:wrap;gap:8px}.roll-preview-action{padding:13px 0;border-bottom:1px solid var(--line);overflow-wrap:anywhere}.roll-confirm-bar{border:1px solid #9da58d;background:#f0f3e8;padding:16px;border-radius:10px}.roll-heading-row{display:flex;justify-content:space-between;gap:12px;align-items:center}.roll-heading-row h3{margin:6px 0}.roll-status{white-space:pre-wrap;overflow-wrap:anywhere}.roll-meeting-footer{position:relative;background:var(--paper,#fafaf5);padding:14px 0 max(14px,env(safe-area-inset-bottom))}#roll-call-panel button,#roll-call-panel a.button{min-height:44px}#roll-call-panel :focus-visible{outline:3px solid #718d59;outline-offset:3px}#roll-call-panel .caption{line-height:1.6}#roll-call-panel fieldset{border:0;padding:0;margin:0;min-width:0}@media(max-width:1180px){.roll-grid{grid-template-columns:1fr}.roll-toolbar label{flex:1;min-width:150px}}@media(max-width:650px){.roll-note-grid{grid-template-columns:1fr}.roll-toolbar{display:grid;grid-template-columns:1fr}.roll-toolbar label{min-width:0}.roll-note textarea,.roll-note input,.roll-note select{font-size:16px}.roll-heading-row{align-items:flex-start;flex-wrap:wrap}}
    </style>
    <style>.roll-confirm-bar>label{min-height:44px;display:flex;align-items:center}.roll-confirm-bar input[type=checkbox]{width:22px;min-height:22px;margin-right:8px;accent-color:#315b43}</style>
    <div class="panel stack">
        <div class="eyebrow">Current work. Clear next steps.</div>
        <h2>Roll Call</h2>
        <p class="subtle">Keep the operational Roll Call tickets handed to technicians on paper connected to shared owners, status and updates. Review daily work here and retain an optional dated printout; this is work dispatch, not attendance.</p>
        <p class="caption" id="roll-work-mode"></p>
        <form id="roll-scope-form" class="roll-toolbar">
            <label>Meeting scope<select id="roll-scope" aria-label="Meeting scope"><option value="workspace">Selected workspace</option><option value="campus">Campus I can access</option><option value="project">One project</option><option value="team">Assigned person</option></select></label>
            <label id="roll-project-label" hidden>Project<select id="roll-project" aria-label="Scope project"></select></label>
            <label id="roll-person-label" hidden>Person<select id="roll-person" aria-label="Person"></select></label>
            <button class="button primary" type="submit">Refresh current work</button>
            <button class="button" type="button" id="roll-my-work" hidden>My assigned daily work</button>
        </form>
        <p class="caption" id="roll-method"></p>
        <p class="roll-status" id="roll-status" role="status" aria-live="polite"></p>
    </div>
    <div class="roll-grid">
        <section class="panel" aria-labelledby="roll-agenda-heading"><h2 id="roll-agenda-heading">Priorities &amp; agenda</h2><div id="roll-priorities"></div><p class="caption" id="roll-coverage"></p></section>
        <section class="panel stack" aria-labelledby="roll-meeting-heading">
            <div class="roll-heading-row"><h2 id="roll-meeting-heading">Meeting notes</h2><button class="button" id="roll-new" type="button" hidden>New meeting</button></div>
            <p class="caption">Decisions, blockers and commitments stay private to the meeting leader. Saving notes does not create or change tasks.</p>
            <div class="roll-meeting-list" id="roll-meetings" aria-label="Your saved meetings"></div>
            <p class="caption" id="roll-editor-message"></p>
            <form id="roll-meeting-form" class="stack" hidden>
                <fieldset id="roll-meeting-fields" class="stack">
                    <label>Meeting title<input id="roll-meeting-title" maxlength="100" required></label>
                    <div class="roll-heading-row"><h3>Decisions</h3><button class="button" type="button" data-roll-add="decisions">Add decision</button></div>
                    <div id="roll-decisions" class="stack"></div>
                    <div class="roll-heading-row"><h3>Blockers</h3><button class="button" type="button" data-roll-add="blockers">Add blocker</button></div>
                    <div id="roll-blockers" class="stack"></div>
                    <div class="roll-heading-row"><h3>Commitments</h3><button class="button" type="button" data-roll-add="commitments">Add commitment</button></div>
                    <p class="caption">Commitment selectors use existing authorized owners and projects in the selected workspace. A task change requires a saved meeting, an exact preview and your separate confirmation. Reassignment and resumption stay in Operations.</p>
                    <div id="roll-commitments" class="stack"></div>
                </fieldset>
                <div class="actions roll-meeting-footer">
                    <button class="button primary" id="roll-save" type="submit">Save meeting notes</button>
                    <button class="button" id="roll-preview" type="button" disabled>Review task changes</button>
                    <button class="button" id="roll-pdf" type="button" disabled>Download fresh one-page PDF</button>
                    <button class="button" id="roll-retry" type="button" hidden>Retry the same request</button>
                    <button class="button" id="roll-reload" type="button" hidden>Review current saved version</button>
                </div>
            </form>
            <section id="roll-preview-panel" class="roll-confirm-bar stack" aria-label="Exact task change preview" hidden>
                <h3>Review these exact task changes</h3><p class="caption">The task preview expires after 15 minutes. No task changes happen until you confirm.</p>
                <div id="roll-preview-actions"></div><p class="caption" id="roll-preview-meta"></p>
                <label><span><input type="checkbox" id="roll-confirm-checkbox"> I reviewed the owners, projects, dates and exact changes shown above.</span></label>
                <button class="button primary" id="roll-confirm" type="button" disabled>Confirm these task changes</button>
            </section>
        </section>
    </div>
</section>
