<?php

namespace App\Http\Controllers;

use App\Models\MarketingCampaign;
use App\Models\ShopifyStore;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use App\Services\Marketing\MarketingSmsEligibilityService;
use App\Services\Marketing\TeamShowTextAudience;
use App\Services\Marketing\TeamShowTextCampaigns;
use App\Services\Team\Operations\OperationsAccess;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamShowTextController extends Controller
{
    private const GROUPS = ['show', 'nearby', 'online'];

    public function __construct(
        private OperationsAccess $access,
        private TeamShowTextAudience $audience,
        private TeamShowTextCampaigns $campaigns,
    ) {}

    public function show(Request $request, string $id)
    {
        [$tenant, $event] = $this->context($request, $id);
        $context = $this->audience->context($event);
        $review = $request->session()->get($this->reviewKey($event, $request));
        $editing = $request->boolean('edit');
        $draft = $review['draft'] ?? [
            'body' => $this->defaultMessage($tenant, $event), 'photo' => null, 'settings' => $this->audience->settings($event, $context),
            'groups' => self::GROUPS, 'store' => $this->stores($tenant)[0],
        ];
        $review = ! $editing && $request->query('review') === ($review['id'] ?? null) ? $review : null;
        $runs = MarketingCampaign::forTenantId($tenant->id)->where('source_label', 'team_show_text')
            ->where('target_snapshot->team_show_text->event_id', $event->id)
            ->where('target_snapshot->team_show_text->area_id', $event->area_id)
            ->latest('id')->limit(20)->get();
        $preview = $this->previewRecipient($request, $tenant);

        return response()->view('team.show-text', ['tenant' => $tenant, 'event' => $event, 'review' => $review, 'draft' => $draft,
            'nearby' => $context['nearby'], 'stores' => $this->stores($tenant), 'runs' => $runs, 'preview' => $preview])
            ->header('Cache-Control', 'private, no-store');
    }

    public function photo(Request $request, string $id)
    {
        [$tenant, $event] = $this->context($request, $id);
        $data = $request->validate(['photo' => 'required|file|max:4882', 'store' => ['required', Rule::in($this->stores($tenant))]]);
        $asset = $this->campaigns->upload($tenant, $request->user(), $event, $data['store'], $data['photo']);

        return response()->json(['id' => $asset->id, 'url' => $asset->public_url])->header('Cache-Control', 'private, no-store');
    }

    public function review(Request $request, string $id)
    {
        [$tenant, $event] = $this->context($request, $id);
        $context = $this->audience->context($event);
        $data = $request->validate([
            'body' => 'required|string|max:1600',
            'store' => ['required', Rule::in($this->stores($tenant))],
            'photo_id' => 'nullable|integer|min:1',
            'groups' => 'required|array|min:1|max:3', 'groups.*' => ['distinct', Rule::in(self::GROUPS)],
            'nearby_series' => 'sometimes|array|max:30', 'nearby_series.*' => ['string', 'distinct', Rule::in($context['nearby']->keys()->all())],
            'cities' => 'nullable|string|max:500', 'state' => 'nullable|string|max:60', 'zips' => 'nullable|string|max:250',
            'save_area' => 'sometimes|boolean',
        ]);
        $body = trim($data['body']);
        $photo = $this->campaigns->selection($tenant, $request->user(), $event, $data['store'], $data['photo_id'] ?? null);
        $this->campaigns->validateMessage($body, $photo);
        $settings = [
            'nearby_series' => array_values($data['nearby_series'] ?? []),
            'cities' => $this->split($data['cities'] ?? ''), 'state' => trim($data['state'] ?? ''),
            'zips' => $this->split($data['zips'] ?? ''),
        ];
        if (($settings['cities'] && $settings['state'] === '') || collect($settings['zips'])->contains(fn ($zip) => ! preg_match('/^\d{5}$/D', $zip))) {
            throw ValidationException::withMessages(['cities' => 'Enter the state for your cities and use five-digit ZIP codes.']);
        }
        $selection = $this->audience->build($tenant, $event, $context, $settings, $data['groups']);
        if ($data['save_area'] ?? false) {
            $this->audience->save($event, $context, $settings);
        }
        $reviewId = (string) Str::uuid();
        $request->session()->put($this->reviewKey($event, $request), [
            'id' => $reviewId, 'version' => $event->version, 'expires' => now()->addHours(2)->timestamp,
            'draft' => ['body' => $body, 'photo' => $photo, 'settings' => $settings, 'groups' => $data['groups'], 'store' => $data['store']],
            'selection' => $selection,
        ]);

        return redirect()->route('team.events.show-text', ['id' => $id, 'tenant' => $tenant->slug, 'review' => $reviewId]);
    }

    public function send(Request $request, string $id)
    {
        return $this->launch($request, $id, false);
    }

    public function preview(Request $request, string $id)
    {
        return $this->launch($request, $id, true);
    }

    public function results(Request $request, string $id, int $campaign)
    {
        [$tenant, $event] = $this->context($request, $id);
        $run = MarketingCampaign::forTenantId($tenant->id)->where('source_label', 'team_show_text')
            ->where('target_snapshot->team_show_text->event_id', $event->id)
            ->where('target_snapshot->team_show_text->area_id', $event->area_id)->findOrFail($campaign);
        $filter = $request->validate(['status' => ['nullable', Rule::in(['Queued', 'Sent', 'Delivered', 'Failed', 'Replied', 'Opted out'])]])['status'] ?? '';
        $reviewId = $request->session()->get($this->reviewKey($event, $request).'.id');
        $backToReview = $reviewId && $run->slug === $this->campaigns->slug($event, $request->user(), $reviewId, true) ? $reviewId : null;

        return response()->view('team.show-text-results', ['tenant' => $tenant, 'event' => $event, 'run' => $run, 'filter' => $filter, 'backToReview' => $backToReview,
            ...$this->campaigns->results($run, $filter)])->header('Cache-Control', 'private, no-store');
    }

    private function launch(Request $request, string $id, bool $preview)
    {
        [$tenant, $event] = $this->context($request, $id);
        $data = $request->validate(['review' => 'required|uuid', 'excluded' => 'nullable|string|max:100000']);
        $actor = $request->user();
        $review = $request->session()->get($this->reviewKey($event, $request));
        abort_unless(($review['id'] ?? null) === $data['review'], 409, 'This review changed. Open the latest review before sending.');
        $existing = MarketingCampaign::forTenantId($tenant->id)->where('slug', $this->campaigns->slug($event, $actor, $review['id'], $preview))->first();
        if ($existing) {
            return redirect()->route('team.events.show-text.results', ['id' => $event->id, 'campaign' => $existing->id, 'tenant' => $tenant->slug]);
        }
        abort_unless($review['expires'] >= now()->timestamp && $review['version'] === $event->version, 409, 'This review expired or the show changed. Review again.');
        $draft = $review['draft'];
        abort_unless(in_array($draft['store'], $this->stores($tenant), true), 409, 'The selected store changed. Review again.');
        $photo = $this->campaigns->selection($tenant, $actor, $event, $draft['store'], $draft['photo']['asset_id'] ?? null);
        abort_unless($photo === $draft['photo'], 409, 'The photo changed. Review again.');
        if ($preview) {
            $recipient = $this->previewRecipient($request, $tenant);
            abort_unless($recipient, 422, 'A preview requires your opted-in customer profile.');
            $rows = [$recipient];
        } else {
            $excluded = json_decode($data['excluded'] ?? '[]', true);
            $ids = array_column($review['selection']['rows'], 'id');
            abort_unless(is_array($excluded) && array_is_list($excluded) && collect($excluded)->every(fn ($value) => is_int($value) && in_array($value, $ids, true)), 422);
            $rows = array_values(array_filter($review['selection']['rows'], fn ($row) => ! in_array($row['id'], $excluded, true)));
            $fresh = $this->audience->build($tenant, $event, $this->audience->context($event), $draft['settings'], $draft['groups']);
            $eligible = array_column($fresh['rows'], 'phone', 'id');
            abort_unless($rows && collect($rows)->every(fn ($row) => ($eligible[$row['id']] ?? null) === $row['phone']), 409,
                'The audience changed. Review again before sending.');
        }
        $run = $this->campaigns->queue($tenant, $actor, $event, $draft['store'], $review['id'], $draft['body'], $photo, $rows, $preview);

        return redirect()->route('team.events.show-text.results', ['id' => $event->id, 'campaign' => $run->id, 'tenant' => $tenant->slug]);
    }

    private function context(Request $request, string $id): array
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant && TeamShowTextCampaigns::enabledFor($tenant->id), 404);
        abort_unless($this->access->admin($this->access->actor($tenant, $request->user()))
            && app(TenantModuleAccessResolver::class)->canAccess($tenant->id, 'sms'), 403);
        $event = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'kind' => 'event'])->findOrFail($id);
        $this->access->record($tenant, $request->user(), $event);

        return [$tenant, $event];
    }

    private function stores(Tenant $tenant): array
    {
        return ShopifyStore::forTenantId($tenant->id)->pluck('store_key')
            ->filter(fn ($key) => is_string($key) && preg_match('/^[a-z0-9_-]+$/D', $key))->unique()->sort()->values()->all() ?: ['shared'];
    }

    private function reviewKey(TeamOperationRecord $event, Request $request): string
    {
        return 'team.show-text.'.$event->tenant_id.'.'.$event->id.'.'.$request->user()->id;
    }

    private function previewRecipient(Request $request, Tenant $tenant): ?array
    {
        $actor = $request->user();
        $profile = $actor->email_verified_at ? app(CanonicalMarketingProfileResolver::class)->byEmail($tenant->id, $actor->email) : null;
        if (! $profile || $profile->archived_at || $profile->sms_opted_out_at) {
            return null;
        }
        $evaluation = app(MarketingSmsEligibilityService::class)->evaluateProfile($profile, $tenant->id);

        return ($evaluation['eligible'] ?? false) && filled($evaluation['normalized_phone'] ?? null)
            ? ['id' => (int) $profile->id, 'phone' => $evaluation['normalized_phone'], 'reasons' => ['Owner preview']] : null;
    }

    private function defaultMessage(Tenant $tenant, TeamOperationRecord $event): string
    {
        $fields = app(\App\Services\Team\Operations\EventEssentials::class)->fields($event->data ?? []);
        $title = preg_replace('/^\s*\d{1,2}[.\/]\d{1,2}[.\/]\d{2,4}\s+/', '', $event->title);
        $when = $event->starts_on?->format('M j');
        if ($event->ends_on && (! $event->starts_on || ! $event->ends_on->isSameDay($event->starts_on))) {
            $when = trim($when.'–'.$event->ends_on->format('M j'), '–');
        }
        $show = 'Come see '.$tenant->name.' at '.$title.($when ? ', '.$when : '').'.';
        $location = implode(', ', array_filter([
            filled($fields['booth'] ?? $fields['booth_number'] ?? null) ? 'Booth '.($fields['booth'] ?? $fields['booth_number']) : null,
            $fields['venue'] ?? null, $fields['address'] ?? null,
        ], 'is_string'));
        $ticket = $event->data['reviewed_fields']['ticket_url'] ?? $event->data['ticket_url'] ?? null;

        return implode(' ', array_filter([$show, $location ? $location.'.' : null,
            is_string($ticket) && filter_var($ticket, FILTER_VALIDATE_URL) && parse_url($ticket, PHP_URL_SCHEME) === 'https' ? 'Tickets: '.$ticket : null,
            'Reply STOP to opt out.']));
    }

    private function split(string $value): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $value)))));
    }
}
