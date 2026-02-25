<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
  @include('partials.head')
  @livewireStyles


  <style>
    @import url('https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600;9..144,700&family=Manrope:wght@400;500;600;700;800&family=Archivo:wght@500;700;800;900&family=Barlow+Condensed:wght@600;700;800&family=IBM+Plex+Sans:wght@400;500;600;700&family=Newsreader:opsz,wght@6..72,500;6..72,600&family=Sora:wght@400;500;600;700;800&display=swap');

    html { scroll-behavior: smooth; }

    /* Motion/feel */
    .mf-transition { transition: all 200ms cubic-bezier(.2,.8,.2,1); }
    .mf-fade-in { animation: mfFadeIn 220ms ease-out both; }
    @keyframes mfFadeIn {
      from { opacity: 0; transform: translateY(4px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    /* Sidebar glow */
    .mf-sidebar-glow{
      pointer-events: none;
      z-index: 0;
    }
    .mf-sidebar-glow::before{
      content:"";
      position:absolute;
      inset:-40px -60px -40px -60px;
      background:
        radial-gradient(700px 400px at 0% 0%, rgba(16,185,129,.25), transparent 55%),
        radial-gradient(600px 350px at 0% 100%, rgba(245,158,11,.16), transparent 55%);
      filter: blur(14px);
      pointer-events:none;
      opacity:.9;
    }

    .mf-nav-item { will-change: transform; }
    .mf-nav-item:hover { transform: translateX(2px); }
    .mf-sidebar-sort-item { cursor: grab; touch-action: manipulation; }
    .mf-sidebar-sort-item:active { cursor: grabbing; }
    .mf-sidebar-ghost { opacity: .45; }
    .mf-sidebar-drag { opacity: .8; }
    .mf-sidebar-sort-item + .mf-sidebar-sort-item { margin-top: .18rem; }

    .mf-active-pill { position: relative; }
    .mf-active-pill::after{
      content:"";
      position:absolute;
      left: var(--mf-active-indicator-offset);
      top:50%;
      width: var(--mf-active-indicator-width);
      height: var(--mf-active-indicator-height);
      transform: translateY(-50%);
      border-radius:999px;
      background: linear-gradient(to bottom, rgba(var(--mf-accent), .95), rgba(var(--mf-accent-2), .45));
      box-shadow: 0 0 0 1px rgba(var(--mf-accent), .22), 0 10px 25px rgba(var(--mf-accent), .14);
    }

    /* Surface: explicit light + explicit dark (prevents “whiting out”) */
    .mf-surface{
      background:
        radial-gradient(900px 520px at 10% 0%, rgba(16,185,129,.10), transparent 58%),
        radial-gradient(900px 520px at 90% 10%, rgba(245,158,11,.08), transparent 60%),
        linear-gradient(to bottom, rgba(255,255,255,.80), rgba(255,255,255,.65));
    }
    .dark .mf-surface{
      background:
        radial-gradient(900px 520px at 10% 0%, rgba(16,185,129,.12), transparent 58%),
        radial-gradient(900px 520px at 90% 10%, rgba(245,158,11,.10), transparent 60%),
        linear-gradient(to bottom, rgba(24,24,27,.78), rgba(24,24,27,.55));
    }

    /* Soft edges; avoids harsh wireframe */
    .mf-soft-edge{
      box-shadow:
        inset 0 0 0 1px rgba(255,255,255,.07),
        0 30px 80px -55px rgba(0,0,0,.90);
    }

    /* Keep borders tasteful inside the main surface only */
    .mf-surface :where(.border, [class*="border-"]) { border-color: rgba(255,255,255,.08) !important; }
    .mf-surface :where(input, textarea, select) {
      background-color: rgba(255,255,255,.04) !important;
      border-color: rgba(255,255,255,.10) !important;
    }

    /* Optional: if you ever tag a debug banner, it gets nuked */
    .mf-hide-debug-banner :where(.debug-banner, #debug-banner, [data-debug-banner]) { display:none !important; }

    /* Theme tokens */
    body[data-mf-theme]{
      --mf-sidebar-item-pad-x: .62rem;
      --mf-sidebar-item-pad-y: .45rem;
      --mf-active-indicator-offset: 4px;
      --mf-active-indicator-width: 4px;
      --mf-active-indicator-height: 24px;
      --mf-font-body: "Manrope", ui-sans-serif, system-ui, sans-serif;
      --mf-font-display: "Fraunces", ui-serif, Georgia, serif;
      --mf-font-accent: "Sora", ui-sans-serif, system-ui, sans-serif;
      --mf-text-1: rgba(244,244,245,.96);
      --mf-text-2: rgba(228,228,231,.78);
      --mf-text-3: rgba(212,212,216,.58);
      --mf-panel-bg: rgba(255,255,255,.03);
      --mf-panel-bg-2: rgba(255,255,255,.045);
      --mf-panel-border: rgba(255,255,255,.10);
      --mf-panel-strong-border: rgba(255,255,255,.16);
      --mf-input-bg: rgba(255,255,255,.04);
      --mf-input-border: rgba(255,255,255,.12);
      --mf-focus-ring: rgba(var(--mf-accent), .22);
      --mf-control-text: var(--mf-text-1);
      --mf-control-bg: rgba(var(--mf-accent), .12);
      --mf-control-bg-hover: rgba(var(--mf-accent), .18);
      --mf-control-muted-bg: rgba(255,255,255,.04);
      --mf-control-muted-hover: rgba(255,255,255,.07);
      --mf-table-stripe: rgba(255,255,255,.015);
      --mf-nav-bg-hover: rgba(255,255,255,.035);
      --mf-nav-bg-active: rgba(var(--mf-accent), .13);
      --mf-nav-border-active: rgba(var(--mf-accent), .35);
      --mf-sidebar-text: rgba(255,255,255,.92);
      --mf-sidebar-muted: rgba(255,255,255,.62);
      --mf-sidebar-heading: rgba(255,255,255,.72);
      --mf-floral-opacity: 0;
      --mf-body-text: 244,244,245;
      --mf-chart-1: rgba(var(--mf-accent), .88);
      --mf-chart-2: rgba(var(--mf-accent-2), .86);
      --mf-chart-3: rgba(59,130,246,.82);
      --mf-chart-4: rgba(168,85,247,.80);
      --mf-chart-5: rgba(236,72,153,.80);
      --mf-chart-6: rgba(34,197,94,.78);
      --bg-main: var(--mf-body-bg);
      --bg-card: color-mix(in srgb, var(--mf-panel-bg-2) 75%, #fff 0%);
      --card-border: var(--mf-panel-border);
      --card-shadow: 0 12px 24px rgba(0,0,0,.10);
      --text-primary: var(--mf-text-1);
      --text-secondary: var(--mf-text-2);
      --text-muted: var(--mf-text-3);
      --heading: var(--mf-text-1);
      --link: rgba(var(--mf-accent), 1);
      --link-hover: rgba(var(--mf-accent-2), 1);
      --btn-primary-bg: rgba(var(--mf-accent), .9);
      --btn-primary-hover: rgba(var(--mf-accent), 1);
      --btn-primary-text: #fff;
      --btn-secondary-bg: var(--mf-control-muted-bg);
      --btn-secondary-hover: var(--mf-control-muted-hover);
      --btn-secondary-text: var(--mf-control-text);
      --btn-secondary-border: var(--mf-panel-strong-border);
      --input-bg: var(--mf-input-bg);
      --input-border: var(--mf-input-border);
      --input-focus: rgba(var(--mf-accent), .9);
      --input-text: var(--mf-text-1);
      --placeholder: var(--mf-text-3);
      --table-header-bg: rgba(var(--mf-accent), .06);
      --table-header-text: var(--mf-text-1);
      --table-row-hover: rgba(var(--mf-accent), .06);
      --table-border: var(--mf-panel-border);
      --pill-bg: var(--mf-control-muted-bg);
      --pill-text: var(--mf-control-text);
      --pill-border: var(--mf-panel-strong-border);
      --pill-active-bg: var(--mf-control-bg);
      --pill-active-text: var(--mf-control-text);
      --indicator: rgba(var(--mf-accent), 1);
      --divider: var(--mf-panel-border);
      --color-bg: var(--mf-body-bg);
      --color-surface: var(--mf-panel-bg);
      --color-text: var(--mf-text-1);
      --color-accent: rgba(var(--mf-accent), 1);
      --color-accent-strong: rgba(var(--mf-accent-2), 1);
      --color-border: var(--mf-panel-border);
      --color-sidebar-bg: var(--mf-sidebar-bg);
      --color-sidebar-active: var(--mf-nav-bg-active);
      --font-heading: var(--mf-font-display);
      --font-body: var(--mf-font-body);
      --radius: 1rem;
    }

    body[data-mf-theme="forestry-green"]{
      --mf-font-body: "Manrope", ui-sans-serif, system-ui, sans-serif;
      --mf-font-display: "Newsreader", Georgia, serif;
      --mf-font-accent: "Sora", ui-sans-serif, system-ui, sans-serif;
      --mf-body-bg: #08110d;
      --mf-sidebar-bg: #090f0d;
      --mf-sidebar-border: rgba(255,255,255,.08);
      --mf-main-card-bg: linear-gradient(to bottom, rgba(16,24,21,.82), rgba(16,24,21,.62));
      --mf-main-card-border: rgba(255,255,255,.10);
      --mf-accent: 16,185,129;
      --mf-accent-2: 245,158,11;
      --mf-chip-bg: rgba(16,185,129,.12);
      --mf-chip-border: rgba(16,185,129,.28);
      --mf-chip-text: rgba(236,253,245,.92);
      --mf-panel-bg: rgba(8, 25, 19, .42);
      --mf-panel-bg-2: rgba(9, 30, 22, .55);
      --mf-panel-border: rgba(110, 231, 183, .12);
      --mf-panel-strong-border: rgba(110, 231, 183, .22);
      --mf-input-bg: rgba(8, 25, 19, .55);
      --mf-input-border: rgba(110, 231, 183, .16);
      --mf-table-stripe: rgba(110, 231, 183, .035);
      --mf-nav-bg-hover: rgba(16,185,129,.06);
      --mf-nav-bg-active: rgba(16,185,129,.10);
      --mf-nav-border-active: rgba(16,185,129,.30);
      --mf-sidebar-text: rgba(236,253,245,.94);
      --mf-sidebar-muted: rgba(209,250,229,.62);
      --mf-sidebar-heading: rgba(209,250,229,.72);
    }
    body[data-mf-theme="sugar-and-spice"]{
      --mf-font-body: "Manrope", ui-sans-serif, system-ui, sans-serif;
      --mf-font-display: "Fraunces", Georgia, serif;
      --mf-font-accent: "Sora", ui-sans-serif, system-ui, sans-serif;
      --mf-body-bg: #fff8fb;
      --mf-sidebar-bg: #fff8fb;
      --mf-sidebar-border: rgba(236, 72, 153, .10);
      --mf-main-card-bg: linear-gradient(to bottom, rgba(255,255,255,.94), rgba(255,249,253,.98));
      --mf-main-card-border: rgba(236, 72, 153, .10);
      --mf-accent: 244,114,182;
      --mf-accent-2: 148,163,122;
      --mf-chip-bg: rgba(244,114,182,.09);
      --mf-chip-border: rgba(244,114,182,.22);
      --mf-chip-text: rgba(136, 19, 55, .88);
      --mf-panel-bg: rgba(255, 255, 255, .82);
      --mf-panel-bg-2: rgba(255, 247, 251, .92);
      --mf-panel-border: rgba(244, 114, 182, .12);
      --mf-panel-strong-border: rgba(244, 114, 182, .20);
      --mf-input-bg: rgba(255, 255, 255, .94);
      --mf-input-border: rgba(244, 114, 182, .16);
      --mf-focus-ring: rgba(136,19,55,.18);
      --mf-control-text: rgba(88,34,65,.94);
      --mf-control-bg: rgba(136,19,55,.10);
      --mf-control-bg-hover: rgba(136,19,55,.16);
      --mf-control-muted-bg: rgba(136,19,55,.05);
      --mf-control-muted-hover: rgba(136,19,55,.08);
      --mf-table-stripe: rgba(244, 114, 182, .03);
      --mf-nav-bg-hover: rgba(244,114,182,.045);
      --mf-nav-bg-active: rgba(244,114,182,.08);
      --mf-nav-border-active: rgba(244,114,182,.22);
      --mf-text-1: rgba(77, 29, 57, .95);
      --mf-text-2: rgba(106, 46, 79, .78);
      --mf-text-3: rgba(137, 77, 110, .58);
      --mf-sidebar-text: rgba(88, 34, 65, .93);
      --mf-sidebar-muted: rgba(120, 56, 91, .62);
      --mf-sidebar-heading: rgba(136, 52, 99, .72);
      --mf-body-text: 77,29,57;
      --mf-chart-3: rgba(148,163,122,.78);
      --mf-chart-4: rgba(190,24,93,.76);
      --mf-chart-5: rgba(45,212,191,.72);
      --mf-floral-opacity: .22;
      --bg-main: #F6E9EF;
      --bg-card: #FFFFFF;
      --card-border: #E3C8D3;
      --card-shadow: 0 10px 25px rgba(148, 60, 100, 0.12);
      --text-primary: #4A1F2E;
      --text-secondary: #7A4B5C;
      --text-muted: #9A7A87;
      --heading: #381522;
      --link: #B8326E;
      --link-hover: #9F2A5E;
      --btn-primary-bg: #B8326E;
      --btn-primary-hover: #9F2A5E;
      --btn-primary-text: #FFFFFF;
      --btn-secondary-bg: #F2D1DC;
      --btn-secondary-hover: #E8B7C8;
      --btn-secondary-text: #4A1F2E;
      --btn-secondary-border: #D6A7B8;
      --input-bg: #FFFFFF;
      --input-border: #C58FA6;
      --input-focus: #B8326E;
      --input-text: #4A1F2E;
      --placeholder: #9A7A87;
      --table-header-bg: #E9BCCB;
      --table-header-text: #3A1825;
      --table-row-hover: #F2DCE4;
      --table-border: #E3C8D3;
      --pill-bg: #F7E3EB;
      --pill-text: #4A1F2E;
      --pill-border: #D6A7B8;
      --pill-active-bg: #B8326E;
      --pill-active-text: #FFFFFF;
      --indicator: #B8326E;
      --divider: #E3C8D3;
    }
    body[data-mf-theme="get-shit-done"]{
      --mf-font-body: "Archivo", ui-sans-serif, system-ui, sans-serif;
      --mf-font-display: "Barlow Condensed", "Archivo", Impact, sans-serif;
      --mf-font-accent: "Archivo", ui-sans-serif, system-ui, sans-serif;
      --mf-body-bg: #0f1013;
      --mf-sidebar-bg: #0d0e11;
      --mf-sidebar-border: rgba(255,255,255,.06);
      --mf-main-card-bg: linear-gradient(to bottom, rgba(23,24,28,.92), rgba(16,17,20,.88));
      --mf-main-card-border: rgba(255,255,255,.07);
      --mf-accent: 190,18,60;
      --mf-accent-2: 127,29,29;
      --mf-chip-bg: rgba(190,18,60,.14);
      --mf-chip-border: rgba(239,68,68,.26);
      --mf-chip-text: rgba(254,242,242,.95);
      --mf-panel-bg: rgba(22, 23, 27, .58);
      --mf-panel-bg-2: rgba(28, 29, 34, .72);
      --mf-panel-border: rgba(239, 68, 68, .14);
      --mf-panel-strong-border: rgba(239, 68, 68, .26);
      --mf-input-bg: rgba(17, 18, 22, .82);
      --mf-input-border: rgba(239, 68, 68, .16);
      --mf-focus-ring: rgba(239,68,68,.18);
      --mf-control-text: rgba(250,250,250,.95);
      --mf-control-bg: rgba(190,18,60,.14);
      --mf-control-bg-hover: rgba(190,18,60,.20);
      --mf-control-muted-bg: rgba(255,255,255,.04);
      --mf-control-muted-hover: rgba(255,255,255,.08);
      --mf-table-stripe: rgba(255,255,255,.012);
      --mf-nav-bg-hover: rgba(239,68,68,.055);
      --mf-nav-bg-active: rgba(190,18,60,.16);
      --mf-nav-border-active: rgba(239,68,68,.28);
      --mf-sidebar-text: rgba(250,250,250,.95);
      --mf-sidebar-muted: rgba(212,212,216,.58);
      --mf-sidebar-heading: rgba(228,228,231,.74);
      --mf-chart-3: rgba(148,163,184,.65);
      --mf-chart-4: rgba(251,191,36,.74);
      --mf-chart-5: rgba(244,114,182,.66);
    }
    body[data-mf-theme="steve-jobs"]{
      --mf-font-body: "IBM Plex Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      --mf-font-display: "IBM Plex Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      --mf-font-accent: "IBM Plex Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      --mf-body-bg: #f5f6f8;
      --mf-sidebar-bg: #fbfbf9;
      --mf-sidebar-border: rgba(15,23,42,.06);
      --mf-main-card-bg: linear-gradient(to bottom, rgba(255,255,255,.985), rgba(247,248,250,.97));
      --mf-main-card-border: rgba(15,23,42,.06);
      --mf-accent: 71,85,105;
      --mf-accent-2: 15,23,42;
      --mf-chip-bg: rgba(96,165,250,.08);
      --mf-chip-border: rgba(96,165,250,.16);
      --mf-chip-text: rgba(30,41,59,.92);
      --mf-panel-bg: rgba(255,255,255,.86);
      --mf-panel-bg-2: rgba(248,249,251,.94);
      --mf-panel-border: rgba(148,163,184,.12);
      --mf-panel-strong-border: rgba(100,116,139,.18);
      --mf-input-bg: rgba(255,255,255,.96);
      --mf-input-border: rgba(148,163,184,.16);
      --mf-focus-ring: rgba(30,41,59,.12);
      --mf-control-text: rgba(15,23,42,.92);
      --mf-control-bg: rgba(30,41,59,.06);
      --mf-control-bg-hover: rgba(30,41,59,.10);
      --mf-control-muted-bg: rgba(15,23,42,.025);
      --mf-control-muted-hover: rgba(15,23,42,.05);
      --mf-table-stripe: rgba(15,23,42,.015);
      --mf-nav-bg-hover: rgba(15,23,42,.03);
      --mf-nav-bg-active: rgba(96,165,250,.08);
      --mf-nav-border-active: rgba(96,165,250,.20);
      --mf-text-1: rgba(15,23,42,.95);
      --mf-text-2: rgba(51,65,85,.78);
      --mf-text-3: rgba(100,116,139,.60);
      --mf-sidebar-text: rgba(15,23,42,.90);
      --mf-sidebar-muted: rgba(51,65,85,.58);
      --mf-sidebar-heading: rgba(71,85,105,.68);
      --mf-body-text: 15,23,42;
      --mf-chart-3: rgba(96,165,250,.70);
      --mf-chart-4: rgba(34,197,94,.70);
      --mf-chart-5: rgba(234,88,12,.70);
      --bg-main: #F5F5F5;
      --bg-card: #FFFFFF;
      --card-border: #D6D6D6;
      --card-shadow: 0 10px 25px rgba(0, 0, 0, 0.10);
      --text-primary: #111111;
      --text-secondary: #333333;
      --text-muted: #5A5A5A;
      --heading: #0A0A0A;
      --link: #111111;
      --link-hover: #000000;
      --btn-primary-bg: #111111;
      --btn-primary-hover: #000000;
      --btn-primary-text: #FFFFFF;
      --btn-secondary-bg: #FFFFFF;
      --btn-secondary-hover: #F0F0F0;
      --btn-secondary-text: #111111;
      --btn-secondary-border: #BDBDBD;
      --input-bg: #FFFFFF;
      --input-border: #BDBDBD;
      --input-focus: #111111;
      --input-text: #111111;
      --placeholder: #6A6A6A;
      --table-header-bg: #EAEAEA;
      --table-header-text: #111111;
      --table-row-hover: #F2F2F2;
      --table-border: #D6D6D6;
      --pill-bg: #FFFFFF;
      --pill-text: #111111;
      --pill-border: #BDBDBD;
      --pill-active-bg: #111111;
      --pill-active-text: #FFFFFF;
      --indicator: #111111;
      --divider: #D6D6D6;
    }

    body.mf-app-shell{
      font-family: var(--mf-font-body);
      background:
        radial-gradient(1200px 700px at 0% 0%, rgba(var(--mf-accent), .14), transparent 60%),
        radial-gradient(1100px 650px at 100% 10%, rgba(var(--mf-accent-2), .10), transparent 62%),
        var(--mf-body-bg, #08110d);
      color: var(--mf-text-1);
    }
    body[data-mf-theme="sugar-and-spice"],
    body[data-mf-theme="steve-jobs"]{
      color-scheme: light;
    }
    body[data-mf-theme="forestry-green"],
    body[data-mf-theme="get-shit-done"]{
      color-scheme: dark;
    }

    .mf-sidebar-theme-shell{
      background: var(--mf-sidebar-bg, #090f0d) !important;
      border-color: var(--mf-sidebar-border, rgba(255,255,255,.08)) !important;
      color: var(--mf-sidebar-text);
    }
    .mf-sidebar-header{
      padding-inline: .75rem;
      padding-top: .5rem;
      padding-bottom: .45rem;
      position: relative;
      z-index: 1;
    }
    .mf-sidebar-theme-slot{
      padding-inline: .75rem;
      padding-top: .5rem;
      padding-bottom: .9rem;
      position: relative;
      z-index: 1;
    }
    .mf-sidebar-nav{
      padding-inline: .35rem;
      padding-top: .15rem;
      padding-bottom: .35rem;
      position: relative;
      z-index: 1;
    }
    .mf-sidebar-nav > *:first-child{
      margin-top: 0 !important;
    }
    .mf-sidebar-nav > * + *{
      margin-top: .7rem;
    }
    .mf-sidebar-group-balanced{
      padding-inline: .25rem;
      padding-top: .15rem;
    }
    .mf-sidebar-group-balanced [data-flux-sidebar-group-heading],
    .mf-sidebar-group-balanced :where(h2, h3, h4){
      margin-bottom: .6rem;
      padding-inline: .45rem;
      color: var(--mf-sidebar-heading);
      letter-spacing: .08em;
    }
    .mf-sidebar-group-balanced > .space-y-1{
      border-radius: 1rem;
      padding: .35rem;
      background: linear-gradient(to bottom, rgba(255,255,255,.01), rgba(255,255,255,0));
      border: 1px solid rgba(255,255,255,.03);
    }
    .mf-sidebar-panel{
      margin-top: .35rem;
    }
    .mf-sidebar-panel summary{
      min-height: 2rem;
    }
    .mf-sidebar-panel > div{
      margin-top: .45rem !important;
    }
    .mf-sidebar-nav :where(a[href], button, summary){
      min-height: 2.25rem;
    }
    .mf-sidebar-footer{
      margin-top: .25rem;
      padding: .7rem .75rem .85rem;
      border-top: 1px solid var(--divider);
      background: linear-gradient(to top, rgba(255,255,255,.015), rgba(255,255,255,0));
      position: relative;
      z-index: 1;
    }

    .mf-sidebar-theme-shell::after{
      content:"";
      position:absolute;
      inset:0;
      pointer-events:none;
      opacity: var(--mf-floral-opacity, 0);
      background-image:
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='140' height='140' viewBox='0 0 140 140'%3E%3Cg fill='none' stroke='%23ffb7d7' stroke-width='1.5' stroke-linecap='round' opacity='.45'%3E%3Cpath d='M28 30c8-10 22-10 30 0-8 10-22 10-30 0Z'/%3E%3Cpath d='M43 15c10 8 10 22 0 30-10-8-10-22 0-30Z'/%3E%3Cpath d='M58 30c8-10 22-10 30 0-8 10-22 10-30 0Z'/%3E%3Cpath d='M43 45c10 8 10 22 0 30-10-8-10-22 0-30Z'/%3E%3Cpath d='M43 30c6-6 14-6 20 0-6 6-14 6-20 0Z' fill='%23ffd7ea' fill-opacity='.22'/%3E%3Cpath d='M88 92c6-8 18-8 24 0-6 8-18 8-24 0Z'/%3E%3Cpath d='M100 80c8 6 8 18 0 24-8-6-8-18 0-24Z'/%3E%3Cpath d='M16 104c4 0 6 2 8 6 2-4 4-6 8-6-4 0-6-2-8-6-2 4-4 6-8 6Z' fill='%23ffe7f3' stroke='none'/%3E%3C/g%3E%3C/svg%3E");
      background-size: 170px 170px;
      background-position: right top;
      mix-blend-mode: screen;
    }

    .mf-sidebar-glow::before{
      background:
        radial-gradient(700px 400px at 0% 0%, rgba(var(--mf-accent), .24), transparent 55%),
        radial-gradient(600px 350px at 0% 100%, rgba(var(--mf-accent-2), .16), transparent 55%);
    }

    .mf-app-card{
      background: var(--mf-main-card-bg, linear-gradient(to bottom, rgba(16,24,21,.82), rgba(16,24,21,.62)));
      border: 1px solid var(--mf-main-card-border, rgba(255,255,255,.10));
      box-shadow:
        inset 0 0 0 1px rgba(255,255,255,.04),
        0 30px 80px -50px rgba(0,0,0,.9);
    }

    body[data-mf-theme="sugar-and-spice"] .mf-app-card{
      background:
        radial-gradient(700px 260px at 8% 0%, rgba(255, 192, 220, .05), transparent 55%),
        radial-gradient(650px 240px at 92% 6%, rgba(148, 163, 122, .05), transparent 58%),
        var(--mf-main-card-bg);
    }

    .mf-app-glow{
      position: relative;
      overflow: hidden;
    }

    .mf-app-glow::before{
      content: "";
      position: absolute;
      inset: -20% -10% auto -10%;
      height: 55%;
      background: radial-gradient(60% 100% at 20% 0%, rgba(var(--mf-accent), .08), transparent 70%);
      pointer-events: none;
    }

    .mf-app-glow::after{
      content: "";
      position: absolute;
      inset: auto -6% -12% auto;
      width: 180px;
      height: 180px;
      pointer-events: none;
      opacity: calc(var(--mf-floral-opacity, 0) * .55);
      background:
        radial-gradient(circle at 50% 50%, rgba(255,214,236,.25) 0 18%, transparent 19% 100%),
        radial-gradient(circle at 28% 38%, rgba(255,184,221,.18) 0 9%, transparent 10% 100%),
        radial-gradient(circle at 72% 38%, rgba(255,184,221,.18) 0 9%, transparent 10% 100%),
        radial-gradient(circle at 35% 68%, rgba(255,184,221,.18) 0 8%, transparent 9% 100%),
        radial-gradient(circle at 65% 68%, rgba(255,184,221,.18) 0 8%, transparent 9% 100%);
      filter: blur(.15px);
      transform: rotate(-12deg);
    }

    .mf-theme-selector{
      border-color: var(--mf-chip-border, rgba(16,185,129,.28));
      background: linear-gradient(to bottom, rgba(0,0,0,.22), rgba(0,0,0,.12)), var(--mf-chip-bg, rgba(16,185,129,.12));
      color: var(--mf-chip-text, rgba(236,253,245,.92));
      box-shadow: 0 10px 30px -20px rgba(0,0,0,.8);
      backdrop-filter: blur(10px);
      width: 100%;
      min-height: 2.75rem;
      overflow: hidden;
    }
    .mf-theme-selector-label{
      flex: 0 0 auto;
      font-family: var(--mf-font-accent);
      color: inherit;
      opacity: .9;
    }
    .mf-theme-select-wrap{
      position: relative;
      flex: 1 1 auto;
      min-width: 0;
      max-width: 100%;
      border: 1px solid var(--btn-secondary-border);
      border-radius: .8rem;
      background: var(--btn-secondary-bg);
      overflow: hidden;
    }
    .mf-theme-select-wrap:hover{
      background: var(--btn-secondary-hover);
      border-color: var(--btn-secondary-border);
    }
    .mf-theme-select-wrap::after{
      content: "▾";
      position: absolute;
      right: .65rem;
      top: 50%;
      transform: translateY(-50%);
      color: var(--mf-chip-text);
      opacity: .82;
      font-size: .75rem;
      line-height: 1;
      pointer-events: none;
    }
    .mf-theme-selector select{
      appearance: none;
      -webkit-appearance: none;
      -moz-appearance: none;
      display: block;
      width: 100%;
      min-width: 0;
      max-width: 100%;
      color: inherit;
      color: var(--btn-secondary-text);
      background: transparent;
      border: 0;
      font-family: var(--mf-font-accent);
      font-size: .875rem;
      line-height: 1.2;
      padding: .5rem 1.9rem .5rem .65rem;
      text-overflow: ellipsis;
      white-space: nowrap;
      overflow: hidden;
    }
    .mf-theme-selector select option{
      color: #111827;
      background: #fff;
    }
    .mf-theme-selector select:focus{
      outline: none;
    }
    .mf-theme-selector:focus-within{
      box-shadow: 0 0 0 1px var(--mf-nav-border-active), 0 10px 30px -20px rgba(0,0,0,.8);
    }
    .mf-theme-selector:focus-within .mf-theme-select-wrap{
      border-color: var(--mf-nav-border-active);
      box-shadow: inset 0 0 0 1px var(--mf-focus-ring);
    }

    /* Typography mood by theme */
    #app-main :is(h1,h2,h3,h4,h5,h6){
      font-family: var(--mf-font-display);
      color: var(--heading);
      letter-spacing: -0.02em;
    }
    #app-main :is(label, th, summary, .uppercase){
      font-family: var(--mf-font-accent);
    }
    body[data-mf-theme="get-shit-done"] #app-main :is(h1,h2,h3,h4,h5,h6){
      text-transform: uppercase;
      letter-spacing: .02em;
      line-height: .95;
    }
    body[data-mf-theme="steve-jobs"] #app-main :is(h1,h2,h3,h4,h5,h6){
      letter-spacing: -.035em;
      font-weight: 600;
    }
    body[data-mf-theme="sugar-and-spice"] #app-main :is(h1,h2,h3){
      letter-spacing: -.03em;
      text-shadow: 0 8px 30px rgba(244,114,182,.10);
    }

    /* Sidebar internals */
    .mf-sidebar-theme-shell :where(a, button, summary, [role="button"]){
      color: var(--mf-sidebar-text);
    }
    .mf-sidebar-theme-shell :where([class*="sidebar-item"], a[href]){
      border-radius: .95rem;
    }
    .mf-sidebar-theme-shell :where([class*="sidebar-item"]){
      padding-left: var(--mf-sidebar-item-pad-x);
      padding-right: var(--mf-sidebar-item-pad-x);
      padding-top: var(--mf-sidebar-item-pad-y);
      padding-bottom: var(--mf-sidebar-item-pad-y);
    }
    .mf-sidebar-theme-shell :where(a[href]):hover{
      background: var(--mf-nav-bg-hover);
    }
    .mf-sidebar-theme-shell :where([aria-current="page"], .mf-active-pill > *){
      background: linear-gradient(to right, var(--mf-nav-bg-active), rgba(255,255,255,0));
      box-shadow: inset 0 0 0 1px var(--mf-nav-border-active);
      border-radius: 14px;
      color: var(--pill-active-text);
    }
    .mf-sidebar-theme-shell :where([class*="text-zinc"], [class*="text-emerald"], [class*="text-amber"]){
      color: inherit;
    }
    .mf-sidebar-theme-shell :where(svg){
      color: rgba(var(--mf-accent), .92);
    }
    .mf-sidebar-theme-shell :where([aria-current="page"] svg, .mf-active-pill svg){
      color: var(--indicator);
    }
    .mf-sidebar-theme-shell details{
      background: var(--mf-panel-bg-2) !important;
      background: color-mix(in srgb, var(--mf-panel-bg-2) 78%, transparent) !important;
      border-color: var(--divider) !important;
    }
    .mf-sidebar-theme-shell details > summary{
      color: var(--mf-sidebar-heading) !important;
    }
    .mf-sidebar-theme-shell details > div{
      background: rgba(0,0,0,.22) !important;
      border: 1px solid var(--mf-panel-border);
    }
    body[data-mf-theme="sugar-and-spice"] .mf-sidebar-theme-shell details > div,
    body[data-mf-theme="steve-jobs"] .mf-sidebar-theme-shell details > div{
      background: rgba(255,255,255,.65) !important;
    }
    .mf-sidebar-theme-shell .mf-sidebar-action-btn{
      border-color: var(--mf-panel-strong-border) !important;
      background: linear-gradient(to bottom, rgba(var(--mf-accent), .12), rgba(0,0,0,.10)) !important;
      color: var(--mf-sidebar-text) !important;
    }
    .mf-sidebar-theme-shell .mf-sidebar-action-btn:hover{
      background: linear-gradient(to bottom, rgba(var(--mf-accent), .18), rgba(0,0,0,.14)) !important;
    }
    body[data-mf-theme="get-shit-done"] .mf-sidebar-theme-shell .mf-sidebar-action-btn{
      text-transform: uppercase;
      font-weight: 700;
      letter-spacing: .04em;
    }

    /* Widget/panel unification inside main app card */
    .mf-app-card{
      color: var(--text-primary);
      min-width: 0;
      box-shadow: var(--card-shadow), inset 0 0 0 1px rgba(255,255,255,.02);
    }
    .mf-app-card > *{
      min-width: 0;
    }
    .mf-app-card :where(.flex,[class*="flex "],[class^="flex"]) > *{
      min-width: 0;
    }
    .mf-app-card :where(.grid,[class*="grid "],[class^="grid"]) > *{
      min-width: 0;
    }
    .mf-app-card :where(.truncate){
      min-width: 0;
      max-width: 100%;
    }
    .mf-app-card :where(.overflow-x-auto){
      max-width: 100%;
      -webkit-overflow-scrolling: touch;
      overscroll-behavior-x: contain;
      border-radius: .9rem;
    }
    .mf-app-card :where(table){
      width: 100%;
      table-layout: auto;
    }
    .mf-app-card :where(th, td){
      vertical-align: top;
      overflow-wrap: anywhere;
    }
    .mf-app-card :where(.rounded-full, .rounded-xl, .rounded-2xl, .rounded-3xl){
      box-sizing: border-box;
    }
    .mf-app-card :where(p, span, small, li, td, dt, dd){
      color: var(--text-secondary);
    }
    .mf-app-card :where(.text-zinc-50, .text-zinc-100, .text-zinc-200, .text-white){
      color: var(--text-primary) !important;
    }
    .mf-app-card :where(.text-zinc-300, .text-zinc-400, .text-zinc-500, .text-slate-300, .text-slate-400){
      color: var(--text-secondary) !important;
    }
    .mf-app-card :where(.text-zinc-600, .text-slate-500){
      color: var(--text-muted) !important;
    }
    .mf-app-card :where(div,section,article,aside,details)[class*="rounded-"][class*="border"]{
      border-color: var(--card-border) !important;
      background: linear-gradient(to bottom, color-mix(in srgb, var(--bg-card) 94%, transparent), var(--bg-card)) !important;
      box-shadow: var(--card-shadow), inset 0 0 0 1px rgba(255,255,255,.02);
    }
    .mf-app-card :where(input, textarea, select){
      color: var(--input-text) !important;
      background: var(--input-bg) !important;
      border-color: var(--input-border) !important;
      box-shadow: none !important;
    }
    .mf-app-card :where(input, textarea, select):focus{
      outline: none !important;
      border-color: var(--input-focus) !important;
      box-shadow: 0 0 0 3px var(--mf-focus-ring) !important;
    }
    .mf-app-card :where(input, textarea, select)::placeholder{
      color: var(--placeholder) !important;
    }
    .mf-app-card :where(table){
      border-color: var(--table-border) !important;
    }
    .mf-app-card :where(table thead){
      background: var(--table-header-bg) !important;
      color: var(--table-header-text) !important;
    }
    .mf-app-card :where(table thead th){
      color: var(--table-header-text) !important;
    }
    .mf-app-card :where(table tbody tr:nth-child(even)){
      background: var(--mf-table-stripe);
    }
    .mf-app-card :where(table tbody tr:hover){
      background: var(--table-row-hover) !important;
    }
    .mf-app-card :where(th, td){
      border-color: var(--table-border) !important;
    }
    .mf-app-card :where(button, [type="button"], [type="submit"]).rounded-xl:not(.mf-sidebar-action-btn){
      border-color: var(--btn-secondary-border);
    }
    .mf-app-card :where(a, button){
      transition: background-color .16s ease, border-color .16s ease, color .16s ease, transform .16s ease;
    }
    /* Interaction guardrails: decorative layers must not block controls. */
    #app-main :where(a, button, input, select, textarea, summary, [role="button"], [role="tab"]){
      pointer-events: auto;
      position: relative;
      z-index: 1;
    }
    #app-main :where(.pointer-events-none){
      z-index: 0;
    }
    .mf-app-card :where(a:hover, button:hover){
      border-color: var(--mf-nav-border-active);
    }
    .mf-app-card :where(a[href]){
      color: var(--link);
    }
    .mf-app-card :where(a[href]:hover){
      color: var(--link-hover);
      text-decoration-color: var(--link-hover);
    }
    body[data-mf-theme="sugar-and-spice"] .mf-app-card :where(button, [type="button"], [type="submit"]).rounded-xl,
    body[data-mf-theme="steve-jobs"] .mf-app-card :where(button, [type="button"], [type="submit"]).rounded-xl{
      color: var(--btn-secondary-text);
      background: var(--btn-secondary-bg);
      border-color: var(--btn-secondary-border);
      box-shadow: 0 8px 18px -14px rgba(15,23,42,.22);
    }
    body[data-mf-theme="sugar-and-spice"] .mf-app-card :where(button, [type="button"], [type="submit"]).rounded-xl:hover,
    body[data-mf-theme="steve-jobs"] .mf-app-card :where(button, [type="button"], [type="submit"]).rounded-xl:hover{
      background: var(--btn-secondary-hover);
    }
    body[data-mf-theme="sugar-and-spice"] .mf-app-card :where(.rounded-full)[class*="border"],
    body[data-mf-theme="steve-jobs"] .mf-app-card :where(.rounded-full)[class*="border"]{
      border-color: var(--pill-border) !important;
      box-shadow: 0 6px 16px -14px rgba(15,23,42,.18);
      color: var(--pill-text) !important;
      background: var(--pill-bg) !important;
    }
    body[data-mf-theme="sugar-and-spice"] .mf-sidebar-theme-shell .mf-sidebar-action-btn,
    body[data-mf-theme="steve-jobs"] .mf-sidebar-theme-shell .mf-sidebar-action-btn{
      background: linear-gradient(to bottom, var(--btn-primary-bg), color-mix(in srgb, var(--btn-primary-bg) 82%, #000 18%)) !important;
      color: var(--btn-primary-text) !important;
      border-color: var(--btn-primary-bg) !important;
    }
    body[data-mf-theme="sugar-and-spice"] .mf-sidebar-theme-shell .mf-sidebar-action-btn:hover,
    body[data-mf-theme="steve-jobs"] .mf-sidebar-theme-shell .mf-sidebar-action-btn:hover{
      background: linear-gradient(to bottom, var(--btn-primary-hover), color-mix(in srgb, var(--btn-primary-hover) 84%, #000 16%)) !important;
      border-color: var(--btn-primary-hover) !important;
    }
    .mf-app-card :where(.sticky){
      max-width: 100%;
    }
    .mf-responsive-shell{
      width: min(100%, 1800px);
      margin-inline: auto;
    }
    .mf-table-wrap{
      overflow-x: auto;
      max-width: 100%;
      border-radius: 1rem;
      border: 1px solid var(--mf-panel-border);
      background: linear-gradient(to bottom, var(--mf-panel-bg-2), var(--mf-panel-bg));
    }
    .mf-table-wrap > table{
      min-width: 40rem;
    }
    @media (max-width: 640px){
      .mf-app-card{
        padding: 1rem !important;
        border-radius: 1.25rem !important;
      }
      .mf-table-wrap > table{
        min-width: 34rem;
      }
      #app-main{
        padding: 1rem !important;
      }
    }

    /* Theme-specific personality accents */
    body[data-mf-theme="get-shit-done"] .mf-app-card{
      box-shadow:
        inset 0 0 0 1px rgba(239,68,68,.06),
        0 34px 90px -52px rgba(0,0,0,.92);
      border-radius: .9rem;
    }
    body[data-mf-theme="get-shit-done"] .mf-theme-selector{
      text-transform: uppercase;
      letter-spacing: .04em;
      font-weight: 700;
    }
    body[data-mf-theme="get-shit-done"] .mf-sidebar-theme-shell :where([class*="sidebar-item"], a[href]){
      border-radius: .55rem;
    }
    body[data-mf-theme="steve-jobs"] .mf-app-card{
      border-radius: 1.5rem;
      box-shadow:
        inset 0 0 0 1px rgba(255,255,255,.02),
        0 24px 44px -30px rgba(15,23,42,.14);
    }
    body[data-mf-theme="steve-jobs"] .mf-sidebar-glow::before{
      opacity: .45;
      filter: blur(24px);
    }
    body[data-mf-theme="steve-jobs"] .mf-sidebar-group-balanced > .space-y-1{
      border-color: rgba(255,255,255,.025);
      background: linear-gradient(to bottom, rgba(255,255,255,.008), rgba(255,255,255,0));
    }
    body[data-mf-theme="steve-jobs"] .mf-sidebar-theme-shell :where([aria-current="page"], .mf-active-pill > *){
      color: var(--mf-sidebar-text) !important;
    }
    /* Steve Jobs theme: force dark text on light surfaces/cards/controls */
    body[data-mf-theme="steve-jobs"] .mf-app-card :where([class*="text-white"], [class*="text-zinc-50"], [class*="text-zinc-100"], [class*="text-zinc-200"], [class*="text-emerald-50"], [class*="text-amber-50"], [class*="text-sky-50"]){
      color: var(--mf-text-1) !important;
    }
    body[data-mf-theme="steve-jobs"] .mf-app-card :where([class*="text-zinc-300"], [class*="text-zinc-400"], [class*="text-zinc-500"], [class*="text-slate-300"], [class*="text-slate-400"], [class*="text-slate-500"], [class*="text-white/50"], [class*="text-white/60"], [class*="text-white/70"]){
      color: var(--mf-text-2) !important;
    }
    body[data-mf-theme="steve-jobs"] .mf-app-card :where(button, a, input, textarea, select){
      color: var(--mf-text-1) !important;
    }
    body[data-mf-theme="steve-jobs"] .mf-app-card :where(input::placeholder, textarea::placeholder){
      color: var(--mf-text-3) !important;
    }
    body[data-mf-theme="steve-jobs"] .mf-sidebar-theme-shell details > div :where([class*="text-white"], [class*="text-zinc-"], [class*="text-emerald-"], [class*="text-amber-"]){
      color: var(--mf-sidebar-text) !important;
    }
    body[data-mf-theme="get-shit-done"] .mf-sidebar-group-balanced [data-flux-sidebar-group-heading],
    body[data-mf-theme="get-shit-done"] .mf-sidebar-group-balanced :where(h2, h3, h4){
      letter-spacing: .14em;
      font-weight: 800;
    }
    body[data-mf-theme="sugar-and-spice"] .mf-sidebar-group-balanced > .space-y-1{
      background:
        radial-gradient(180px 80px at 100% 0%, rgba(255,184,221,.04), transparent 70%),
        linear-gradient(to bottom, rgba(255,255,255,.012), rgba(255,255,255,0));
      border-color: rgba(255,184,221,.08);
    }
    body[data-mf-theme="sugar-and-spice"] .mf-sidebar-footer{
      border-top-color: rgba(255,184,221,.08);
    }

    /* App-level announcement banner (outside .mf-app-card) */
    .mf-announcement{
      border-color: rgba(252, 211, 77, .28) !important;
      background: rgba(245, 158, 11, .12) !important;
      color: rgba(255, 251, 235, .92) !important;
      box-shadow: 0 14px 28px -22px rgba(245, 158, 11, .45);
    }
    .mf-announcement-subtle{
      color: rgba(255, 251, 235, .72) !important;
    }
    .mf-announcement-btn{
      border-color: rgba(252, 211, 77, .34) !important;
      background: rgba(245, 158, 11, .18) !important;
      color: rgba(255, 251, 235, .94) !important;
    }
    .mf-announcement-btn:hover{
      background: rgba(245, 158, 11, .24) !important;
      border-color: rgba(252, 211, 77, .42) !important;
    }
    .mf-announcement-btn.mf-announcement-btn-muted{
      background: rgba(245, 158, 11, .11) !important;
      color: rgba(255, 251, 235, .82) !important;
    }
    .mf-announcement-btn.mf-announcement-btn-muted:hover{
      background: rgba(245, 158, 11, .15) !important;
    }

    body[data-mf-theme="sugar-and-spice"] .mf-announcement{
      border-color: #d6a7b8 !important;
      background: linear-gradient(to bottom, rgba(184,50,110,.16), rgba(122,28,72,.14)) !important;
      color: #4a1f2e !important;
      box-shadow: 0 16px 28px -22px rgba(148, 60, 100, .24);
    }
    body[data-mf-theme="sugar-and-spice"] .mf-announcement-subtle{
      color: #7a4b5c !important;
    }
    body[data-mf-theme="sugar-and-spice"] .mf-announcement-btn{
      border-color: #b06a88 !important;
      background: #b8326e !important;
      color: #fff !important;
    }
    body[data-mf-theme="sugar-and-spice"] .mf-announcement-btn:hover{
      border-color: #9f2a5e !important;
      background: #9f2a5e !important;
    }
    body[data-mf-theme="sugar-and-spice"] .mf-announcement-btn.mf-announcement-btn-muted{
      border-color: #d6a7b8 !important;
      background: #f2d1dc !important;
      color: #4a1f2e !important;
    }
    body[data-mf-theme="sugar-and-spice"] .mf-announcement-btn.mf-announcement-btn-muted:hover{
      background: #e8b7c8 !important;
    }

    body[data-mf-theme="steve-jobs"] .mf-announcement{
      border-color: #bdbdbd !important;
      background: linear-gradient(to bottom, #e5e5e5, #dcdcdc) !important;
      color: #111111 !important;
      box-shadow: 0 16px 28px -22px rgba(0, 0, 0, .18);
    }
    body[data-mf-theme="steve-jobs"] .mf-announcement-subtle{
      color: #333333 !important;
    }
    body[data-mf-theme="steve-jobs"] .mf-announcement-btn{
      border-color: #111111 !important;
      background: #111111 !important;
      color: #ffffff !important;
    }
    body[data-mf-theme="steve-jobs"] .mf-announcement-btn:hover{
      border-color: #000000 !important;
      background: #000000 !important;
    }
    body[data-mf-theme="steve-jobs"] .mf-announcement-btn.mf-announcement-btn-muted{
      border-color: #bdbdbd !important;
      background: #f4f4f4 !important;
      color: #111111 !important;
    }
    body[data-mf-theme="steve-jobs"] .mf-announcement-btn.mf-announcement-btn-muted:hover{
      background: #ececec !important;
    }
  </style>
</head>

@php
  $prefs = is_array(auth()->user()?->ui_preferences ?? null) ? auth()->user()->ui_preferences : [];
  $wideLayout = !empty($prefs['wide_layout']);
  $compactTables = !empty($prefs['compact_tables']);
  $themeOptions = [
      'forestry-green' => 'Forestry Green',
      'sugar-and-spice' => 'Sugar and Spice',
      'get-shit-done' => 'Get Shit Done',
      'steve-jobs' => 'Steve Jobs',
  ];
  $activeTheme = is_string($prefs['theme'] ?? null) && array_key_exists($prefs['theme'], $themeOptions)
      ? $prefs['theme']
      : 'forestry-green';
@endphp
<body data-mf-theme="{{ $activeTheme }}" class="min-h-screen text-zinc-100 antialiased mf-app-shell {{ $wideLayout ? 'mf-wide' : '' }} {{ $compactTables ? 'mf-compact' : '' }}">
@php
  use Illuminate\Support\Facades\Route;
  $user = auth()->user();
  $isAdmin = $user?->isAdmin() ?? true;
  $isManager = $user?->isManager() ?? false;
  $isPouring = $user?->isPouring() ?? false;

  $hrefDashboard = Route::has('dashboard')        ? route('dashboard')        : '/dashboard';
  $hrefShipping  = Route::has('shipping.orders')  ? route('shipping.orders')  : '/shipping/orders';
  $hrefPouring   = Route::has('pouring.index')    ? route('pouring.index')    : '/pouring';
  $hrefRetailPlan = Route::has('retail.plan')     ? route('retail.plan')      : '/retail/plan';
  $hrefAdmin     = Route::has('admin.index')      ? route('admin.index')      : '/admin';
  $hrefAnalytics = Route::has('analytics.index')  ? route('analytics.index')  : '/analytics';

  $shippingActive  = request()->routeIs('shipping.*')  || request()->is('shipping*');
  $pouringActive   = request()->routeIs('pouring.index')
      || request()->routeIs('pouring.queue')
      || request()->routeIs('pouring.bulk')
      || request()->is('pouring')
      || request()->is('pouring/queue')
      || request()->is('pouring/bulk');
  $retailPlanActive = request()->routeIs('retail.plan') || request()->is('retail/plan');
  $adminActive     = request()->routeIs('admin.*')     || request()->is('admin*');
  $analyticsActive = request()->routeIs('analytics.*') || request()->is('analytics*');
  $wikiActive = request()->routeIs('wiki.index') || request()->is('wiki');
  $inventoryActive = request()->routeIs('inventory.index');
  $eventsActive = request()->routeIs('events.*');
  $marketListsActive = request()->routeIs('markets.lists.*');
  $marketsActive = request()->routeIs('pouring.requests');

  $sidebarItems = [];
  if (!$isPouring) {
      $sidebarItems[] = ['key' => 'retail-plan', 'icon' => 'clipboard-document-check', 'href' => $hrefRetailPlan, 'label' => 'Retail/Pour List', 'current' => $retailPlanActive];
      $sidebarItems[] = ['key' => 'inventory', 'icon' => 'archive-box', 'href' => route('inventory.index'), 'label' => 'Inventory', 'current' => $inventoryActive];
      $sidebarItems[] = ['key' => 'shipping-room', 'icon' => 'truck', 'href' => $hrefShipping, 'label' => 'Shipping Room', 'current' => $shippingActive];
  }
  if ($isAdmin || $isManager) {
      $sidebarItems[] = ['key' => 'events', 'icon' => 'calendar-days', 'href' => route('events.index'), 'label' => 'Events', 'current' => $eventsActive];
      $sidebarItems[] = ['key' => 'market-pour-lists', 'icon' => 'list-bullet', 'href' => route('markets.lists.index'), 'label' => 'Market Pour Lists', 'current' => $marketListsActive];
  }
  $sidebarItems[] = ['key' => 'pouring-room', 'icon' => 'fire', 'href' => $hrefPouring, 'label' => 'Pouring Room', 'current' => $pouringActive];
  $sidebarItems[] = ['key' => 'markets', 'icon' => 'clipboard-document', 'href' => route('pouring.requests'), 'label' => 'Markets', 'current' => $marketsActive];
  if ($isAdmin || $isManager) {
      $sidebarItems[] = ['key' => 'administration', 'icon' => 'cog', 'href' => $hrefAdmin, 'label' => 'Administration', 'current' => $adminActive];
  }
  if (!$isPouring) {
      $sidebarItems[] = ['key' => 'analytics', 'icon' => 'chart-bar', 'href' => $hrefAnalytics, 'label' => 'Analytics', 'current' => $analyticsActive];
  }
  $sidebarItems[] = ['key' => 'backstage-wiki', 'icon' => 'book-open', 'href' => route('wiki.index'), 'label' => 'Backstage Wiki', 'current' => $wikiActive];

  $preferredSidebarOrder = is_array($prefs['sidebar_order'] ?? null) ? $prefs['sidebar_order'] : [];
  $sidebarItemsByKey = collect($sidebarItems)->keyBy('key');
  $orderedSidebarKeys = [];
  foreach ($preferredSidebarOrder as $key) {
      if (is_string($key) && $sidebarItemsByKey->has($key) && !in_array($key, $orderedSidebarKeys, true)) {
          $orderedSidebarKeys[] = $key;
      }
  }
  foreach ($sidebarItems as $item) {
      if (!in_array($item['key'], $orderedSidebarKeys, true)) {
          $orderedSidebarKeys[] = $item['key'];
      }
  }
  $orderedSidebarItems = collect($orderedSidebarKeys)
      ->map(fn ($key) => $sidebarItemsByKey->get($key))
      ->filter()
      ->values();

  $unresolvedExceptions = \App\Models\MappingException::query()
      ->whereNull('resolved_at')
      ->count();
  $latestRun = \App\Models\ShopifyImportRun::query()
      ->orderByDesc('id')
      ->first();
@endphp

<div class="min-h-screen flex">

  {{-- Sidebar --}}
  <flux:sidebar
    sticky
    collapsible="mobile"
    class="relative overflow-hidden mf-transition border-e mf-sidebar-theme-shell"
  >
    <div class="mf-sidebar-glow absolute inset-0"></div>

    <div class="relative mf-fade-in">
      <flux:sidebar.header class="mf-transition mf-sidebar-header">
        <x-app-logo :sidebar="true" href="{{ $hrefDashboard }}" wire:navigate class="mf-transition" />
        <flux:sidebar.collapse class="lg:hidden mf-transition" />
      </flux:sidebar.header>

      @auth
        <div class="mf-sidebar-theme-slot">
          <div class="mf-theme-selector inline-flex w-full items-center justify-between gap-3 rounded-2xl border px-3 py-2">
            <span class="mf-theme-selector-label text-[10px] uppercase tracking-[0.24em]">Theme</span>
            <div class="mf-theme-select-wrap">
              <select id="mf-theme-picker"
                      class="min-w-0 flex-1 text-sm focus:outline-none"
                      data-theme-save-url="{{ route('ui.preferences.theme') }}"
                      data-theme-csrf="{{ csrf_token() }}">
                @foreach($themeOptions as $themeKey => $themeLabel)
                  <option value="{{ $themeKey }}" @selected($activeTheme === $themeKey)>{{ $themeLabel }}</option>
                @endforeach
              </select>
            </div>
          </div>
        </div>
      @endauth

      <flux:sidebar.nav class="mf-sidebar-nav">
        <flux:sidebar.group heading="Modern Forestry Backstage" class="grid mf-sidebar-group-balanced">
          <div class="space-y-1" data-sidebar-sortable data-sidebar-save-url="{{ route('ui.preferences.sidebar-order') }}" data-sidebar-csrf="{{ csrf_token() }}">
            @foreach($orderedSidebarItems as $item)
              <div
                class="mf-sidebar-sort-item {{ $item['current'] ? 'mf-active-pill' : '' }}"
                data-sidebar-item
                data-sidebar-key="{{ $item['key'] }}"
              >
                <flux:sidebar.item icon="{{ $item['icon'] }}" href="{{ $item['href'] }}" :current="$item['current']" wire:navigate class="mf-transition mf-nav-item">
                  {{ $item['label'] }}
                </flux:sidebar.item>
              </div>
            @endforeach
            <details class="ml-3 rounded-xl border px-2 py-1 mf-sidebar-panel" {{ request()->routeIs('wiki.wholesale-processes') || request()->is('wiki/article/wholesale*') || (request()->routeIs('wiki.article') && request()->route('slug') === 'market-room') ? 'open' : '' }}>
              <summary class="cursor-pointer list-none text-xs text-emerald-100/70 flex items-center justify-between px-2 py-1 group">
                <span>Wiki Sections</span>
                <span class="text-[10px] transition-transform group-open:rotate-90">▸</span>
              </summary>
              <div class="mt-2 rounded-xl p-2 space-y-1">
                <div class="{{ request()->routeIs('wiki.wholesale-processes') || request()->is('wiki/article/wholesale*') ? 'mf-active-pill' : '' }}">
                  <flux:sidebar.item icon="folder-open" href="{{ route('wiki.wholesale-processes') }}" class="mf-transition mf-nav-item">
                    Wholesale Processes
                  </flux:sidebar.item>
                </div>
                <div class="{{ request()->routeIs('wiki.article') && request()->route('slug') === 'market-room' ? 'mf-active-pill' : '' }}">
                  <flux:sidebar.item icon="sparkles" href="{{ route('wiki.article', ['slug' => 'market-room']) }}" class="mf-transition mf-nav-item">
                    Market Room Process
                  </flux:sidebar.item>
                </div>
              </div>
            </details>
          </div>
        </flux:sidebar.group>

        @if(!$isPouring)
        <flux:sidebar.group heading="Quick Actions" class="grid mt-3 mf-sidebar-group-balanced">
          <flux:sidebar.item icon="clock" href="{{ $hrefShipping }}" wire:navigate class="mf-transition mf-nav-item">
            Due soon
          </flux:sidebar.item>

          @if($isAdmin || $isManager)
            <details class="mt-2 rounded-2xl border p-3 group mf-sidebar-panel">
              <summary class="cursor-pointer list-none text-[10px] uppercase tracking-[0.3em] text-emerald-100/50 flex items-center justify-between">
                <span>Import Tools</span>
                <span class="text-[10px] transition-transform group-open:rotate-90">▸</span>
              </summary>
              <div class="mt-3 space-y-2">
                <form method="POST" action="{{ route('admin.tools.clear-orders') }}">
                  @csrf
                  <button type="submit" class="w-full rounded-xl border px-3 py-2 text-xs mf-sidebar-action-btn">
                    Clear Orders
                  </button>
                </form>
                <form method="POST" action="{{ route('admin.tools.import-retail') }}">
                  @csrf
                  <button type="submit" class="w-full rounded-xl border px-3 py-2 text-xs mf-sidebar-action-btn">
                    Import Retail Orders
                  </button>
                </form>
                <form method="POST" action="{{ route('admin.tools.import-wholesale') }}">
                  @csrf
                  <button type="submit" class="w-full rounded-xl border px-3 py-2 text-xs mf-sidebar-action-btn">
                    Import Wholesale Orders
                  </button>
                </form>
              </div>
            </details>
          @endif
        </flux:sidebar.group>
        @endif
      </flux:sidebar.nav>

      <flux:spacer />

      @auth
        <div class="mf-transition mf-sidebar-footer">
          <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </div>
      @endauth
    </div>
  </flux:sidebar>

  {{-- Right side --}}
  <div class="flex-1 min-w-0 flex flex-col">

    {{-- Mobile Header --}}
    <flux:header class="lg:hidden mf-fade-in">
      <flux:sidebar.toggle class="lg:hidden mf-transition" icon="bars-2" inset="left" />
      <flux:spacer />
      {{-- keep your auth dropdown --}}
    </flux:header>

    {{-- Main content --}}
    <main id="app-main" class="flex-1 min-w-0 overflow-y-auto p-6">
      @if($unresolvedExceptions > 0)
        <div class="mf-announcement mb-4 rounded-2xl border px-4 py-3 text-sm">
          <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
            <div>
              <span class="font-semibold">Import Attention:</span>
              {{ $unresolvedExceptions }} unmapped line item{{ $unresolvedExceptions === 1 ? '' : 's' }} need review.
              @if($latestRun)
                <span class="mf-announcement-subtle ml-2">Last run: #{{ $latestRun->id }} ({{ $latestRun->store_key ?? 'store' }})</span>
              @endif
            </div>
            <div class="flex items-center gap-2">
              <a href="{{ route('admin.mapping-exceptions') }}"
                 class="mf-announcement-btn inline-flex items-center rounded-full border px-3 py-1.5 text-xs font-semibold">
                Fix Exceptions
              </a>
              <a href="{{ route('admin.import-runs') }}"
                 class="mf-announcement-btn mf-announcement-btn-muted inline-flex items-center rounded-full border px-3 py-1.5 text-xs font-semibold">
                Import Runs
              </a>
            </div>
          </div>
        </div>
      @endif
      <div class="rounded-3xl mf-app-card mf-app-glow p-6 md:p-7 text-zinc-100">
        {{ $slot }}
      </div>
    </main>

  </div>
</div>

<div id="mf-toast" role="status" aria-live="polite" class="pointer-events-none fixed left-1/2 top-5 z-50 hidden w-[min(92vw,48rem)] -translate-x-1/2 rounded-2xl border border-white/10 bg-zinc-900/95 px-5 py-4 text-base font-semibold text-white shadow-2xl"></div>
<script>
  (function () {
    let timeoutId;
    function showToast(detail) {
      const el = document.getElementById('mf-toast');
      if (!el) return;
      const payload = detail && typeof detail === 'object' && detail[0] && typeof detail[0] === 'object'
        ? detail[0]
        : detail;
      const message = payload && payload.message ? payload.message : 'Saved.';
      const style = payload && (payload.style || payload.type) ? (payload.style || payload.type) : 'success';
      el.classList.remove('hidden', 'border-emerald-400/40', 'border-red-400/40', 'border-amber-300/40', 'bg-emerald-950/90', 'bg-red-950/90', 'bg-amber-950/90');
      if (style === 'error') {
        el.classList.add('border-red-400/40');
        el.classList.add('bg-red-950/90');
      } else if (style === 'warning') {
        el.classList.add('border-amber-300/40');
        el.classList.add('bg-amber-950/90');
      } else {
        el.classList.add('border-emerald-400/40');
        el.classList.add('bg-emerald-950/90');
      }
      el.textContent = message;
      clearTimeout(timeoutId);
      timeoutId = setTimeout(() => el.classList.add('hidden'), 6000);
    }
    window.addEventListener('toast', (e) => showToast(e.detail));
  })();
</script>
<script>
  (function () {
    const picker = document.getElementById('mf-theme-picker');
    if (!picker || picker.dataset.mfBound === '1') return;
    picker.dataset.mfBound = '1';

    picker.addEventListener('change', async () => {
      const theme = picker.value || 'forestry-green';
      document.body.setAttribute('data-mf-theme', theme);
      window.dispatchEvent(new CustomEvent('mf:theme-changed', { detail: { theme } }));

      try {
        const res = await fetch(picker.dataset.themeSaveUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': picker.dataset.themeCsrf || '',
            'Accept': 'application/json',
          },
          body: JSON.stringify({ theme }),
          credentials: 'same-origin',
        });

        if (!res.ok) {
          throw new Error('Failed to save theme');
        }

        window.dispatchEvent(new CustomEvent('toast', {
          detail: { style: 'success', message: 'Theme updated.' }
        }));
      } catch (e) {
        window.dispatchEvent(new CustomEvent('toast', {
          detail: { style: 'warning', message: 'Theme preview changed, but save failed.' }
        }));
      }
    });
  })();
</script>
<script>
  (function () {
    function scrollTop() {
      const main = document.getElementById('app-main');
      const doScroll = () => {
        if (main) {
          main.scrollTop = 0;
        }
        window.scrollTo({ top: 0, left: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
      };
      doScroll();
      requestAnimationFrame(doScroll);
      setTimeout(doScroll, 50);
    }
    document.addEventListener('livewire:navigated', scrollTop);
    document.addEventListener('livewire:navigation', scrollTop);
    document.addEventListener('admin-tab-changed', scrollTop);
  })();
</script>

@fluxScripts
@livewireScripts
@livewireScriptConfig
</body>
</html>
