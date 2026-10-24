{{-- One native modal; presentation changes never replace the active composer or file input. --}}
<style>
#work-drawer[data-placement="center"]{inset:0;margin:auto;width:min(1280px,calc(100vw - 48px));height:min(1000px,calc(100dvh - 48px));max-width:none;max-height:calc(100dvh - 48px);border:1px solid var(--line);border-radius:12px;box-shadow:0 24px 80px #17352240}
#work-drawer[data-placement="side"]{inset:0 0 0 auto;margin:0;width:min(720px,100vw);height:100dvh;max-width:100vw;max-height:100dvh;border:0;border-left:1px solid var(--line);border-radius:0}
.work-detail-tools,.work-detail-placement{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.work-detail-placement{border:1px solid var(--line);padding:3px;border-radius:8px;gap:2px}
.work-detail-placement .button{border:0;min-height:38px;padding:8px 12px;background:transparent}
.work-detail-placement .button[aria-pressed="true"]{background:#e4eee6;color:#183c2f;font-weight:650}
#work-drawer button:focus-visible,#work-drawer a:focus-visible,#work-drawer textarea:focus-visible,#work-drawer input:focus-visible,#work-drawer summary:focus-visible{outline:3px solid #b28a3e;outline-offset:3px}
#work-drawer .work-drawer-head{position:relative;z-index:2;padding-top:max(14px,env(safe-area-inset-top));min-height:76px}
#work-drawer .work-detail-summary{min-width:0}
#work-drawer .discussion{min-width:0}
#work-drawer .discussion-feed{max-height:360px}
#work-drawer .discussion-composer{position:relative;padding:16px 0 max(12px,env(safe-area-inset-bottom));margin-top:12px}
#work-drawer .discussion-composer textarea{min-height:130px}
#work-drawer .discussion-upload-tools{display:flex;gap:10px;flex-wrap:wrap;padding:16px;border:1px dashed #9eae9d;border-radius:8px;background:#f6f8f2}
#work-drawer .discussion-upload-tools .button{position:relative;display:inline-flex;align-items:center;min-height:44px;cursor:pointer}
#work-drawer .discussion-upload-tools input[type="file"]{position:absolute;width:1px;height:1px;opacity:0;inset:50%;padding:0;overflow:hidden}
#work-drawer .discussion-upload-tools .button:focus-within{outline:3px solid #b28a3e;outline-offset:3px}
#work-drawer .discussion-access-help{font-size:12px;color:#536451;margin:8px 0}
#work-drawer .discussion-access-help summary{cursor:pointer;min-height:36px;display:flex;align-items:center}
#work-drawer .discussion-access-help p{line-height:1.6;margin:0 0 10px}
#work-drawer .discussion-files-heading{margin-top:22px}
@media(min-width:1001px){
 #work-drawer[data-placement="center"] #detail:has(>.discussion){display:grid;grid-template-columns:minmax(0,1.15fr) minmax(360px,.85fr);gap:0 30px;align-content:start}
 #work-drawer[data-placement="center"] #detail>.work-detail-summary,#work-drawer[data-placement="center"] #detail>.project-overview{grid-column:1;grid-row:1}
 #work-drawer[data-placement="center"] #detail>.discussion{grid-column:2;grid-row:1 / span 20;margin:0;border-top:0;border-left:1px solid var(--line);padding:0 0 0 28px}
 #work-drawer[data-placement="center"] #detail>:not(.discussion):not(.work-detail-summary):not(.project-overview){grid-column:1}
}
@media(max-width:650px){
 #work-drawer[data-placement="center"],#work-drawer[data-placement="side"]{inset:0;margin:0;width:100vw;height:100dvh;max-width:100vw;max-height:100dvh;border:0;border-radius:0}
 #work-drawer .work-drawer-head{padding-left:max(12px,env(safe-area-inset-left));padding-right:max(12px,env(safe-area-inset-right));gap:8px;align-items:flex-start}
 #work-drawer .work-detail-tools{justify-content:flex-end}
 #work-drawer .work-detail-placement .button{padding:8px;min-height:44px}
 #work-drawer #detail{padding-bottom:max(20px,env(safe-area-inset-bottom));overflow-x:hidden}
 #work-drawer .discussion-upload-tools{padding:12px}
}
</style>
<dialog class="work-drawer" id="work-drawer" data-placement="center" aria-labelledby="work-detail-title">
    <header class="work-drawer-head">
        <h2 id="work-detail-title">Work details</h2>
        <div class="work-detail-tools">
            <div class="work-detail-placement" role="group" aria-label="Detail position">
                <button class="button" type="button" data-detail-placement="center" aria-pressed="true">Center</button>
                <button class="button" type="button" data-detail-placement="side" aria-pressed="false">Side</button>
            </div>
            <button class="button" id="close-work-detail" type="button" autofocus>Close</button>
        </div>
    </header>
    <div id="work-drawer-feedback"></div>
    <div id="detail" aria-label="Selected work details"></div>
</dialog>

@include('enterprise.attachment-gallery')

@if(array_key_exists('reviewerAttachments', $enterprisePresentation))
<template id="shared-work-files-template">@include('enterprise.partials.reviewer-workspace-files')</template>
@endif
