@props([
    'searchEndpoint',
    'placeholder' => 'Search actions, pages, and Shopify tools',
    'contextLabel' => 'Commerce',
    'documents' => [],
    'context' => [],
])

<div
    data-shopify-global-command-menu
    data-search-endpoint="{{ $searchEndpoint }}"
    data-placeholder="{{ $placeholder }}"
    data-context-label="{{ $contextLabel }}"
>
    <script type="application/json" data-command-documents>
        {!! json_encode($documents, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}
    </script>
    <script type="application/json" data-command-context>
        {!! json_encode($context, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}
    </script>
</div>
