<?php

namespace App\Services\Team\Operations;

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Shopify\ShopifyEmbeddedEmailComposerService;

/** Operational templates never interpolate arbitrary notes, compensation or source payloads. */
class OperationsTemplates
{
    public const TOKENS = ['company_name', 'recipient_name', 'record_title', 'starts_on', 'ends_on', 'venue', 'address', 'setup_time', 'event_hours', 'instructions', 'contact_name', 'next_action', 'shipping', 'pickup'];

    public function catalog(Tenant $tenant, User $user, TeamArea $area): array
    {
        $access = app(OperationsAccess::class);
        $access->area($tenant, $user, $area->id);
        $admin = $access->admin($access->actor($tenant, $user));
        $saved = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'email_template'])->whereIn('status', $admin ? ['draft', 'published', 'archived'] : ['published'])->orderBy('title')->get();

        return ['items' => $saved->map(fn ($r) => ['id' => $r->id, 'name' => $r->title, 'status' => $r->status, 'version' => $r->version, 'data' => $r->data])->all(), 'starters' => $this->starters($area->profile), 'tokens' => self::TOKENS, 'notice' => 'Templates are reusable drafts. Choosing or publishing a template does not send email.'];
    }

    public function starters(string $profile): array
    {
        $rows = $profile === 'events' ? [
            ['event_logistics', 'Staff event briefing', '{{record_title}} · your event briefing', "Hi {{recipient_name}},\n\nHere are the details for {{record_title}} ({{starts_on}}–{{ends_on}}).\n\nVenue: {{venue}}\nAddress: {{address}}\nSetup: {{setup_time}}\nEvent hours: {{event_hours}}\n\n{{instructions}}\n\nPlease reply if anything needs attention.\n{{company_name}}", 'day_before'],
            ['event_checklist', 'Coordinator preparation check', '{{record_title}} · preparation check', "Hi {{recipient_name}},\n\nPlease review staffing, travel, documents and organizer details for {{record_title}} on {{starts_on}}.\n\nNext action: {{next_action}}\n\nConfirm any missing details before briefing the team.\n{{company_name}}", 'missing_information'],
            ['event_update', 'Event update', 'Update · {{record_title}}', "Hi {{recipient_name}},\n\nAn update for {{record_title}}:\n\n{{instructions}}\n\nPlease reply with any questions.\n{{company_name}}", 'manual'],
        ] : [
            ['wholesale_intro', 'Account introduction', 'Hello from {{company_name}}', "Hi {{recipient_name}},\n\nThank you for your interest in {{company_name}}. We would love to learn more about your store and what you are looking for.\n\nPlease reply with your preferred products, timing and any questions.\n\nThank you,\n{{company_name}}", 'manual'],
            ['wholesale_followup', 'Account follow-up', '{{record_title}} · following up', "Hi {{recipient_name}},\n\nFollowing up on {{record_title}}.\n\n{{next_action}}\n\nPlease let us know how we can help.\n{{company_name}}", 'followup_due'],
            ['wholesale_order', 'Order coordination', '{{record_title}} · order coordination', "Hi {{recipient_name}},\n\nPlease confirm the order details for {{record_title}}.\n\nShipping: {{shipping}}\nPickup: {{pickup}}\nNext action: {{next_action}}\n\nWe will confirm timing once the details are reviewed.\n{{company_name}}", 'manual'],
            ['wholesale_reorder', 'Reorder check-in', 'Checking in · {{record_title}}', "Hi {{recipient_name}},\n\nHow are things going at {{record_title}}? Let us know if you would like help planning your next order or choosing products.\n\nThank you,\n{{company_name}}", 'reorder_check'],
        ];

        return array_map(fn ($r) => ['id' => $r[0], 'name' => $r[1], 'data' => ['subject' => $r[2], 'text' => $r[3], 'trigger' => $r[4]]], $rows);
    }

    public function validate(array $data): void
    {
        validator($data, ['subject' => 'required|string|max:200|not_regex:/[\r\n]/', 'text' => 'required|string|max:20000', 'cta_label' => 'nullable|string|max:100', 'cta_url' => 'nullable|url:https|max:2000'])->validate();
        preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/', ($data['subject'] ?? '').($data['text'] ?? ''), $matches);
        abort_unless(! array_diff($matches[1], self::TOKENS), 422, 'Use only the listed operational template fields.');
    }

    public function render(Tenant $tenant, User $user, TeamArea $area, TeamOperationRecord $record, User $recipient, array $input): array
    {
        $access = app(OperationsAccess::class);
        $access->record($tenant, $user, $record);
        $access->record($tenant, $recipient, $record);
        $data = $input;
        if (! empty($input['template_id'])) {
            $template = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'email_template', 'status' => 'published'])->findOrFail($input['template_id']);
            $data = $template->data;
        } elseif (! empty($input['starter_id'])) {
            $starter = collect($this->starters($area->profile))->firstWhere('id', $input['starter_id']);
            abort_unless($starter, 422);
            $data = $starter['data'];
        }
        $this->validate($data);
        // Source notes must first pass field review; unreviewed extraction is never sent.
        $fields = $record->connection_id ? ($record->data['reviewed_fields'] ?? []) : $record->data;
        $variables = ['company_name' => $tenant->name, 'recipient_name' => $recipient->name, 'record_title' => $record->title, 'starts_on' => $record->starts_on?->toDateString(), 'ends_on' => $record->ends_on?->toDateString(), 'contact_name' => $fields['contact'] ?? null];
        foreach (self::TOKENS as $key) {
            if (! array_key_exists($key, $variables)) {
                $variables[$key] = is_scalar($fields[$key] ?? null) ? (string) $fields[$key] : null;
            }
        }
        $missing = [];
        $replace = function (string $text) use ($variables, &$missing): string {
            return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($variables, &$missing) {
                if (blank($variables[$m[1]] ?? null)) {
                    $missing[] = $m[1];

                    return '[Confirm '.str_replace('_', ' ', $m[1]).']';
                }

                return (string) $variables[$m[1]];
            }, $text);
        };
        $subject = mb_substr(str_replace(["\r", "\n"], ' ', $replace($data['subject'])), 0, 200);
        $text = $replace($data['text']);
        abort_if(mb_strlen($text) > 20000, 422, 'The resolved message is too long. Shorten the template or its reviewed source details.');
        $sections = [['type' => 'text', 'html' => nl2br(e($text))]];
        if (filled($data['cta_url'] ?? '') && filled($data['cta_label'] ?? '')) {
            $sections[] = ['type' => 'button', 'label' => $data['cta_label'], 'href' => $data['cta_url'], 'align' => 'left'];
            $text .= "\n\n".$data['cta_label'].': '.$data['cta_url'];
        }
        $composed = app(ShopifyEmbeddedEmailComposerService::class)->compose($subject, $text, 'sections', $sections, null);

        return ['subject' => $subject, 'text' => $text, 'html' => $composed['html'], 'missing_fields' => array_values(array_unique($missing)), 'record_version' => $record->version, 'template_id' => $template->id ?? null, 'template_version' => $template->version ?? null, 'notice' => 'Review the current recipient, details and formatted message. Missing fields must be completed before preparation.'];
    }
}
