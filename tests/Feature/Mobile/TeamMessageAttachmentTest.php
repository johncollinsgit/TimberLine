<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use App\Services\FieldService\TeamCommunicationService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

test('private message files upload safely and only conversation members can download them', function (): void {
    $this->withoutVite();
    Queue::fake();
    Storage::fake('local');
    $tenant = Tenant::query()->create(['name' => 'Files Crew', 'slug' => 'files-crew']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    $sender = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $recipient = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $outsider = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    foreach ([$sender, $recipient, $outsider] as $user) {
        $user->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    }
    $team = app(TeamCommunicationService::class);
    $channel = $team->directChannel($tenant, $sender, $recipient);
    $otherChannel = $team->directChannel($tenant, $sender, $outsider);
    $base = '/api/mobile/v1/workspaces/files-crew/field-service/channels/'.$channel->id;
    $bytes = "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
    $hash = hash('sha256', $bytes);
    Sanctum::actingAs($sender, ['mobile:read', 'mobile:write']);
    $data = ['file_name' => 'site-plan.pdf', 'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'client_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'];
    $upload = $this->postJson($base.'/attachments/initialize', $data)->assertCreated()->json();
    $this->postJson($base.'/attachments/initialize', $data)->assertCreated()->assertJsonPath('upload_id', $upload['upload_id']);
    $endpoint = $base.'/attachments/'.$upload['upload_id'];
    $chunk = ['token' => $upload['token'], 'offset' => 0, 'contents_base64' => base64_encode($bytes), 'checksum_sha256' => $hash];
    $this->postJson($endpoint.'/chunks', [...$chunk, 'checksum_sha256' => str_repeat('0', 64)])->assertUnprocessable();
    $this->postJson($endpoint.'/chunks', $chunk)->assertOk()->assertJsonPath('received_bytes', strlen($bytes));
    $this->postJson($endpoint.'/chunks', $chunk)->assertOk();
    $this->postJson($endpoint.'/complete', ['token' => $upload['token'], 'checksum_sha256' => $hash])->assertOk();
    $this->get($endpoint)->assertNotFound();
    $message = ['client_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'attachment_ids' => [$upload['upload_id']]];
    $this->postJson($base.'/messages', $message)->assertCreated()->assertJsonPath('message.body', '')->assertJsonPath('message.attachments.0.name', 'site-plan.pdf');
    $this->postJson($base.'/messages', $message)->assertCreated()->assertJsonCount(1, 'message.attachments');
    $this->postJson('/api/mobile/v1/workspaces/files-crew/field-service/channels/'.$otherChannel->id.'/messages', $message)->assertConflict();
    $this->get($endpoint)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    Sanctum::actingAs($recipient, ['mobile:read', 'mobile:write']);
    $this->get($endpoint)->assertOk();
    $this->getJson($base)->assertOk()->assertJsonPath('messages.0.attachments.0.id', $upload['upload_id']);
    $this->postJson($endpoint.'/complete', ['token' => $upload['token'], 'checksum_sha256' => $hash])->assertNotFound();
    Sanctum::actingAs($outsider, ['mobile:read', 'mobile:write']);
    $this->get($endpoint)->assertNotFound();
    Sanctum::actingAs($recipient, ['mobile:read', 'mobile:write']);
    $recipient->tenants()->updateExistingPivot($tenant->id, ['membership_active' => false]);
    $this->get($endpoint)->assertNotFound();
});

test('message upload rejects disguised files and files selected in another conversation', function (): void {
    $this->withoutVite();
    Queue::fake();
    Storage::fake('local');
    $tenant = Tenant::query()->create(['name' => 'File Validation', 'slug' => 'file-validation']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    $sender = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $recipient = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    foreach ([$sender, $recipient] as $user) {
        $user->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    }
    $team = app(TeamCommunicationService::class);
    $private = $team->directChannel($tenant, $sender, $recipient);
    $company = $team->companyChannel($tenant, $sender);
    Sanctum::actingAs($sender, ['mobile:read', 'mobile:write']);
    $base = '/api/mobile/v1/workspaces/file-validation/field-service/channels/';
    $bytes = '<html><script>alert(1)</script></html>';
    $upload = $this->postJson($base.$private->id.'/attachments/initialize', ['file_name' => 'fake.pdf', 'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'client_uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'])->assertCreated()->json();
    $endpoint = $base.$private->id.'/attachments/'.$upload['upload_id'];
    $this->postJson($endpoint.'/chunks', ['token' => $upload['token'], 'offset' => 0, 'contents_base64' => base64_encode($bytes), 'checksum_sha256' => hash('sha256', $bytes)])->assertOk();
    $this->postJson($endpoint.'/complete', ['token' => $upload['token'], 'checksum_sha256' => hash('sha256', $bytes)])->assertUnprocessable();
    $this->postJson($base.$company->id.'/messages', ['client_uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'attachment_ids' => [$upload['upload_id']]])->assertNotFound();
    $this->deleteJson($endpoint, ['token' => $upload['token']])->assertOk();
    $this->assertDatabaseCount('team_message_attachments', 0);
    $this->assertDatabaseCount('team_messages', 0);
});
