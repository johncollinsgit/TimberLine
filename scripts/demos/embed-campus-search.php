<?php

declare(strict_types=1);

/** Deterministically embed the same first-party assets served by native Teams. */
function embedCampusSearch(string $html): string
{
    $root = dirname(__DIR__, 2);
    $html = preg_replace('/<!-- EVERBRANCH_SEARCH_BEGIN -->.*?<!-- EVERBRANCH_SEARCH_END -->\s*/s', '', $html);
    if (! str_contains($html, 'function campusGlobalSearch()')) {
        // The pinned v3 export predates the persistent shell. Adapt it without touching fixtures.
        $legacyBridge = <<<'JS'
let campusScopeGeneration=0,campusScopePending=false,tourGeneration=0;
function publicPerson(id,person){return person;}
function campusGlobalSearch(){return '<div id="campus-search-slot"></div>';}
function bindCampusGlobalSearch(){globalThis.mountCampusSearch({get data(){return data},set data(value){data=value},get pending(){return campusScopePending},get scope(){return campusScopeGeneration},get navigation(){return tourGeneration},api,publicPerson,esc,go,toast,showModal,modal,nav,openCampusRecord});}
async function openCampusRecord(kind,id,persona,current){const fresh=await api('state');if(!current()||campusScopePending||fresh.persona!==persona)return;const rows=kind==='task'?fresh.tasks:kind==='project'?fresh.projects:kind==='request'?fresh.requests:fresh.operations?.jobs;const row=rows?.find(item=>item.id===id);if(!row)return;data=fresh;if(kind==='task')await openTask(id);else if(kind==='project'){project=id;go('board');}else if(kind==='request'){go('intake');await reviewRequest(id);}else{go('operations');if(globalThis.openPublicOperationJob)globalThis.openPublicOperationJob(id);else showModal('<h2>'+esc(row.title)+'</h2><p>'+esc(row.description||row.location||'')+'</p>');}}
async function switchPersona(persona){campusScopeGeneration++;campusScopePending=true;globalThis.retireCampusSearch?.();try{return await switchPersonaLegacy(persona);}finally{campusScopePending=false;}}
JS;
        $html = str_replace('async function switchPersona(', 'async function switchPersonaLegacy(', $html);
        $html = str_replace('function bind(){', $legacyBridge."\nfunction bind(){bindCampusGlobalSearch();", $html);
        $html = str_replace('</div><div class="perspective">', '</div>${campusGlobalSearch()}<div class="perspective">', $html);
    }
    $css = file_get_contents($root.'/public/css/everbranch-search.css');
    $css .= "\n.topbar.eb-workspace-header{grid-template-columns:minmax(0,1fr) minmax(240px,560px) minmax(0,1fr);height:auto;min-height:80px}.topbar.eb-workspace-header>.breadcrumb{flex:none;min-width:0}.topbar.eb-workspace-header>.perspective{justify-self:end;flex:none;min-width:0}.topbar.eb-workspace-header>#campus-search-slot{grid-column:2;min-width:0;width:100%;}.topbar .perspective select{max-width:100%;min-width:0}@media(max-width:1150px){.topbar.eb-workspace-header{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}.topbar.eb-workspace-header>#campus-search-slot{grid-column:1/-1;grid-row:2;justify-self:center;max-width:560px}.topbar.eb-workspace-header>.perspective{grid-column:2;grid-row:1}.topbar.eb-workspace-header>.breadcrumb{grid-column:1;grid-row:1}}@media(max-width:650px){.topbar.eb-workspace-header>#campus-search-slot{max-width:none}.topbar.eb-workspace-header>.perspective label,.topbar.eb-workspace-header>.perspective .avatar{display:none}}";
    $css .= "\n@media(min-width:1151px) and (max-width:1400px){.topbar.eb-workspace-header{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}.topbar.eb-workspace-header>#campus-search-slot{grid-column:1/-1;grid-row:2;justify-self:center;max-width:560px}.topbar.eb-workspace-header>.perspective{grid-column:2;grid-row:1}.topbar.eb-workspace-header>.breadcrumb{grid-column:1;grid-row:1}}";
    $catalog = json_decode(file_get_contents($root.'/resources/data/team-search-commands.json'), true, flags: JSON_THROW_ON_ERROR);
    $scripts = ['globalThis.EverbranchCommandDefinitions='.json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';'.file_get_contents($root.'/public/js/everbranch-search.js'), file_get_contents($root.'/resources/js/search/campus-search.js')];
    $block = "<!-- EVERBRANCH_SEARCH_BEGIN -->\n<style>".$css."</style>\n";
    foreach ($scripts as $script) {
        $block .= '<script>'.str_ireplace('</script', '<\\/script', $script)."</script>\n";
    }
    $block .= "<!-- EVERBRANCH_SEARCH_END -->\n";
    $html = str_replace('</head>', $block.'</head>', $html);
    if (! str_contains($html, 'function renderWorkspace(){')) {
        $html = str_replace('function render(){globalThis.campusMapDispose', 'function renderWorkspace(){globalThis.campusMapDispose', $html);
        $html = str_replace('function bind(){', "function render(){const restore=globalThis.detachCampusSearch?.();renderWorkspace();restore?.();}\nfunction bind(){", $html);
    }
    $html = str_replace('<header class="topbar">', '<header class="topbar eb-workspace-header">', $html);
    if (! str_contains($html, "if(globalThis.EverbranchSearch)return '<div id=\"campus-search-slot\"")) {
        $html = str_replace('function campusGlobalSearch() {', "function campusGlobalSearch() {\n  if(globalThis.EverbranchSearch)return '<div id=\"campus-search-slot\"></div>';", $html);
        $html = str_replace('function bindCampusGlobalSearch() {', "function bindCampusGlobalSearch() {\n  if(globalThis.mountCampusSearch){globalThis.mountCampusSearch();return;}", $html);
        $html = str_replace('campusScopePending=true;closeCampusAssistant(false);', 'campusScopePending=true;globalThis.retireCampusSearch?.();closeCampusAssistant(false);', $html);
    }

    $environment = 'globalThis.mountCampusSearch({get data(){return data},set data(value){data=value},get pending(){return campusScopePending},get scope(){return campusScopeGeneration},get navigation(){return tourGeneration},api,publicPerson,esc,go,toast,showModal,modal,nav,openCampusRecord})';
    $html = str_replace('globalThis.mountCampusSearch();return;', $environment.';return;', $html);

    require_once __DIR__.'/embed-campus-media.php';

    return embedCampusMedia(str_replace("\r\n", "\n", $html));
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $path = $argv[1] ?? dirname(__DIR__, 2).'/resources/demos/lees-mcrae-campus.html';
    $html = file_get_contents($path);
    if ($html === false) {
        throw new RuntimeException('Campus source not found.');
    }
    file_put_contents($path, embedCampusSearch($html));
    echo "Embedded shared Everbranch search.\n";
}
