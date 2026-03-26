<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-[#0b0f0c] text-zinc-100 antialiased">
        @php
            $authTenantPresentation = $authTenantPresentation ?? [];
            $tenantLabel = $authTenantPresentation['tenant_label'] ?? 'Modern Forestry';
            $portalName = $authTenantPresentation['portal_name'] ?? 'Backstage';
            $heroTitle = $authTenantPresentation['hero_title'] ?? 'Production, shipping, and wholesale operations in one calm place.';
            $heroSubtitle = $authTenantPresentation['hero_subtitle'] ?? 'Built for real inventory flow. Track orders, line items, and fulfillment without the noise.';
            $heroTagline = $authTenantPresentation['hero_tagline'] ?? 'Operations Console';
        @endphp
        <div class="relative min-h-svh overflow-hidden">
            <div class="pointer-events-none absolute inset-0">
                <div class="absolute -left-40 -top-40 h-96 w-96 rounded-full bg-emerald-500/20 blur-[120px]"></div>
                <div class="absolute right-0 top-1/3 h-[28rem] w-[28rem] rounded-full bg-amber-400/10 blur-[140px]"></div>
                <div class="absolute left-1/3 bottom-0 h-80 w-80 rounded-full bg-lime-400/10 blur-[120px]"></div>
                <div class="absolute inset-0 bg-[radial-gradient(circle_at_top,_rgba(16,24,20,0.8),_rgba(8,10,9,1))]"></div>
            </div>

            <div class="relative z-10 grid min-h-svh grid-cols-1 lg:grid-cols-2">
                <div class="hidden lg:flex flex-col justify-between p-12">
                    <div class="flex items-center gap-3">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-400/20 ring-1 ring-emerald-200/20">
                            <x-app-logo-icon class="size-8 fill-current text-emerald-200" />
                        </span>
                        <div>
                            <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/60">{{ $tenantLabel }}</div>
                            <div class="text-2xl font-['Fraunces'] font-semibold text-white">{{ $portalName }}</div>
                        </div>
                    </div>

                    <div class="max-w-md space-y-4">
                        <div class="text-4xl font-['Fraunces'] font-semibold leading-tight text-white">
                            {{ $heroTitle }}
                        </div>
                        <p class="text-sm text-emerald-50/70">
                            {{ $heroSubtitle }}
                        </p>
                    </div>

                    <div class="text-xs uppercase tracking-[0.2em] text-emerald-50/50">
                        {{ $heroTagline }}
                    </div>
                </div>

                <div class="flex items-center justify-center p-6 md:p-10">
                    <div class="w-full max-w-md rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-8 shadow-[0_30px_80px_-40px_rgba(0,0,0,0.9)] backdrop-blur">
                        <div class="mb-6 flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-emerald-400/20 ring-1 ring-emerald-200/20 lg:hidden">
                                <x-app-logo-icon class="size-6 fill-current text-emerald-200" />
                            </span>
                            <div>
                                <div class="text-xs uppercase tracking-[0.3em] text-emerald-100/60 lg:hidden">{{ $tenantLabel }}</div>
                                <div class="text-lg font-['Fraunces'] font-semibold text-white lg:text-xl">{{ $portalName }}</div>
                            </div>
                        </div>

                        <div class="space-y-6">
                            {{ $slot }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @fluxScripts
    </body>
</html>
