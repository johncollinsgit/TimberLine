<?php

namespace App\Proposals;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProposalService
{
    public const LEGAL_NOTICE = 'Draft legal language is unreviewed and not attorney approved. This proposal is non-binding. Requesting an agreement is not signing or authorizing payment.';

    public function assets(Tenant $tenant, ?string $kind = null)
    {
        return Asset::query()->where('tenant_id', $tenant->id)->when($kind, fn ($q) => $q->where('kind', $kind))->oldest()->get();
    }

    public function asset(Tenant $tenant, string $id, string $kind): Asset
    {
        return Asset::query()->where('tenant_id', $tenant->id)->where('kind', $kind)->findOrFail($id);
    }

    public function saveAsset(Tenant $tenant, int $actor, string $kind, array $input, ?string $id = null): Asset
    {
        abort_unless(in_array($kind, ['client', 'catalog', 'template'], true), 404);
        $rules = ['name' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:6000']];
        if ($kind === 'client') {
            $rules += ['reference' => ['required', 'string', 'max:100']];
        } elseif ($kind === 'template') {
            $rules += ['text' => ['required', 'string', 'max:16000']];
        } else {
            $rules += [
                'client_id' => ['nullable', 'uuid'], 'exclusive_group' => ['nullable', 'string', 'max:80'],
                'terms_months' => ['present', 'array', 'max:3'], 'terms_months.*' => ['integer', 'distinct', Rule::in([12, 24, 36])],
                'charges' => ['required', 'array', 'min:1', 'max:2'],
                'charges.*' => ['array:label,amount_cents,cadence'],
                'charges.*.label' => ['required', 'string', 'max:180'],
                'charges.*.amount_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
                'charges.*.cadence' => ['required', Rule::in(['one_time', 'month', 'year'])],
            ];
        }
        $payload = Validator::make($input, $rules)->validate();
        if ($kind === 'catalog') {
            $payload['terms_months'] = array_map('intval', $payload['terms_months']);
            $payload['charges'] = array_map(fn ($charge) => [...$charge, 'amount_cents' => (int) $charge['amount_cents']], $payload['charges']);
            $setupCount = count(array_filter($payload['charges'], fn ($charge) => $charge['cadence'] === 'one_time'));
            $recurringCount = count($payload['charges']) - $setupCount;
            if ($setupCount > 1 || $recurringCount > 1) {
                throw ValidationException::withMessages(['charges' => 'A catalog item supports one one-time charge and one recurring charge. Add separate service items for additional charges.']);
            }
        }
        if ($kind === 'catalog' && ! empty($payload['client_id'])) {
            $this->asset($tenant, $payload['client_id'], 'client');
        }
        if ($kind === 'template') {
            $payload['legal_status'] = 'unreviewed';
        }

        return DB::transaction(function () use ($tenant, $actor, $kind, $input, $id, $payload): Asset {
            if ($id) {
                $asset = Asset::query()->where('tenant_id', $tenant->id)->where('kind', $kind)->lockForUpdate()->findOrFail($id);
                abort_unless((int) ($input['revision'] ?? 0) === (int) $asset->revision, 409, 'This asset changed. Reload before saving.');
                $asset->fill(['payload' => $payload, 'revision' => $asset->revision + 1])->save();

                return $asset;
            }

            return Asset::create(['tenant_id' => $tenant->id, 'kind' => $kind, 'payload' => $payload, 'created_by' => $actor]);
        });
    }

    public function draft(Tenant $tenant, string $id): Draft
    {
        return Draft::query()->where('tenant_id', $tenant->id)->findOrFail($id);
    }

    public function saveDraft(Tenant $tenant, int $actor, array $input, ?string $id = null): Draft
    {
        $data = Validator::make($input, [
            'client_id' => ['required', 'uuid'], 'title' => ['required', 'string', 'max:220'],
            'intro' => ['nullable', 'string', 'max:8000'], 'catalog_ids' => ['required', 'array', 'min:1', 'max:50'],
            'catalog_ids.*' => ['required', 'uuid', 'distinct'], 'template_id' => ['nullable', 'uuid'],
            'source' => ['nullable', Rule::in(['manual', 'ai_draft', 'voice_draft'])],
            'scope_notes' => ['nullable', 'string', 'max:8000'],
            'usage_plan' => ['nullable', Rule::in(['unconfirmed', 'separately_billed', 'workspace_allowance', 'pooled_bundle_allowance'])],
            'approach_key' => ['nullable', Rule::in(['evergrove_department_journey'])],
            'reviewer_names' => ['nullable', 'array', 'max:20'], 'reviewer_names.*' => ['required', 'string', 'distinct', 'max:80'],
        ])->validate();
        $client = $this->asset($tenant, $data['client_id'], 'client');
        $items = [];
        foreach ($data['catalog_ids'] as $assetId) {
            $asset = $this->asset($tenant, $assetId, 'catalog');
            abort_if(! empty($asset->payload['client_id']) && $asset->payload['client_id'] !== $client->id, 422, 'This price is specific to another client.');
            $items[] = ['id' => $asset->id, 'revision' => $asset->revision, ...$asset->payload];
        }
        $template = empty($data['template_id']) ? null : $this->asset($tenant, $data['template_id'], 'template');
        $payload = [
            'title' => $data['title'], 'intro' => $data['intro'] ?? '',
            'prepared_by' => 'Evergrove Software', 'client' => ['id' => $client->id, ...$client->payload],
            'items' => $items, 'legal_notice' => self::LEGAL_NOTICE, 'legal_status' => 'unreviewed',
            'template' => $template ? ['id' => $template->id, 'revision' => $template->revision, ...$template->payload] : null,
            'source' => $data['source'] ?? 'manual', 'binding' => false,
            'scope_notes' => $data['scope_notes'] ?? '', 'usage_plan' => $data['usage_plan'] ?? 'unconfirmed',
            'approach_key' => $data['approach_key'] ?? null,
            'journey' => empty($data['approach_key']) ? [] : DepartmentJourney::content(),
            'reviewer_names' => $data['reviewer_names'] ?? [],
            'client_details' => $client->payload['reference'] === 'lees-mcrae-college' && config('service_proposals.owner_workspaces.'.$tenant->id) === 'enterprise' ? LeesMcRaeDetails::content() : [],
            'unresolved' => ['Additional terms, discounts, usage charges and renewal are not finalized.', 'Contract duration is separate from billing cadence.'],
        ];

        return DB::transaction(function () use ($tenant, $actor, $input, $id, $client, $payload): Draft {
            if ($id) {
                $draft = Draft::query()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id);
                abort_unless((int) ($input['revision'] ?? 0) === (int) $draft->revision, 409, 'This draft changed. Reload before saving.');
                $draft->fill(['client_id' => $client->id, 'payload' => $payload, 'revision' => $draft->revision + 1])->save();

                return $draft;
            }

            return Draft::create(['tenant_id' => $tenant->id, 'client_id' => $client->id, 'payload' => $payload, 'created_by' => $actor]);
        });
    }

