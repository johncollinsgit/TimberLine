<style>
    .eb-teams-product-brand { min-width: 0; overflow: hidden; border-bottom: 1px solid #dce5d9; background: #fff; color: #183e32; font: 600 18px/1.3 var(--eb-font-ui, Inter, ui-sans-serif, system-ui, sans-serif); }
    .eb-teams-product-brand .eb-teams-tree-canopy { height: 40px; overflow: hidden; background-color: #f7f9f4; background-repeat: repeat-x; background-size: auto 100%; background-position: center bottom; pointer-events: none; }
    .eb-teams-product-brand .eb-teams-product-lockup { display: flex; align-items: center; gap: 10px; min-height: 50px; padding: 10px 28px; background: #fff; }
    .eb-teams-product-brand .eb-teams-product-leaf { display: block; flex: 0 0 24px; width: 24px; height: 24px; background-repeat: no-repeat; background-position: center; background-size: contain; }
    .eb-teams-product-brand .eb-teams-product-lockup strong { color: inherit; font: inherit; font-weight: 650; letter-spacing: -.025em; overflow-wrap: anywhere; }
    @media (max-width: 639px) {
        .eb-teams-product-brand .eb-teams-product-lockup { min-height: 48px; padding: 10px 16px; font-size: 17px; }
    }
</style>
<div class="eb-teams-product-brand" data-teams-product-brand>
    <div class="eb-teams-tree-canopy" aria-hidden="true" style="background-image: url('{{ asset('brand/everbranch-teams-trees-v1.webp') }}')"></div>
    <div class="eb-teams-product-lockup">
        <span class="eb-teams-product-leaf" aria-hidden="true" style="background-image: url('{{ asset('brand/everbranch-mark.svg') }}')"></span>
        <strong>Everbranch Teams</strong>
    </div>
</div>
