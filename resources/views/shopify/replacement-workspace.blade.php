<x-shopify-embedded-shell
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :store-label="$storeLabel"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :page-subnav="$pageSubnav ?? []"
    :page-actions="$pageActions ?? []"
>
    @php
        $module = (array) ($modulePayload ?? []);
        $data = (array) ($workspace ?? []);
        $embeddedUrl = static fn (string $url): string => app(\App\Services\Shopify\ShopifyEmbeddedUrlGenerator::class)->append($url, request());
    @endphp
    <style>
        .rw{display:grid;gap:15px}.rw-panel{background:#fff;border:1px solid #dce3df;border-radius:15px;padding:15px;display:grid;gap:10px}.rw h2,.rw h3{margin:0;color:#17211c}.rw p{margin:0;color:#58665e;font-size:13px;line-height:1.5}.rw-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}.rw-stat{background:#f5f8f6;border-radius:10px;padding:10px}.rw-stat strong,.rw-stat span{display:block}.rw-stat span{font-size:10px;text-transform:uppercase;color:#68766e}.rw-table-wrap{overflow:auto}.rw table{width:100%;border-collapse:collapse;font-size:12px}.rw th,.rw td{text-align:left;padding:9px;border-bottom:1px solid #edf0ee;white-space:nowrap}.rw input,.rw select{border:1px solid #bcc7c1;border-radius:8px;padding:7px;background:#fff}.rw button,.rw-link{border:1px solid #9eafa6;border-radius:999px;padding:8px 12px;background:#fff;color:#24533f;font-weight:700;font-size:12px;text-decoration:none;cursor:pointer}.rw-result{font-size:11px;min-width:100px}.rw-warning{background:#fff8e8;border:1px solid #edd8a7;border-radius:10px;padding:10px;color:#795015}@media(max-width:800px){.rw-grid{grid-template-columns:1fr 1fr}}
    </style>
    <section class="rw" data-workspace data-workflow-base="{{ $workflowEndpointBase ?? '' }}">
        <div class="rw-panel">
            <div><h2>Candidate administration</h2><p>Active provider: {{ $module['active_provider'] ?? 'unknown' }}. Candidate status: {{ str_replace('_', ' ', $module['status'] ?? 'draft') }}. Changes here affect only imported candidate data.</p></div>
            <div class="rw-grid">
                <div class="rw-stat"><strong>{{ $module['passing_checks'] ?? 0 }}/{{ $module['required_checks'] ?? 0 }}</strong><span>checks green</span></div>
                <div class="rw-stat"><strong>{{ $module['latest_import']['imported_count'] ?? 0 }}</strong><span>imported</span></div>
                <div class="rw-stat"><strong>{{ $module['latest_import']['rejected_count'] ?? 0 }}</strong><span>rejected</span></div>
                <div class="rw-stat"><strong>{{ $module['snapshots'] ?? 0 }}</strong><span>snapshots</span></div>
            </div>
        </div>

        @if(($data['kind'] ?? '') === 'storeify_forms')
            <div class="rw-panel">
                <div><h3>Imported forms</h3><p>Contact and Job Opportunity are draft candidates. Forestry Weekend and the old retail Wholesale Application are searchable archives.</p></div>
                <div class="rw-grid">
                    @foreach($data['forms'] as $form)
                        <div class="rw-stat"><strong>{{ $form->submissions_count }}</strong><span>{{ $form->name }} · {{ $form->status }}</span></div>
                    @endforeach
                </div>
                <div><a class="rw-link" data-auth-download href="{{ $exportEndpoint }}">Export reconciled CSV</a></div>
            </div>
            <div class="rw-panel">
                <form method="GET" action="{{ $embeddedUrl(route('shopify.app.replacements.show', ['module' => $module['id']], false)) }}"><label>Search submissions <input name="q" value="{{ $data['search'] }}" autocomplete="off"></label> <button type="submit">Search</button></form>
                <div class="rw-table-wrap"><table>
                    <thead><tr><th>Submitted</th><th>Form</th><th>Name</th><th>Email</th><th>Status</th><th>Assigned to</th><th>Action</th></tr></thead>
                    <tbody>
                    @foreach($data['submissions'] as $submission)
                        <tr data-submission="{{ $submission->id }}">
                            <td>{{ $submission->submitted_at?->format('Y-m-d H:i') ?? 'Unknown' }}</td><td>{{ $submission->form?->name }}</td><td>{{ $submission->submitter_name }}</td><td>{{ $submission->submitter_email }}</td>
                            <td><select data-status>@foreach(['submitted','in_review','resolved','archived'] as $status)<option value="{{ $status }}" @selected($submission->status === $status)>{{ str_replace('_', ' ', $status) }}</option>@endforeach</select></td>
                            <td><input data-assigned type="email" value="{{ data_get($submission->metadata, 'assigned_to') }}" placeholder="owner@example.com"></td>
                            <td><button type="button" data-save>Save</button> <span class="rw-result" aria-live="polite"></span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            </div>
        @elseif(($data['kind'] ?? '') === 'omnium_locator')
            <div class="rw-panel">
                <div><h3>Unpublished locator preview</h3><p>All {{ count($data['locations']) }} locations are isolated from the storefront until the public map, keyboard support, directions, performance, and rollback gates pass.</p></div>
                <div class="rw-table-wrap"><table><thead><tr><th>Order</th><th>Location</th><th>Address</th><th>Coordinates</th><th>Featured</th><th>Published</th></tr></thead><tbody>
                @foreach($data['locations'] as $location)<tr><td>{{ $location->sort_order + 1 }}</td><td>{{ $location->name }}</td><td>{{ $location->address }}</td><td>{{ $location->latitude }}, {{ $location->longitude }}</td><td>{{ $location->featured ? 'Yes' : 'No' }}</td><td>{{ $location->published ? 'Yes' : 'No' }}</td></tr>@endforeach
                </tbody></table></div>
            </div>
        @else
            <div class="rw-panel"><p class="rw-warning">This module is waiting for an authenticated source export or live-system inventory. No placeholder data has been substituted.</p>@foreach($module['activation_blockers'] ?? [] as $blocker)<p>• {{ $blocker }}</p>@endforeach</div>
        @endif
    </section>
    <script>
        (() => {
            const root=document.querySelector('[data-workspace]'); if(!root)return;
            async function headers(){const fn=window.ForestryEmbeddedApp?.resolveEmbeddedAuthHeaders;if(typeof fn!=='function')throw new Error('Shopify authorization is not ready.');return {...await fn(),'Content-Type':'application/json','Accept':'application/json'};}
            root.addEventListener('click',async event=>{const button=event.target.closest('[data-save]');if(!button)return;const row=button.closest('[data-submission]'),result=row.querySelector('.rw-result');button.disabled=true;result.textContent='Saving…';try{const response=await fetch(`${root.dataset.workflowBase}/${row.dataset.submission}`,{method:'PATCH',headers:await headers(),body:JSON.stringify({status:row.querySelector('[data-status]').value,assigned_to:row.querySelector('[data-assigned]').value||null})});const payload=await response.json();if(!response.ok||!payload.ok)throw new Error(payload.message||'Save failed.');result.textContent=payload.message;}catch(error){result.textContent=error.message||'Save failed.';}finally{button.disabled=false;}});
            for(const link of root.querySelectorAll('[data-auth-download]'))link.addEventListener('click',async event=>{event.preventDefault();try{const response=await fetch(link.href,{headers:await headers()});if(!response.ok)throw new Error('Export failed.');const blob=await response.blob(),anchor=document.createElement('a');anchor.href=URL.createObjectURL(blob);anchor.download='everbranch-storeify-submissions.csv';anchor.click();URL.revokeObjectURL(anchor.href);}catch(error){window.alert(error.message||'Export failed.');}});
        })();
    </script>
</x-shopify-embedded-shell>
