@php
    $requestedPicker = (string) request()->query('picker', 'home');
    $initialPicker = in_array($requestedPicker, ['home', 'apps', 'controls', 'utilities', 'templates'], true)
        ? $requestedPicker
        : 'home';
    $providedBootstrap = (array) ($workflowStudioBootstrap ?? $studioBootstrap ?? []);
    $catalogPayload = is_array($componentCatalog ?? null)
        ? $componentCatalog
        : (is_array($workflowComponentCatalog ?? null) ? $workflowComponentCatalog : []);
    $bootstrap = array_replace_recursive([
        'mode' => 'create',
        'csrf_token' => csrf_token(),
        'workflow' => [
            'id' => null,
            'name' => 'Untitled workflow',
            'status' => 'draft',
            'draft_revision' => 0,
            'published_version' => null,
        ],
        'definition' => [
            'schema_version' => 2,
            'trigger' => null,
            'steps' => [],
            'settings' => [
                'poll_interval_minutes' => 10,
                'max_items_per_poll' => 100,
            ],
        ],
        'catalog' => $catalogPayload,
        'providers' => (array) ($providers ?? []),
        'templates' => (array) ($templates ?? []),
        'connections' => (array) ($workflowConnections ?? []),
        'test_state' => [],
        'initial_picker' => $initialPicker,
        'endpoints' => [
            'index' => route('workflows.index'),
            'create' => route('workflows.store'),
            'connections' => route('workflows.connections'),
            'history' => route('workflows.history'),
            'show' => url('/workflows/{workflow}'),
        ],
    ], $providedBootstrap);
    if (request()->has('picker')) {
        $bootstrap['initial_picker'] = $initialPicker;
    }
    $bootstrapJson = json_encode(
        $bootstrap,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
@endphp

<x-layouts::app :title="'Create workflow'">
    <div
        data-workflow-studio-root
        data-workflow-bootstrap="{{ $bootstrapJson }}"
        wire:ignore
    >
        <div class="eb-studio-loading" role="status">
            <span aria-hidden="true"></span>
            <strong>Opening Workflow Studio…</strong>
        </div>
    </div>
    <noscript>
        <div class="eb-studio-noscript">
            Workflow Studio needs JavaScript. Turn it on and reload this page.
        </div>
    </noscript>
</x-layouts::app>
