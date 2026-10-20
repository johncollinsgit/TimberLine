export function coverTransform(videoWidth,videoHeight,width,height){
  const scale=Math.max(width/videoWidth,height/videoHeight);
  return {scale,offsetX:(width-videoWidth*scale)/2,offsetY:(height-videoHeight*scale)/2,videoWidth,videoHeight,width,height};
}
export function projectHand(landmarks,transform){
  const {scale,offsetX,offsetY,videoWidth,videoHeight,width,height}=transform;
  if(!Array.isArray(landmarks)||landmarks.length!==21 || landmarks.some(p=>!Number.isFinite(p.x)||!Number.isFinite(p.y)||!Number.isFinite(p.z)))return null;
  return landmarks.map(p=>({x:p.x*videoWidth*scale+offsetX-width/2,y:height/2-(p.y*videoHeight*scale+offsetY),z:-p.z*videoWidth*scale}));
}
export function palmSpan(points){
  if(!points||points.length!==21)return 0;
  return Math.hypot(points[5].x-points[17].x,points[5].y-points[17].y,points[5].z-points[17].z);
}
export function millimetersToPixels(span,palmMm=80){
  if(!Number.isFinite(span)||span<=0||!Number.isFinite(palmMm)||palmMm<40||palmMm>140)return 0;
  return span/palmMm;
}
export function validConfig(config){
  return config && config.version===1 && ['hand','room'].includes(config.placement) && Number.isFinite(config.lengthMm) && config.lengthMm>0 && config.lengthMm<=10000 && typeof config.modelUrl==='string' && Array.isArray(config.grip) && config.grip.length===3 && config.grip.every(v=>Number.isFinite(v)&&v>=0&&v<=1) && Array.isArray(config.rotationDeg) && config.rotationDeg.length===3 && config.rotationDeg.every(v=>Number.isFinite(v)&&Math.abs(v)<=180);
}
