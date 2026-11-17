/** Only opaque navigation keys enter browser history; report state stays in memory. */
export function reportNavigation({window:win,capture,restore,context,enabled=()=>true,invalidate=()=>{}}){
  let session=key(),current=null,scope=null,sequence=0;
  const frames=new Map();
  function key(){return win.crypto?.randomUUID?.()||`${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;}
  function metadata(){return {version:1,session,entry:current};}
  function write(method){const state=win.history.state;win.history[method]({...((state&&typeof state==='object')?state:{}),trajectoryNavigation:metadata()},'',win.location.href);}
  function reset(){session=key();scope=context();current=key();frames.clear();frames.set(current,{context:scope,parent:null,value:capture()});if(enabled())write('replaceState');}
  function sync(){
    if(!enabled())return;
    if(current===null){reset();return;}
    if(scope!==context()){invalidate('context');reset();return;}
    frames.get(current).value=capture();
  }
  function push(){
    if(!enabled())return false;
    sync();const previous=current;current=key();frames.set(current,{context:scope,parent:previous,value:capture()});write('pushState');return true;
  }
  function back(){
    const state=win.history.state?.trajectoryNavigation,frame=frames.get(current);
    if(!enabled()||state?.session!==session||state?.entry!==current||!frame?.parent||!frames.has(frame.parent))return false;
    sync();win.history.back();return true;
  }
  async function pop(event){
    const meta=event.state?.trajectoryNavigation;
    if(!enabled())return;
    if(meta?.session!==session||!frames.has(meta.entry)||frames.get(meta.entry).context!==context()){
      // A changed financial scope has no reusable evidence or private cached panel.
      if(meta?.version===1){invalidate('history');reset();}
      return;
    }
    if(frames.has(current))frames.get(current).value=capture();
    current=meta.entry;const target=current,request=++sequence;
    await restore(frames.get(target).value,()=>current===target&&sequence===request&&scope===context());
  }
  win.addEventListener('popstate',pop);
  return {sync,push,back,dispose:()=>win.removeEventListener('popstate',pop)};
}
