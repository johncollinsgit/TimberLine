<?php

namespace App\Jobs;

use App\Models\WholesaleProspectDiscoveryRun;
use App\Services\Wholesale\GooglePlacesProspectClient;
use App\Services\Wholesale\WholesaleProspectIngestService;
use App\Services\Wholesale\WholesaleProspectWebsiteEnricher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunWholesaleProspectDiscovery implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 180;

    public function __construct(public int $runId, public int $tenantId) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(
        GooglePlacesProspectClient $client,
        WholesaleProspectIngestService $ingest,
        ?WholesaleProspectWebsiteEnricher $websiteEnricher = null
    ): void {
        $run = WholesaleProspectDiscoveryRun::query()->forAllTenants()
            ->where('tenant_id', $this->tenantId)
            ->findOrFail($this->runId);
        if ($run->cancelled_at !== null) {
            return;
        }

        $run->forceFill(['status' => 'running', 'started_at' => $run->started_at ?? now(), 'error_message' => null])->save();
        $remaining = (int) $run->maximum_results;
        $discovered = $created = $duplicates = $requests = 0;
        $sourceLog = [];

        try {
            foreach ((array) $run->search_phrases as $phrase) {
                if ($remaining <= 0 || $run->fresh()->cancelled_at !== null) {
                    break;
                }

                $query = trim((string) $phrase.' in '.(string) $run->search_region);
                $places = $client->searchText($query, min(20, $remaining));
                $requests++;
                $sourceLog[] = ['provider' => 'google_places', 'query' => $query, 'returned' => count($places), 'requested_at' => now()->toIso8601String()];

                foreach ($places as $place) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $result = $ingest->ingestGooglePlace($run, $place, $query);
                    if ($run->website_enrichment && $result['created'] && filled($result['prospect']->website)) {
                        ($websiteEnricher ?? app(WholesaleProspectWebsiteEnricher::class))->enrich($result['prospect']);
                    }
                    $discovered++;
                    $created += $result['created'] ? 1 : 0;
                    $duplicates += $result['exact_duplicate'] ? 1 : 0;
                    $remaining--;
                }
            }

            $run->forceFill([
                'status' => $run->fresh()->cancelled_at ? 'cancelled' : 'completed',
                'api_request_count' => $requests,
                'actual_api_cost' => round($requests * (float) config('services.google_places.estimated_cost_per_request'), 4),
                'results_discovered' => $discovered,
                'results_created' => $created,
                'duplicates_suppressed' => $duplicates,
                'source_log' => $sourceLog,
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'api_request_count' => $requests,
                'actual_api_cost' => round($requests * (float) config('services.google_places.estimated_cost_per_request'), 4),
                'source_log' => $sourceLog,
                'error_message' => $exception->getMessage(),
            ])->save();
            throw $exception;
        }
    }
}
