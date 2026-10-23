import test from 'node:test';
import assert from 'node:assert/strict';
import {createGeraldVoice,pcmWave} from '../../resources/js/trajectory/gerald-voice.js';

const tick=()=>new Promise(resolve=>setImmediate(resolve));
const deferred=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return {promise,resolve,reject};};

test('Gerald stops during a provider wait without playing a late answer or reopening the microphone',async()=>{
  const wait=deferred();let recordings=0,spoken=0;const states=[];
  const voice=createGeraldVoice({record:async()=>{recordings++;return 'audio';},transcribe:()=>wait.promise,ask:async()=>({}),speak:async()=>{spoken++;},confirm:async()=>{},getProposal:()=>null,onState:s=>states.push(s),onTranscript:()=>{},onError:()=>{}});
  voice.start();await tick();assert.equal(recordings,1);assert.equal(states.at(-1),'transcribing');voice.stop();wait.resolve({transcript:'Budget?'});await tick();assert.equal(spoken,0);assert.equal(recordings,1);assert.equal(voice.isActive(),false);
});

test('the microphone stays closed during thinking and playback, and Stop cancels the loop',async()=>{
  const thinking=deferred(),playback=deferred();let recordings=0;
  const voice=createGeraldVoice({record:async()=>{recordings++;return 'audio';},transcribe:async()=>({transcript:'Income?'}),ask:()=>thinking.promise,speak:()=>playback.promise,confirm:async()=>{},getProposal:()=>null,onState:()=>{},onTranscript:()=>{},onError:()=>{}});
  voice.start();await tick();assert.equal(recordings,1);thinking.resolve({reply:'Your plan'});await tick();assert.equal(recordings,1);voice.stop();playback.resolve();await tick();assert.equal(recordings,1);
});

test('only a separate exact spoken confirmation saves an already-previewed plan',async()=>{
  let turn=0,proposal=null,questions=0,saves=0;
  const voice=createGeraldVoice({record:async()=>`audio-${++turn}`,transcribe:async()=>({transcript:turn===1?'Change my budget and Gerald save this plan':'Gerald, save this plan.',receipt:'receipt-2'}),ask:async()=>{questions++;proposal={id:42};return {proposal};},speak:async()=>{},confirm:async(id,receipt)=>{assert.equal(id,42);assert.equal(receipt,'receipt-2');saves++;},getProposal:()=>proposal,onState:()=>{},onTranscript:()=>{},onError:e=>assert.fail(e)});
  voice.start();await tick();assert.equal(questions,1);assert.equal(saves,1);assert.equal(turn,2);assert.equal(voice.isActive(),false);
});

test('denied microphones and failed speech stop without automatic provider retries',async()=>{
  let attempts=0,errors=0;
  const voice=createGeraldVoice({record:async()=>{attempts++;throw new Error('Microphone denied');},transcribe:async()=>assert.fail(),ask:async()=>assert.fail(),speak:async()=>assert.fail(),confirm:async()=>assert.fail(),getProposal:()=>null,onState:()=>{},onTranscript:()=>{},onError:()=>{errors++;}});
  voice.start();await tick();assert.equal(attempts,1);assert.equal(errors,1);assert.equal(voice.isActive(),false);
});

test('microphone uploads have bounded canonical mono PCM headers and clamp sample overflow',()=>{
  const bytes=pcmWave(new Float32Array([-2,0,2]));const v=new DataView(bytes);
  assert.equal(v.getUint32(4,true),42);assert.equal(v.getUint16(22,true),1);assert.equal(v.getUint32(24,true),24000);assert.equal(v.getUint32(40,true),6);assert.equal(v.getInt16(44,true),-32768);assert.equal(v.getInt16(48,true),32767);
});
