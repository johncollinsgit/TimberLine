<?php

namespace App\Enterprise\Trial;

use App\Enterprise\MountUrls;
use App\Models\TeamOperationRecord;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Server-held fictional records behind independently approved reviewer sessions and destinations. */
class ReviewerTrial
{
    public const FIELDS = ['title', 'status', 'owner', 'next_action', 'due_on', 'blocker'];

    public function __construct(private TrialStore $store, private TrialBudget $budget, private TrialTransport $transport) {}

    private function grant(string $id): array
    {
        $grant = config('enterprise.trial.reviewers.'.$id);
        abort_unless(is_array($grant) && is_string($grant['code_hash'] ?? null) && strlen($grant['code_hash']) === 64
            && is_string($grant['expires_at'] ?? null) && now()->lt(\Carbon\CarbonImmutable::parse($grant['expires_at'])), 403, 'This reviewer approval is unavailable or expired.');

        return $grant;
    }

    private function browser(Request $request, bool $create = false): string
    {
        abort_unless($request->hasSession() && $request->session()->getId() !== '', 403);
        $nonce = $request->session()->get('enterprise.trial.browser');
        if ($create && ! is_string($nonce)) {
            $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $request->session()->put('enterprise.trial.browser', $nonce);
        }
        abort_unless(is_string($nonce) && strlen($nonce) === 43, 403, 'The approved reviewer browser session is required.');

        return hash('sha256', $request->session()->getId().'|'.$nonce);
    }

    private function approved(TeamOperationRecord $session): array
    {
        abort_unless($session->status === 'active' && now()->lt(\Carbon\CarbonImmutable::parse($session->data['expires_at'])), 403, 'The trial session expired or was revoked.');
        $grant = $this->grant($session->data['reviewer_id']);
        abort_unless(hash_equals($session->data['grant_hash'], hash('sha256', json_encode($grant, JSON_THROW_ON_ERROR))), 403, 'Reviewer approval changed.');
        abort_unless(hash_equals($session->data['context_hash'], $this->contextHash()), 403, 'Trial deployment or synthetic storage context changed.');

        return $grant;
    }

    public function session(Request $request): TeamOperationRecord
    {
        $id = $request->session()->get('enterprise.trial.session');
        abort_unless(is_string($id) && Str::isUuid($id), 403, 'An approved reviewer session is required.');
        $session = $this->store->row('ent_trial_session', $id);
        $this->approved($session);
        abort_unless(hash_equals($session->data['browser_hash'], $this->browser($request)), 403, 'The original approved reviewer browser session is required.');
        abort_unless(MountUrls::matches($request) && $request->getSchemeAndHttpHost() === $this->origin(), 403, 'Use the approved first-party trial mount.');

        return $session;
    }

    /** Provider boundary recheck, without making a cross-device capability depend on browser cookies. */
    public function authority(string $id): TeamOperationRecord
    {
        $row = $this->store->row('ent_trial_session', $id);
        $this->approved($row);

        return $row;
    }

    private function samples(): array
    {
        $tasks = [];
        foreach ([['Sample facilities request', 'Sample Operations coordinator', 'Inspect the fictional repair and provide an update.', 'awaiting_update', 'Sample access window needs confirmation.'],
            ['Sample IT check', 'Sample IT coordinator', 'Verify the fictional equipment checklist.', 'in_progress', ''],
            ['Sample event setup', 'Sample Events coordinator', 'Confirm the fictional setup handoff.', 'awaiting_review', 'Sample handoff is awaiting approval.'],
        ] as [$title, $owner, $next, $status, $blocker]) {
            $id = (string) Str::uuid();
            $tasks[$id] = ['id' => $id, 'title' => $title, 'owner' => $owner, 'next_action' => $next, 'status' => $status, 'blocker' => $blocker,
                'due_on' => now('America/New_York')->addDay()->toDateString(), 'note' => '', 'acknowledged_at' => null, 'version' => 1];
        }

        return $tasks;
    }

