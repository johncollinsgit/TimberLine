@props([
    'decorative' => true,
    'title' => 'Leaf',
])

@php
    $tenant = request()->attributes->get('current_tenant');
    $presentation = app(\App\Services\Tenancy\TenantBrandProfileService::class)->presentationFor(
        $tenant instanceof \App\Models\Tenant ? $tenant : null
    );
    $brandMarkUrl = (string) ($presentation['icon_url'] ?? asset('brand/everbranch-mark.png'));
@endphp

<span
    {{ $attributes->class('mf-leaf-icon') }}
    style="--mf-leaf-icon-url: url('{{ $brandMarkUrl }}');"
    @if($decorative)
        aria-hidden="true"
    @else
        role="img"
        aria-label="{{ $title }}"
    @endif
></span>
