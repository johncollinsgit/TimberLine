@php($allowanceClientId = isset($record) ? $record->client_tenant_id : $context['tenant']->id)
@if(config('software_support.clients.'.$allowanceClientId.'.approved_support_allowance', false))
<details><summary>Support and review calls</summary><p>During onboarding: one 30-minute discovery or progress call per week, up to four calls for each department. After go-live: one 60-minute review call per active workspace each month.</p><p>Between calls, report bugs, service incidents and questions through tickets. Additional consulting, workshops or development work is separately scoped and approved. A voluntary extra session is a one-time courtesy and does not change the ongoing allowance.</p></details>
@endif
@if(config('software_support.clients.'.$allowanceClientId.'.support_expectations.approved', false))
<details><summary>Support hours and first response</summary><p>Monday through Friday, 9 AM–5 PM Eastern, holidays excluded. An initial human response is provided within one business day. An automatic ticket receipt is not a human response. Resolution time is not guaranteed.</p>@if(isset($record) && ($record->payload['initial_human_response_at'] ?? null))<p class="muted">Initial human response: {{ $record->payload['initial_human_response_at'] }}</p>@endif</details>
@endif