    public function start(Request $request, string $code): array
    {
        abort_unless(MountUrls::matches($request) && $request->getSchemeAndHttpHost() === $this->origin(), 403, 'Use the approved first-party trial mount.');
        $this->store->tenant();
        abort_if(strlen($code) < 16 || strlen($code) > 200, 403, 'An approved reviewer invitation is required.');
        $id = null;
        foreach ((array) config('enterprise.trial.reviewers', []) as $key => $grant) {
            if (is_string($key) && preg_match('/^[A-Za-z0-9_-]{1,60}$/D', $key) && is_array($grant)
                && is_string($grant['code_hash'] ?? null) && hash_equals($grant['code_hash'], hash('sha256', $code))) {
                $id = $key;
                break;
            }
        }
        abort_unless($id, 403, 'An approved reviewer invitation is required.');
        $grant = $this->grant($id);
        $browser = $this->browser($request, true);
        $current = $request->session()->get('enterprise.trial.session');
        if (is_string($current) && Str::isUuid($current)) {
            try {
                $existing = $this->session($request);
                if ($existing->data['reviewer_id'] === $id) {
                    return $this->show($request);
                }
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException|\Illuminate\Database\Eloquent\ModelNotFoundException) {
                // Only a new explicitly approved invitation can replace an expired browser session.
            }
        }
        $session = $this->store->locked(function () use ($id, $grant, $browser) {
            $session = $this->store->create('ent_trial_session', 'session:'.Str::uuid(), ['reviewer_id' => $id, 'grant_hash' => hash('sha256', json_encode($grant, JSON_THROW_ON_ERROR)),
                'context_hash' => $this->contextHash(), 'browser_hash' => $browser, 'expires_at' => now()->addHours(2)->min(\Carbon\CarbonImmutable::parse($grant['expires_at']))->utc()->toIso8601String()]);
            $task = $this->store->create('ent_trial_task', 'task:'.$session->id, ['session_id' => $session->id, 'tasks' => $this->samples(), 'sample' => true]);
            $this->store->audit($task, 'trial.synthetic_tasks_created');
            $this->store->save($session, $session->data + ['task_id' => $task->id]);
            $this->store->audit($session, 'trial.session_started');

            return $session;
        });
        $request->session()->put('enterprise.trial.session', $session->id);

        return $this->show($request);
    }

    public function show(Request $request): array
    {
        $session = $this->session($request);
        $grant = $this->approved($session);
        $task = $this->store->row('ent_trial_task', $session->data['task_id']);
        $actions = TeamOperationRecord::query()->where(['tenant_id' => $session->tenant_id, 'kind' => 'ent_trial_preview'])->orderByDesc('created_at')->limit(500)->get()
            ->filter(fn ($row) => $row->data['session_id'] === $session->id)->take(30)->map(fn ($row) => $this->previewDto($row))->values()->all();
        try {
            $budget = $this->budget->status();
            $budget['ready'] = ! $budget['paused'];
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
            $budget = ['ready' => false, 'cap_microusd' => TrialBudget::CAP, 'status' => 'Prior spend or storage policy requires verification before paid calls.'];
        }

        return ['sample' => true, 'session_id' => $session->id, 'expires_at' => $session->data['expires_at'], 'tasks' => array_values($task->data['tasks']),
            'destinations' => ['email' => array_values($grant['emails'] ?? []), 'sms' => array_values($grant['sms'] ?? [])],
            'fields' => self::FIELDS, 'actions' => $actions, 'budget' => $budget, 'as_of' => now()->toIso8601String(),
            'notice' => 'Controlled Everbranch demonstration. All work records are fictional; recipients are approved test destinations. No mailbox access or employee grants.'];
    }

