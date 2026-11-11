<?php

namespace App\Http\Controllers;

use App\Models\MarketingProfile;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use App\Services\Pos\PosCustomerPurchases;
use App\Services\Pos\PosReceiptCustomers;
use App\Services\Pos\PosService;
use App\Services\Pos\PosStore;
use App\Services\Reporting\SalesChannelSummaryService;
use App\Services\Team\Studio\EventCommerceReport;
use Illuminate\Http\Request;

/** Event financial access is checked by the same report used by Pay. No register/device authority is invented. */
class TeamConfirmedEventSalesController extends Controller
{
    public function __invoke(Request $request, string $id)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $input = $request->validate(['page' => 'sometimes|integer|min:1|max:100000']);
        $event = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'kind' => 'event'])->findOrFail($id);
        $report = app(EventCommerceReport::class)->forEvent($tenant, $request->user(), $event, true);
        $store = app(PosStore::class);
        $ids = $report['sales']['receipt_ids'];
        unset($report['sales']['receipt_ids'], $report['sales']['square_order_ids']);
        $external = app(SalesChannelSummaryService::class)->forReviewedSquareEvent($tenant->id, $report['event_id']);
        $index = $store->rows('sales', $tenant->id)->whereIn('id', $ids)->get(['id', 'created_at'])->map(fn ($sale) => ['source' => 'pos_sale', 'source_id' => $sale->id,
            'occurred_at' => \Carbon\CarbonImmutable::parse($sale->created_at, 'UTC')->toIso8601String()])->all();
        foreach ($external['purchases'] as $purchase) {
            $index[] = $purchase;
        }
        $page = $input['page'] ?? 1;
        $selected = collect($index)->sortBy([['occurred_at', 'desc'], ['source_id', 'asc']])->slice(($page - 1) * 20, 20);
        $rows = [];
        foreach ($selected as $row) {
            if ($row['source'] === 'pos_sale') {
                $sale = $store->rows('sales', $tenant->id)->where('id', $row['source_id'])->firstOrFail();
                $snapshot = app(PosService::class)->snapshot($sale);
                $customerReview = false;
                try {
                    $snapshot = app(PosReceiptCustomers::class)->decorate($sale, $snapshot);
                } catch (\Throwable) {
                    $snapshot['customer'] = null;
                    $customerReview = true;
                }
                if (isset($snapshot['customer']['id'])) {
                    $profile = app(CanonicalMarketingProfileResolver::class)->byId($tenant->id, (int) $snapshot['customer']['id']);
                    $customerReview = ! $profile || $profile->archived_at !== null;
                    $snapshot['customer'] = $customerReview ? null : ['id' => $profile->id, 'name' => trim($profile->first_name.' '.$profile->last_name) ?: ($profile->email ?: $profile->phone ?: 'Customer')];
                }
                $rows[] = ['source' => 'pos_sale', 'id' => $sale->id, 'number' => 'EB-'.strtoupper(substr($sale->id, 0, 12)),
                    'occurred_at' => $row['occurred_at'], 'amount_minor' => (int) $sale->amount_minor,
                    'refunded_minor' => app(PosCustomerPurchases::class)->confirmedRefunds($sale), 'tender' => $snapshot['tender'] ?? (($snapshot['reward_only'] ?? false) ? 'rewards' : 'card'),
                    'customer' => $snapshot['customer'] ?? null, 'customer_review_required' => $customerReview,
                    'lines' => array_map(fn ($line) => array_intersect_key($line, array_flip(['title', 'quantity'])), $snapshot['terms']['display'])];
            } else {
                $profile = $row['customer_id'] ? MarketingProfile::forTenantId($tenant->id)->find($row['customer_id']) : null;
                $rows[] = ['source' => 'square_order', 'id' => $row['source_id'], 'number' => 'Square '.$row['source_id'], 'occurred_at' => $row['occurred_at'],
                    'amount_minor' => $row['amount_minor'], 'refunded_minor' => $row['refunded_minor'],
                    'tender' => implode(' / ', array_column($row['tenders'], 'tender')), 'customer' => $profile ? ['id' => $profile->id, 'name' => trim($profile->first_name.' '.$profile->last_name) ?: ($profile->email ?: $profile->phone)] : null,
                    'customer_review_required' => $row['customer_review_required'] ?? false,
                    'lines' => array_map(fn ($line) => array_intersect_key($line, array_flip(['title', 'quantity'])), $row['lines'])];
            }
        }

        return response()->json(['report' => $report, 'sales' => $rows, 'page' => $page, 'has_more' => count($index) > $page * 20])->header('Cache-Control', 'private, no-store');
    }
}
