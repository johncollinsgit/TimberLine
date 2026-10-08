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
    <p><a href="/crm/fleet/demo"><strong>Explore the interactive fleet demo</strong></a> — fictional vehicles and CRM work show the map, connection health, trips, maintenance, alerts and dispatch before Bouncie is active.</p>
    <aside>During the pilot, an Everbranch operator must activate your workspace before location collection begins. Contact support if setup shows that activation is pending.</aside>
    <h2>Set up your workspace</h2>
    <ol>
        <li><strong>Open Everbranch.</strong> An agency administrator installs the app for your client account. Open Everbranch in that account's navigation. Fleet requires current administrator access.</li>
        <li><strong>Approve company vehicle tracking.</strong> In Settings, enter your tracking policy version, policy text and owner approval reference. Confirm authorization, choose a retention period of 1–30 days, and enable company vehicle collection. Keep your approved policy document for your records.</li>
        <li><strong>Connect Bouncie.</strong> In Connection, authorize your own Bouncie account in the popup. Allow popups for app.theeverbranch.com. Return to the CRM when authorization finishes; third-party cookies are not required.</li>
        <li><strong>Select vehicles.</strong> Choose up to {{ config('highlevel.vehicle_limit') }} devices from your connected account and save. To add another vehicle at the limit, remove an existing selection first. A device cannot be active in two workspaces.</li>
        <li><strong>Check Fleet.</strong> Search the vehicle list, choose a vehicle, and check its latest location and provider last reported timestamp. The visible page refreshes every 60 seconds. An older reading may mean a vehicle is parked; it does not automatically mean the device is offline.</li>
    </ol>
    <h2>Connect CRM work to your fleet</h2>
    <p>In Jobs &amp; dispatch, choose the CRM calendar or pipeline used for work and sync its references. Existing installs must upgrade to version 2.0.0 and approve the new read-only permissions. Add crew availability, skills and stock, then confirm vehicle assignments, stop coordinates and schedules. Dispatch suggestions remain your choice.</p>
    <p>Trips &amp; routes shows provider distance and drive time. Confirm the correct job and expected road distance with an allowance to flag excess mileage for review. Maintenance creates due tasks from service dates or odometer readings; Health alerts lets you assign and resolve reported vehicle issues.</p>
    <h2>Connection and access</h2>
    <p>If Bouncie requires fresh authorization, reconnect from Connection. Saved readings remain available during a provider outage within your retention period. If administrator access changes, an authorized administrator must reopen Everbranch.</p>
    <h2>Disconnect or uninstall</h2>
    <p>Disconnect Bouncie in Connection to stop its collection and remove the saved authorization. An agency administrator can uninstall Everbranch Fleet in the CRM to end app access.@if(config('highlevel.subscription_required')) Uninstall in the CRM to end the app subscription.@else Everbranch Fleet is free for now; no Everbranch app subscription is required.@endif Uninstalling Everbranch does not cancel your separate Bouncie subscription.</p>
    <p>Support: <a href="mailto:{{ config('everbranch.support_email') }}">{{ config('everbranch.support_email') }}</a></p>
</main>
</body>
</html>
