import {useEffect, useRef, useState} from 'react';
import {App} from '@capacitor/app';
import {Browser} from '@capacitor/browser';
import {Camera} from '@capacitor/camera';
import {Filesystem, Directory} from '@capacitor/filesystem';
import {Share} from '@capacitor/share';

type Descriptor = {version: 1; productId: number; title: string; placement: 'hand'|'room'; modelUrl: string; lengthMm: number; grip: number[]; rotationDeg: number[]; variants: {id: number; label: string; modelUrl: string}[]};
type Viewer = HTMLElement & {beforeCamera?: ()=>Promise<void>; openRoomAR?: ()=>Promise<void>; savePreview?: (blob: Blob,name: string)=>Promise<void>; stop?: ()=>void};
let viewerBundle: Promise<unknown>|undefined;
function loadViewer(){
  if(!viewerBundle){
    const style=document.createElement('link');style.rel='stylesheet';style.href='/product-visualization/viewer.css';document.head.append(style);
    // This file is copied into the Capacitor app at build time. Never load executable code from a product descriptor.
    const path='/product-visualization/viewer.js';viewerBundle=import(/* @vite-ignore */ path);
  }
  return viewerBundle;
}
function safeHttps(value: string, hosts: string[]){
  const url=new URL(value);
  if(url.protocol!=='https:'||url.username||url.password||!hosts.includes(url.hostname))throw new Error('Unrecognized product preview host');
  return url;
}
function dataUrl(blob: Blob): Promise<string>{return new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(String(reader.result).split(',')[1]);reader.onerror=reject;reader.readAsDataURL(blob);});}

/** Mount from the existing tenant catalog screen. Use current authorized API data, never an offline draft or a different workspace's saved descriptor. */
export function ProductVisualization({descriptor,publicViewerUrl,allowedHosts}:{descriptor:Descriptor;publicViewerUrl:string;allowedHosts:string[]}){
  const host=useRef<HTMLDivElement>(null);const [error,setError]=useState('');
  const serialized=JSON.stringify(descriptor);const trusted=JSON.stringify(allowedHosts);
  useEffect(()=>{
    let disposed=false;let viewer:Viewer|undefined;let removeListener:(()=>Promise<void>)|undefined;
    (async()=>{
      const hosts=JSON.parse(trusted) as string[];
      for(const model of [descriptor.modelUrl,...descriptor.variants.map(v=>v.modelUrl)]){
        const url=safeHttps(model,hosts);
        if(!/^\/product-visualization\/assets\/\d+\/\d+\.glb$/.test(url.pathname))throw new Error('Unrecognized product model route');
      }
      const publicUrl=safeHttps(publicViewerUrl,hosts);
      await loadViewer();if(disposed)return;
      viewer=document.createElement('everbranch-product-viewer') as Viewer;
      viewer.setAttribute('native','');viewer.setAttribute('data-config',serialized);
      viewer.beforeCamera=async()=>{const permission=await Camera.requestPermissions({permissions:['camera']});if(permission.camera!=='granted')throw new Error('Allow camera access in the app settings, then retry.');};
      viewer.openRoomAR=async()=>{viewer?.stop?.();await Browser.open({url:publicUrl.href});};
      viewer.savePreview=async(blob,name)=>{
        const file=await Filesystem.writeFile({path:`product-previews/${Date.now()}-${name}`,directory:Directory.Cache,data:await dataUrl(blob),recursive:true});
        await Share.share({title:'Product preview',files:[file.uri]});
      };
      host.current?.replaceChildren(viewer);
      const listener=await App.addListener('appStateChange',({isActive})=>{if(!isActive)viewer?.stop?.();});
      if(disposed)await listener.remove();else removeListener=()=>listener.remove();
    })().catch(e=>{if(!disposed)setError(e instanceof Error?e.message:'Product preview is unavailable.');});
    return()=>{disposed=true;viewer?.stop?.();viewer?.remove();void removeListener?.();};
  },[serialized,trusted,publicViewerUrl]);
  return <section aria-label="Product visualization">{error&&<p role="alert">{error}</p>}<div ref={host}/></section>;
}
