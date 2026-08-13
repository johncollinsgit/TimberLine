<?php

namespace App\Http\Controllers;

use App\Models\ServicePlanOffer;
use App\Models\ServicePlanVersionMedia;
use App\Services\FieldService\ServiceMembershipService;
use App\Services\FieldService\WorkspaceAssetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ServicePlanOfferPortalController extends Controller
{
    public function show(string $token)
    {
        $offer = $this->offer($token);

        return view('service-memberships.offer', ['offer' => $offer, 'token' => $token]);
    }

    public function accept(Request $request, string $token, ServiceMembershipService $memberships)
    {
        $offer = $this->offer($token);
        $data = $request->validate(['accepted_name' => ['required', 'string', 'max:255'], 'consent' => ['accepted'], 'addons' => ['nullable', 'array', 'max:20'], 'addons.*.id' => ['nullable', 'integer'], 'addons.*.quantity' => ['required', 'integer', 'min:1', 'max:99']]);
        try {
            $memberships->acceptOffer($offer, collect((array) ($data['addons'] ?? []))->filter(fn (array $addon): bool => filled($addon['id'] ?? null))->values()->all(), (string) $data['accepted_name'], $request->ip(), $request->userAgent());
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['offer' => $exception->getMessage()]);
        }

        return redirect()->route('service-plan-offers.show', ['token' => $token])->with('status', 'Your service-plan acceptance was recorded. The service team will confirm invoicing and activation.');
    }

    public function requestInvoice(string $token)
    {
        $offer = $this->offer($token);
        abort_unless($offer->status === 'accepted', 409);
        $offer->forceFill(['invoice_requested_at' => now()])->save();

        return redirect()->route('service-plan-offers.show', ['token' => $token])->with('status', 'Your invoice request was sent to the service team.');
    }

    public function media(string $token, ServicePlanVersionMedia $media, WorkspaceAssetService $assets)
    {
        $offer = $this->offer($token);
        abort_unless((int) $media->tenant_id === (int) $offer->tenant_id && collect((array) data_get($offer->snapshot, 'media', []))->contains('id', (int) $media->id), 404);
        $media->loadMissing('asset');
        abort_unless($media->asset !== null && ($disk = $assets->readableDisk($media->asset)) !== null, 404);

        return Storage::disk($disk)->response($media->asset->storage_path, $media->asset->file_name, ['Content-Type' => $media->asset->mime_type]);
    }

    protected function offer(string $token): ServicePlanOffer
    {
        abort_unless(strlen($token) === 64, 404);
        $offer = ServicePlanOffer::query()->where('portal_token_hash', hash('sha256', $token))->with('customer')->firstOrFail();
        abort_if($offer->revoked_at !== null || $offer->expires_at?->isPast(), 410, 'This service-plan offer is no longer available.');

        return $offer;
    }
}
