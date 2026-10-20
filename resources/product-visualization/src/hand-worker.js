import {FilesetResolver,HandLandmarker} from '@mediapipe/tasks-vision';
let landmarker;
self.onmessage=async({data})=>{
  try{
    if(data.type==='init'){
      const files=await FilesetResolver.forVisionTasks(data.wasm);
      landmarker=await HandLandmarker.createFromOptions(files,{baseOptions:{modelAssetPath:data.model,delegate:'CPU'},runningMode:'VIDEO',numHands:1,minHandDetectionConfidence:.6,minHandPresenceConfidence:.6,minTrackingConfidence:.6});
      self.postMessage({type:'ready'});
    }else if(data.type==='frame'){
      const result=landmarker.detectForVideo(data.bitmap,data.timestamp);
      data.bitmap.close();
      self.postMessage({type:'result',landmarks:result.landmarks[0]??null});
    }
  }catch(error){
    data.bitmap?.close();
    self.postMessage({type:'error',message:'The hand tracker could not run on this device.'});
  }
};
