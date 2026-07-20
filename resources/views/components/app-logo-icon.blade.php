@php
  $tenant = request()->attributes->get('current_tenant');
  $brandPresentation = app(\App\Services\Tenancy\TenantBrandProfileService::class)->presentationFor(
      $tenant instanceof \App\Models\Tenant ? $tenant : null
  );
  $brandLogoSrc = (string) $brandPresentation['icon_url'];
@endphp

<img
  src="{{ $brandLogoSrc }}"
  alt="{{ $brandPresentation['display_name'] }}"
  {{ $attributes->merge(['class' => 'object-contain']) }}
/>
