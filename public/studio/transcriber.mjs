import { pipeline, env } from './transformers.web.js';
env.allowLocalModels=false;
env.backends.onnx.wasm.numThreads=1;
let engine;
self.onmessage=async event=>{
 try{
  engine ||= await pipeline('automatic-speech-recognition','Xenova/whisper-tiny.en',{revision:'79fb389fc764e7c395bd330e9531d9d32ada7049',dtype:'q8',device:'wasm',progress_callback:p=>self.postMessage({type:'progress',status:p.status,progress:p.progress,file:p.file})});
  self.postMessage({type:'working'});
  const result=await engine(event.data.samples,{chunk_length_s:30,stride_length_s:5,return_timestamps:true});
  self.postMessage({type:'done',text:result.text,chunks:result.chunks});
 }catch(error){self.postMessage({type:'error',error:error.message || 'On-device transcription could not finish.'});}
};
