import {test} from 'node:test';
import assert from 'node:assert/strict';
import {createReviewerVoice} from '../../resources/js/enterprise/reviewer/live-ai-voice.js';
const settle=()=>new Promise(resolve=>setImmediate(resolve));
function fixture({available=true, delayed=false}={}) {
 const captures=[],speech=[],states=[],errors=[],prepared=[];let state={phase:'idle',question:'',pending:null}, voice;
 const emit=value=>{state={...state,...value};voice.observe(state);};
 class Recognition {constructor(){captures.push(this);}start(){this.onstart();}abort(){this.aborted=true;this.onend?.();}
  result(text){const result=[{transcript:text}];result.isFinal=true;this.onresult({results:[result]});}}
 class Utterance {constructor(text){this.text=text;}}
 const client={read:()=>state,prepare:async question=>{prepared.push(question);emit({phase:'preparing',question});if(!delayed)emit({phase:'preview',preview:{id:'receipt1'}});},invalidate(){emit({phase:'idle',pending:null,result:null});}};
 voice=createReviewerVoice({client,Recognition:available?Recognition:null,Utterance,synthesis:{cancel(){},speak(value){speech.push(value);}},onState:value=>states.push(value),onTranscript(){},onError:value=>errors.push(value)});
 return {voice,captures,speech,states,errors,prepared,emit,async ask(text){voice.start();captures.at(-1).result(text);const done=captures.at(-1).onend();await settle();return {done};}};
}
test('recognition only prepares the existing sharing preview; explicit accepted text answer is the only speech input',async()=>{
 const f=fixture(),{done}=await f.ask('Who owns that job?');
 assert.deepEqual(f.prepared,['Who owns that job?']);assert.equal(f.speech.length,0);assert.equal(f.captures.length,1);
 f.emit({phase:'sending',pending:{preview_id:'receipt1'}});assert.equal(f.speech.length,0);
 f.emit({phase:'accepted',pending:null,result:{preview_id:'receipt1',answer:'The scoped text answer.'}});await done;
 assert.equal(f.speech[0].text,'The scoped text answer.');assert.equal(f.captures.length,1);
 f.voice.stop();f.speech[0].onend();await settle();assert.equal(f.captures.length,1);f.voice.destroy();
});
test('Stop before sharing retires late previews; Stop during paid wait keeps receipt and suppresses late playback',async()=>{
 for(const phase of ['preparing','preview','sending']){
  const f=fixture({delayed:phase==='preparing'}),{done}=await f.ask('Which building?');
  if(phase==='sending')f.emit({phase,pending:{preview_id:'receipt1'}});
  f.voice.stop();f.emit({phase:'accepted',pending:phase==='sending'?{preview_id:'receipt1'}:null,result:{preview_id:'receipt1',answer:'Late response.'}});
  await done;assert.equal(f.speech.length,0);assert.equal(f.captures.length,1);assert.equal(f.voice.isActive(),false);f.voice.destroy();
 }
});
test('Interrupt stops playback before a new microphone turn; each follow-up still requires its own preview',async()=>{
 const f=fixture(),{done}=await f.ask('Which jobs?');f.emit({phase:'accepted',result:{preview_id:'receipt1',answer:'First answer.'}});await done;
 assert.equal(f.voice.interrupt(),true);assert.equal(f.captures.length,2);
 f.speech[0].onend();assert.equal(f.captures.length,2);
 f.captures[1].result('Who owns those?');const next=f.captures[1].onend();await settle();
 assert.deepEqual(f.prepared,['Which jobs?','Who owns those?']);assert.equal(f.speech.length,1);f.voice.stop();await next;f.voice.destroy();
});
test('unsupported speech, denied microphone, uncertain outcomes and changed questions retain text fallback without retry',async()=>{
 const unsupported=fixture({available:false});unsupported.voice.start();assert.match(unsupported.errors[0],/unavailable/);unsupported.voice.destroy();
 const denied=fixture();denied.voice.start();denied.captures[0].onerror({error:'not-allowed'});assert.match(denied.errors[0],/permission was denied/);assert.equal(denied.prepared.length,0);denied.voice.destroy();
 for(const value of [{phase:'uncertain',error:'Check saved status',pending:{preview_id:'receipt1'}},{phase:'idle',question:'Edited typed draft'}]){
  const f=fixture(),{done}=await f.ask('What is next?');f.emit(value);await done;
  assert.equal(f.speech.length,0);assert.equal(f.voice.isActive(),false);assert.equal(f.prepared.length,1);assert.equal(f.errors.length,1);f.voice.destroy();
 }
});