    /** Explicit owner action only. It returns a private link and never sends it. */
    public function publish(Tenant $tenant, int $actor, string $id, int $revision, string $password, int $days, string $requestKey, string $audience = 'client'): array
    {
        abort_unless(config('service_proposals.publish_enabled'), 403, 'Publication is disabled.');
        Validator::make(['password' => $password, 'days' => $days, 'request_key' => $requestKey], ['password' => ['required', 'string', 'min:12', 'max:128'], 'days' => ['integer', 'min:1', 'max:30'], 'request_key' => ['required', 'uuid']])->validate();

        abort_unless(in_array($audience, ['client', 'reviewer'], true), 422);

        return DB::transaction(function () use ($tenant, $actor, $id, $revision, $password, $days, $requestKey, $audience): array {
            $draft = Draft::query()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id);
            $existing = Version::where('tenant_id', $tenant->id)->where('draft_id', $id)->where('publish_key', hash('sha256', $requestKey))->first();
            if ($existing) {
                abort_unless($existing->payload['draft_revision'] === $revision && $existing->payload['audience'] === $audience
                    && $existing->access_result['days'] === $days && Hash::check($password, $existing->password_hash), 409, 'This publication request was already used with different details.');

                return ['version' => $existing, 'token' => $existing->access_result['token']];
            }
            abort_unless($draft->revision === $revision, 409, 'Review the current draft before publishing.');
            $token = Str::random(64);
            $payload = [...$draft->payload, 'draft_revision' => $revision, 'audience' => $audience];
            if (($campus = app(CampusReviewManifest::class)->forPublication($tenant, $payload)) !== null) {
                $payload['campus_review'] = $campus;
            }
            $version = Version::create([
                'tenant_id' => $tenant->id, 'draft_id' => $draft->id,
                'number' => Version::where('tenant_id', $tenant->id)->where('draft_id', $id)->max('number') + 1,
                'payload' => $payload, 'content_hash' => $this->hash($payload), 'token_hash' => hash('sha256', $token),
                'password_hash' => Hash::make($password), 'expires_at' => now()->addDays($days), 'created_by' => $actor,
                'publish_key' => hash('sha256', $requestKey), 'access_result' => ['token' => $token, 'days' => $days],
            ]);

            return ['version' => $version, 'token' => $token];
        });
    }

    public function selection(array $payload, array $selected): array
    {
        $selected = Validator::make(['services' => $selected], [
            'services' => ['required', 'array', 'min:1', 'max:50'],
            'services.*' => ['array:id,term_months'], 'services.*.id' => ['required', 'uuid', 'distinct'],
            'services.*.term_months' => ['nullable', 'integer'],
        ])->validate()['services'];
        $items = collect($payload['items'])->keyBy('id');
        $groups = [];
        $lines = [];
        $services = [];
        foreach ($selected as $choice) {
            $item = $items->get($choice['id']);
            if (! $item) {
                throw ValidationException::withMessages(['services' => 'Choose only services in this exact proposal version.']);
            }
            $group = $item['exclusive_group'] ?? null;
            if ($group && isset($groups[$group])) {
                throw ValidationException::withMessages(['services' => 'Choose only one option from each alternative group.']);
            }
            $groups[$group ?? $item['id']] = true;
            $term = $choice['term_months'] ?? null;
            $allowed = $item['terms_months'] ?? [];
            if (($allowed && ! in_array($term, $allowed, true)) || (! $allowed && $term !== null)) {
                throw ValidationException::withMessages(['services' => 'Choose a configured contract term. Unconfigured terms remain pending.']);
            }
            $services[] = ['id' => $item['id'], 'name' => $item['name'], 'term_months' => $term];
            foreach ($item['charges'] as $charge) {
                $lines[] = ['service_id' => $item['id'], 'service_name' => $item['name'], ...$charge, 'term_months' => $term];
            }
        }
        usort($services, fn ($a, $b) => strcmp($a['id'], $b['id']));
        usort($lines, fn ($a, $b) => strcmp($a['service_id'].$a['cadence'].$a['label'], $b['service_id'].$b['cadence'].$b['label']));

        return ['services' => $services, 'lines' => $lines,
            'totals_cents' => collect($lines)->groupBy('cadence')->map(fn ($entries) => $entries->sum('amount_cents'))->all(),
            'currency' => 'USD', 'binding' => false, 'legal_status' => 'unreviewed',
            'scope_notes' => $payload['scope_notes'] ?? '', 'usage_plan' => $payload['usage_plan'] ?? 'unconfirmed',
            'journey' => $payload['journey'] ?? [],
            'client_details' => $payload['client_details'] ?? [],
            'legal_notice' => self::LEGAL_NOTICE, 'template' => $payload['template'], 'unresolved' => $payload['unresolved']];
    }

    public function requestAgreement(Version $version, array $selected, string $requestKey, string $ip): Review
    {
        abort_unless(($version->payload['audience'] ?? 'client') === 'client', 403);
        abort_if($version->revoked_at || $version->expires_at->isPast(), 410);
        $selection = $this->selection($version->payload, $selected);
        $selectionHash = $this->hash(['version_hash' => $version->content_hash, 'selection' => $selection]);

        return DB::transaction(function () use ($version, $requestKey, $ip, $selection, $selectionHash): Review {
            $locked = Version::query()->lockForUpdate()->findOrFail($version->id);
            abort_if($locked->revoked_at || $locked->expires_at->isPast(), 410);
            $existing = Review::where('tenant_id', $version->tenant_id)->where('version_id', $version->id)->where('request_key', hash('sha256', $requestKey))->first();
            if ($existing) {
                abort_unless(hash_equals($existing->selection_hash, $selectionHash), 409, 'This request was already recorded with a different selection.');

                return $existing;
            }

            return Review::create(['tenant_id' => $version->tenant_id, 'version_id' => $version->id,
                'selection_hash' => $selectionHash, 'request_key' => hash('sha256', $requestKey),
                'payload' => ['status' => 'agreement_requested', 'version_hash' => $version->content_hash,
                    'client' => $version->payload['client'], 'title' => $version->payload['title'], 'version_number' => $version->number,
                    'selection' => $selection, 'ip_hash' => hash('sha256', $ip),
                    'signature_status' => 'not_signed', 'payment_status' => 'not_started', 'subscription_status' => 'not_started']]);
        });
    }

    public function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function comment(Version $version, string $name, string $page, string $body, string $requestKey): Comment
    {
        abort_unless(in_array($name, $version->payload['reviewer_names'] ?? [], true), 403);
        Validator::make(compact('page', 'body', 'requestKey'), ['page' => ['required', 'in:overview,approach,scope,pricing'], 'body' => ['required', 'string', 'max:4000'], 'requestKey' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($version, $name, $page, $body, $requestKey): Comment {
            $locked = Version::whereKey($version->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->revoked_at || $locked->expires_at->isPast(), 410);
            $payload = ['name' => $name, 'page' => $page, 'body' => $body, 'client' => $version->payload['client']['name'], 'title' => $version->payload['title'], 'version_number' => $version->number, 'version_hash' => $version->content_hash, 'identity_status' => 'self_selected_display_name'];
            $existing = Comment::where('tenant_id', $version->tenant_id)->where('version_id', $version->id)->where('request_key', hash('sha256', $requestKey))->first();
            if ($existing) {
                abort_unless($existing->payload === $payload, 409);

                return $existing;
            }

            return Comment::create(['tenant_id' => $version->tenant_id, 'version_id' => $version->id, 'request_key' => hash('sha256', $requestKey), 'payload' => $payload]);
        });
    }

    /** Explicit review metadata only. No campus tenant, user, seat or operational permission is created. */
    public function commentCampus(Version $version, string $name, string $page, string $body, string $requestKey, array $binding): Comment
    {
        abort_unless(config('service_proposals.enabled') && config('service_proposals.publish_enabled')
            && config('service_proposals.writes_enabled'), 403);
        Validator::make(compact('body', 'requestKey'), ['body' => ['required', 'string', 'max:4000'],
            'requestKey' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($version, $name, $page, $body, $requestKey, $binding): Comment {
            $locked = Version::where('tenant_id', $version->tenant_id)->lockForUpdate()->findOrFail($version->id);
            $manifest = app(CampusReviewManifest::class)->assertCurrent($locked, $page);
            abort_unless(in_array($name, $locked->payload['reviewer_names'] ?? [], true), 403);
            abort_unless(($binding['version_hash'] ?? null) === $locked->content_hash
                && ($binding['build_id'] ?? null) === $manifest['build_id']
                && ($binding['source_hash'] ?? null) === $manifest['source_hash'], 409, 'The reviewed campus version changed. Reload before commenting.');
            $payload = ['name' => $name, 'page' => 'campus:'.$page, 'body' => $body,
                'client' => $locked->payload['client']['name'], 'title' => $locked->payload['title'],
                'version_number' => $locked->number, 'version_hash' => $locked->content_hash,
                'identity_status' => 'self_selected_display_name', 'campus_review' => ['page' => $page,
                    'client_reference' => $manifest['client_reference'], 'build_id' => $manifest['build_id'], 'source_hash' => $manifest['source_hash']]];
            $existing = Comment::where('tenant_id', $locked->tenant_id)->where('version_id', $locked->id)
                ->where('request_key', hash('sha256', $requestKey))->first();
            if ($existing) {
                abort_unless($existing->payload === $payload, 409, 'The review request key already belongs to different feedback.');

                return $existing;
            }

            return Comment::create(['tenant_id' => $locked->tenant_id, 'version_id' => $locked->id,
                'request_key' => hash('sha256', $requestKey), 'payload' => $payload]);
        });
    }

    public function leesMcRaeDraft(Tenant $tenant, int $actor): Draft
    {
        abort_unless(config('service_proposals.owner_workspaces.'.$tenant->id) === 'enterprise', 403);

        return DB::transaction(function () use ($tenant, $actor): Draft {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $client = $this->assets($tenant, 'client')->first(fn ($asset) => ($asset->payload['reference'] ?? '') === 'lees-mcrae-college');
            $client ??= $this->saveAsset($tenant, $actor, 'client', ['name' => 'Lees-McRae College', 'reference' => 'lees-mcrae-college']);
            $items = [];
            foreach ([['One workspace', 300000, 100000], ['Three-workspace bundle', 700000, 250000]] as [$name, $setup, $monthly]) {
                $existing = $this->assets($tenant, 'catalog')->first(fn ($asset) => ($asset->payload['client_id'] ?? '') === $client->id && $asset->payload['name'] === $name);
                $items[] = ($existing ?? $this->saveAsset($tenant, $actor, 'catalog', [
                    'name' => $name, 'client_id' => $client->id, 'exclusive_group' => 'workspace-choice', 'terms_months' => [],
                    'description' => $name === 'One workspace' ? 'A focused initial workspace. Department scope requires review.' : 'Three committed department workspaces. Prices shown are TOTAL for the bundle. Campus-wide expansion is scoped separately.',
                    'charges' => [['label' => 'One-time setup', 'amount_cents' => $setup, 'cadence' => 'one_time'], ['label' => 'Monthly service', 'amount_cents' => $monthly, 'cadence' => 'month']],
                ]))->id;
            }

            return $this->saveDraft($tenant, $actor, ['client_id' => $client->id, 'title' => 'A proposal for Lees-McRae College', 'catalog_ids' => $items, 'approach_key' => 'evergrove_department_journey', 'reviewer_names' => ['Ross', 'Sarah', 'Paul'],
                'intro' => 'Today, Roll Call tickets are printed pieces of paper handed to technicians, and the team runs into problems with that handoff. Proposed outcome: an installable web app for assigned daily work, job details, status, notes and photos, interruption and resume, client-scoped permissions and a separate manager dispatch view. Keep an optional printout and protect unfinished work during updates. Delivery and device acceptance remain to be verified. Offline operation, background GPS and native-app parity are not promised. Department scope and campus-wide expansion require review.',
                'scope_notes' => 'Included: the selected campus-operations workflows and workspaces listed in this proposal. Admissions and marketing are outside the initial offer. New department expansion, new or custom software integrations, substantial data migrations, replacement of existing systems, major new workflows or campaigns, and other work outside this scope require discovery and a separate written scope, timeline and price approval before work starts. Additional AI, SMS or email consumption is distinct from custom development. Usage beyond the agreed allowance requires approval under the selected usage plan. Usage prices and allowances are not finalized; no unlimited usage or automatic unapproved overages are included.']);
        });
    }
}
