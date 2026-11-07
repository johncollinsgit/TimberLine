<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Shopify\ShopifyEmbeddedEmailComposerService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\Validator;

/** Prepares one existing campaign for review; never delivers a message or changes consent. */
class CustomerOutreachDraft
{
    public function authorize(Tenant $tenant, User $actor, MarketingProfile $profile): MarketingProfile
    {
        $actor = $actor->fresh();
        abort_unless($actor && $actor->is_active && $actor->email_verified_at
            && app(CustomerProfileAccess::class)->allows($actor, $tenant), 403);
        $membership = $actor->tenants()->whereKey($tenant->id)->wherePivot('membership_active', true)->first();
        abort_unless($membership && in_array(strtolower((string) $membership->pivot->role), ['owner', 'tenant_owner', 'admin'], true), 403);
        abort_unless(app(CustomerCommerceLinkService::class)->canLink($actor, $tenant->id), 403);
        abort_unless(app(TenantModuleAccessResolver::class)->canAccess($tenant->id, 'campaigns'), 403);
        $canonical = app(CanonicalMarketingProfileResolver::class)->canonical($profile, $tenant->id);
        abort_unless($canonical && ! $canonical->archived_at, 404);

        return $canonical;
    }

    public function preview(Tenant $tenant, MarketingProfile $profile, array $input): array
    {
        $profile = app(CanonicalMarketingProfileResolver::class)->byId($tenant->id, (int) $profile->id);
        abort_unless($profile && ! $profile->archived_at, 404);
        $i = Validator::make($input, ['channel' => 'required|in:sms,email', 'subject' => 'nullable|string|max:250', 'body' => 'required|string|max:10000', 'heading' => 'nullable|string|max:250', 'button_label' => 'nullable|string|max:100', 'button_url' => 'nullable|url:https|max:2000'])->validate();
        $commerce = app(CustomerCommerceSnapshotService::class)->forProfile($profile, $tenant->id, 100);
        $preferences = app(CustomerPreferenceService::class)->forStaff($profile, $tenant->id);
        $variables = [
            'first_name' => trim((string) $preferences['contact']['first_name']) ?: 'there', 'company_name' => $tenant->name,
            'most_purchased_scent' => $commerce['behavior']['most_purchased_scents'][0]['name'] ?? '',
            'most_purchased_product' => $commerce['behavior']['most_purchased_products'][0]['name'] ?? '',
            'rewards_name' => $commerce['rewards']['label'], 'rewards_balance' => $commerce['rewards']['balance'] ?? '',
            'last_purchase_date' => substr($commerce['behavior']['last_purchase_at'] ?? '', 0, 10),
            'favorite_scents' => implode(', ', $preferences['declared']['favorite_scents'] ?? []),
        ];
        $missing = [];
        $used = [];
        $render = function (string $text) use ($variables, &$missing, &$used): string {
            return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($match) use ($variables, &$missing, &$used) {
                $key = strtolower($match[1]);
                $used[$key] = true;
                if (! array_key_exists($key, $variables) || $variables[$key] === '') {
                    $missing[] = $key;
                }

                return $variables[$key] ?? '';
            }, $text);
        };
        $subject = trim($render($i['subject'] ?? 'A note from {{company_name}}'));
        $body = trim($render($i['body']));
        $heading = $render($i['heading'] ?? '');
        $button = $render($i['button_label'] ?? '');
        Validator::make(['subject' => $subject, 'body' => $body], ['subject' => 'string|max:250|regex:/\A[^\p{C}]*\z/u', 'body' => 'required|string|max:'.($i['channel'] === 'sms' ? 1600 : 10000)])->validate();
        $sections = [];
        if ($heading !== '') {
            $sections[] = ['id' => 'heading', 'type' => 'heading', 'text' => $heading];
        }
        $sections[] = ['id' => 'body', 'type' => 'text', 'html' => nl2br(e($body))];
        if ($button !== '' && ! empty($i['button_url'])) {
            $sections[] = ['id' => 'button', 'type' => 'button', 'label' => $button, 'href' => $i['button_url']];
        }
        $composed = app(ShopifyEmbeddedEmailComposerService::class)->compose($subject, $body, 'sections', $sections, null);
        $consent = $i['channel'] === 'email' ? $profile->accepts_email_marketing && filled($profile->normalized_email) : $profile->accepts_sms_marketing && filled($profile->normalized_phone);
        $decision = app(CustomerOutreachPolicy::class)->decision($profile, $i['channel']);
        abort_unless(($decision['preferences']['version'] ?? null) === $preferences['version'], 409, 'Preferences changed. Preview the draft again.');
        $variables = array_intersect_key($variables, $used);
        $explanation = app(CustomerOutreachExplanation::class)->build($profile, $commerce, $variables, $decision, $missing);

