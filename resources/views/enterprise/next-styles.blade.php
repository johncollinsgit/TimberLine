#work{grid-template-columns:minmax(230px,.75fr) minmax(430px,1.6fr)}#detail{padding:28px;min-height:540px}#detail h2{font:32px Georgia,serif;margin:12px 0}#detail .work-detail{font-size:15px;line-height:1.7}.discussion{margin-top:26px;border-top:1px solid var(--line);padding-top:18px}.discussion-feed{max-height:360px;overflow:auto;overscroll-behavior:contain}.discussion-note{white-space:pre-wrap;overflow-wrap:anywhere;background:#f3f3eb;border-radius:8px;padding:14px;margin:10px 0}.discussion-composer{position:sticky;bottom:0;background:var(--paper,#fafaf5);padding:16px 0 max(12px,env(safe-area-inset-bottom));border-top:1px solid var(--line)}.discussion-composer textarea{min-height:100px;width:100%;resize:vertical}.file-picker{min-height:44px;max-width:100%;width:100%}.file-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:12px 0}.file-row span{overflow-wrap:anywhere}.ask-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(260px,1fr);gap:22px}.chat-log{max-height:55vh;overflow:auto;overscroll-behavior:contain;display:grid;gap:14px;padding:4px}.chat-turn{white-space:pre-wrap;overflow-wrap:anywhere;padding:16px;border-radius:12px;background:#f0f2e9}.chat-turn.question{background:#e4ecdf}.chat-turn a{display:block;min-height:44px;padding:10px 0}.chat-turn ul{padding-left:18px}.ask-composer{position:sticky;bottom:0;background:#fafaf5;padding-top:14px}.ask-composer textarea{min-height:84px}.planning-grid{display:grid;grid-template-columns:1fr 1fr;gap:22px}#planning-list{min-width:0}.planning-record{padding:16px;border-bottom:1px solid var(--line);overflow-wrap:anywhere}.planning-record:target{background:#eaf1e3}#email-preview{white-space:pre-wrap;overflow-wrap:anywhere;border:1px solid var(--line);padding:15px}input,textarea,select{max-width:100%;box-sizing:border-box}.button,button.nav-item{min-height:44px}.table-wrap table{table-layout:fixed;width:100%}.table-wrap td,.table-wrap th{overflow-wrap:anywhere}.stack textarea,.stack input,.stack select{font:inherit;min-height:44px}#detail a{overflow-wrap:anywhere}.next-panel h2{font:30px Georgia,serif}.next-panel{min-width:0}.file-notice{font-size:12px;line-height:1.6}#upload-status{white-space:pre-wrap;overflow-wrap:anywhere}.dialog-body{max-height:70dvh;overflow:auto}@media(max-width:1150px){#work{display:block}#detail{margin-top:22px}#work>.toolbar{margin-bottom:20px}.ask-grid{grid-template-columns:1fr}.planning-grid{grid-template-columns:1fr}}@media(max-width:650px){#detail{padding:18px;min-height:0}#detail h2{font-size:28px}.discussion-composer,.ask-composer{bottom:0}.discussion-feed{max-height:35dvh}.chat-log{max-height:45dvh}.stack textarea,.stack input,.stack select{font-size:16px}.actions{flex-wrap:wrap}.content{padding-bottom:max(24px,env(safe-area-inset-bottom))}.topbar{overflow-wrap:anywhere}.chat-turn{padding:12px}}
.discussion-composer{position:relative;bottom:auto}.discussion-feed{max-height:min(360px,calc(var(--visible-height,100dvh) * .35))}

/* Keep the chat composer in its panel when voice details make the adjacent column taller. */
.ask-grid{align-items:start}.ask-composer{position:relative;bottom:auto}

/* A growing workspace list must remain reachable inside the desktop sticky rail. */
.sidebar{align-self:start;min-height:0;overflow-y:auto;overscroll-behavior-y:contain}
@media(max-width:1000px){.sidebar{overflow-y:visible;overscroll-behavior-y:auto}}

