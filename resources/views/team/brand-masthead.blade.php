<style>
    .eb-teams-product-brand { min-width: 0; overflow: hidden; border-bottom: 1px solid #dce5d9; background: #fff; color: #183e32; font: 600 18px/1.3 var(--eb-font-ui, Inter, ui-sans-serif, system-ui, sans-serif); }
    .eb-teams-product-brand .eb-teams-tree-canopy { position: relative; height: 40px; overflow: hidden; background-color: #f7f9f4; background-image: var(--eb-teams-tree-art); background-repeat: repeat-x; background-size: auto 100%; background-position: center bottom; pointer-events: none; }
    .eb-teams-product-brand .eb-teams-forest-run { display: none; }
    .eb-teams-product-brand .eb-teams-product-lockup { display: flex; align-items: center; gap: 10px; min-height: 50px; padding: 10px 28px; background: #fff; }
    .eb-teams-product-brand .eb-teams-product-leaf { display: block; flex: 0 0 24px; width: 24px; height: 24px; background-repeat: no-repeat; background-position: center; background-size: contain; }
    .eb-teams-product-brand .eb-teams-product-lockup strong { color: inherit; font: inherit; font-weight: 650; letter-spacing: -.025em; overflow-wrap: anywhere; }
    @media (max-width: 639px) {
        .eb-teams-product-brand .eb-teams-product-lockup { min-height: 48px; padding: 10px 16px; font-size: 17px; }
    }
    @media (min-width: 640px) {
        .eb-teams-product-brand { display: flex; align-items: center; }
        .eb-teams-product-brand .eb-teams-product-lockup { flex: 0 0 auto; min-height: 64px; box-sizing: border-box; }
        .eb-teams-product-brand .eb-teams-tree-canopy { order: 1; flex: 1 1 0; min-width: 0; height: 64px; background: transparent; }
        .eb-teams-product-brand .eb-teams-forest-run { position: absolute; inset: 0; display: flex; align-items: flex-end; }
        .eb-teams-product-brand .eb-teams-tree-cluster { flex: 1 0 var(--tree-width); height: var(--tree-height); margin-left: -12px; background-image: var(--eb-teams-tree-art); background-repeat: no-repeat; background-size: auto 100%; background-position: var(--tree-position) bottom; transform: scaleX(var(--tree-direction)); }
        .eb-teams-product-brand .eb-teams-tree-cluster:first-child { margin-left: 0; }
    }
</style>
<div class="eb-teams-product-brand" data-teams-product-brand>
    <div class="eb-teams-tree-canopy" aria-hidden="true" style="--eb-teams-tree-art: url('{{ asset('brand/everbranch-teams-trees-v1.webp') }}')">
        <span class="eb-teams-forest-run">
            @for ($treeCluster = 0; $treeCluster < 64; $treeCluster++)
                <span class="eb-teams-tree-cluster" style="--tree-width: {{ random_int(98, 148) }}px; --tree-height: {{ random_int(42, 62) }}px; --tree-position: {{ random_int(0, 100) }}%; --tree-direction: {{ random_int(0, 1) ? -1 : 1 }}"></span>
            @endfor
        </span>
    </div>
    <div class="eb-teams-product-lockup">
        <span class="eb-teams-product-leaf" aria-hidden="true" style="background-image: url('{{ asset('brand/everbranch-mark.svg') }}')"></span>
        <strong>Everbranch Teams</strong>
    </div>
</div>
