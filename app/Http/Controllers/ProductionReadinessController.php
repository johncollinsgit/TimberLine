<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProductionReadinessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            $this->assertRequiredConfiguration();
            DB::selectOne('select 1 as ready');

            $probeKey = 'everbranch:readiness:'.(string) config('app.release_id', 'unknown');
            Cache::store()->put($probeKey, 'ok', now()->addSeconds(30));
            if (Cache::store()->get($probeKey) !== 'ok') {
                throw new \RuntimeException('Cache probe did not round-trip.');
            }

            return response()->json([
                'status' => 'ok',
                'release' => (string) config('app.release_id', 'unknown'),
            ]);
        } catch (Throwable $exception) {
            Log::error('production.readiness.failed', ['exception' => $exception::class]);

            return response()->json(['status' => 'unavailable'], 503);
        }
    }

    private function assertRequiredConfiguration(): void
    {
        foreach ([config('app.key'), config('database.default'), config('cache.default')] as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new \RuntimeException('Required production configuration is unavailable.');
            }
        }
    }
}