#ask-voice-controls>section{width:100%;min-width:0}#ask-voice-controls pre{overflow-wrap:anywhere;font:14px/1.7 inherit}#ask-voice-controls input,#ask-voice-controls button{min-height:44px}#ask-voice-controls [aria-label="Exact draft recipient"]{display:block;width:100%;margin:12px 0}
@media(max-width:650px){#ask-voice-controls input{font-size:16px}}

#read-only-assistant{margin-bottom:22px}#read-only-answer a{display:inline-block;min-height:44px;padding:10px 0;overflow-wrap:anywhere}#read-only-answer{overflow-wrap:anywhere}#read-only-answer summary{min-height:44px;cursor:pointer}#read-only-form{max-width:650px}
/* Use the approved Enterprise system-palette disclosure at WebKit's usable target size. */
#read-only-form select:not([multiple]),#conversation-controls select:not([multiple]){
  -webkit-appearance:none;appearance:none;min-height:44px;height:44px;color-scheme:light;
  padding:10px 36px 10px 11px;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 8' style='color-scheme:light%20dark'%3E%3Cpath d='M1 1.5 6 6.5 11 1.5' fill='none' stroke='CanvasText' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 12px center;background-size:12px 8px;
}
@media(forced-colors:active){
  #read-only-form select:not([multiple]),#conversation-controls select:not([multiple]){color-scheme:light dark;background-color:Canvas;color:CanvasText;border-color:ButtonText;background-image:none}
  #read-only-form select:disabled:not([multiple]),#conversation-controls select:disabled:not([multiple]){color:GrayText;border-color:GrayText}
  /* Paint with the document's actual system palette; an external SVG can resolve CanvasText differently. */
  #read-only-form label:has(>select:not([multiple])),#conversation-controls label:has(>select:not([multiple])){position:relative;color-scheme:light dark}
  #read-only-form label:has(>select:not([multiple]))::after,#conversation-controls label:has(>select:not([multiple]))::after{content:"";position:absolute;right:16px;bottom:18px;width:8px;height:8px;border-right:2px solid CanvasText;border-bottom:2px solid CanvasText;transform:rotate(45deg);pointer-events:none}
  #read-only-form label:has(>select:disabled:not([multiple]))::after,#conversation-controls label:has(>select:disabled:not([multiple]))::after{border-color:GrayText}
}

/* The text conversation shares Ask's existing log/composer and never starts audio. */
.ask-grid:has(#generated-ask-panel[data-conversation="true"]){grid-template-columns:minmax(0,1fr)}
#conversation-controls{margin-bottom:18px}#conversation-controls h3{font:24px Georgia,serif;margin:0}
#conversation-controls button,#conversation-controls summary{min-height:44px}#conversation-controls summary{cursor:pointer;padding:10px 0}
#conversation-controls select{min-height:44px}#conversation-controls .field-pair>*{min-width:0}
#generated-ask-panel[data-conversation="true"] .chat-log:empty{display:none}
#generated-ask-panel[data-conversation="true"] .ask-composer{margin-top:16px}
#generated-ask-panel[data-conversation="true"] textarea[readonly]{background:#f3f3ed}
#conversation-clear-confirm{border:1px solid var(--line);border-radius:8px;padding:14px}
.conversation-answer a{overflow-wrap:anywhere;min-height:44px;display:inline-flex;align-items:center}
@media(max-width:650px){#conversation-controls .field-pair{display:grid;grid-template-columns:1fr}#conversation-controls select{font-size:16px}}

#saved-assistants{border:1px solid var(--line);border-radius:8px;padding:16px}#saved-assistants h4{font:20px Georgia,serif;margin:0}
#saved-assistants fieldset{border:1px solid var(--line);padding:12px}#saved-assistants fieldset label,#saved-assistants .saved-assistant-confirm{display:flex;align-items:center;gap:10px;min-height:44px}
#saved-assistants input[type=checkbox]{min-height:24px;height:24px;width:24px;flex:0 0 24px}#saved-assistant-delete-confirm{border-top:1px solid var(--line);padding-top:14px}

#private-memory{border:1px solid var(--line);border-radius:8px;padding:16px}#private-memory h4{font:20px Georgia,serif;margin:0}#private-memory h5{font:17px Georgia,serif;margin:0}
#private-memory .memory-check{display:flex;align-items:center;gap:10px;min-height:44px}#private-memory input[type=checkbox]{min-height:24px;height:24px;width:24px;flex:0 0 24px}
#memory-form,#memory-delete-confirm{border-top:1px solid var(--line);padding-top:14px;margin-top:14px}#memory-list a{min-height:44px;display:inline-flex;align-items:center;overflow-wrap:anywhere}
