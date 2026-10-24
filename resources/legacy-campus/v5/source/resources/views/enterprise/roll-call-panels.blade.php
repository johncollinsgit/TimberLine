<section id="roll-call-panel" class="stack" aria-label="Roll Call and meetings" hidden>
    <style>
        .roll-toolbar{display:flex;gap:14px;align-items:end;flex-wrap:wrap}.roll-toolbar label{display:grid;gap:6px;font-size:12px;min-width:160px}.roll-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:20px}.roll-priority{border-bottom:1px solid var(--line);padding:18px 0;overflow-wrap:anywhere}.roll-priority h3{font-size:17px;margin:8px 0}.roll-priority dl{display:grid;grid-template-columns:90px 1fr;gap:6px;font-size:12px}.roll-priority dt{color:#657364}.roll-priority dd{margin:0}.roll-note{border:1px solid var(--line);padding:14px;border-radius:9px;min-width:0}.roll-note textarea{min-height:78px;width:100%;resize:vertical}.roll-note input,.roll-note select{width:100%;min-height:44px}.roll-note-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.roll-meeting-list{display:flex;flex-wrap:wrap;gap:8px}.roll-preview-action{padding:13px 0;border-bottom:1px solid var(--line);overflow-wrap:anywhere}.roll-confirm-bar{border:1px solid #9da58d;background:#f0f3e8;padding:16px;border-radius:10px}.roll-heading-row{display:flex;justify-content:space-between;gap:12px;align-items:center}.roll-heading-row h3{margin:6px 0}.roll-status{white-space:pre-wrap;overflow-wrap:anywhere}.roll-meeting-footer{position:relative;background:var(--paper,#fafaf5);padding:14px 0 max(14px,env(safe-area-inset-bottom))}#roll-call-panel button,#roll-call-panel a.button{min-height:44px}#roll-call-panel :focus-visible{outline:3px solid #718d59;outline-offset:3px}#roll-call-panel .caption{line-height:1.6}#roll-call-panel fieldset{border:0;padding:0;margin:0;min-width:0}@media(max-width:1180px){.roll-grid{grid-template-columns:1fr}.roll-toolbar label{flex:1;min-width:150px}}@media(max-width:650px){.roll-note-grid{grid-template-columns:1fr}.roll-toolbar{display:grid;grid-template-columns:1fr}.roll-toolbar label{min-width:0}.roll-note textarea,.roll-note input,.roll-note select{font-size:16px}.roll-heading-row{align-items:flex-start;flex-wrap:wrap}}
    </style>
    <style>.roll-confirm-bar>label{min-height:44px;display:flex;align-items:center}.roll-confirm-bar input[type=checkbox]{width:22px;min-height:22px;margin-right:8px;accent-color:#315b43}</style>
    <style>
        .roll-project-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.roll-project-head h2{margin:0;font:22px Georgia,serif}.roll-project-tools{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.roll-project-tools label{display:grid;gap:5px;font-size:12px;min-width:140px;flex:1}.roll-project-tools input,.roll-project-tools select{width:100%;min-height:44px;font:inherit}.roll-project-search{display:grid;gap:6px;font-size:12px}.roll-project-search input{width:100%;min-height:48px;padding:10px;font:inherit;border:1px solid var(--line);border-radius:6px}.roll-project-table-wrap{overflow-x:auto}.roll-project-table{width:100%;border-collapse:collapse;text-align:left}.roll-project-table th{font-size:12px;color:#536451;padding:12px;border-bottom:1px solid var(--line);white-space:nowrap}.roll-project-table td{padding:12px;border-bottom:1px solid var(--line);font-size:13px;vertical-align:top}.roll-project-table th:first-child{width:28%}.roll-project-table .roll-open-project{font:inherit;font-weight:600;text-align:left;background:none;border:0;color:#175440;padding:0;min-height:44px;cursor:pointer}.roll-project-table .caption{margin:0}.roll-member-chips{display:flex;flex-wrap:wrap;gap:5px}.roll-member-chip{display:inline-block;padding:4px 7px;background:#edf2ed;border-radius:12px;font-size:12px}.roll-secondary{border:1px solid var(--line);border-radius:8px;padding:0 16px}.roll-secondary>summary{min-height:44px;display:flex;align-items:center;cursor:pointer;font-size:13px;font-weight:600}.roll-secondary[open]>summary{margin-bottom:12px}.roll-secondary .panel{box-shadow:none}.roll-secondary .roll-grid{margin-bottom:16px}.roll-project-status{margin:0;font-size:12px;line-height:1.5}.roll-project-empty{padding:22px 12px}.roll-project-table tbody tr:hover{background:#f4f6f1}#roll-call-panel .roll-project-summary{margin:0;font-size:12px}#roll-call-panel details>summary:focus-visible{outline:3px solid #718d59;outline-offset:3px}@media(max-width:650px){.roll-project-tools{display:grid;grid-template-columns:1fr 1fr}.roll-project-tools label{min-width:0}.roll-project-tools .button{width:100%}.roll-project-table{min-width:680px}.roll-project-head{flex-wrap:wrap}.roll-project-head .button{flex:1}.roll-project-tools select{font-size:16px}}
        #work-drawer[data-entity-type="project"]{inset:0;margin:auto;width:min(1180px,calc(100vw - 48px));height:min(900px,calc(100dvh - 48px));max-width:none;max-height:calc(100dvh - 48px);border-radius:12px}.project-overview{display:grid;gap:16px}.project-overview h2{margin:0!important}.project-detail-facts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;padding:16px;border:1px solid var(--line);border-radius:8px}.project-detail-facts dt{font-size:12px;color:#536451;margin-bottom:6px}.project-detail-facts dd{margin:0;overflow-wrap:anywhere;font-size:14px}.project-detail-description{white-space:pre-wrap;overflow-wrap:anywhere;margin:0}.project-detail-actions{display:flex;gap:8px;flex-wrap:wrap}.project-detail-actions a{min-height:44px}.project-detail-help{font-size:12px}.project-detail-help summary{min-height:44px;display:flex;align-items:center;cursor:pointer}.project-detail-help p{margin:0 0 12px}.project-detail-roster{display:flex;gap:8px;flex-wrap:wrap}.project-detail-roster span{padding:6px 9px;background:#edf2ed;border-radius:14px;font-size:12px}.project-detail-tasks{width:100%;border-collapse:collapse;text-align:left}.project-detail-tasks td,.project-detail-tasks th{padding:9px;border-bottom:1px solid var(--line);font-size:13px}.project-detail-tasks th{font-size:12px}.project-summary-extra{font-size:13px}.project-summary-extra:empty{display:none}@media(max-width:650px){#work-drawer[data-entity-type="project"]{width:calc(100vw - 16px);height:calc(100dvh - 16px);max-height:calc(100dvh - 16px);border-radius:8px}.project-detail-facts{grid-template-columns:1fr 1fr}.project-detail-tasks{min-width:550px}}
    </style>
    <section class="panel stack" aria-labelledby="roll-projects-heading">
        <div class="roll-project-head"><h2 id="roll-projects-heading">Projects &amp; people</h2><div class="actions"><button class="button" id="roll-project-incoming" type="button">Incoming tickets</button><button class="button primary" id="roll-project-create" type="button" hidden>Create project</button><button class="button" id="roll-projects-refresh" type="button">Refresh projects</button></div></div>
        <label class="roll-project-search">Search projects<input id="roll-projects-search" type="search" placeholder="Project, person or location" maxlength="200"></label>
        <div class="roll-project-tools">
            <label>Workspace<select id="roll-projects-area"><option value="">All I can access</option></select></label>
            <label>In-house person<select id="roll-projects-person"><option value="">Any person</option></select></label>
            <label>Contractor<select id="roll-projects-contractor"><option value="">Any contractor</option></select></label>
            <label>Project status<select id="roll-projects-status"><option value="">Any status</option></select></label>
            <label>Location<select id="roll-projects-location"><option value="">Any location</option></select></label>
            <label>Sort by<select id="roll-projects-sort"><option value="project">Project name</option><option value="workspace">Workspace / department</option><option value="person">Assigned person</option><option value="updated">Recently updated</option></select></label>
            <button class="button" id="roll-projects-clear" type="button">Clear filters</button>
        </div>
        <p id="roll-projects-status-message" class="roll-project-status" role="status" aria-live="polite">Checking permitted projects…</p>
        <div class="roll-project-table-wrap"><table class="roll-project-table" aria-label="Permitted projects and assigned people"><thead><tr><th scope="col">Project</th><th scope="col">Owner / in-house</th><th scope="col">Contractors</th><th scope="col">Status / work</th><th scope="col">Location</th><th scope="col">Updated</th></tr></thead><tbody id="roll-projects-body"></tbody></table></div>
        <details class="project-detail-help"><summary>About this view</summary><p id="roll-projects-coverage">Only current permitted projects and recorded assignments. Location means saved work location, not live tracking.</p></details>
    </section>
    <details id="roll-meeting-tools" class="roll-secondary"><summary>Priorities &amp; meeting notes</summary>
    <div class="panel stack">
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
            <p class="caption">Private leader notes. Task changes require a separate preview and confirmation.</p>
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
    </details>
</section>
