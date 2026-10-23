<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\AppManifest;
use App\Enterprise\AskModel;
use App\Enterprise\FinancePackets;
use App\Enterprise\MailboxGateway;
use App\Enterprise\RequestWorkflow;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/** Actual dedicated HTTP kernel. Install metadata must not need a tenant, schema or session. */
final class EnterpriseAppManifestTest extends TestCase
{
    private const ORIGIN = 'https://enterprise.example.invalid';

    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/apps/enterprise/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->make(Kernel::class)->bootstrap();
        $app->detectEnvironment(fn () => 'testing');
        $app['config']->set(['app.env' => 'testing', 'app.url' => self::ORIGIN,
            'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
            'enterprise_runtime.isolated' => true, 'enterprise_runtime.base_path' => '', 'enterprise_runtime.trial_mount_only' => false,
            'database.connections.enterprise' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'session.driver' => 'database', 'cache.default' => 'array', 'cache.stores.array' => ['driver' => 'array', 'serialize' => false],
            'enterprise.pwa_enabled' => true]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        DB::enableQueryLog();
        foreach ([AskModel::class, MailboxGateway::class, FinancePackets::class, RequestWorkflow::class] as $service) {
            $this->app->bind($service, fn () => throw new \LogicException('Install metadata must never resolve an operational/provider service.'));
        }
        $this->mount('');
    }

    private function mount(string $prefix): void
    {
        config(['enterprise_runtime.base_path' => $prefix, 'session.path' => $prefix ?: '/']);
        $this->withServerVariables(['SCRIPT_FILENAME' => $this->app->publicPath('index.php'), 'SCRIPT_NAME' => $prefix.'/index.php', 'PHP_SELF' => $prefix.'/index.php']);
    }

    private function assertNoOperationalActivity($response): void
    {
        $this->assertSame([], $response->headers->getCookies());
        $this->assertOnlySiloSchemaProbes();
        Http::assertNothingSent();
    }

    private function assertOnlySiloSchemaProbes(): void
    {
        // Preserve the actual global fail-closed silo guard. Inspect every SQL
        // statement; never filter operational queries out of the evidence.
        foreach (DB::getQueryLog() as $query) {
            $this->assertSame('select exists (select 1 from "main".sqlite_master where name = \'enterprise_client_silos\' and type = \'table\') as "exists"', $query['query']);
            $this->assertSame([], $query['bindings']);
        }
    }

