import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';

const source=await readFile(new URL('../../resources/views/enterprise/resources-script.blade.php',import.meta.url),'utf8');
const actualScript=source.split('\n').slice(2).join('\n');

function fixture(){
    const elements=new Map(),listeners=new Map(),pending=[];
    function element(id){
        if(!elements.has(id))elements.set(id,{
            id,hidden:false,open:false,dataset:{},textContent:'',value:'',children:[],
            classList:{toggle(){}},setAttribute(){},replaceChildren(...nodes){this.children=nodes;},
            append(...nodes){for(const node of nodes)node.parentElement=this;this.children.push(...nodes);},
            prepend(...nodes){for(const node of nodes)node.parentElement=this;this.children.unshift(...nodes);},
            insertBefore(node){node.parentElement=this;this.children.push(node);},
            querySelector(selector){return element(this.id+':'+selector);},querySelectorAll(){return [];},
            addEventListener(){},close(){this.open=false;},focus(){},scrollIntoView(){},
        });
        return elements.get(id);
    }
    const context={URL,URLSearchParams,Intl,Date,Map,Number,String,Object,Array,Boolean,JSON,Promise,
        transport:{mode:'private',href:value=>value},workspace:'fixture-tenant',viewer:'fixture-user',nextBase:'/enterprise',tenantSlug:'fixture',
        section:'buildings',area:'allowed',selected:null,data:{features:{resources:true},requests:[],projects:[],members:[]},
        selectionMemory:new Map(),rememberUrl(){},message(){},matchMedia:()=>({matches:false}),
        esc:value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char])),
        $:element,location:{href:'https://fixture.invalid/enterprise-campus/teams',origin:'https://fixture.invalid'},
        document:{addEventListener:(name,fn)=>listeners.set(name,fn),createElement:tag=>element('created-'+tag)},
        sessionStorage:{getItem:()=>null,setItem(){}},crypto:{randomUUID:()=> '00000000-0000-4000-8000-000000000000'},
        api(url){
            if(url.includes('/history'))return Promise.resolve({items:[]});
            return new Promise((resolve,reject)=>pending.push({url,resolve,reject}));
        },
    };
    vm.createContext(context);
    vm.runInContext('const resourceCatalog={entries:[]};\n'+actualScript+'\nglobalThis.actual={enter:resourceEnter,load:resourceLoad};',context);
    const map=element('res-plan'),label=element('res-plan-selection');
    const prior=()=>{map.open=true;map.hidden=false;label.textContent='Synthetic prior private building';};
    const cleared=()=>{assert.equal(map.hidden,true);assert.equal(map.open,false);assert.equal(label.textContent,'Choose a building to keep its map reference here.');};
    return {context,elements,element,listeners,pending,map,label,prior,cleared,enter:()=>context.actual.enter(),reset:()=>listeners.get('enterprise:reset')()};
}
const settled=()=>new Promise(resolve=>setImmediate(resolve));
const locationRow={id:'new-location',kind:'location',area_id:'allowed',title:'Synthetic current building',status:'active',version:1,data:{level:'facility'}};

test('detached source map clears immediately on reset and cannot reappear in a disabled workspace',()=>{
    const f=fixture();f.enter();assert.equal(f.map.parentElement,f.element('resource-workspace'));
    f.prior();f.reset();f.cleared();assert.equal(f.element('resource-workspace').hidden,true);
    f.context.data.features.resources=false;f.enter();assert.equal(f.element('resource-workspace').hidden,false);f.cleared();
});

test('direct feature loss, missing workspace and non-resource navigation clear the map before early returns',()=>{
    for(const state of [{resources:false},{area:null},{section:'home'},{section:'assets'}]){
        const f=fixture();f.prior();
        if('resources'in state)f.context.data.features.resources=state.resources;
        if('area'in state)f.context.area=state.area;
        if('section'in state)f.context.section=state.section;
        f.enter();f.cleared();
    }
});

test('map stays cleared while current permissions are pending and shows only the fresh authorized building',async()=>{
    const f=fixture();f.context.selected=locationRow.id;f.prior();f.enter();f.cleared();assert.equal(f.pending.length,1);
    f.pending[0].resolve({items:[locationRow],can_coordinate:false});await settled();
    assert.equal(f.map.hidden,false);assert.equal(f.map.open,false);assert.match(f.label.textContent,/Synthetic current building/);assert.doesNotMatch(f.label.textContent,/prior private/);
});

test('failed resource read keeps the detached map hidden, collapsed and free of prior private context',async()=>{
    const f=fixture();f.prior();f.enter();f.cleared();
    f.pending[0].reject(Object.assign(new Error('Current access denied'),{status:403}));await settled();f.cleared();
});

test('a former scope response cannot reveal its map after reset and feature loss',async()=>{
    const f=fixture();f.context.selected=locationRow.id;f.enter();f.prior();f.reset();f.context.area='other';f.context.data.features.resources=false;f.enter();
    f.pending[0].resolve({items:[locationRow],can_coordinate:false});await settled();f.cleared();
});