    private function origin(): string
    {
        $origin = config('enterprise.trial.canonical_origin');
        $parts = is_string($origin) ? parse_url($origin) : false;
        abort_unless(is_array($parts) && ($parts['scheme'] ?? '') === 'https' && isset($parts['host'])
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']) && ! isset($parts['path']), 503, 'An exact first-party HTTPS trial origin must be configured.');
        abort_unless($origin === MountUrls::origin(), 503, 'The trial origin must belong to this application.');

        return $origin;
    }

    private function contextHash(): string
    {
        return hash('sha256', json_encode([$this->store->tenant()->id, config('enterprise.trial.area_id'), $this->origin(), config('app.url'), MountUrls::context()], JSON_THROW_ON_ERROR));
    }

    private function sources(TeamOperationRecord $task, array $fields): array
    {
        return array_values(array_map(fn ($row) => ['ref' => 'sample:'.$row['id'], 'fields' => array_intersect_key($row, array_flip($fields)), 'version' => $row['version']], $task->data['tasks']));
    }

    public function preview(Request $request, array $input): array
    {
        $input = Validator::make($input, ['request_id' => ['required', 'uuid'], 'channel' => ['required', 'in:sms,email,ai'],
            'destination' => ['nullable', 'string', 'max:254'], 'fields' => ['sometimes', 'array', 'max:6'], 'fields.*' => ['string', 'in:'.implode(',', self::FIELDS)]])->validate();

        return $this->store->locked(function () use ($request, $input): array {
            $session = $this->session($request);
            $grant = $this->approved($session);
            $channel = $input['channel'];
            $destination = $channel === 'email' ? strtolower(trim($input['destination'] ?? '')) : trim($input['destination'] ?? '');
            if ($channel !== 'ai') {
                $allowed = $channel === 'sms' ? ($grant['sms'] ?? []) : array_map('strtolower', $grant['emails'] ?? []);
                abort_unless(in_array($destination, $allowed, true) && ($channel === 'sms' ? preg_match('/^\+[1-9][0-9]{7,14}$/D', $destination) : filter_var($destination, FILTER_VALIDATE_EMAIL)), 403, 'This receiving destination has not been approved for this channel/reviewer.');
            }
            $fields = array_values(array_unique($input['fields'] ?? self::FIELDS));
            sort($fields);
            abort_unless($fields !== [] && array_diff($fields, self::FIELDS) === [], 422, 'Select only the listed fictional fields.');
            $inputHash = hash('sha256', json_encode([$channel, $destination, $fields], JSON_THROW_ON_ERROR));
            $key = hash('sha256', 'preview:'.$session->id.':'.$input['request_id']);
            $prior = TeamOperationRecord::query()->where(['tenant_id' => $session->tenant_id, 'kind' => 'ent_trial_preview', 'source_key' => $key])->first();
            if ($prior) {
                abort_unless(hash_equals($prior->data['input_hash'], $inputHash), 409);

                return $this->previewDto($prior) + ['replayed' => true];
            }
            $task = $this->store->row('ent_trial_task', $session->data['task_id']);
            $sources = $this->sources($task, $fields);
            $content = ['destination' => $destination, 'fields' => $fields, 'sources' => $sources];
            $capability = null;
            if ($channel !== 'ai') {
                $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                $url = MountUrls::url('/enterprise/trial/task/'.$token);
                $capability = $this->store->create('ent_trial_link', 'link:'.$token, ['session_id' => $session->id, 'task_id' => $task->id,
                    'channel' => $channel, 'expires_at' => $session->data['expires_at'], 'opened_at' => null, 'open_count' => 0], 'pending');
                $primary = reset($sources);
                $content['url'] = $url;
                $content['subject'] = 'Lees-McRae sample request - Everbranch demonstration';
                $content['body'] = $channel === 'sms' ? 'Lees-McRae demo: Campus is waiting on your task update. View the sample task and upload a photo: '.$url
                    : "Lees-McRae College - Everbranch demonstration\nFictional sample request.\n".implode("\n", array_map(fn ($key, $value) => $key.': '.$value, array_keys($primary['fields']), $primary['fields']))."\nView sample request: ".$url;
                if ($channel === 'email') {
                    $rows = implode('', array_map(fn ($key, $value) => '<tr><th align="left" style="padding:9px;color:#335441">'.e(ucwords(str_replace('_', ' ', $key))).'</th><td style="padding:9px">'.e($value).'</td></tr>', array_keys($primary['fields']), $primary['fields']));
                    $content['html'] = '<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;background:#f3f4ee;font:16px Arial;color:#20382c"><table role="presentation" width="100%"><tr><td align="center"><table role="presentation" width="100%" style="max-width:600px;background:#fff"><tr><td style="padding:28px;background:#174b36;color:white"><img src="'.e(MountUrls::url('/enterprise/trial/wordmark.png')).'" width="220" alt="Lees-McRae College" style="max-width:100%;height:auto"><div style="font:24px Georgia">Lees-McRae College</div><p>Everbranch Enterprise demonstration</p></td></tr><tr><td style="padding:24px"><h1 style="font-size:24px">Your sample request needs an update</h1><p>These are fictional work details, not a real campus assignment.</p><table width="100%">'.$rows.'</table><p style="margin:28px 0"><a style="display:inline-block;padding:16px 24px;background:#174b36;color:white;text-decoration:none;border-radius:8px" href="'.e($url).'">View sample request</a></p><p style="font-size:13px;color:#58675d">This approved test was sent by Everbranch. No college mailbox access is requested. The private sample link expires with this reviewer trial.</p></td></tr></table></td></tr></table></body></html>';
                }
            }
            $quote = $this->transport->quote($channel, $content);
            $data = ['session_id' => $session->id, 'task_id' => $task->id, 'channel' => $channel, 'content' => $content, 'quote' => $quote,
                'input_hash' => $inputHash, 'source_hash' => hash('sha256', json_encode($sources, JSON_THROW_ON_ERROR)),
                'expires_at' => now()->addMinutes(10)->toIso8601String(), 'link_id' => $capability?->id,
                'events' => ['acceptance' => 'not_submitted', 'delivery' => 'unverified', 'link_opened' => false, 'acknowledged' => false]];
            $data['digest'] = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $row = $this->store->create('ent_trial_preview', 'preview:'.$session->id.':'.$input['request_id'], $data, 'preview');
            if ($capability) {
                $this->store->save($capability, $capability->data + ['preview_id' => $row->id]);
            }
            $this->store->audit($row, 'trial.preview_created', ['channel' => $channel, 'digest' => $data['digest']]);

            return $this->previewDto($row);
        });
    }

    private function previewDto(TeamOperationRecord $row): array
    {
        $data = $row->data;
        $quote = $data['quote'];
        unset($quote['config_hash']);

        return ['id' => $row->id, 'link_id' => $data['link_id'], 'sample' => true, 'status' => $row->status, 'channel' => $data['channel'], 'digest' => $data['digest'],
            'expires_at' => $data['expires_at'], 'content' => $data['content'], 'quote' => $quote, 'events' => $data['events'], 'outcome' => $data['outcome'] ?? null];
    }

    public function confirm(Request $request, string $id, string $digest): array
    {
        $session = $this->session($request);
        $row = $this->store->row('ent_trial_preview', $id);
        abort_unless($row->data['session_id'] === $session->id && hash_equals($row->data['digest'], $digest), 404);
        if (in_array($row->status, ['accepted', 'uncertain', 'rejected'], true)) {
            return $this->previewDto($row) + ['replayed' => true];
        }
        $data = $row->data;
        abort_unless($row->status === 'preview' && now()->lt(\Carbon\CarbonImmutable::parse($data['expires_at'])), 409, 'This exact preview expired or is already claimed.');
        $task = $this->store->row('ent_trial_task', $data['task_id']);
        abort_unless(hash_equals($data['source_hash'], hash('sha256', json_encode($this->sources($task, $data['content']['fields']), JSON_THROW_ON_ERROR))), 409, 'The sample work changed. Review a fresh preview.');
        $quote = $this->transport->quote($data['channel'], $data['content']);
        abort_unless($quote['ready'] && $quote === $data['quote'], 503, 'Channel approval/configuration changed or is unavailable. No call was made.');
        $claim = $this->budget->reserve($data['channel'], $row->id, $data['digest'], $quote['maximum_microusd']);
        abort_if($claim['replayed'], 409, 'The paid attempt already has an outcome. Reconcile its existing preview.');
        $this->store->locked(function () use ($request, $row, $claim): void {
            $this->session($request);
            $current = $this->store->row('ent_trial_preview', $row->id);
            abort_unless($current->status === 'preview', 409);
            $this->store->save($current, $current->data + ['charge_id' => $claim['id']], 'dispatching');
            if ($current->data['link_id']) {
                $link = $this->store->row('ent_trial_link', $current->data['link_id']);
                $this->store->save($link, $link->data, 'active');
            }
            $this->store->audit($current, 'trial.preview_confirmed', ['digest' => $current->data['digest']]);
        });
        $outcome = ['status' => 'uncertain', 'provider_id' => null, 'actual_cost_microusd' => null];
        try {
            $this->session($request);
            abort_unless($this->transport->quote($data['channel'], $data['content']) === $quote, 409);
            $outcome = $this->transport->perform($data['channel'], $data['content'], $row->id);
            abort_unless(in_array($outcome['status'] ?? '', ['accepted', 'rejected', 'uncertain'], true), 503);
        } catch (\Throwable) {
            $outcome = ['status' => 'uncertain', 'provider_id' => null, 'actual_cost_microusd' => null];
        }
        try {
            $this->budget->settle($claim['id'], $outcome['status'], $outcome, $outcome['actual_cost_microusd'] ?? null);
        } catch (\Throwable) {
            $outcome = ['status' => 'uncertain', 'provider_id' => null, 'actual_cost_microusd' => null];
        }
        $this->store->locked(function () use ($row, $outcome): void {
            $current = $this->store->row('ent_trial_preview', $row->id);
            $data = $current->data;
            $data['outcome'] = $outcome;
            $data['events']['acceptance'] = $outcome['status'];
            $data['events']['accepted_at'] = $outcome['status'] === 'accepted' ? now()->toIso8601String() : null;
            $this->store->save($current, $data, $outcome['status']);
            $this->store->audit($current, 'trial.'.$outcome['status'], ['provider_id' => $outcome['provider_id'] ?? null]);
        });
        $this->session($request);

        return $this->previewDto($this->store->row('ent_trial_preview', $row->id));
    }

    private function link(string $token): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{43}$/D', $token), 404);
        $link = TeamOperationRecord::query()->where(['tenant_id' => $this->store->tenant()->id, 'kind' => 'ent_trial_link', 'source_key' => hash('sha256', 'link:'.$token)])->firstOrFail();
        abort_unless($link->status === 'active' && now()->lt(\Carbon\CarbonImmutable::parse($link->data['expires_at'])), 404);
        $session = $this->store->row('ent_trial_session', $link->data['session_id']);
        $this->approved($session);

        return [$link, $this->store->row('ent_trial_task', $link->data['task_id'])];
    }

