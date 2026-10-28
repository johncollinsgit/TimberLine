{{-- Presentation only: the supplied landscape is decorative; proposal content stays versioned. --}}
<style>
.proposal-reader{background:#F7F5EE}
.proposal-reader main{max-width:1480px;padding:36px clamp(20px,3.5vw,52px) 64px}
.proposal-reader .proposal-hero{position:relative;isolation:isolate;overflow:hidden;min-height:380px;padding:32px calc(clamp(280px,36%,480px) + 24px) 44px 36px;border:0;border-bottom:1px solid #d8ded3;background:transparent;margin:0 0 28px}
.proposal-reader .proposal-corner{position:absolute;top:0;right:0;width:clamp(280px,36%,480px);height:auto;pointer-events:none}
.proposal-reader .proposal-hero .topline{justify-content:flex-start;gap:24px}
.proposal-reader .proposal-hero .brand{display:block;flex:none;line-height:0}
.proposal-reader .proposal-company-wordmark{display:block;width:220px;max-width:100%;height:auto}
.proposal-reader .proposal-hero h1{max-width:1050px;font-size:clamp(34px,4.5vw,62px);margin-bottom:18px}
.proposal-reader .proposal-hero .muted{color:#43594b}
.proposal-reader .proposal-introduction{max-width:92ch}
.proposal-reader .proposal-personal-letter{max-width:820px;margin:0 auto;padding:28px clamp(20px,4vw,44px);background:white;font:17px/1.7 Georgia,serif;color:#26352f}
.proposal-reader .proposal-letter-logo{display:block;width:283px;max-width:100%;height:auto;margin:0 0 24px}
.proposal-reader .proposal-personal-letter p{margin:0 0 18px}
.proposal-reader .proposal-letter-signature strong{display:inline-block;margin-top:12px}
.proposal-reader .proposal-letter-signature span{font:14px/1.6 ui-sans-serif,system-ui,sans-serif;color:#66736b}
.proposal-reader .proposal-personal-letter figure{margin:12px 0 0;width:280px;max-width:100%}
.proposal-reader .proposal-personal-letter figure img{display:block;width:100%;height:auto}
.proposal-reader .proposal-personal-letter figcaption{font:12px/1.6 ui-sans-serif,system-ui,sans-serif;color:#66736b}
.proposal-reader .body-copy{max-width:92ch}
.proposal-reader .campus-review-entry{display:grid;grid-template-columns:minmax(0,1fr) auto;column-gap:28px;align-items:center;padding:20px 24px}
.proposal-reader .campus-review-entry h2{grid-column:1;font-size:20px;margin:0 0 8px}
.proposal-reader .campus-review-entry p:first-of-type{grid-column:1;margin:0;max-width:86ch;font-size:14px}
.proposal-reader .campus-review-entry p:last-of-type{grid-column:2;grid-row:1/span 2;margin:0}
.proposal-reader .journey{margin:44px 0;padding-top:28px}
.proposal-reader .journey-intro{max-width:92ch}
.proposal-reader .journey-overview{margin:24px 0 30px}
.proposal-reader .journey-landscape{display:none}
.proposal-reader .department-story{margin:32px 0 36px}
.proposal-reader .department-story-lead{max-width:92ch}
.proposal-reader .department-network{max-width:1200px;margin-left:auto;margin-right:auto}
.proposal-reader .rollout-story{max-width:none}
.proposal-reader .journey-path{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px;margin:0}
.proposal-reader .journey-path>li,.proposal-reader .journey-path>li:nth-child(even){padding:24px 26px 26px 82px;min-height:0;border:1px solid #d8ded3;border-radius:14px;background:#fffefa;break-inside:avoid}
.proposal-reader .journey-trail{display:none}
.proposal-reader .journey-step{left:22px;top:26px;width:40px;height:40px;background:#edf0e6;box-shadow:none}
.proposal-reader .journey-path>li:nth-child(even) .journey-phase{margin-left:0}
.proposal-reader .journey-phase{max-width:none}
.proposal-reader .journey-phase h3{font-size:30px}
.proposal-reader .journey-body{font-size:15px}
.proposal-reader .journey-detail{gap:20px}
.proposal-reader .journey-end{margin:28px 0 0;max-width:100%;gap:20px}
.proposal-reader .journey-end>svg{visibility:hidden}
.proposal-reader .proposal-network-leaf{visibility:hidden}
.proposal-reader .grid{gap:24px}
.proposal-reader .working-boundaries-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));column-gap:42px;row-gap:14px}
.proposal-reader .working-boundaries-grid article{min-width:0}
.proposal-reader .working-boundaries-grid p{margin:8px 0 14px;max-width:78ch}
/* Passive CSS chevrons keep native select semantics without an image permission. */
.proposal-reader .proposal-select{display:block;position:relative;min-width:0}
.proposal-reader .proposal-select::after{content:"";position:absolute;right:16px;top:calc(50% - 6px);width:8px;height:8px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg);pointer-events:none}
.proposal-reader select:not([multiple]){-webkit-appearance:none;appearance:none;min-height:46px;color-scheme:light;padding:10px 38px 10px 12px}
.proposal-reader .proposal-select:has(select:disabled)::after{color:GrayText}
.proposal-reader #review-comments form{max-width:860px}
.proposal-reader #review-comments>article{max-width:1000px}
@media(max-width:1000px){
.proposal-reader .proposal-hero{min-height:300px;padding-right:calc(30% + 24px)}
.proposal-reader .proposal-corner{width:30%}
.proposal-reader .journey-path{grid-template-columns:1fr}
.proposal-reader .journey-detail{grid-template-columns:repeat(2,minmax(0,1fr))}
.proposal-reader .working-boundaries-grid{column-gap:28px}
}
@media(max-width:720px){
.proposal-reader main{padding:20px 18px 40px}
.proposal-reader .proposal-hero{min-height:0;padding:24px 22px 32px;margin:0 0 24px}
.proposal-reader .proposal-hero h1{font-size:36px;letter-spacing:-.7px}
.proposal-reader .proposal-corner{width:44%;max-width:218px}
.proposal-reader .proposal-hero .topline{display:block;min-height:182px;padding-right:calc(min(44%,218px) + 8px)}
.proposal-reader .proposal-company-wordmark{width:160px}
.proposal-reader .proposal-hero .pill{margin-top:12px}
.proposal-reader .campus-review-entry{display:block;padding:20px}
.proposal-reader .campus-review-entry p:last-of-type{margin-top:16px}
.proposal-reader .journey-path{gap:18px}
.proposal-reader .journey-path>li,.proposal-reader .journey-path>li:nth-child(even){padding:22px 20px 24px}
.proposal-reader .journey-step{position:absolute;left:20px;top:22px;width:34px;height:34px;margin:0;font-size:16px}
.proposal-reader .journey-phase>.eyebrow{padding:8px 0 8px 50px;min-height:34px;margin:0 0 16px}
.proposal-reader .journey-phase h3{font-size:27px}
.proposal-reader .journey-detail,.proposal-reader .working-boundaries-grid{grid-template-columns:1fr;gap:0}
.proposal-reader .row{flex-wrap:wrap;gap:8px}
.proposal-reader .row .price{margin-left:auto}
}
@media(max-width:480px){.proposal-reader .proposal-hero .topline{min-height:128px}}
@media(forced-colors:active){
.proposal-reader select:not([multiple]){color-scheme:light dark;background-color:Canvas;color:CanvasText;border-color:ButtonText}
.proposal-reader .proposal-select{color-scheme:light dark;color:CanvasText}
.proposal-reader select:disabled:not([multiple]){color:GrayText;border-color:GrayText}
.proposal-reader .proposal-select:has(select:disabled)::after{color:GrayText}
}
@media print{
.proposal-reader main{max-width:none;padding:0}
.proposal-reader .proposal-hero{min-height:240px;padding:20px calc(36% + 24px) 32px 24px;margin:0 0 24px;print-color-adjust:exact;-webkit-print-color-adjust:exact}
.proposal-reader .proposal-corner{width:36%;max-width:none}
.proposal-reader .proposal-hero .topline{display:flex;min-height:0;padding:0}
.proposal-reader .proposal-hero h1{font-size:36px}
.proposal-reader .campus-review-entry{display:block}
.proposal-reader .campus-review-entry .button{display:none}
.proposal-reader .journey{margin:28px 0}
.proposal-reader .journey-path{display:block}
.proposal-reader .journey-path>li,.proposal-reader .journey-path>li:nth-child(even){margin-bottom:18px;padding:18px 22px 20px 74px}
.proposal-reader .journey-step{position:absolute;left:20px;top:20px;width:34px;height:34px;margin:0}
.proposal-reader .journey-phase h3{font-size:26px}
.proposal-reader .journey-phase>.eyebrow{padding:0;min-height:0;margin:2px 0 13px}
.proposal-reader .journey-detail{grid-template-columns:repeat(2,minmax(0,1fr))}
.proposal-reader .working-boundaries-grid{display:block}
.proposal-reader .working-boundaries-grid article{break-inside:avoid}
}

.proposal-reader .lmc-comparison h2{font:400 32px/1.2 Georgia,serif;color:#193f35;margin:0 0 16px}.proposal-reader .lmc-comparison h3{font-size:22px;line-height:1.2;margin:0 0 12px}.proposal-reader .lmc-comparison-scroll{max-width:100%;overflow-x:auto;border:1px solid #dce3d7;border-radius:12px}.proposal-reader .lmc-comparison-table{width:100%;min-width:620px;table-layout:fixed;border-collapse:collapse;margin:0}.proposal-reader .lmc-comparison-table th,.proposal-reader .lmc-comparison-table td{text-align:left;vertical-align:top;padding:16px;font-size:14px;line-height:1.5;border-bottom:1px solid #dce3d7;border-right:1px solid #dce3d7}.proposal-reader .lmc-comparison-table th{text-transform:none;letter-spacing:normal}.proposal-reader .lmc-comparison-table .lmc-comparison-bundle small{color:#e0e9d9}.proposal-reader .lmc-comparison-table th:first-child{width:34%}.proposal-reader .lmc-comparison-table thead th{background:#edf2e8;color:#243e32}.proposal-reader .lmc-comparison-table thead th:first-child{vertical-align:bottom}.proposal-reader .lmc-comparison-table thead .lmc-comparison-bundle{background:#193f35;color:#fff}.proposal-reader .lmc-comparison-plan>span{display:block;font-size:18px;font-weight:700}.proposal-reader .lmc-comparison-plan>small{display:block;font-weight:400;margin:9px 0 16px}.proposal-reader .lmc-comparison-plan>strong{display:block;font-size:34px;line-height:1.15;letter-spacing:-1px}.proposal-reader .lmc-comparison-plan strong small{font-size:13px;letter-spacing:0;font-weight:400}.proposal-reader .lmc-comparison-plan>b{display:block;padding-top:13px;border-top:1px solid #9bae92}.proposal-reader .lmc-comparison-table tbody th{background:#fafbf7}.proposal-reader .lmc-comparison-table tbody td:last-child{background:#f0f5ed}.proposal-reader .lmc-comparison-table tr:last-child>*{border-bottom:0}.proposal-reader .lmc-comparison-table tr>*:last-child{border-right:0}.proposal-reader .lmc-comparison-value{display:grid;grid-template-columns:1fr 1fr;gap:25px;padding:24px;margin:24px 0;background:#edf2e8;border:1px solid #dbe4d4;border-radius:12px}.proposal-reader .lmc-comparison-value>p{font-size:13px}.proposal-reader .lmc-comparison-value strong{font-size:24px;color:#214d3e}.proposal-reader .lmc-comparison-stages{margin:28px 0}.proposal-reader .lmc-comparison-stage{display:grid;grid-template-columns:160px minmax(100px,1fr) 165px;gap:15px;align-items:center;margin:15px 0;font-size:14px}.proposal-reader .lmc-comparison-stage b,.proposal-reader .lmc-comparison-stage>div>small{display:block}.proposal-reader .lmc-comparison-stage small{font-size:12px;font-weight:400}.proposal-reader .lmc-comparison-stage>strong{text-align:right}.proposal-reader .lmc-comparison-track{background:#edf0e8;height:24px;border-radius:5px;overflow:hidden}.proposal-reader .lmc-comparison-bar{display:block;height:100%}.proposal-reader .lmc-comparison-bar-0{width:40%;background:#8aa68a}.proposal-reader .lmc-comparison-bar-1{width:80%;background:#c2a762}.proposal-reader .lmc-comparison-bar-2{width:100%;background:#214b3e}.proposal-reader .lmc-comparison-scope{background:#f8f7f0;border-left:3px solid #bca461;padding:15px 18px;font-size:13px}
@media(max-width:650px){.proposal-reader .lmc-comparison h2{font-size:28px}.proposal-reader .lmc-comparison-value{grid-template-columns:1fr;gap:12px;padding:20px}.proposal-reader .lmc-comparison-stage{grid-template-columns:1fr 1fr;gap:6px 12px;margin:20px 0}.proposal-reader .lmc-comparison-track{grid-column:1/-1;grid-row:2}.proposal-reader .lmc-comparison-table th,.proposal-reader .lmc-comparison-table td{padding:13px}}
@media(forced-colors:active){.proposal-reader .lmc-comparison-track,.proposal-reader .lmc-comparison-bar{border:1px solid CanvasText}.proposal-reader .lmc-comparison-table thead .lmc-comparison-bundle{background:Canvas;color:CanvasText}}
@media print{.proposal-reader .lmc-comparison-table{min-width:0}.proposal-reader .lmc-comparison-table th,.proposal-reader .lmc-comparison-table td{padding:7px;font-size:9px}.proposal-reader .lmc-comparison-plan>span{font-size:13px}.proposal-reader .lmc-comparison-plan>strong{font-size:20px}.proposal-reader .lmc-comparison-value,.proposal-reader .lmc-comparison-stage{break-inside:avoid}}
.proposal-reader .proposal-section-tabs:has(>.proposal-section-controls>[data-proposal-section="compare"]:checked)>.proposal-section-panels>[data-proposal-panel="compare"]{display:block}
</style>
