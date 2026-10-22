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
    <h1>Get started with Fleet</h1>
    <p>See company vehicles, assign vans to jobs and keep up with maintenance.</p>
    <p><a href="/crm/fleet/demo"><strong>Try the guided demo</strong></a> with fictional vehicles, jobs and costs.</p>
    <h2>Connect your vehicles</h2>
    <ol>
        <li><strong>Open Everbranch in HighLevel.</strong> Use the client account where your agency installed Fleet. Your HighLevel administrator sign-in opens Fleet automatically.</li>
        <li><strong>Connect Bouncie.</strong> Finish signing in in the popup, then return to Fleet. Allow popups if the window does not open.</li>
        <li><strong>Choose your vehicles.</strong> Search and select up to {{ config('highlevel.vehicle_limit') }} company vehicles, then save.</li>
        <li><strong>Review tracking settings.</strong> Add your approved company tracking policy and approval record, choose how long to keep locations (1–30 days), then save. Keep your original policy document.</li>
    </ol>
    <aside>If setup says “activation pending,” contact support to activate your pilot. Your Bouncie connection and vehicle choices are already saved.</aside>
    <h2>Assign a van to a job</h2>
    <ol>
        <li><strong>Set up Vans.</strong> Choose each van’s crew, job types and materials on board. Mark it available when it can take jobs.</li>
        <li><strong>Find the job in Jobs.</strong> Fleet brings in HighLevel appointments and open opportunities automatically. Search by job, customer or address.</li>
        <li><strong>Check the address and time.</strong> Use the job’s saved address, choose another saved job address, or type a complete street address. Unscheduled work needs a start and finish time.</li>
        <li><strong>Choose a van.</strong> Search by van, crew or scheduled location, then Save assignment. Or choose Save &amp; find nearby vans to see available crews with the job types and materials needed. Distances are approximate.</li>
    </ol>
    <p>Assignments are saved in Fleet. Your HighLevel records stay unchanged. Use Refresh jobs for new work; appointments cover the past 7 days and next 14 days.</p>
    <h2>Use Fleet each day</h2>
    <p><strong>Fleet:</strong> Search and check the vans you want on the map. Choose dates to compare their recorded routes.</p>
    <p><strong>Maintenance:</strong> Review what is due, plan upcoming service and record completed work and costs.</p>
    <p><strong>Trips &amp; routes:</strong> View a trip’s route, connect it to a job and add review notes. Open Distance checks when you need to compare expected mileage.</p>
    <p><strong>Alerts:</strong> Assign someone to check a battery or engine issue and record what was done.</p>
    <h2>Connection and access</h2>
    <p>Reconnect Bouncie from Connection if asked. An agency administrator can uninstall Fleet in HighLevel to end app access.@if(config('highlevel.subscription_required')) Your agency manages the app subscription there.@else Fleet is free for now.@endif Your separate Bouncie subscription is managed with Bouncie.</p>
    <p>Support: <a href="mailto:{{ config('everbranch.support_email') }}">{{ config('everbranch.support_email') }}</a></p>
</main>
</body>
</html>
