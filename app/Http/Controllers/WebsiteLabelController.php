<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\WebsiteShipment;
use App\Services\ManagedWebsite\WebsiteLabelDocumentService;
use App\Services\ManagedWebsite\WebsiteLabelPdf;
use App\Services\ManagedWebsite\WebsiteLabelPrintService;
use App\Services\ManagedWebsite\WebsitePrintProfileService;
use App\Services\ManagedWebsite\WebsiteShippingAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WebsiteLabelController extends Controller
{
    public function settings(Request $request): View
    {
        $tenant = $this->tenant($request);
        $profiles = app(WebsitePrintProfileService::class)->profiles($tenant, $request->user());

        return view('managed-website.printing.settings', compact('tenant', 'profiles'));
    }

    public function saveProfile(Request $request): RedirectResponse
    {
        app(WebsitePrintProfileService::class)->save($this->tenant($request), $request->user(), $request->all());

        return back()->with('status', 'Workstation print profile saved. Run the no-postage test before using a shipping label.');
    }

    public function testDocument(Request $request): Response
    {
        $this->tenant($request);
        [$layout, $orientation] = $this->layout($request);

        return $this->pdf(app(WebsiteLabelPdf::class)->render(app(WebsiteLabelPdf::class)->testPng(), $layout, $orientation), 'everbranch-no-postage-test.pdf', $request->boolean('inline'));
    }

    public function show(Request $request, WebsiteShipment $shipment): View
    {
        $tenant = $this->tenant($request, $shipment);
        $profiles = app(WebsitePrintProfileService::class)->profiles($tenant, $request->user());
        $history = $shipment->events()->forTenantId($tenant->id)->whereIn('event_type', ['label_print_requested', 'label_print_result'])->latest('id')->limit(40)->get();
        $printable = data_get($shipment->parcel, '_native.buy_state') === 'purchased' && ! data_get($shipment->parcel, '_native.retired', false);
        $requestId = (string) Str::uuid();

        return view('managed-website.printing.label', compact('tenant', 'shipment', 'profiles', 'history', 'printable', 'requestId'));
    }

    public function prepare(Request $request, WebsiteShipment $shipment): RedirectResponse
    {
        $this->tenant($request, $shipment);
        [, $orientation] = $this->layout($request);
        app(WebsiteLabelDocumentService::class)->prepare($shipment, $orientation);

        return back()->with('status', 'Actual-size label documents are ready. No postage was purchased.');
    }

    public function download(Request $request, WebsiteShipment $shipment): Response
    {
        $this->tenant($request, $shipment);
        [$layout, $orientation] = $this->layout($request);
        $bytes = app(WebsiteLabelDocumentService::class)->bytes($shipment, $layout, $orientation);

        return $this->pdf($bytes, 'shipping-label-'.$shipment->id.'-'.$layout.'.pdf', $request->boolean('inline'));
    }

    public function requestPrint(Request $request, WebsiteShipment $shipment): RedirectResponse
    {
        $this->tenant($request, $shipment);
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'profile_id' => ['required', 'uuid']]);
        app(WebsiteLabelPrintService::class)->request($shipment, $request->user(), $data['profile_id'], $data['request_id']);

        return redirect()->route('managed-website.labels.print-view', ['shipment' => $shipment, 'requestId' => $data['request_id']]);
    }

    public function printView(Request $request, WebsiteShipment $shipment, string $requestId): View
    {
        $tenant = $this->tenant($request, $shipment);
        $attempt = app(WebsiteLabelPrintService::class)->attempt($shipment, $request->user(), $requestId);
        abort_unless($attempt, 404);
        app(WebsiteLabelDocumentService::class)->bytes($shipment, $attempt['profile']['layout'], $attempt['profile']['orientation']);
        $result = $shipment->events()->forTenantId($tenant->id)->where('provider_event_id', 'print:'.$requestId.':result')->first();

        return view('managed-website.printing.print', compact('tenant', 'shipment', 'attempt', 'requestId', 'result'));
    }

    public function image(Request $request, WebsiteShipment $shipment, string $requestId): Response
    {
        $this->tenant($request, $shipment);
        $attempt = app(WebsiteLabelPrintService::class)->attempt($shipment, $request->user(), $requestId);
        abort_unless($attempt, 404);
        $documents = app(WebsiteLabelDocumentService::class);
        $documents->bytes($shipment, $attempt['profile']['layout'], $attempt['profile']['orientation']);
        $manifest = $documents->manifest($shipment, $attempt['profile']['orientation']);
        abort_unless(hash_equals($attempt['source_sha256'], $manifest['source_sha256'])
            && hash_equals($attempt['document_sha256'], $manifest['files'][$attempt['profile']['layout']]['sha256']), 409);

        return response(Storage::disk('local')->get($manifest['source_path']), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function printResult(Request $request, WebsiteShipment $shipment, string $requestId): RedirectResponse
    {
        $this->tenant($request, $shipment);
        $data = $request->validate(['result' => ['required', Rule::in(['operator_confirmed', 'failed', 'cancelled'])]]);
        app(WebsiteLabelPrintService::class)->result($shipment, $request->user(), $requestId, $data['result']);

        return redirect()->route('managed-website.labels.show', $shipment)->with('status', 'Print result recorded. Carrier handoff is a separate action.');
    }

    private function tenant(Request $request, ?WebsiteShipment $shipment = null): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        if (! $tenant instanceof Tenant) {
            $tenant = Tenant::query()->findOrFail((int) $request->attributes->get('current_tenant_id'));
        }
        abort_unless($request->user(), 403);
        app(WebsiteShippingAccessService::class)->assertCanView($tenant, $request->user());
        abort_if($shipment && $shipment->tenant_id !== $tenant->id, 404);

        return $tenant;
    }

    private function layout(Request $request): array
    {
        $data = $request->validate(['layout' => ['sometimes', Rule::in(array_keys(WebsiteLabelPdf::LAYOUTS))], 'orientation' => ['sometimes', Rule::in(['portrait', 'landscape'])], 'inline' => ['sometimes', 'boolean']]);

        return [$data['layout'] ?? 'thermal_4x6', $data['orientation'] ?? 'portrait'];
    }

    private function pdf(string $bytes, string $name, bool $inline): Response
    {
        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
