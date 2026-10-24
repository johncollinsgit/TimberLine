@php
    $enterprisePresentation = $presentation ?? [
        'runtime' => 'private',
        'branding' => $clientBranding ?? [],
        'identity' => ['tenantSlug' => $tenant->slug, 'viewer' => (string) auth()->id(), 'workspace' => (string) $tenant->id, 'name' => $clientBranding['organization_name'] ?? $tenant->name, 'userName' => auth()->user()->name],
        'csrf' => csrf_token(),
        'routes' => [
            'workflows' => url('/enterprise/workflows'),
            'operations' => url('/enterprise/operations'),
            'back' => route('enterprise.index', ['tenant' => $tenant->slug, 'area_id' => $scope['area_id'], 'section' => 'projects', 'selected' => $scope['project_id']]),
        ],
    ];
    $enterpriseSample = ($enterprisePresentation['runtime'] ?? 'private') === 'sample';
    $enterpriseInstall = $enterpriseSample ? [] : \App\Enterprise\AppManifest::links(request());
    $workflowBoot = array_merge($enterprisePresentation, [
        'tenant' => $enterprisePresentation['identity']['tenantSlug'],
        'tenant_id' => (string) $enterprisePresentation['identity']['workspace'],
        'user_id' => (string) $enterprisePresentation['identity']['viewer'],
        'scope' => $enterprisePresentation['scope'] ?? $scope,
        'initial' => $enterprisePresentation['initial'] ?? $bootstrap,
        'back' => $enterprisePresentation['routes']['back'],
    ]);
@endphp
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="csrf-token" content="{{ $enterpriseSample ? "" : ($enterprisePresentation["csrf"] ?? "") }}"><title>Workflow workspace · {{ $enterprisePresentation["identity"]["name"] }}</title>
@include('campus-v5::enterprise.app-install-head')
@if($enterpriseSample)
<style>{!! $inlineAssets['css'] ?? '' !!}</style>
@else
@viteReactRefresh
@vite('resources/js/enterprise/workspace.tsx')
@endif
@if(!$enterpriseSample && preg_match('/^#[0-9a-fA-F]{6}$/D', $enterprisePresentation['branding']['accent'] ?? ''))
<style>:root{--gold:{{ $enterprisePresentation['branding']['accent'] }};--ew-accent:{{ $enterprisePresentation['branding']['accent'] }}}</style>
@endif
</head><body>
@includeIf('campus-v5::enterprise.runtime-designation')
@if($enterpriseSample)
@includeIf('campus-v5::enterprise.reviewer-adapter')
@endif
@include('campus-v5::enterprise.workflow-priorities')
<div id="enterprise-workflow" data-bootstrap="{{ json_encode($workflowBoot, JSON_THROW_ON_ERROR) }}"></div><noscript>This workflow workspace requires JavaScript. Return to <a href="{{ $enterprisePresentation['routes']['back'] }}">Enterprise Projects</a>.</noscript>
@if($enterpriseSample)<script type="module">{!! $inlineAssets['js'] ?? '' !!}</script>@endif
@include('campus-v5::enterprise.app-install')
</body></html>
