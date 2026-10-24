<?php

use App\Models\Tenant;
use App\Models\User;
use App\Proposals\Comment;
use App\Proposals\ProposalService;
use App\Proposals\Version;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$app = require __DIR__.'/proposal-review-browser-bootstrap.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$command = $argv[1] ?? 'seed';
if ($command === 'activity') {
    echo json_encode(app(\App\Proposals\Activity::class)->read(Version::findOrFail($argv[2] ?? '')), JSON_THROW_ON_ERROR);
    exit;
}
if ($command === 'inspect') {
    echo json_encode(['comments' => Comment::all()->map(fn ($comment) => ['id' => $comment->id, 'payload' => $comment->payload])->all()], JSON_THROW_ON_ERROR);
    exit;
}
if (in_array($command, ['expire', 'revoke'], true)) {
    $version = Version::findOrFail($argv[2] ?? '');
    if ($command === 'revoke') {
        $version->update(['revoked_at' => now()]);
    } else {
        // Test-clock equivalent only. Published production settings remain immutable.
        DB::table('service_proposal_versions')->where('id', $version->id)->update(['expires_at' => now()->subMinute()]);
    }
    echo json_encode(['changed' => $version->id], JSON_THROW_ON_ERROR);
    exit;
}
if ($command !== 'seed' || Schema::hasTable('tenants')) {
    throw new RuntimeException('Seed requires a fresh disposable database.');
}
Schema::create('tenants', static function (Blueprint $table): void {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->timestamps();
});
Schema::create('users', static function (Blueprint $table): void {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->string('password');
    $table->timestamp('email_verified_at')->nullable();
    $table->string('role');
    $table->boolean('is_active');
    $table->rememberToken();
    $table->timestamps();
});
Schema::create('tenant_user', static function (Blueprint $table): void {
    $table->id();
    $table->unsignedBigInteger('tenant_id');
    $table->unsignedBigInteger('user_id');
    $table->string('role');
    $table->boolean('membership_active');
    $table->timestamps();
});
(require dirname(__DIR__, 2).'/database/migrations/2026_10_22_150000_create_service_proposal_tables.php')->up();
$tenant = Tenant::create(['name' => 'Synthetic proposal owner', 'slug' => 'synthetic-proposal-owner']);
$owner = User::create(['name' => 'Synthetic Owner', 'email' => 'owner@example.invalid', 'password' => Str::random(40), 'role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
$tenant->users()->attach($owner->id, ['role' => 'owner', 'membership_active' => true]);
$service = app(ProposalService::class);
$draft = $service->leesMcRaeDraft($tenant, $owner->id)->refresh();
$versions = [];
foreach (['main', 'expired', 'revoked'] as $label) {
    $published = $service->publish($tenant, $owner->id, $draft->id, $draft->revision, 'Synthetic campus password', 7, (string) Str::uuid(), 'reviewer');
    $versions[$label] = ['id' => $published['version']->id, 'token' => $published['token']];
}
echo json_encode(['versions' => $versions, 'password' => 'Synthetic campus password', 'owner_id' => $owner->id, 'tenant_id' => $tenant->id], JSON_THROW_ON_ERROR);
