<?php

namespace App\Http\Controllers\Retail;

use App\Http\Controllers\Controller;
use App\Models\LandlordProspect;
use App\Models\TenantSite;
use App\Models\WebsiteCustomer;
use App\Services\Retail\RetailShowroomService;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RetailShowroomController extends Controller
{
    public function __construct(private RetailShowroomService $showroom) {}

    private function owner(Request $r): void
    {
        abort_unless($this->showroom->owner($r->user()), 403);
    }

    private function preview(Request $r, string $slug): void
    {
        if ($this->showroom->owner($r->user())) {
            return;
        }
        $grant = $r->session()->get('retail.preview.'.$slug);
        $shares = data_get($this->showroom->site($slug)->settings, 'retail_demo.shares', []);
        $shared = $grant && isset($shares[$grant]) && $shares[$grant]['expires_at'] > time();
        abort_unless($shared || $this->showroom->staffRoleOrNull($r->user(), $slug) !== null, 403);
    }

    private function response(array $data): \Illuminate\Http\JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function index(Request $r)
    {
        $this->owner($r);

        return response()->view('retail.showroom')->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function concept(Request $r, string $slug)
    {
        $this->preview($r, $slug);

        return response()->view('retail.showroom', ['initial' => $slug])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function bootstrap(Request $r)
    {
        $slug = (string) $r->query('merchant', '');
        if ($slug !== '') {
            $this->preview($r, $slug);
        } else {
            $this->owner($r);
        }
        $all = $slug === '' || $this->showroom->owner($r->user());
        $vendors = $all ? array_map(fn ($v) => $this->showroom->catalog($v['slug']), $this->showroom->definitions()['vendors']) : [$this->showroom->catalog($slug)];
        if ($all) {
            $vendors[] = $this->showroom->catalog('sawyer-naturals');
        }
        $sawyer = \App\Models\Tenant::where('slug', 'sawyer-naturals')->first();
        $staff = $all || $this->showroom->staffRoleOrNull($r->user(), $slug) !== null;

        return $this->response(['event' => $this->showroom->definitions()['event'], 'offer' => app(\App\Services\Retail\EvergroveOffer::class)->presentation(), 'vendors' => $vendors, 'owner' => $this->showroom->owner($r->user()), 'staff' => $staff, 'sawyer_url' => $sawyer && $this->showroom->owner($r->user()) ? url('/landlord/tenants/'.$sawyer->id) : null]);
    }

    public function quote(Request $r, string $slug)
    {
        $this->preview($r, $slug);
        $data = $r->validate(['lines' => 'required|array|max:40', 'lines.*.product_id' => 'required|string', 'lines.*.variant_id' => 'required|string', 'lines.*.quantity' => 'required|integer|min:1|max:20', 'destination' => 'required|array', 'destination.zip' => 'required|string', 'destination.state' => 'required|string']);

        return $this->response($this->showroom->quote($slug, $data['lines'], $data['destination']));
    }

    public function cart(Request $r, string $slug)
    {
        abort_unless($r->user(), 403);
        $this->showroom->staffRole($r->user(), $slug);
        $data = $r->validate(['lines' => 'present|array|max:40', 'lines.*.product_id' => 'required|string', 'lines.*.variant_id' => 'required|string', 'lines.*.quantity' => 'required|integer|min:1|max:20']);
        $cart = $this->showroom->syncCart($slug, $data['lines'], $r->session()->get('retail.cart.'.$slug));
        $r->session()->put('retail.cart.'.$slug, $cart->token);

        return $this->response(['saved' => true, 'is_demo' => true]);
    }

    public function checkout(Request $r, string $slug)
    {
        abort_unless($r->user(), 403);
        $this->showroom->staffRole($r->user(), $slug);
        $data = $r->validate(['lines' => 'required|array|max:40', 'destination' => 'required|array', 'destination.zip' => 'required|string', 'destination.state' => 'required|string', 'service' => 'required|string', 'customer_id' => 'nullable|integer', 'redeem_points' => 'nullable|integer']);

        return $this->response(['order' => $this->showroom->checkout($slug, $data, $r->user(), (string) $r->header('Idempotency-Key'))]);
    }

    public function staff(Request $r, string $slug)
    {
        abort_unless($r->user(), 403);
        $role = $this->showroom->staffRole($r->user(), $slug);

        return $this->response([...$this->showroom->staff($slug), 'role' => $role, 'user' => $r->user()->name]);
    }

    public function note(Request $r, string $slug)
    {
        abort_unless($r->user(), 403);
        $this->showroom->staffRole($r->user(), $slug);
        $data = $r->validate(['customer_id' => 'required|integer', 'body' => 'required|string|max:2000']);
        $site = $this->showroom->site($slug);
        DB::transaction(function () use ($r, $site, $data) {
            $customer = WebsiteCustomer::where('tenant_id', $site->tenant_id)->lockForUpdate()->findOrFail($data['customer_id']);
            abort_unless(data_get($customer->notes, 'is_demo') === true, 404);
            $notes = $customer->notes;
            $notes['private_notes'][] = ['body' => $data['body'], 'by' => $r->user()->name, 'at' => now()->toIso8601String()];
            $customer->notes = $notes;
            $customer->save();
        });

        return $this->response(['saved' => true]);
    }

    public function research(Request $r)
    {
        $this->owner($r);

        return $this->response(['prospects' => LandlordProspect::whereIn('source_snapshot->showroom->slug', array_column($this->showroom->definitions()['vendors'], 'slug'))->get()->sortBy(fn ($p) => data_get($p->source_snapshot, 'festival.walking_order', 99))->values()->map(fn ($p) => ['id' => $p->id, 'name' => $p->business_name, 'slug' => data_get($p->source_snapshot, 'showroom.slug'), 'notes' => $p->notes, 'follow_up_at' => $p->next_follow_up_at?->format('Y-m-d'), 'festival' => data_get($p->source_snapshot, 'festival', []), 'research' => data_get($p->source_snapshot, 'research', [])])]);
    }

    public function prospect(Request $r, string $slug)
    {
        $this->owner($r);
        $this->showroom->merchant($slug);
        $data = $r->validate(['walking_order' => 'sometimes|integer|min:1|max:99', 'stage' => 'required|in:Research,Visit,Shown,Interested,Proposal,Won,Not interested', 'shown' => 'boolean', 'interest' => 'nullable|string|max:500', 'notes' => 'nullable|string|max:4000', 'contact_name' => 'nullable|string|max:160', 'email' => 'nullable|email|max:255', 'follow_up_at' => 'nullable|date_format:Y-m-d']);
        $p = LandlordProspect::where('source_snapshot->showroom->slug', $slug)->firstOrFail();
        $snapshot = $p->source_snapshot;
        if (isset($data['walking_order'])) {
            $data['walking_order'] = (int) $data['walking_order'];
        }
        $snapshot['festival'] = [...($snapshot['festival'] ?? []), ...$data];
        $p->forceFill(['source_snapshot' => $snapshot, 'notes' => $data['notes'] ?? $p->notes, 'next_follow_up_at' => $data['follow_up_at'] ?? null, 'contact_name' => $data['contact_name'] ?? $p->contact_name, 'email' => $data['email'] ?? $p->email])->save();
        app(LandlordOperatorActionAuditService::class)->record(tenantId: null, actorUserId: $r->user()->id, actionType: 'retail_showroom_prospect_updated', targetType: 'landlord_prospect', targetId: $p->id, result: ['stage' => $data['stage'], 'source' => 'woolly-worm-2026']);

        return $this->response(['saved' => true]);
    }

    public function share(Request $r, string $slug)
    {
        $this->owner($r);
        $token = Str::random(48);
        $hash = hash('sha256', $token);
        $expires = time() + 86400;
        DB::transaction(function () use ($slug, $hash, $expires) {
            $site = TenantSite::whereKey($this->showroom->site($slug)->id)->lockForUpdate()->firstOrFail();
            $s = $site->settings;
            $s['retail_demo']['shares'][$hash] = ['expires_at' => $expires];
            $site->settings = $s;
            $site->save();
        });

        return $this->response(['url' => url('/retail/preview/'.$slug.'/'.$token), 'expires_at' => date(DATE_ATOM, $expires)]);
    }

    public function revoke(Request $r, string $slug)
    {
        $this->owner($r);
        DB::transaction(function () use ($slug) {
            $site = TenantSite::whereKey($this->showroom->site($slug)->id)->lockForUpdate()->firstOrFail();
            $s = $site->settings;
            $s['retail_demo']['shares'] = [];
            $site->settings = $s;
            $site->save();
        });

        return $this->response(['revoked' => true]);
    }

    public function shared(Request $r, string $slug, string $token)
    {
        abort_unless(config('retail_showroom.enabled'), 404);
        $hash = hash('sha256', $token);
        $shares = data_get($this->showroom->site($slug)->settings, 'retail_demo.shares', []);
        abort_unless(isset($shares[$hash]) && $shares[$hash]['expires_at'] > time(), 404);
        $r->session()->put('retail.preview.'.$slug, $hash);

        return $this->concept($r, $slug);
    }

    public function conversion(Request $r, string $slug)
    {
        $this->owner($r);
        $site = $this->showroom->site($slug);
        $catalog = $this->showroom->catalog($slug);

        return $this->response(['merchant' => $catalog['name'], 'activation_allowed' => false, 'status' => 'Merchant approval required', 'review' => ['branding' => $catalog['colors'], 'products' => count($catalog['products']), 'shipping_origin' => null, 'catalog_review' => 'Required', 'image_rights' => 'Required', 'demo_orders_to_copy' => 0, 'demo_customers_to_copy' => 0, 'demo_users_to_copy' => 0], 'questions' => $this->showroom->merchant($slug)['requires_merchant_confirmation']]);
    }
}
