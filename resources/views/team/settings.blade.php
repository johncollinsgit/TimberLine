<dialog id="tw-settings-dialog" class="teams-settings-dialog" aria-labelledby="tw-settings-heading">
    <header class="teams-settings-header">
        <div><p class="teams-settings-kicker">{{ $tenant->name }}</p><h2 id="tw-settings-heading">Teams settings</h2><p class="muted">Keep your account and work connected.</p></div>
        <button type="button" id="tw-settings-close">Back to work</button>
    </header>
    <div class="teams-settings-layout">
        <nav class="teams-settings-tabs" aria-label="Teams settings sections">
            <button type="button" data-team-settings-tab="profile" aria-current="page">Your profile</button>
            <button type="button" data-team-settings-tab="connections">Connections</button>
            <button type="button" data-team-settings-tab="reminders">Reminders</button>
        </nav>
        <div class="teams-settings-content">
            <section data-team-settings-panel="profile" aria-label="Your profile">
                <livewire:pages::settings.profile :team-tenant-id="$tenant->id" />
            </section>
            <section data-team-settings-panel="connections" aria-label="Connection settings" hidden>
                <h3>Connected accounts</h3><p>Connect Asana, Gmail and Sheets for each work area. Your account access and selected sources stay separate for Events and Wholesale.</p>
                <p class="muted">Choose a work area to manage its connections inside Teams. Provider authorization may open the provider’s consent page and return here.</p>
                <div id="tw-settings-connections" class="teams-settings-areas"></div>
            </section>
            <section data-team-settings-panel="reminders" aria-label="Reminder settings" hidden>
                <h3>Reminders &amp; briefings</h3><p>Manage schedules, audiences, timezones and quiet hours for each work area.</p>
                @if(in_array($actor['role'], ['owner', 'admin']))
                    <div id="tw-settings-reminders" class="teams-settings-areas"></div>
                    <p class="muted">External email and text delivery require their own enabled sender and approved rules.</p>
                @else
                    <p class="muted">A workspace administrator manages reminder rules. Your Teams Inbox shows reminders you are permitted to receive.</p>
                @endif
            </section>
            <p id="tw-settings-notice" class="teams-settings-notice" role="status" aria-live="polite"></p>
        </div>
    </div>
</dialog>
