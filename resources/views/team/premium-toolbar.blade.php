<header class="teams-work-header">
    <div class="teams-work-heading">
        <div class="teams-breadcrumb"><button type="button" class="teams-menu-toggle" data-teams-menu aria-label="Open navigation" aria-expanded="false">☰</button><span id="tw-workspace-label">Workspace: {{ $tenant->name }}</span><span>/</span><button id="tw-workspaces" type="button">Change workspace</button></div>
        <h1 id="tw-page-title">Home</h1>
        @unless($standalone)<a href="{{ route('team.app', ['tenant' => $tenant->slug]) }}" class="team-app-entry" data-team-surface-link>Open {{ $teamAppName }}</a>@endunless
    </div>
    <div class="actions teams-heading-actions">
        @unless($standalone)<button type="button" data-team-settings aria-haspopup="dialog">Teams settings</button>@endunless
        <button id="tw-status-resume" type="button" hidden>Resume status draft</button><button id="tw-templates">Templates</button><button id="tw-new-project">New project</button><button id="tw-new-task" class="primary">+ Create task</button>
    </div>
</header>
<section id="tw-home" class="card" aria-label="Personal work overview" hidden><div id="tw-home-counts" class="actions"></div><p id="tw-home-context" class="muted"></p></section>
<section class="teams-work-toolbar" aria-label="Work controls">
<div class="teams-work-viewbar">
    <div class="teams-tabs" role="tablist" aria-label="Work layout"><button type="button" data-team-layout="list" role="tab" aria-selected="true">List</button><button type="button" data-team-layout="board" role="tab" aria-selected="false">Board</button><button type="button" data-team-layout="calendar" role="tab" aria-selected="false">Calendar</button></div>
    <div data-team-universal-search class="teams-universal-search"></div>
    <div class="teams-work-view-tools"><label><span class="sr-only">More work views</span><select id="tw-more-views"><option value="">More views</option><option value="timeline">Timeline</option><option value="workload">Workload</option><option value="dashboard">Dashboard</option></select></label><button id="tw-filter-toggle" type="button" aria-expanded="false" aria-controls="tw-controls-panel">Controls</button></div>
</div>
<div id="tw-controls-panel" class="teams-controls-panel" hidden>
<div class="teams-work-scope">
    <label class="teams-search-wrap" data-team-search-icon><span>Filter this view</span><input id="tw-search" type="search" placeholder="Filter this view…"></label>
    <label>Project<select id="tw-project-filter" aria-label="Project"><option value="">All accessible projects</option></select></label>
    <label>Source<select id="tw-source-filter" aria-label="Source"><option value="all">All work</option><option value="native">Teams</option>@if(config('team_work.asana_enabled'))<option value="asana">Asana</option>@endif</select></label>
</div>
<div id="tw-filters" class="teams-filters">
    <label>View<select id="tw-mode"><option value="home">Home</option><option value="all">All work</option><option value="mine">My Tasks</option><option value="inbox">Inbox</option></select></label>
    <label>Layout<select id="tw-layout"><option value="list">List</option><option value="board">Board</option><option value="calendar">Calendar (UTC)</option><option value="timeline">Timeline</option><option value="workload">Workload</option><option value="dashboard">Dashboard</option></select></label>
    <label>Completion<select id="tw-completion"><option value="incomplete">Incomplete</option><option value="all">All tasks</option><option value="completed">Completed</option></select></label>
    <label>Priority filter<select id="tw-priority-filter"><option value="">Any priority</option><option>low</option><option>normal</option><option>high</option><option>urgent</option></select></label>
    <label>Due date filter<select id="tw-date-filter"><option value="all">Any date</option><option value="today">Today</option><option value="tomorrow">Tomorrow</option><option value="overdue">Overdue incomplete</option><option value="week">This week</option><option value="undated">No due date</option></select></label>
    <label>Sort tasks<select id="tw-sort"><option value="">Project order / default</option><option value="due">Due date</option><option value="start">Start date</option><option value="priority">Priority</option><option value="created">Created date (oldest first)</option><option value="title">Title</option></select></label><button id="tw-clear-filters" type="button">Reset filters</button>
</div>
<div class="teams-work-calendar-options"><label id="tw-month-label" hidden>Calendar month<input id="tw-month" type="month"></label><label id="tw-calendar-format-label" hidden>Calendar format<select id="tw-calendar-style" aria-label="Calendar format" hidden><option value="grid">Month grid (UTC)</option><option value="agenda">Agenda (UTC)</option></select></label><button id="tw-event-calendar-entry" type="button" hidden>Event calendar</button><p id="tw-task-calendar-context" hidden>Task due dates use UTC. Event calendars keep reviewed setup and selling days separate.</p></div>
<details id="tw-view-tools" class="teams-work-secondary" open>
<summary>Saved views &amp; connections</summary>
<div class="teams-work-view-options">
    <div class="teams-work-saved"><label>Saved work views<select id="tw-saved-views" aria-label="Saved work views"><option value="">Choose a saved view</option></select></label><div class="teams-work-saved-actions"><button id="tw-save-view" type="button">Save this view</button><button id="tw-remove-view" type="button" disabled>Remove selected view</button></div></div>
    @if(config('team_work.asana_enabled'))<button id="tw-asana-manage" type="button">Asana connection ↗</button>@endif
</div>
<div class="teams-work-project-actions"><button id="tw-refresh" type="button" aria-label="Refresh work">↻ Refresh</button><button id="tw-favorite-project" hidden>☆ Star project</button><button id="tw-overview" type="button" hidden>Overview</button><button id="tw-edit-project" hidden>Settings</button></div>
</details>
</div>
@if(config('team_work.asana_enabled'))<p id="tw-asana-notice" class="teams-work-source-notice muted" role="status"></p>@endif
</section>
<div id="tw-status" role="status" aria-live="polite"></div>
<dialog id="tw-view-dialog" aria-labelledby="tw-view-heading"><form id="tw-view-form"><h2 id="tw-view-heading">Save view</h2><p class="muted">Save the current Teams project, layout, completion, date filter and sort for your account.</p><label>View name<input id="tw-view-name" required maxlength="80"></label><div class="actions"><button class="primary">Save view</button><button id="tw-view-close" type="button">Cancel</button></div></form></dialog>
