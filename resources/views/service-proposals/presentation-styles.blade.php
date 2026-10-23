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
</style>
