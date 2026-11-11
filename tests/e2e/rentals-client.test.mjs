import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import ts from 'typescript';

async function client(handler) {
  const source=await readFile(new URL('../../resources/js/rentals/client.ts',import.meta.url),'utf8');
  const values=new Map();
  globalThis.document={getElementById:()=>({dataset:{bootstrap:JSON.stringify({baseUrl:'https://fictional.test/rentals',tenant:'fictional',userId:'123'})}}),querySelector:()=>({content:'fictional-csrf'})};
  globalThis.location={href:'https://fictional.test/rentals',origin:'https://fictional.test',search:''};
  globalThis.history={replaceState:()=>{}};
  globalThis.sessionStorage={getItem:key=>values.get(key)||null,setItem:(key,value)=>values.set(key,value),removeItem:key=>values.delete(key)};
  const property='00000000-0000-4000-8000-000000000001';
  const state={property:{id:property},version:7,documents:[]};
  globalThis.fetch=async(url,options={})=>{
    assert.equal(new URL(url).origin,'https://fictional.test');
    assert.equal(new URL(url).searchParams.get('tenant'),'fictional');
    if(options.method!=='POST'&&new URL(url).pathname==='/rentals/properties')return Response.json({properties:[{id:property}]});
    if(options.method!=='POST'&&!new URL(url).pathname.includes('/commands/'))return Response.json(state);
    return handler(url,options,state);
  };
  const output=ts.transpileModule(source,{compilerOptions:{target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.ESNext}}).outputText;
  const api=await import('data:text/javascript;base64,'+Buffer.from(output+'\n//'+Math.random()).toString('base64'));
  await api.loadProperty();
  return {api,state,values};
}

test('an uncertain save retries the exact immutable original request and blocks another mutation',async()=>{
  const original=globalThis.fetch; const posts=[];
  try {
    const {api,state}=await client(async(_url,options)=>{
      posts.push(JSON.parse(options.body));
      if(posts.length===1)throw Error('lost response');
      return Response.json({...state,version:8});
    });
    await assert.rejects(api.command('recordPayment',{duesId:'fictional-due',amountCents:2345,method:'check',reference:'fictional check'}));
    assert.equal(api.hasPending(),true);
    await assert.rejects(api.command('recordPayment',{amountCents:9999}),/original saved change/);
    await api.retryPending();
    assert.deepEqual(posts[1],posts[0]);
    assert.equal(posts[0].version,7); assert.match(posts[0].requestKey,/^[a-f0-9-]{36}$/);
    assert.equal(api.hasPending(),false);
  }finally{globalThis.fetch=original;}
});

test('a receipt after an acknowledged-lost response resolves without another write',async()=>{
  const original=globalThis.fetch;let writes=0;
  try {
    const {api,state}=await client(async(url,options)=>{
      if(options.method==='POST'){writes++;state.version=8;throw Error('response lost after commit');}
      assert.ok(new URL(url).pathname.includes('/commands/'));
      return Response.json({applied:true,state});
    });
    await assert.rejects(api.command('claimWeek',{weekId:'fictional-week'}));
    await api.loadProperty();
    assert.equal(api.hasPending(),false);assert.equal(writes,1);
  }finally{globalThis.fetch=original;}
});

test('a definitive version conflict reloads state before the next new command',async()=>{
  const original=globalThis.fetch;const posts=[];
  try{
    const {api,state}=await client(async(_url,options)=>{
      posts.push(JSON.parse(options.body));
      if(posts.length===1){state.version=8;return Response.json({message:'Property changed'},{status:409});}
      return Response.json({...state,version:9});
    });
    await assert.rejects(api.command('claimWeek',{weekId:'fictional-week'}),/Property changed/);
    assert.equal(api.hasPending(),false);
    await api.command('stayComment',{weekId:'fictional-week',body:'A reviewed new comment'});
    assert.equal(posts[1].version,8);assert.notEqual(posts[1].requestKey,posts[0].requestKey);
  }finally{globalThis.fetch=original;}
});
