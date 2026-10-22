<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Getting started with Everbranch Fleet</title>
    <style>
        body{margin:0;padding:24px;font:16px system-ui,sans-serif;line-height:1.6;color:#17342a;background:#f5f7f4}
        main{max-width:760px;margin:0 auto;padding:clamp(20px,5vw,40px);background:white;border:1px solid #dce5df;border-radius:20px}
        h1,h2{line-height:1.2}h2{margin-top:32px}li{margin-bottom:16px}a{color:#175c43}strong{font-weight:650}
        aside{padding:16px;border-radius:12px;background:#edf4ef}ol{padding-left:24px}
    </style>
</head>
<body>
<main>
    <strong>Everbranch Fleet</strong>
    <h1>Your company vehicles, inside your CRM</h1>
    <p>Each installed client account receives its own workspace. Fleet supports one connected Bouncie account and up to {{ config('highlevel.vehicle_limit') }} selected company vehicles.</p>
    <p><a href="/crm/fleet/demo"><strong>Explore the guided fictional fleet demo</strong></a> — fictional vehicles and CRM work show the map, connection health, trips, maintenance, alerts and dispatch before Bouncie is active. Follow the seven-step tour to compare routes and review fictional overdue/upcoming/completed maintenance and sample costs. Demo data never enters a client workspace.</p>
    <aside>During the pilot, an Everbranch operator must activate your workspace before location collection begins. Contact support if setup shows that activation is pending.</aside>
    <h2>Set up your workspace</h2>
    <ol>
        <li><strong>Open Everbranch.</strong> An agency administrator installs the app for your client account. Open Everbranch in that account's navigation. Fleet verifies your current CRM administrator access automatically. No separate Fleet password is needed.</li>
                <li><strong>Connect Bouncie.</strong> In Connection, authorize your own Bouncie account in the popup. Allow popups for app.theeverbranch.com. The Fleet tab automatically opens vehicle selection when authorization finishes. Use Return to Fleet if the sign-in window stays open. Third-party cookies are not required.</li>
        <li><strong>Select vehicles.</strong> Choose up to {{ config('highlevel.vehicle_limit') }} devices from your connected account and save. To add another vehicle at the limit, remove an existing selection first. A device cannot be active in two workspaces.</li>
        <li><strong>Review tracking settings.</strong> After saving vehicles, review the approved company vehicle policy, owner approval reference, retention period of 1–30 days, and collection setting. Keep the approved policy document for your records. If setup is saved but pilot activation is pending, contact support; reconnecting Bouncie will not activate tracking.</li>
        <li><strong>Check Fleet.</strong> Search the vehicle list and check or uncheck vehicles to compare them on the map. Use From/Through to load recorded Bouncie trip routes and Fit selected vehicles to show the checked routes together. Check each vehicle’s latest location and provider last reported timestamp. The visible page refreshes every 60 seconds. An older reading may mean a vehicle is parked; it does not automatically mean the device is offline.</li>
    </ol>
    <h2>Connect CRM work to your fleet</h2>
    <p>Fleet automatically discovers calendars and open pipeline opportunities from this client account. Jobs &amp; dispatch shows the included sources and a Refresh CRM work button; no CRM/source ID is needed. Appointments cover the previous 7 days and next 14 days. Open opportunities remain pipeline work until you confirm their schedule and fleet context. Existing installs must upgrade to version 2.0.0 and approve the new read-only permissions. Add crew availability, skills and stock, then confirm vehicle assignments, stop coordinates and schedules. Dispatch suggestions remain your choice.</p>
    <p>Trips &amp; routes shows provider distance and drive time. Confirm the correct job and expected road distance with an allowance to flag excess mileage for review. Maintenance groups overdue/due work, upcoming plans and completed service. Optional USD service estimates and recorded actual costs are planning records, not charges or accounting updates; Health alerts lets you assign and resolve reported vehicle issues.</p>
    <h2>Connection and access</h2>
    <p>If Bouncie requires fresh authorization, reconnect from Connection. Saved readings remain available during a provider outage within your retention period. If administrator access changes, an authorized administrator must reopen Everbranch.</p>
    <h2>Disconnect or uninstall</h2>
    <p>Disconnect Bouncie in Connection to stop its collection and remove the saved authorization. An agency administrator can uninstall Everbranch Fleet in the CRM to end app access.@if(config('highlevel.subscription_required')) Uninstall in the CRM to end the app subscription.@else Everbranch Fleet is free for now; no Everbranch app subscription is required.@endif Uninstalling Everbranch does not cancel your separate Bouncie subscription.</p>
    <p>Support: <a href="mailto:{{ config('everbranch.support_email') }}">{{ config('everbranch.support_email') }}</a></p>
</main>
</body>
</html>
