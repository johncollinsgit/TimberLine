<style>
.enterprise-navigation-toggle,.enterprise-mobile-actions,.enterprise-navigation-close{display:none}
.enterprise-nav-links{display:contents}
@media(max-width:800px){
  .enterprise-mobile-nav-ready .sidebar,.enterprise-mobile-nav-ready .ew-sidebar{display:flex;flex-direction:row;align-items:center;justify-content:space-between;gap:12px;padding:14px}
  .enterprise-mobile-nav-ready .sidebar .enterprise-brand{flex:1;min-width:0;max-width:244px;padding:0}
  .enterprise-mobile-nav-ready .ew-brand{flex:1;min-width:0;max-width:244px;margin:0}
  .enterprise-mobile-nav-ready .enterprise-navigation-toggle{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-width:44px;min-height:46px;padding:10px 12px;border:1px solid #e0e7da;border-radius:7px;background:#fff;color:#204c39;font:600 14px/1.4 system-ui;cursor:pointer;flex:none}
  .enterprise-mobile-nav-ready .enterprise-nav-links[popover]{display:none}
  .enterprise-mobile-nav-ready .enterprise-nav-links:popover-open{display:flex;flex-direction:column;gap:8px;position:fixed;inset:12px 12px auto;margin:0;width:calc(100vw - 24px);max-height:calc(100dvh - 24px);overflow:auto;overscroll-behavior:contain;padding:18px;border:1px solid #456a55;border-radius:12px;background:#204d3a;color:#fff;box-shadow:0 16px 55px #102d3155}
  .enterprise-nav-links::backdrop{background:#142c234d}
  .enterprise-mobile-nav-ready .enterprise-navigation-close{display:inline-flex;position:sticky;top:0;z-index:1;align-self:flex-end;align-items:center;justify-content:center;min-height:46px;padding:10px 14px;border:1px solid #d2e0ce;border-radius:6px;background:#fff;color:#204c39;font:600 14px/1.4 system-ui;cursor:pointer}
  .enterprise-mobile-nav-ready .enterprise-nav-links nav,.enterprise-mobile-nav-ready .enterprise-nav-links #areas,.enterprise-mobile-nav-ready .enterprise-nav-links .ew-library{display:flex;flex-direction:column;gap:6px;max-height:none;overflow:visible;width:100%;padding:0;margin:0}
  .enterprise-mobile-nav-ready .enterprise-nav-links .nav-item,.enterprise-mobile-nav-ready .enterprise-nav-links .sidebar-project,.enterprise-mobile-nav-ready .enterprise-nav-links nav button,.enterprise-mobile-nav-ready .enterprise-nav-links .ew-library button,.enterprise-mobile-nav-ready .enterprise-nav-links .ew-back{width:100%;max-width:100%;min-height:46px;padding:11px 12px;text-align:left;font-size:16px;line-height:1.4;white-space:normal;overflow-wrap:anywhere;flex:none}
  .enterprise-mobile-nav-ready .enterprise-nav-links .sidebar-bottom{display:block;margin-top:14px;font-size:13px;line-height:1.6}
  .enterprise-mobile-nav-ready .enterprise-nav-links .workspace-label,.enterprise-mobile-nav-ready .enterprise-nav-links .ew-eyebrow{display:block;margin:14px 0 5px;color:#d3dfd0;font-size:11px;letter-spacing:.12em}
  .enterprise-mobile-nav-ready .enterprise-mobile-actions{display:grid;gap:8px;margin-top:12px;padding-top:14px;border-top:1px solid #ffffff40}
  .enterprise-mobile-actions button,.enterprise-mobile-actions a,.enterprise-mobile-actions summary{display:flex;align-items:center;min-height:46px;padding:11px 12px;border:1px solid #ffffff55;border-radius:6px;background:transparent;color:#fff;text-align:left;text-decoration:none;font:600 15px/1.4 system-ui;cursor:pointer}
  .enterprise-mobile-actions details p{font:13px/1.6 system-ui;color:#e1eadb;margin:8px 12px;overflow-wrap:anywhere}
  .enterprise-mobile-nav-ready .ert-launch,.enterprise-mobile-nav-ready .campus-review-tools,.enterprise-mobile-nav-ready .campus-review-standalone{display:none!important}
  .enterprise-navigation-toggle:focus-visible,.enterprise-nav-links :focus-visible{outline:3px solid #d6b96d;outline-offset:3px}
}
@media print{.enterprise-navigation-toggle,.enterprise-mobile-actions,.enterprise-navigation-close{display:none!important}}
</style>
<script>{!! file_get_contents(resource_path('js/enterprise/mobile-navigation.js')) !!}</script>