    /** Internal capability check for the private upload service; never serialize these records to a client. */
    public function capability(string $token): array
    {
        return $this->link($token);
    }

    public function upload(string $token, string $requestId, UploadedFile $file): array
    {
        return app(TrialFiles::class)->upload($token, $requestId, $file);
    }

    public function download(string $token, string $id)
    {
        return app(TrialFiles::class)->download($token, $id);
    }

    public function task(string $token, bool $opened = true): array
    {
        [$link, $task] = $this->link($token);
        if ($opened) {
            $this->store->locked(function () use ($token): void {
                [$link] = $this->link($token);
                $data = $link->data;
                $data['open_count']++;
                $data['opened_at'] ??= now()->toIso8601String();
                $this->store->save($link, $data);
                $this->store->audit($link, 'trial.link_opened');
                $preview = $this->store->row('ent_trial_preview', $data['preview_id']);
                $events = $preview->data;
                $events['events']['link_opened'] = true;
                $this->store->save($preview, $events);
            });
        }
        $tasks = $task->data['tasks'];
        $primary = reset($tasks);
        $uploads = TeamOperationRecord::query()->where(['tenant_id' => $task->tenant_id, 'kind' => 'ent_trial_upload'])->orderByDesc('created_at')->limit(500)->get()
            ->filter(fn ($row) => $row->data['task_id'] === $task->id)->map(fn ($row) => ['id' => $row->id, 'name' => $row->data['name'], 'status' => $row->status, 'size' => $row->data['size']])->values()->all();

        return ['sample' => true, 'task' => $primary, 'uploads' => $uploads, 'expires_at' => $link->data['expires_at'],
            'notice' => 'Fictional Everbranch sample task. This limited link grants no college account or other work access. Files stay private and quarantined until scanned.'];
    }

