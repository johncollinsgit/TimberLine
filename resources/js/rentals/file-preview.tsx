'use client';
import {useEffect,useState} from 'react';
import {FileText} from 'lucide-react';

export default function FilePreview({file}:{file:any}){
 const [text,setText]=useState(file.body||'');
 useEffect(()=>{if(file.type!=='text/plain'||!file.url)return;const controller=new AbortController();fetch(file.url,{signal:controller.signal,cache:'no-store'}).then(async r=>{if(!r.ok)throw Error('Preview unavailable');setText((await r.text()).slice(0,1800));}).catch(()=>{});return()=>controller.abort();},[file.url,file.type]);
 if((file.type||'').startsWith('image/'))return <div className="file-preview"><img src={file.previewUrl||file.url} alt={file.name||file.title}/></div>;
 if(file.type==='application/pdf'&&file.url)return <div className="file-preview pdf-preview"><div className="pdf-fallback"><FileText size={32}/><span>PDF · open full preview</span></div><iframe src={file.url+'#page=1&view=FitH&toolbar=0&navpanes=0&scrollbar=0'} title={(file.name||file.title)+' page preview'} tabIndex={-1} aria-hidden="true" loading="lazy"/><span className="preview-type">PDF</span></div>;
 return <div className="file-preview document-preview"><FileText size={27}/><strong>{file.category||'DOCUMENT'}</strong><pre>{text||'Open this document to view its contents.'}</pre></div>;
}
