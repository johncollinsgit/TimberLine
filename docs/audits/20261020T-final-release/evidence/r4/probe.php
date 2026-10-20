<?php
// Synthetic-only audit runner. Never point this at an existing database/server.
$repo = realpath($argv[1] ?? '');
$mode = $argv[2] ?? '';
$out = realpath($argv[3] ?? '');
if (!$repo || !$out || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '33318'
    || !in_array(getenv('DB_DATABASE'), ['everbranch_r4_source_test', 'everbranch_r4_restore_test'], true)
    || getenv('APP_ENV') !== 'testing') {
    throw new RuntimeException('Refusing non-private synthetic drill configuration.');
}
require $repo.'/vendor/autoload.php';
class R4HttpProbe extends Tests\TestCase {
    public function createApplication() {
        $app = require getenv('R4_REPO').'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }
    public function bootProbe(): void { $this->setUp(); }
    // This is a one-shot CLI runner rather than a PHPUnit process. Disconnect
    // explicitly; PHPUnit's global registry teardown does not exist here.
    public function closeProbe(): void { Illuminate\Support\Facades\DB::disconnect(); }
}
$probe = new R4HttpProbe('syntheticDrill');
$probe->bootProbe();
use App\Models\{Tenant, User, MarketingProfile, MarketingProfileLink, FieldServiceJob, FieldServiceTask, FieldServiceFinancialDocument, TenantMessagingCreditAccount, TenantMessagingLedgerEntry, LandlordOperatorAction};
use App\Services\Tenancy\LandlordTenantOperationsService;
use Illuminate\Support\Facades\{DB, Storage, Http, Mail, Notification};
use Illuminate\Http\UploadedFile;
Http::preventStrayRequests(); Mail::fake(); Notification::fake();
config(['filesystems.disks.local.root' => $out.'/exports', 'tenancy.landlord.primary_host' => 'app.theeverbranch.com',
    'tenancy.landlord.hosts' => ['app.theeverbranch.com'], 'tenancy.landlord.operator_roles' => ['admin'],
    'tenancy.landlord.operator_emails' => [], 'mail.default' => 'array', 'queue.default' => 'sync']);
function checkR4(bool $condition, string $label): void { if (!$condition) throw new RuntimeException($label); }
function stableR4(string $table, ?int $tenantId = null): array {
    $q = DB::table($table)->orderBy('id');
    if ($tenantId !== null) $q->where('tenant_id', $tenantId);
    return $q->get()->map(fn($r) => (array)$r)->all();
}
function exportR4($probe, Tenant $tenant, User $operator, string $out, string $tag): array {
    $url = 'http://app.theeverbranch.com/landlord/tenants/'.$tenant->id.'/operations/export';
    $probe->actingAs($operator)->post($url, ['tenant_id' => $tenant->id, 'tenant_slug' => $tenant->slug,
        'confirm_phrase' => 'confirm '.$tenant->slug, 'confirm_export' => '1', 'reason' => 'Synthetic recovery drill customer export'])->assertRedirect();
    $action = LandlordOperatorAction::where('tenant_id', $tenant->id)->where('action_type', 'tenant_ops.export_snapshot')->latest('id')->firstOrFail();
    checkR4($action->status === 'success', 'Actual export route did not succeed');
    $path = Storage::disk('local')->path($action->result['artifact_path']);
    $download = $probe->actingAs($operator)->get('http://app.theeverbranch.com/landlord/tenants/'.$tenant->id.'/operations/exports/'.$action->id)->assertOk();
    checkR4($download->streamedContent() === file_get_contents($path), 'Actual download route bytes differ');
    $payload = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    checkR4((int)$payload['tenant']['id'] === (int)$tenant->id, 'Wrong tenant in export');
    foreach ($payload['data'] as $rows) foreach ($rows as $row) checkR4((int)$row['tenant_id'] === (int)$tenant->id, 'Foreign tenant row leaked');
    copy($path, $out.'/'.$tag.'.json');
    return [$path, $payload, $action->id];
}
if ($mode === 'seed') {
    foreach (['a','b'] as $i => $suffix) {
        $tenant = Tenant::create(['name' => 'Fictional Recovery '.$suffix, 'slug' => 'r4-fictional-'.$suffix]);
        $owner = User::factory()->create(['name' => 'Fictional Owner '.$suffix, 'email' => 'r4-owner-'.$suffix.'@example.test', 'role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
        $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
        foreach ([1,2] as $n) {
            $customer = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Fictional '.$suffix, 'last_name' => 'Customer '.$n,
                'email' => 'r4-'.$suffix.$n.'@example.test', 'normalized_email' => 'r4-'.$suffix.$n.'@example.test',
                'notes' => 'Synthetic audit fixture only', 'tags' => ['synthetic-r4'], 'accepts_email_marketing' => false, 'accepts_sms_marketing' => false]);
            MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $customer->id,
                'source_type' => 'r4_fixture', 'source_id' => $suffix.$n, 'match_method' => 'synthetic', 'confidence' => '100.00']);
            DB::table('customer_external_profiles')->insert(['tenant_id' => $tenant->id, 'marketing_profile_id' => $customer->id, 'provider' => 'synthetic',
                'integration' => 'r4_fixture', 'store_key' => $suffix, 'external_customer_id' => $suffix.$n, 'email' => $customer->email]);
            $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $customer->id, 'assigned_user_id' => $owner->id,
                'title' => 'Fictional inspection '.$suffix.$n, 'status' => 'open', 'operational_status' => 'active', 'customer_name' => $customer->first_name,
                'customer_email' => $customer->email, 'metadata' => ['fixture' => true]]);
            FieldServiceTask::create(['tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'assigned_user_id' => $owner->id,
                'title' => 'Fictional safety check', 'status' => 'open']);
            FieldServiceFinancialDocument::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $customer->id, 'field_service_job_id' => $job->id,
                'source' => 'synthetic', 'document_type' => 'invoice', 'external_id' => 'r4-'.$suffix.$n, 'status' => 'draft',
                'total_amount' => '123.45', 'balance' => '123.45', 'currency' => 'USD']);
        }
        $account = TenantMessagingCreditAccount::create(['tenant_id' => $tenant->id, 'currency' => 'USD', 'balance_micros' => 5000000]);
        TenantMessagingLedgerEntry::create(['tenant_id' => $tenant->id, 'tenant_messaging_credit_account_id' => $account->id,
            'entry_type' => 'synthetic_funding', 'status' => 'settled', 'amount_micros' => 5000000, 'pricing_version' => 'synthetic-r4',
            'idempotency_key' => 'r4-'.$suffix, 'occurred_at' => now(), 'metadata' => ['synthetic' => true]]);
    }
    User::factory()->create(['name' => 'Fictional Recovery Operator', 'email' => 'r4-operator@example.test', 'role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
}
if (in_array($mode, ['seed','verify'], true)) {
    $operator = User::where('email','r4-operator@example.test')->sole();
    $graph = [];
    foreach (['tenants','users','tenant_user','marketing_profiles','marketing_profile_links','customer_external_profiles','field_service_jobs',
        'field_service_tasks','field_service_financial_documents','tenant_messaging_credit_accounts','tenant_messaging_ledger_entries'] as $table) {
        // Pivot table has no id; deterministic primary pair order instead.
        $graph[$table] = $table === 'tenant_user' ? DB::table($table)->orderBy('tenant_id')->orderBy('user_id')->get()->map(fn($r)=>(array)$r)->all() : stableR4($table);
    }
    $relationships = [];
    foreach (Tenant::orderBy('id')->get() as $tenant) {
        $jobs = FieldServiceJob::where('tenant_id',$tenant->id)->with(['customer','tasks','financialDocuments'])->orderBy('id')->get();
        checkR4($jobs->count() === 2, 'Missing restored jobs');
        foreach ($jobs as $job) {
            checkR4($job->customer && $job->customer->tenant_id === $tenant->id, 'Restored customer relationship mismatch');
            checkR4($job->tasks->count() === 1 && $job->tasks[0]->tenant_id === $tenant->id, 'Restored task relationship mismatch');
            checkR4($job->financialDocuments->count() === 1 && $job->financialDocuments[0]->marketing_profile_id === $job->customer->id, 'Restored invoice relationship mismatch');
        }
        $account = TenantMessagingCreditAccount::where('tenant_id',$tenant->id)->with('ledgerEntries')->sole();
        checkR4($account->ledgerEntries->sum('amount_micros') === $account->balance_micros, 'Ledger balance reconciliation failed');
        $relationships[$tenant->slug] = ['customers'=>2,'jobs'=>$jobs->count(),'tasks'=>$jobs->sum(fn($j)=>$j->tasks->count()),
            'invoice_total'=>FieldServiceFinancialDocument::where('tenant_id',$tenant->id)->sum('total_amount'),
            'ledger_balance_micros'=>$account->balance_micros,'ledger_sum_micros'=>$account->ledgerEntries->sum('amount_micros')];
        exportR4($probe, $tenant, $operator, $out, $mode.'-'.$tenant->slug);
    }
    $result = ['mode'=>$mode, 'source_sha'=>trim(shell_exec('git -C '.escapeshellarg($repo).' rev-parse HEAD')),
        'mysql_version'=>DB::selectOne('SELECT VERSION() AS version')->version, 'graph'=>$graph, 'relationships'=>$relationships, 'http_sent'=>0];
    file_put_contents($out.'/'.$mode.'-app-read.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}
if ($mode === 'customer-recovery') {
    $tenant = Tenant::where('slug','r4-fictional-a')->sole();
    $foreign = Tenant::where('slug','r4-fictional-b')->sole();
    $operator = User::where('email','r4-operator@example.test')->sole();
    $owner = User::where('email','r4-owner-a@example.test')->sole();
    [$path,$payload,$actionId] = exportR4($probe,$tenant,$operator,$out,'customer-before');
    $service = app(LandlordTenantOperationsService::class);
    $foreignBefore = [];
    foreach ($service->snapshotTables() as $table) $foreignBefore[$table] = stableR4($table,$foreign->id);
    $upload = fn(string $p) => new UploadedFile($p,basename($p),'application/json',null,true);
    $blocked = [];
    try { $service->restoreTenantSnapshot($foreign,$upload($path),true,false); } catch (RuntimeException $e) { $blocked['foreign_target'] = str_contains($e->getMessage(),'does not match'); }
    $tampered = $payload; $tampered['data']['marketing_profiles'][0]['email']='tampered@example.test';
    file_put_contents($out.'/tampered.json',json_encode($tampered));
    try { $service->restoreTenantSnapshot($tenant,$upload($out.'/tampered.json'),true,false); } catch (RuntimeException $e) { $blocked['tamper'] = str_contains($e->getMessage(),'checksum'); }
    $url='http://app.theeverbranch.com/landlord/tenants/'.$tenant->id.'/operations/export';
    $beforeActions=LandlordOperatorAction::count();
    $probe->actingAs($owner)->post($url,['tenant_id'=>$tenant->id,'tenant_slug'=>$tenant->slug,'confirm_phrase'=>'confirm '.$tenant->slug,'confirm_export'=>'1','reason'=>'Synthetic role denial check'])->assertForbidden();
    $blocked['tenant_owner_not_operator'] = LandlordOperatorAction::count() === $beforeActions;
    $probe->actingAs($owner)->get('http://app.theeverbranch.com/landlord/tenants/'.$tenant->id.'/operations/exports/'.$actionId)->assertForbidden();
    $blocked['tenant_owner_download_denied'] = true;
    $probe->actingAs($operator)->get('http://app.theeverbranch.com/landlord/tenants/'.$foreign->id.'/operations/exports/'.$actionId)->assertNotFound();
    $blocked['foreign_download_denied'] = true;
    $probe->actingAs($operator)->post($url,['tenant_id'=>$foreign->id,'tenant_slug'=>$foreign->slug,'confirm_phrase'=>'confirm '.$foreign->slug,'confirm_export'=>'1','reason'=>'Synthetic mismatched tenant check'])->assertRedirect()->assertSessionHasErrors();
    $blocked['route_context_mismatch'] = true;
    DB::table('marketing_profiles')->where('tenant_id',$tenant->id)->update(['email'=>'damaged@example.test']);
    DB::table('marketing_profile_links')->where('tenant_id',$tenant->id)->delete();
    DB::table('customer_external_profiles')->where('tenant_id',$tenant->id)->delete();
    $damaged=[]; foreach ($service->snapshotTables() as $table) $damaged[$table]=stableR4($table,$tenant->id);
    $dry=$service->restoreTenantSnapshot($tenant,$upload($path),true,true);
    foreach ($damaged as $table=>$rows) checkR4($rows===stableR4($table,$tenant->id),'Dry run mutated rows');
    $applied=$service->restoreTenantSnapshot($tenant,$upload($path),true,false);
    [$afterPath,$after]=exportR4($probe,$tenant,$operator,$out,'customer-after');
    foreach ($payload['data'] as $table=>$rows) checkR4($rows === $after['data'][$table], 'Customer export recovery mismatch: '.$table);
    foreach ($foreignBefore as $table=>$rows) checkR4($rows===stableR4($table,$foreign->id),'Foreign customer rows changed');
    foreach ($blocked as $name=>$pass) checkR4($pass,'Boundary did not block: '.$name);
    $result=['customer_export_scope'=>$service->snapshotTables(),'supported_marketing_snapshot_only'=>true,'not_a_job_or_ledger_export'=>true,
        'blocked'=>$blocked,'dry_run_no_mutation'=>true,'apply'=>$applied,'foreign_rows_unchanged'=>true,'all_export_data_equal'=>true,'http_sent'=>0];
    file_put_contents($out.'/customer-recovery-result.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}
Http::assertNothingSent(); Mail::assertNothingSent(); Notification::assertNothingSent();
$probe->closeProbe();
echo json_encode(['mode'=>$mode,'passed'=>true]).PHP_EOL;
