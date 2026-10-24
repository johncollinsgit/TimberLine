<style>
#map .map-head h2{font:600 16px/1.4 var(--font-sans,Inter,system-ui,sans-serif);margin:0}
.work-month{border:1px solid var(--line);background:white;border-radius:10px;overflow:hidden}
.month-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 16px;border-bottom:1px solid var(--line)}
.month-toolbar h2{font:600 19px/1.4 var(--font-sans,Inter,system-ui,sans-serif);margin:0}
.month-toolbar .actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.month-toolbar input[type="month"]{max-width:175px;min-height:44px}
.month-scroll{overflow:auto;overscroll-behavior:contain}
.month-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));min-width:700px}
.month-weekday{font-weight:600;font-size:12px;padding:12px;text-align:center;border-bottom:1px solid var(--line);background:#fafbf7}
.month-cell{min-height:145px;border-right:1px solid var(--line);border-bottom:1px solid var(--line);padding:8px;min-width:0}
.month-cell:nth-child(7n){border-right:0}
.month-cell[data-outside="true"]{background:#f5f6f0;color:#6a7566}
.month-cell time{display:inline-flex;min-width:28px;min-height:28px;align-items:center;justify-content:center;font-size:12px}
.month-cell[data-today="true"] time{border-radius:50%;color:white;background:#24523e;font-weight:650}
.month-event{display:block;width:100%;text-align:left;border:1px solid #cad9c7;background:#eaf2e5;color:#193f2d;border-radius:6px;cursor:pointer;padding:8px;margin:6px 0;overflow-wrap:anywhere;font:inherit;font-size:12px;line-height:1.45;min-height:44px}
.month-event strong,.month-event small{display:block}
.month-event small{color:#445f4a;margin-top:3px;font-size:11px}
.month-event[data-complete="true"]{background:#f0f1ed;color:#586457}
.month-event:focus-visible,.month-toolbar button:focus-visible,.campus-work-marker:focus-visible{outline:3px solid #a88135;outline-offset:2px}
.month-unscheduled{padding:14px 16px}.month-unscheduled summary{cursor:pointer;min-height:44px;display:flex;align-items:center;font-weight:600}
.month-unscheduled-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:6px 12px}
.month-help{padding:0 16px 14px;font-size:12px;color:#536451}.month-help summary{min-height:36px;display:flex;align-items:center;cursor:pointer}.month-help p{line-height:1.5}
#map-holder .campus-work-map-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(240px,320px);gap:0}
.campus-work-map-scroll{max-height:650px;overflow:auto;overscroll-behavior:contain;background:#f4f4ec}
.campus-work-map-stage{position:relative;width:100%;min-width:480px}
#map-holder .campus-work-map-stage img{display:block;width:100%;height:auto;margin:0}
.campus-work-markers{position:absolute;inset:0}
.campus-work-marker{position:absolute;transform:translate(-50%,-50%);min-width:44px;min-height:44px;padding:4px;border:2px solid white;border-radius:20px;background:#234d3a;color:white;box-shadow:0 2px 6px #17352270;font-size:11px;font-weight:650;cursor:pointer;z-index:1}
.campus-work-marker:hover,.campus-work-marker[aria-pressed="true"]{z-index:2;background:#9a702e}
.campus-work-map-tickets{padding:16px;border-left:1px solid var(--line);max-height:650px;overflow:auto}
.campus-work-map-tickets h3{font-size:15px;margin:0 0 12px}
.campus-map-source{padding:12px 16px;border-top:1px solid var(--line)}
.resource-month-calendar .month-grid{min-width:700px}.resource-month-calendar .month-cell{min-height:120px}
@media(max-width:800px){#map-holder .campus-work-map-layout{grid-template-columns:1fr}.campus-work-map-tickets{border-left:0;border-top:1px solid var(--line);max-height:350px}.campus-work-map-scroll{max-height:480px}.month-cell{min-height:120px}.month-toolbar{padding:12px}.month-scroll{max-width:100%}}
#res-columns[data-calendar="true"]{display:block}#res-columns[data-calendar="true"] .resource-list{display:block;max-height:none;overflow:visible;padding:0}#res-columns[data-calendar="true"] #res-detail{margin-top:16px;position:static}
</style>
