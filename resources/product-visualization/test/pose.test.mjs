import {test} from 'node:test';
import assert from 'node:assert/strict';
import {coverTransform,projectHand,palmSpan,millimetersToPixels,validConfig} from '../src/pose.js';

test('landmarks use the same centered cover crop as a portrait camera video',()=>{
  const transform=coverTransform(1280,720,360,640);
  const points=projectHand(Array.from({length:21},()=>({x:.5,y:.5,z:0})),transform);
  assert.ok(Math.abs(points[0].x)<1e-8);assert.ok(Math.abs(points[0].y)<1e-8);
  const edge=projectHand(Array.from({length:21},()=>({x:0,y:0,z:0})),transform)[0];
  assert.ok(edge.x< -180);assert.equal(edge.y,320);
});
test('invalid or missing detections hide the product rather than leaving an old hand',()=>{
  const transform=coverTransform(640,480,640,480);
  for(const input of [null,[],Array(21).fill({x:NaN,y:0,z:0})])assert.equal(projectHand(input,transform),null);
  assert.equal(palmSpan(null),0);
});
test('palm calibration uses measured millimeters and rejects unstable scale',()=>{
  assert.equal(millimetersToPixels(160,80),2);assert.equal(millimetersToPixels(160,100),1.6);
  for(const bad of [0,-1,NaN,Infinity])assert.equal(millimetersToPixels(bad,80),0);
  assert.equal(millimetersToPixels(100,10),0);
});
test('viewer contract supports both modes and rejects malformed placement and grip',()=>{
  const config={version:1,placement:'room',lengthMm:900,modelUrl:'https://example.test/barrel.glb',grip:[.5,.2,.5],rotationDeg:[0,0,0]};
  assert.ok(validConfig(config));assert.ok(validConfig({...config,placement:'hand'}));
  assert.ok(!validConfig({...config,placement:'face'}));assert.ok(!validConfig({...config,grip:[3,0,0]}));assert.ok(!validConfig({...config,lengthMm:Infinity}));
});
