<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="csrf-token" content="{{ csrf_token() }}"><title>Workflow workspace · {{ $tenant->name }}</title>
@viteReactRefresh
@vite('resources/js/enterprise/workspace.tsx')
</head><body><div id="enterprise-workflow" data-bootstrap="{{ json_encode(['tenant' => $tenant->slug, 'tenant_id' => (string)$tenant->id, 'user_id' => (string)auth()->id(), 'scope' => $scope, 'initial' => $bootstrap, 'back' => route('enterprise.index', ['tenant' => $tenant->slug, 'area_id' => $scope['area_id'], 'section' => 'projects', 'selected' => $scope['project_id']])], JSON_THROW_ON_ERROR) }}"></div><noscript>This workflow workspace requires JavaScript. Return to <a href="{{ route('enterprise.index', ['tenant' => $tenant->slug]) }}">Enterprise Projects</a>.</noscript></body></html>
