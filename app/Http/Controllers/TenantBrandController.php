<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantBrandProfile;
use App\Services\Tenancy\TenantBrandProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantBrandController extends Controller
{
    public function edit(Request $request, TenantBrandProfileService $brands): View
    {
        $tenant = $this->tenant($request);
        $this->authorizeCustomization($request, $tenant, $brands);
        $profile = $brands->ensureForTenant($tenant, $request->user());

        return view('tenant-branding.edit', [
            'tenant' => $tenant,
            'profile' => $profile,
            'theme' => $brands->presentationFor($tenant),
            'assets' => $profile->assets()->latest()->limit(12)->get(),
            'kit' => $this->isCollinsTenant($tenant) ? $this->kitManifest() : [],
        ]);
    }

    public function update(Request $request, TenantBrandProfileService $brands): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeCustomization($request, $tenant, $brands);
        $profile = $brands->ensureForTenant($tenant, $request->user());
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:180'],
            'primary_color' => ['required', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'accent_color' => ['required', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'surface_color' => ['required', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'text_color' => ['required', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'display_style' => ['required', 'in:classic,technical,editorial,bold'],
            'corner_style' => ['required', 'in:soft,standard,sharp'],
            'decor_preset' => ['required', 'in:none,signal,grid,dawn'],
        ]);

        $validated['tagline'] = filled($validated['tagline'] ?? null) ? trim((string) $validated['tagline']) : null;
        foreach (['primary_color', 'accent_color', 'surface_color', 'text_color'] as $color) {
            $validated[$color] = strtoupper((string) $validated[$color]);
        }
        $brands->update($profile, $validated, $request->user());

        return back()->with('status', 'Workspace brand updated.');
    }

    public function upload(Request $request, string $slot, TenantBrandProfileService $brands): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeCustomization($request, $tenant, $brands);
        abort_unless(in_array($slot, ['light_logo', 'dark_logo', 'icon'], true), 404);
        $request->validate([
            'asset' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $brands->storeLogo($brands->ensureForTenant($tenant, $request->user()), $request->file('asset'), $slot, $request->user());

        return back()->with('status', 'Brand asset uploaded.');
    }

    public function asset(TenantBrandProfile $profile, string $slot): StreamedResponse
    {
        $field = match ($slot) {
            'light_logo' => 'light_logo_path',
            'dark_logo' => 'dark_logo_path',
            'icon' => 'icon_path',
            default => abort(404),
        };
        abort_unless(data_get($profile->asset_sources, $slot) === 'upload', 404);
        $path = trim((string) $profile->{$field});
        abort_unless($path !== '' && Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, null, [
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function reset(Request $request, TenantBrandProfileService $brands): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeCustomization($request, $tenant, $brands);
        $brands->reset($tenant, $request->user());

        return back()->with('status', 'Workspace brand reset to its safe default.');
    }

    public function downloadKit(Request $request, string $asset, TenantBrandProfileService $brands): BinaryFileResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeCustomization($request, $tenant, $brands);
        abort_unless($this->isCollinsTenant($tenant), 404);
        $manifest = collect($this->kitManifest())->keyBy('key');
        abort_unless($manifest->has($asset), 404);
        $item = $manifest->get($asset);
        $path = public_path((string) $item['path']);
        abort_unless(File::isFile($path), 404);

        return response()->download($path, (string) $item['download']);
    }

    /** @return array<int,array{key:string,label:string,type:string,path:string,download:string}> */
    protected function kitManifest(): array
    {
        $base = 'brand/kits/collins-upstate-electric/';

        return [
            ['key' => 'logo-family', 'label' => 'Logo family', 'type' => 'SVG bundle reference', 'path' => $base.'collins-lockup-navy.svg', 'download' => 'collins-upstate-electric-logo-navy.svg'],
            ['key' => 'email-header', 'label' => 'Email header', 'type' => 'Editable SVG', 'path' => $base.'templates/email-header.svg', 'download' => 'collins-email-header.svg'],
            ['key' => 'social-square', 'label' => 'Square social post', 'type' => 'Editable SVG', 'path' => $base.'templates/social-square.svg', 'download' => 'collins-social-square.svg'],
            ['key' => 'social-story', 'label' => 'Social story', 'type' => 'Editable SVG', 'path' => $base.'templates/social-story.svg', 'download' => 'collins-social-story.svg'],
            ['key' => 'service-flyer', 'label' => 'Service flyer', 'type' => 'Editable SVG', 'path' => $base.'templates/service-flyer.svg', 'download' => 'collins-service-flyer.svg'],
            ['key' => 'yard-sign', 'label' => 'Yard-sign layout', 'type' => 'Editable SVG', 'path' => $base.'templates/yard-sign.svg', 'download' => 'collins-yard-sign.svg'],
            ['key' => 'vehicle-decals', 'label' => 'Vehicle decals reference', 'type' => 'Editable SVG', 'path' => $base.'templates/vehicle-decals.svg', 'download' => 'collins-vehicle-decals.svg'],
            ['key' => 'campaign-backdrop', 'label' => 'Campaign backdrop', 'type' => 'PNG', 'path' => $base.'campaign-backdrop.png', 'download' => 'collins-campaign-backdrop.png'],
        ];
    }

    protected function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    protected function authorizeCustomization(Request $request, Tenant $tenant, TenantBrandProfileService $brands): void
    {
        abort_unless($brands->userCanCustomize($request->user(), $tenant), 403);
    }

    protected function isCollinsTenant(Tenant $tenant): bool
    {
        return strtolower(trim((string) $tenant->slug)) === 'collins-electric';
    }
}