        return ['input' => $i, 'subject' => $subject, 'body' => $body, 'html' => $composed['html'], 'variables' => $variables,
            'outreach_decision' => $decision, 'explanation' => $explanation,
            'missing_variables' => array_values(array_unique($missing)), 'channel_consent_recorded' => (bool) $consent,
            'profile_partial' => $commerce['behavior']['partial'], 'basis' => $commerce['behavior']['basis'],
            'notice' => 'Draft only. Channel consent, suppression, sender readiness, quiet hours and approval are rechecked by the existing campaign delivery flow.'];
    }

    public function save(Tenant $tenant, User $actor, MarketingProfile $profile, array $input): MarketingCampaign
    {
        $profile = $this->authorize($tenant, $actor, $profile);
        $request = Validator::make($input, ['request_id' => 'required|uuid'])->validate()['request_id'];
        $slug = 'customer-outreach-'.$tenant->id.'-'.$request;
        $payload = array_intersect_key($input, array_flip(['channel', 'subject', 'body', 'heading', 'button_label', 'button_url']));
        $hash = hash('sha256', json_encode([$profile->id, $payload], JSON_THROW_ON_ERROR));

        return app(CustomerAccountFence::class)->forTenant($tenant->id, function () use ($tenant, $actor, $profile, $input, $slug, $hash) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $locked = MarketingProfile::forTenantId($tenant->id)->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $current = $this->authorize($tenant, $actor->fresh() ?? $actor, $locked);
            abort_unless((int) $current->id === (int) $profile->id, 409, 'The customer identity changed. Review the profile before preparing a new draft.');
            $profile = $current;
            $prior = MarketingCampaign::forTenantId($tenant->id)->where('slug', $slug)->first();
            if ($prior) {
                abort_unless(($prior->target_snapshot['outreach_payload_hash'] ?? null) === $hash && (int) $prior->created_by === (int) $actor->id, 409, 'This draft ID belongs to different content.');

                return $prior;
            }
            $preview = $this->preview($tenant, $profile, $input);
            abort_if($preview['missing_variables'], 422, 'Replace or remove the missing profile variables before saving.');
            $campaign = MarketingCampaign::create(['tenant_id' => $tenant->id, 'name' => 'Personal note · '.trim($profile->first_name.' '.$profile->last_name), 'slug' => $slug,
                'status' => 'draft', 'channel' => $preview['input']['channel'], 'objective' => 'retention', 'source_label' => 'customer_profile',
                'message_subject' => $preview['subject'], 'message_body' => $preview['body'], 'message_html' => $preview['input']['channel'] === 'email' ? $preview['html'] : null,
                'target_snapshot' => ['outreach_payload_hash' => $hash, 'profile_id' => $profile->id, 'variables' => $preview['variables'], 'coverage_partial' => $preview['profile_partial'], 'prepared_at' => now()->toIso8601String(), 'render_hash' => $this->renderHash($preview['subject'], $preview['body'], $preview['html']), 'text_hash' => $this->textHash($tenant->id, $profile->id, $preview['input']['channel'], $preview['body'])],
                'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $snapshot = $campaign->target_snapshot;
            $snapshot['explanation'] = $preview['explanation'];
            $snapshot['preparation_audit_id'] = app(CustomerOutreachExplanation::class)->recordPreparation($campaign, $profile, $preview['explanation'], $actor->id);
            $campaign->update(['target_snapshot' => $snapshot]);
            $variant = $campaign->variants()->create(['name' => 'Personal note', 'variant_key' => 'personal_note', 'message_text' => $preview['body'], 'weight' => 100, 'is_control' => true, 'status' => 'draft']);
            $reasons = [];
            $eligible = $preview['channel_consent_recorded'];
            if ($campaign->channel === 'sms') {
                $evaluation = app(MarketingSmsEligibilityService::class)->evaluateProfile($profile, $tenant->id);
                $eligible = (bool) ($evaluation['eligible'] ?? false);
                $reasons = $evaluation['reason_codes'] ?? [];
            } elseif (! $eligible) {
                $reasons[] = 'email_not_consented_or_missing';
            }
            $campaign->recipients()->create(['marketing_profile_id' => $profile->id, 'variant_id' => $variant->id, 'channel' => $campaign->channel,
                'status' => $eligible ? 'queued_for_approval' : 'skipped', 'reason_codes' => $reasons,
                'segment_snapshot' => ['purpose' => 'individual_customer_outreach', 'profile_id' => $profile->id], 'last_status_note' => $preview['notice']]);

            return $campaign;
        });
    }

    public function emailContent(MarketingCampaign $campaign, MarketingProfile $profile, string $body): ?array
    {
        if ($campaign->source_label !== 'customer_profile' || $campaign->channel !== 'email'
            || (int) $campaign->tenant_id !== (int) $profile->tenant_id || (int) ($campaign->target_snapshot['profile_id'] ?? 0) !== (int) $profile->id
            || $body !== $campaign->message_body || ! is_string($campaign->message_html)
            || ! hash_equals($campaign->target_snapshot['render_hash'] ?? '', $this->renderHash((string) $campaign->message_subject, $body, $campaign->message_html))) {
            return null;
        }

        return ['subject' => $campaign->message_subject, 'body' => $body, 'html' => $campaign->message_html];
    }

    private function renderHash(string $subject, string $body, string $html): string
    {
        return hash('sha256', json_encode([$subject, $body, $html], JSON_THROW_ON_ERROR));
    }

    public function smsBodyMatches(MarketingCampaign $campaign, MarketingProfile $profile, string $body): bool
    {
        return $campaign->source_label === 'customer_profile' && $campaign->channel === 'sms'
            && (int) $campaign->tenant_id === (int) $profile->tenant_id && (int) ($campaign->target_snapshot['profile_id'] ?? 0) === (int) $profile->id
            && $body === $campaign->message_body
            && hash_equals($campaign->target_snapshot['text_hash'] ?? '', $this->textHash($campaign->tenant_id, $profile->id, 'sms', $body));
    }

    private function textHash(int $tenant, int $profile, string $channel, string $body): string
    {
        return hash('sha256', json_encode([$tenant, $profile, $channel, $body], JSON_THROW_ON_ERROR));
    }
}
