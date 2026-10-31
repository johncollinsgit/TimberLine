<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Pos\PosDevelopmentAcceptance;
use App\Services\Pos\PosException;
use App\Services\Pos\PosReadiness;
use App\Services\Pos\PosStore;
use App\Services\Pos\SquareClient;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** An operator records reviewed facts; this does not create provider or Apple permissions. */
final class PosActivateCommand extends Command
{
    protected $signature = 'pos:activate {tenant} {register} {--actor=} {--review=} {--build=} {--acceptance-device=} {--acceptance-minutes=60} {--acceptance-cap=500} {--development-review=} {--apply}';

    protected $description = 'Preview/activate one reviewed register or a bounded named-device acceptance session';

    public function handle(PosStore $store, PosReadiness $readiness, SquareClient $square): int
    {
        $key = 'pos-start-'.hash('sha256', $store->runtime().':'.(int) $this->argument('tenant').':'.$this->argument('register'));

        return Cache::lock($key, 180)->block(5, fn () => $this->activate($store, $readiness, $square));
    }

    private function activate(PosStore $store, PosReadiness $readiness, SquareClient $square): int
    {
        $tenant = (int) $this->argument('tenant');
        $actor = User::query()->find((int) $this->option('actor'));
        $member = $store->db->table('tenant_user')->where('tenant_id', $tenant)->where('user_id', $actor?->id)->first();
        if (! $actor?->is_active || ! $actor->hasVerifiedEmail() || ! $member?->membership_active
            || ! in_array($member->role, ['owner', 'tenant_owner', 'admin'], true)
            || ! app(TenantModuleAccessResolver::class)->canAccess($tenant, 'pos')) {
            throw new PosException('existing_entitled_owner_required', 403);
        }
        $path = $this->option('review');
        if (! is_string($path) || ! is_file($path) || is_link($path) || filesize($path) > 65536 || (fileperms($path) & 0077) !== 0) {
            throw new PosException('private_merchant_review_required');
        }
        $review = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($review) || ($review['tenant_id'] ?? null) !== $tenant
            || ! is_string($review['actual_processing_rate'] ?? null) || strlen($review['actual_processing_rate']) < 3
            || strlen($review['actual_processing_rate']) > 200 || ($review['everbranch_markup_minor'] ?? null) !== 0
            || ($review['paid_square_subscription_added'] ?? null) !== false) {
            throw new PosException('merchant_rate_review_required');
        }
        $register = $store->rows('registers', $tenant)->where('id', $this->argument('register'))->firstOrFail();
        $connection = $square->connection($tenant, $register->connection_id);
        if (($review['merchant_id'] ?? null) !== $connection->merchant_id || ($review['location_id'] ?? null) !== $connection->provider_location_id) {
            throw new PosException('merchant_review_binding_mismatch');
        }
        $square->verify($connection, false);
        $connection->active = true;
        $connection->verified_at = now()->toDateTimeString();
        $settings = $store->decrypt($register->configuration);
        $developmentPath = $this->option('development-review');
        $development = is_string($developmentPath) && $developmentPath !== '';
        $build = $this->option('build');
        if (! is_string($build) || ! preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $build)) {
            throw new PosException('exact_build_required');
        }
        if ($device = $this->option('acceptance-device')) {
            $minutes = (int) $this->option('acceptance-minutes');
            $cap = (int) $this->option('acceptance-cap');
            if ($minutes < 1 || $minutes > 120 || $cap < 1 || $cap > 1000
                || ! $store->rows('assignments', $tenant)->where('register_id', $register->id)->where('user_id', $actor->id)
                    ->where('device_id', $device)->where('active', true)->exists()) {
                throw new PosException('bounded_assigned_acceptance_required');
            }
            $acceptance = ['actor_id' => $actor->id, 'device_id' => $device, 'build' => $build,
                'expires_at' => now()->addMinutes($minutes)->toIso8601String(), 'max_amount_minor' => $cap];
            if ($development) {
                if (config('pos_live.development_acceptance_enabled') !== true || config('pos_live.collection_enabled') !== false
                    || ! is_file($developmentPath) || is_link($developmentPath) || filesize($developmentPath) > 65536
                    || (fileperms($developmentPath) & 0077) !== 0 || isset($settings['development_acceptance']['sale_id'])) {
                    throw new PosException('private_unused_development_review_required');
                }
                $evidence = json_decode(file_get_contents($developmentPath), true, 32, JSON_THROW_ON_ERROR);
                if (! is_array($evidence) || ! app(PosDevelopmentAcceptance::class)->reviewMatches($evidence, $register, $connection, $build, $device)) {
                    throw new PosException('development_review_binding_mismatch');
                }
                $settings['development_acceptance'] = [...$acceptance, 'id' => bin2hex(random_bytes(16)),
                    'issued_at' => now()->toIso8601String(), 'evidence' => $evidence,
                    'document_hash' => hash_file('sha256', $developmentPath)];
                unset($settings['acceptance']);
            } else {
                $settings['acceptance'] = $acceptance;
            }
        } elseif ($connection->acceptance_build !== $build || ! $connection->acceptance_at) {
            throw new PosException('physical_build_acceptance_required');
        }
        if ($development && ! $device) {
            throw new PosException('bounded_assigned_acceptance_required');
        }
        if (! $development) {
            unset($settings['development_acceptance']);
        }
        $settings['merchant_review'] = ['actor_id' => $actor->id, 'reviewed_at' => now()->toIso8601String(),
            'document_hash' => hash_file('sha256', $path), 'actual_processing_rate' => $review['actual_processing_rate'], 'markup_minor' => 0];
        $register->configuration = $store->encrypt($settings);
        $register->collection_enabled = ! $development;
        $blockers = $readiness->blockers($register, $connection);
        $this->line(json_encode(['mode' => $this->option('apply') ? 'apply' : 'preview', 'register_id' => $register->id,
            'blockers' => $blockers, 'collection_enabled' => ! $development && ! $blockers,
            'development_acceptance_ready' => $development && ! $blockers, 'provider_permissions_created' => false]));
        if ($blockers || ! $this->option('apply')) {
            return $blockers ? self::FAILURE : self::SUCCESS;
        }
        $store->db->transaction(function () use ($store, $tenant, $connection, $register, $settings, $actor, $development): void {
            $store->rows('connections', $tenant)->where('id', $connection->id)->update(['active' => true, 'verified_at' => now()]);
            $store->rows('registers', $tenant)->where('id', $register->id)->update(['configuration' => $store->encrypt($settings), 'collection_enabled' => ! $development]);
            $store->event($tenant, $register->id, $development ? 'development_acceptance_activated' : 'collection_activated',
                ['actor_id' => $actor->id, 'review' => $settings['merchant_review'],
                    'acceptance' => $settings['development_acceptance'] ?? ($settings['acceptance'] ?? null)]);
        });

        return self::SUCCESS;
    }
}