    public function update(string $token, array $input): array
    {
        $input = Validator::make($input, ['request_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'in:acknowledge,update'], 'status' => ['required_if:action,update', 'in:in_progress,awaiting_review'],
            'note' => ['nullable', 'string', 'max:2000']])->validate();

        return $this->store->locked(function () use ($token, $input): array {
            [$link, $task] = $this->link($token);
            $data = $task->data;
            $key = hash('sha256', $input['request_id']);
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            if (isset($data['receipts'][$key])) {
                abort_unless(hash_equals($data['receipts'][$key], $hash), 409);

                return $this->task($token, false) + ['replayed' => true];
            }
            $id = array_key_first($data['tasks']);
            $primary = $data['tasks'][$id];
            abort_unless($primary['version'] === $input['expected_version'], 409, 'The sample task changed. Refresh before updating.');
            abort_if(count($data['receipts'] ?? []) >= 100, 429);
            if ($input['action'] === 'acknowledge') {
                $primary['acknowledged_at'] ??= now()->toIso8601String();
            } else {
                $primary['status'] = $input['status'];
                $primary['note'] = $input['note'] ?? '';
            }
            $primary['version']++;
            $data['tasks'][$id] = $primary;
            $data['receipts'][$key] = $hash;
            $this->store->save($task, $data);
            $this->store->audit($task, 'trial.task_'.$input['action']);
            if ($input['action'] === 'acknowledge') {
                $preview = $this->store->row('ent_trial_preview', $link->data['preview_id']);
                $next = $preview->data;
                $next['events']['acknowledged'] = true;
                $next['events']['acknowledged_at'] = now()->toIso8601String();
                $this->store->save($preview, $next);
            }

            return $this->task($token, false);
        });
    }

    public function revokeLink(Request $request, string $id): array
    {
        return $this->store->locked(function () use ($request, $id): array {
            $session = $this->session($request);
            $link = $this->store->row('ent_trial_link', $id);
            abort_unless($link->data['session_id'] === $session->id, 404);
            $this->store->save($link, $link->data, 'revoked');
            $this->store->audit($link, 'trial.link_revoked');

            return ['status' => 'revoked'];
        });
    }
}
