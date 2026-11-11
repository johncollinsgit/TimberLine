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

test('a failed property switch leaves subsequent changes bound to the displayed property',async()=>{
  const original=globalThis.fetch;const posts=[];
  const displayed='00000000-0000-4000-8000-000000000001';
  const unavailable='00000000-0000-4000-8000-000000000002';
  try{
    const {api,state}=await client(async(url,options)=>{
      posts.push({path:new URL(url).pathname,input:JSON.parse(options.body)});
      return Response.json({...state,version:8});
    });
    const configuredFetch=globalThis.fetch;
    globalThis.fetch=async(url,options={})=>{
      if(options.method!=='POST'&&new URL(url).pathname===`/rentals/properties/${unavailable}`)throw Error('connection lost while switching');
      return configuredFetch(url,options);
    };
    await assert.rejects(api.loadProperty(unavailable),/could not be loaded/);
    await api.command('property',{name:'Reviewed name for the displayed property'});
    assert.equal(posts[0].path,`/rentals/properties/${displayed}/commands`);
    assert.equal(posts[0].input.version,7);
  }finally{globalThis.fetch=original;}
});

test('an unreadable success response retains the original change until its receipt is confirmed',async()=>{
  const original=globalThis.fetch;let writes=0;
  try{
    const {api,state}=await client(async(_url,options)=>{
      if(options.method==='POST'){writes++;state.version=8;return new Response('{incomplete',{status:200,headers:{'Content-Type':'application/json'}});}
      return Response.json({applied:true,state});
    });
    await assert.rejects(api.command('claimWeek',{weekId:'fictional-week'}),/response/);
    assert.equal(api.hasPending(),true);
    await api.loadProperty();
    assert.equal(api.hasPending(),false);assert.equal(writes,1);
  }finally{globalThis.fetch=original;}
});

test('an uncertain upload blocks new writes and retries only matching bytes with its original metadata',async()=>{
  const original=globalThis.fetch;const uploads=[];
  try{
    const {api,state,values}=await client(async(_url,options)=>{
      if(options.method!=='POST')return Response.json({applied:false,state});
      const form=options.body;
      uploads.push({requestKey:form.get('requestKey'),version:form.get('version'),teamId:form.get('teamId'),body:await form.get('file').text()});
      if(uploads.length===1)throw Error('upload response lost');
      const file={id:form.get('requestKey'),name:'original.txt',teamId:'private-team'};
      return Response.json({state:{...state,version:8,documents:[file]},file});
    });
    const file=new File(['original private contents'],'original.txt',{type:'text/plain'});
    await assert.rejects(api.uploadFile(file,'private-team'),/response/);
    assert.equal(api.pendingUpload().name,'original.txt');
    assert.equal([...values.values()].some(value=>value.includes('original private contents')),false);
    await assert.rejects(api.command('stayComment',{body:'another write'}),/original saved change/);
    await assert.rejects(api.uploadFile(new File(['different bytes'],'original.txt',{type:'text/plain'})),/same original file/);
    assert.equal(uploads.length,1);
    await api.loadProperty();
    assert.equal(api.hasPending(),true);
    await api.uploadFile(file,'a-different-current-team');
    assert.deepEqual(uploads[1],uploads[0]);
    assert.equal(uploads[0].version,'7');assert.equal(uploads[0].teamId,'private-team');
    assert.equal(api.hasPending(),false);
  }finally{globalThis.fetch=original;}
});

test('a committed upload with a lost response resolves from its receipt without uploading again',async()=>{
  const original=globalThis.fetch;let writes=0;
  try{
    const {api,state}=await client(async(_url,options)=>{
      if(options.method==='POST'){
        writes++;state.version=8;
        state.documents=[{id:options.body.get('requestKey'),name:'original.txt',teamId:''}];
        throw Error('upload response lost after commit');
      }
      return Response.json({applied:true,state});
    });
    await assert.rejects(api.uploadFile(new File(['original contents'],'original.txt',{type:'text/plain'})));
    const loaded=await api.loadProperty();
    assert.equal(loaded.recoveredUpload.name,'original.txt');
    assert.equal(api.hasPending(),false);assert.equal(writes,1);
  }finally{globalThis.fetch=original;}
});
