import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';

const source=await readFile(new URL('../../resources/views/enterprise/voice-script.blade.php',import.meta.url),'utf8');
const start=source.indexOf('  function renderVoice(){');
const end=source.indexOf("  window.addEventListener('everbranch:voice-jobs-ready'",start);
assert.ok(start>=0&&end>start,'Exercise the actual private shell adapter');
function fixture({mode='private',generation=true,drafts=true}={}){
    const calls=[],effects=[],mounts=[],nodes=new Map();let context;
    const mount=(host,options)=>{mounts.push(options);return {dispose(){effects.push('dispose');}};};
    const module={mount};
    context=vm.createContext({
        section:'ask',area:'area-old',workspace:'tenant-old',viewer:'viewer-old',tenantSlug:'old',
        data:{viewer_id:'viewer-old',features:{ask:true,voice_jobs:true,voice_generation:generation,voice_drafts:drafts}},
        voiceScope:null,voiceSession:null,askBusy:false,nextBase:'/enterprise',
        transport:{mode,request:(url,body,options)=>{calls.push({url,body,options});return Promise.resolve({});}},
        window:{EverbranchVoiceJobs:module},EverbranchVoiceJobs:module,
        $:id=>{if(!nodes.has(id))nodes.set(id,{replaceChildren(){},disabled:false,hidden:false,value:''});return nodes.get(id);},
        resetVoice(){context.voiceSession?.dispose();context.voiceSession=null;context.voiceScope=null;},
        nextQuery:()=>'?tenant='+context.tenantSlug,
        openVoiceJob(){},voiceReply:()=>effects.push('reply'),chatMessage:()=>effects.push('transcript'),
        clearVoiceEvidence:()=>effects.push('clear'),message:()=>effects.push('error'),
    });
    vm.runInContext(source.slice(start,end),context);
    context.renderVoice();return {context,calls,effects,mounts};
}
test('generation and draft callbacks require private feature configuration',()=>{
    const enabled=fixture();assert.equal(typeof enabled.mounts[0].generate,'function');assert.equal(typeof enabled.mounts[0].cancelSession,'function');assert.equal(typeof enabled.mounts[0].preparePreview,'function');
    for(const settings of [{mode:'sample'},{generation:false}]){const value=fixture(settings).mounts[0];assert.equal(value.generate,undefined);assert.equal(value.cancelSession,undefined);assert.equal(value.preparePreview,undefined);}
    assert.equal(fixture({drafts:false}).mounts[0].preparePreview,undefined);
});
test('old session cancellation and transport remain bound to the original tenant endpoint',async()=>{
    const {context,mounts,calls}=fixture(),old=mounts[0];
    context.tenantSlug='next';context.area='area-next';context.workspace='tenant-next';context.nextBase='/other';context.renderVoice();
    await old.cancelSession({session_id:'old-session',area_id:'area-old'});
    await old.lookup({area_id:'area-old',question:'Show job'});
    await old.generate({area_id:'area-old',session_id:'old-session'});
    await old.preparePreview({area_id:'area-old',to:'test@example.invalid'});
    assert.deepEqual(calls.map(row=>row.url),['/enterprise/voice/sessions/old-session/cancel?tenant=old','/enterprise/voice/jobs?tenant=old','/enterprise/voice/turns?tenant=old','/enterprise/voice/previews?tenant=old']);
    assert.deepEqual(JSON.parse(JSON.stringify(calls[0].body)),{area_id:'area-old'});
});
test('retired adapter callbacks cannot clear or append the next workspace conversation',()=>{
    const {context,mounts,effects}=fixture(),old=mounts[0];
    context.area='area-next';context.renderVoice();const before=effects.slice();
    old.onReply({});old.onTranscript('Old private text');old.onError('Old failed cancellation');
    assert.deepEqual(effects,before);
    mounts[1].onTranscript('Current question');mounts[1].onReply({});assert.deepEqual(effects.slice(-2),['transcript','reply']);
});
test('changing generation readiness retires the previously mounted session',()=>{
    const {context,mounts,effects}=fixture();context.data.features.voice_generation=false;context.renderVoice();
    assert.equal(mounts.length,2);assert.equal(mounts[1].generate,undefined);assert.equal(effects.filter(value=>value==='dispose').length,1);
});
