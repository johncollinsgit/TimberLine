@php
    $providedBootstrap = (array) ($workflowStudioBootstrap ?? $studioBootstrap ?? []);
    $catalogPayload = is_array($componentCatalog ?? null)
        ? $componentCatalog
        : (is_array($workflowComponentCatalog ?? null) ? $workflowComponentCatalog : []);
    $workflowConnectionsPayload = (array) ($workflowConnections ?? []);

    if ($workflowConnectionsPayload === [] && isset($commerceConnections)) {
        $sourceProvider = (string) data_get($workflow->draft_definition, 'trigger.provider', '');
        if ($sourceProvider !== '') {
            $workflowConnectionsPayload[$sourceProvider] = collect($commerceConnections)
                ->map(fn ($connection): array => [
                    'id' => $connection->id,
                    'provider' => $sourceProvider,
                    'label' => $connection->external_account_label ?: str($sourceProvider)->headline().' account',
                    'status' => $connection->status,
                ])
                ->values()
                ->all();
        }
    }

    $templateKey = (string) ($workflow->template_key ?? 'blank');
    $templatePayload = isset($template) && is_array($template) && $template !== []
        ? [$templateKey => $template]
        : (array) ($templates ?? []);

    $bootstrap = array_replace_recursive([
        'mode' => 'edit',
        'csrf_token' => csrf_token(),
        'workflow' => [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'status' => $workflow->status,
            'draft_revision' => (int) data_get($workflow, 'draft_revision', 0),
            'published_version' => $workflow->publishedVersion?->version,
            'test_state' => (array) $workflow->test_state,
        ],
        'definition' => (array) $workflow->draft_definition,
        'catalog' => $catalogPayload,
        'providers' => (array) ($providers ?? []),
        'templates' => $templatePayload,
        'connections' => $workflowConnectionsPayload,
        'asana_connection' => (array) ($asanaConnection ?? []),
        'google_connection' => (array) ($googleConnection ?? []),
        'test_state' => (array) $workflow->test_state,
        'endpoints' => [
            'index' => route('workflows.index'),
            'create' => route('workflows.store'),
            'save' => route('workflows.update', $workflow),
            'test_trigger' => route('workflows.test-trigger', $workflow),
            'test_action' => route('workflows.test-action', $workflow),
            'publish' => route('workflows.publish', $workflow),
            'pause' => route('workflows.pause', $workflow),
            'resume' => route('workflows.resume', $workflow),
            'run' => route('workflows.run', $workflow),
            'connections' => route('workflows.connections'),
            'history' => route('workflows.history', ['workflow' => $workflow->id]),
            'show' => route('workflows.show', $workflow),
        ],
    ], $providedBootstrap);
    $bootstrapJson = json_encode(
        $bootstrap,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
@endphp

<x-layouts::app :title="$workflow->name">
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
