<?php

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'marketing-desk-preview.sqlite' || ! app()->environment('local', 'testing')) {
    fwrite(STDERR, 'Use the isolated marketing-desk-preview.sqlite fixture database.');
    exit(90);
}
config(['marketing_desk.delivery_enabled' => false, 'marketing_desk.ai_replies_enabled' => false, 'marketing_desk.meta.execution_enabled' => false]);
use App\Models\MarketingBrandKnowledgeVersion;
use App\Models\MarketingSocialPost;
use App\Models\MarketingSocialPublication;
use App\Models\MessagingConversation;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantBudSetting;
use App\Models\TenantModuleEntitlement;
use App\Models\User;

$tenant = Tenant::firstOrCreate(['slug' => 'marketing-preview'], ['name' => 'Fictional Candle Studio']);
$user = User::firstOrCreate(['email' => 'marketing-preview@example.test'], ['name' => 'Marketing Preview', 'role' => 'admin', 'email_verified_at' => now(), 'password' => bcrypt('Preview-only-2026!')]);
$user->tenants()->syncWithoutDetaching([$tenant->id => ['role' => 'admin', 'membership_active' => true]]);
TenantAccessProfile::updateOrCreate(['tenant_id' => $tenant->id], ['plan_key' => 'growth', 'operating_mode' => 'hybrid', 'metadata' => ['marketing_desk' => ['timezone' => 'America/New_York', 'ai_budget' => ['cap_cents' => 5000, 'approved_at' => now()->toIso8601String(), 'approved_by' => $user->id]]]]);
foreach (['messaging', 'workflow_automations', 'ai', 'integrations'] as $module) {
    TenantModuleEntitlement::updateOrCreate(['tenant_id' => $tenant->id, 'module_key' => $module], ['availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included_in_plan', 'entitlement_source' => 'preview', 'price_source' => 'catalog']);
}
TenantBudSetting::updateOrCreate(['tenant_id' => $tenant->id], ['status' => 'approved', 'ai_status' => 'approved', 'ai_monthly_budget_cents' => 5000]);
MarketingBrandKnowledgeVersion::firstOrCreate(['tenant_id' => $tenant->id, 'title' => 'Our values and voice'], ['audience' => 'general', 'content' => ['values' => 'Time with family. Thoughtful craftsmanship. Honest answers.', 'voice' => 'Warm, useful, relaxed, and direct.', 'facts' => 'Fictional preview data only. We hand-pour small-batch candles.'], 'approved_by' => $user->id, 'approved_at' => now(), 'expires_at' => now()->addMonth()]);
if (! MarketingSocialPost::where('tenant_id', $tenant->id)->exists()) {
    foreach ([['A small ritual for a quieter evening. What helps you slow down at the end of the day?', 'draft', null], ['Behind every candle is a little care. A look inside our pouring room this week.', 'scheduled', now()->addDay()->setTime(15, 0)], ['A little light for the moments that matter most.', 'published', now()->subDays(3)]] as [$caption,$status,$when]) {
        $post = MarketingSocialPost::create(['tenant_id' => $tenant->id, 'created_by' => $user->id, 'revision' => 1, 'status' => $status, 'content' => ['caption' => $caption, 'media_type' => 'image', 'media_urls' => [url('/images/marketing/birthday-mountain-candle-hero.png')]], 'scheduled_at' => $when]);
        if ($status === 'published') {
            MarketingSocialPublication::create(['tenant_id' => $tenant->id, 'post_id' => $post->id, 'revision' => 1, 'connection_id' => 999, 'platform' => 'facebook', 'status' => 'published', 'external_id' => 'fictional', 'insights' => ['post_impressions_unique' => 1230, 'post_clicks' => 42], 'observed_at' => now()->subHour()]);
        }
    }
    $conversation = MessagingConversation::create(['tenant_id' => $tenant->id, 'channel' => 'email', 'email' => 'fictional-customer@example.test', 'subject' => 'A question about our next order', 'status' => 'open']);
    app(App\Services\Marketing\MessagingConversationService::class)->appendMessage($conversation, ['direction' => 'inbound', 'provider' => 'preview', 'provider_message_id' => 'fictional-inbound-1', 'body' => 'We loved our last order. Could someone help us choose scents for our shop this fall?', 'received_at' => now()->subMinutes(35), 'delivery_status' => 'received']);
}
// Repeatable photo fixtures are restricted by the disposable-database guard above.
foreach (MarketingSocialPost::forTenantId($tenant->id)->get() as $post) {
    if (empty($post->content['media_urls'])) {
        $post->update(['content' => array_replace($post->content, ['media_type' => 'image', 'media_urls' => [url('/images/marketing/birthday-mountain-candle-hero.png')]])]);
    }
}
config(['marketing_desk.enabled' => true, 'marketing_desk.tenant_ids' => [$tenant->id], 'automation_workflows.v2_enabled' => true, 'automation_workflows.v2_tenant_ids' => [$tenant->id]]);
if (! App\Models\AutomationWorkflow::where('tenant_id', $tenant->id)->exists()) {
    app(App\Services\Automation\V2\WorkflowDraftService::class)->createFromTemplate($tenant->id, 'marketing_retail_welcome', $user);
}
echo $tenant->id;
