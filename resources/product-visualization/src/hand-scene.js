import * as THREE from 'three';
import {GLTFLoader} from 'three/addons/loaders/GLTFLoader.js';
import {coverTransform,projectHand,palmSpan,millimetersToPixels} from './pose.js';
const BONES=[[0,1],[1,2],[2,3],[3,4],[0,5],[5,6],[6,7],[7,8],[5,9],[9,10],[10,11],[11,12],[9,13],[13,14],[14,15],[15,16],[13,17],[0,17],[17,18],[18,19],[19,20]];
function dispose(root){root?.traverse(o=>{o.geometry?.dispose();for(const m of(Array.isArray(o.material)?o.material:[o.material]))if(m){for(const v of Object.values(m))if(v?.isTexture)v.dispose();m.dispose();}});}
export class HandScene{
  constructor(canvas,config){
    this.config=config; this.version=0;this.disposed=false;this.offset={x:0,y:0};this.userRotation=[0,0,0];this.zoom=1;this.palmMm=80;this.masking=true;
    this.renderer=new THREE.WebGLRenderer({canvas,alpha:true,antialias:true,preserveDrawingBuffer:true});
    this.renderer.setPixelRatio(Math.min(devicePixelRatio,2));this.renderer.setClearColor(0,0);this.renderer.outputColorSpace=THREE.SRGBColorSpace;
    this.scene=new THREE.Scene();this.camera=new THREE.OrthographicCamera(-1,1,1,-1,.1,10000);this.camera.position.z=2000;
    this.scene.add(new THREE.HemisphereLight(0xffffff,0x695c50,3));const light=new THREE.DirectionalLight(0xffffff,3);light.position.set(-200,300,700);this.scene.add(light);
    this.object=new THREE.Group();this.scene.add(this.object);this.object.visible=false;
    this.occlusion=new THREE.Group();this.scene.add(this.occlusion);this.occlusion.visible=false;
    const material=new THREE.MeshBasicMaterial({colorWrite:false,depthWrite:true,depthTest:true});
    for(const bone of BONES){const mesh=new THREE.Mesh(new THREE.CylinderGeometry(1,1,1,8),material);mesh.renderOrder=-1;this.occlusion.add(mesh);}
    this.joints=[];for(let i=0;i<21;i++){const joint=new THREE.Mesh(new THREE.SphereGeometry(1,8,6),material);joint.renderOrder=-1;this.occlusion.add(joint);this.joints.push(joint);}
  }
  async load(url){
    const generation=++this.version;this.object.visible=false;
    const {scene}=await new GLTFLoader().loadAsync(url);
    if(this.disposed||generation!==this.version){dispose(scene);return;}
    dispose(this.model);if(this.model)this.object.remove(this.model);
    const box=new THREE.Box3().setFromObject(scene);const size=box.getSize(new THREE.Vector3());const max=Math.max(size.x,size.y,size.z);
    if(!Number.isFinite(max)||max<=0){dispose(scene);throw new Error('This model has no visible geometry.');}
    const grip=new THREE.Vector3(...this.config.grip).multiply(size).add(box.min);
    const model=new THREE.Group();scene.position.sub(grip);model.add(scene);model.scale.setScalar(this.config.lengthMm/max);
    const rotated=new THREE.Group();rotated.add(model);rotated.rotation.set(...this.config.rotationDeg.map(THREE.MathUtils.degToRad));
    this.model=rotated;this.object.add(rotated);
  }
  resize(w,h){this.width=w;this.height=h;this.renderer.setSize(w,h,false);Object.assign(this.camera,{left:-w/2,right:w/2,top:h/2,bottom:-h/2});this.camera.updateProjectionMatrix();}
  update(landmarks,video){
    if(!this.model||!video.videoWidth||!this.width)return;
    const points=projectHand(landmarks,coverTransform(video.videoWidth,video.videoHeight,this.width,this.height));
    const span=palmSpan(points),scale=millimetersToPixels(span,this.palmMm);
    if(!points||span<15||scale<=0){this.object.visible=false;this.occlusion.visible=false;return;}
    const p=points.map(v=>new THREE.Vector3(v.x,v.y,v.z));
    const across=p[17].clone().sub(p[5]).normalize();const along=p[9].clone().sub(p[0]).normalize();const normal=new THREE.Vector3().crossVectors(across,along).normalize();
    if(normal.lengthSq()<.01){this.object.visible=false;this.occlusion.visible=false;return;}
    const up=new THREE.Vector3().crossVectors(normal,across).normalize();
    const pose=new THREE.Quaternion().setFromRotationMatrix(new THREE.Matrix4().makeBasis(across,up,normal));
    pose.multiply(new THREE.Quaternion().setFromEuler(new THREE.Euler(...this.userRotation.map(THREE.MathUtils.degToRad))));
    const center=p[0].clone().lerp(p[9],.67);center.x+=this.offset.x;center.y+=this.offset.y;
    if(!this.object.visible){this.object.position.copy(center);this.object.quaternion.copy(pose);this.object.scale.setScalar(scale*this.zoom);}
    else{this.object.position.lerp(center,.35);this.object.quaternion.slerp(pose,.35);this.object.scale.lerp(new THREE.Vector3().setScalar(scale*this.zoom),.35);}
    this.object.visible=true;this.occlusion.visible=this.masking;
    BONES.forEach(([a,b],i)=>{const mesh=this.occlusion.children[i];const delta=p[b].clone().sub(p[a]);mesh.position.copy(p[a]).lerp(p[b],.5);mesh.quaternion.setFromUnitVectors(new THREE.Vector3(0,1,0),delta.clone().normalize());const radius=span*(a===0?.065:.044);mesh.scale.set(radius,delta.length(),radius);});
    this.joints.forEach((mesh,i)=>{mesh.position.copy(p[i]);mesh.scale.setScalar(span*.044);});
  }
  hide(){this.object.visible=false;this.occlusion.visible=false;}
  render(){this.renderer.render(this.scene,this.camera);}
  close(){this.disposed=true;++this.version;dispose(this.scene);this.renderer.dispose();}
}