    public function test_manifest_root_and_prefix_have_distinct_stable_canonical_scope_without_identity_or_session(): void
    {
        $ids = [];
        foreach (['', '/enterprise-campus', '/other-client/workspace'] as $prefix) {
            $this->mount($prefix);
            $response = $this->get(self::ORIGIN.$prefix.'/enterprise/app.webmanifest')->assertOk();
            $base = self::ORIGIN.$prefix.'/';
            $this->assertSame('application/manifest+json; charset=UTF-8', $response->headers->get('Content-Type'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertSame($base, $response->json('id'));
            $this->assertSame($base, $response->json('start_url'));
            $this->assertSame($base, $response->json('scope'));
            $this->assertSame('Everbranch Enterprise', $response->json('name'));
            $this->assertSame('standalone', $response->json('display'));
            $this->assertFalse($response->json('prefer_related_applications'));
            $this->assertSame(['192x192', '512x512'], array_column($response->json('icons'), 'sizes'));
            $this->assertSame(self::ORIGIN.$prefix.'/enterprise/app-icon-192.png', $response->json('icons.0.src'));
            $this->assertStringNotContainsString('tenant', $response->getContent());
            $this->assertStringNotContainsString('token', $response->getContent());
            $this->assertNoOperationalActivity($response);
            $ids[] = $response->json('id');
        }
        $this->assertCount(3, array_unique($ids));
    }

    public function test_icons_are_fixed_real_png_sizes_reusing_the_existing_brand_bytes(): void
    {
        $this->mount('/enterprise-campus');
        foreach ([180, 192, 512] as $size) {
            $response = $this->get(self::ORIGIN.'/enterprise-campus/enterprise/app-icon-'.$size.'.png')->assertOk()->assertHeader('Content-Type', 'image/png')
                ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
            $file = $response->baseResponse->getFile()->getPathname();
            $metadata = getimagesize($file);
            $this->assertSame([$size, $size, IMAGETYPE_PNG], array_slice($metadata, 0, 3));
            if ($size !== 192) {
                $source = $size === 180 ? 'public/apple-touch-icon.png' : 'public/favicon.png';
                $this->assertSame(hash_file('sha256', base_path($source)), hash_file('sha256', $file));
            }
            $this->assertNoOperationalActivity($response);
        }
        $this->get(self::ORIGIN.'/enterprise-campus/enterprise/app-icon-999.png')->assertNotFound();
        $this->assertOnlySiloSchemaProbes();
    }

    public function test_flag_runtime_and_trial_only_gates_deny_manifest_icons_and_version(): void
    {
        foreach (['enterprise.pwa_enabled', 'enterprise.runtime'] as $flag) {
            config([$flag => false]);
            foreach (['/enterprise/app.webmanifest', '/enterprise/app-icon-192.png', '/enterprise/app-version'] as $path) {
                $response = $this->get(self::ORIGIN.$path)->assertNotFound();
                $this->assertNoOperationalActivity($response);
            }
            config([$flag => true]);
        }
        config(['enterprise_runtime.trial_mount_only' => true, 'session.path' => '/enterprise/trial']);
        $this->get(self::ORIGIN.'/enterprise/app.webmanifest')->assertNotFound();
        $this->assertOnlySiloSchemaProbes();
    }

    public function test_misorigin_wrong_scheme_port_base_mount_and_query_cannot_choose_install_authority(): void
    {
        $this->mount('/enterprise-campus');
        foreach (['https://attacker.example.invalid/enterprise-campus/enterprise/app.webmanifest',
            'http://enterprise.example.invalid/enterprise-campus/enterprise/app.webmanifest',
            self::ORIGIN.':8443/enterprise-campus/enterprise/app.webmanifest',
            self::ORIGIN.'/enterprise/app.webmanifest', self::ORIGIN.'/enterprise-campus-other/enterprise/app.webmanifest',
            self::ORIGIN.'/enterprise-campus/enterprise/app.webmanifest?tenant=private',
            self::ORIGIN.'/enterprise-campus/enterprise/app-version?release=attacker',
            self::ORIGIN.'/enterprise-campus/enterprise/app-icon-192.png?token=secret'] as $url) {
            $response = $this->get($url, ['X-Forwarded-Prefix' => '/enterprise-campus'])->assertNotFound();
            $this->assertNoOperationalActivity($response);
        }
        $this->withServerVariables(['SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php']);
        $response = $this->get(self::ORIGIN.'/enterprise-campus/enterprise/app.webmanifest')->assertNotFound();
        $this->assertNoOperationalActivity($response);
    }

    public function test_version_endpoint_and_boot_links_are_current_mount_bound_and_never_cached(): void
    {
        $this->mount('/enterprise-campus');
        config(['app.release_id' => 'release-fixture-1']);
        $response = $this->get(self::ORIGIN.'/enterprise-campus/enterprise/app-version')->assertOk()
            ->assertJsonPath('version', 'release-fixture-1')->assertJsonPath('update_strategy', 'manual_reload')->assertJsonPath('offline_support', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertNoOperationalActivity($response);
        $request = \Illuminate\Http\Request::create(self::ORIGIN.'/enterprise-campus/', 'GET', [], [], [], [
            'SCRIPT_FILENAME' => $this->app->publicPath('index.php'), 'SCRIPT_NAME' => '/enterprise-campus/index.php', 'PHP_SELF' => '/enterprise-campus/index.php']);
        $links = AppManifest::links($request);
        $this->assertSame(self::ORIGIN.'/enterprise-campus/enterprise/app.webmanifest', $links['manifest']);
        $this->assertSame(self::ORIGIN.'/enterprise-campus/enterprise/app-version', $links['version_url']);
        $this->assertSame('release-fixture-1', $links['version']);
        config(['app.release_id' => 'release-fixture-2']);
        $this->get(self::ORIGIN.'/enterprise-campus/enterprise/app-version')->assertOk()->assertJsonPath('version', 'release-fixture-2');
        $this->assertOnlySiloSchemaProbes();
        $this->assertSame([], AppManifest::links(\Illuminate\Http\Request::create('https://attacker.example.invalid/')));
    }

    public function test_invalid_or_missing_release_identifier_uses_only_a_deterministic_fixed_asset_fingerprint(): void
    {
        config(['app.release_id' => null]);
        $fingerprint = AppManifest::version();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fingerprint);
        foreach (['unknown', '../private/config', "bad\nidentifier", ['unexpected'], 'https://attacker.example.invalid', str_repeat('x', 101)] as $invalid) {
            config(['app.release_id' => $invalid]);
            $this->assertSame($fingerprint, AppManifest::version());
        }
        $this->get(self::ORIGIN.'/enterprise/app-version')->assertOk()->assertJsonPath('version', $fingerprint);
        $this->assertOnlySiloSchemaProbes();
    }

    public function test_managed_runtime_binding_failure_is_not_exempted_for_generic_install_metadata(): void
    {
        $this->app->instance('enterprise.client_root_selected', true);
        config(['enterprise_client.client_id' => 'invalid-unprovisioned-client']);
        $response = $this->get(self::ORIGIN.'/enterprise/app.webmanifest')->assertStatus(503);
        $this->assertStringNotContainsString('Everbranch Enterprise', $response->getContent());
        $this->assertSame([], $response->headers->getCookies());
        $this->assertSame([], DB::getQueryLog());
        Http::assertNothingSent();
    }

    public function test_actual_kernel_renders_guarded_head_links_and_suppresses_them_for_public_sample(): void
    {
        Route::get('/install-head-fixture', fn () => response()->view('enterprise.app-install-head', ['enterpriseSample' => false]));
        Route::get('/sample-head-fixture', fn () => response()->view('enterprise.app-install-head', ['enterpriseSample' => true]));
        $this->mount('/enterprise-campus');
        $response = $this->get(self::ORIGIN.'/enterprise-campus/install-head-fixture')->assertOk()
            ->assertSee('rel="manifest"', false)->assertSee(self::ORIGIN.'/enterprise-campus/enterprise/app.webmanifest', false)
            ->assertSee('rel="apple-touch-icon"', false)->assertSee(self::ORIGIN.'/enterprise-campus/enterprise/app-icon-180.png', false);
        $this->assertNoOperationalActivity($response);
        $sample = $this->get(self::ORIGIN.'/enterprise-campus/sample-head-fixture')->assertOk()->assertDontSee('rel="manifest"', false);
        $this->assertNoOperationalActivity($sample);
        config(['enterprise.pwa_enabled' => false]);
        $disabled = $this->get(self::ORIGIN.'/enterprise-campus/install-head-fixture')->assertOk()->assertDontSee('rel="manifest"', false);
        $this->assertNoOperationalActivity($disabled);
    }
}
