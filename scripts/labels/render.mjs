import sharp from 'sharp';
import fs from 'node:fs/promises';
const [input, preview, bitmap, settingsJson = '{}'] = process.argv.slice(2);
const settings = JSON.parse(settingsJson);
let image = sharp(input, {limitInputPixels: 40000000, failOn:'error', animated:false}).rotate();
const metadata = await image.metadata();
if (!metadata.width || !metadata.height || metadata.pages > 1) throw new Error('A single image is required.');
if (settings.crop) {
  const {x,y,width,height} = settings.crop;
  const left = Math.round(x * metadata.width), top = Math.round(y * metadata.height);
  const w = Math.min(metadata.width-left,Math.round(width*metadata.width)), h = Math.min(metadata.height-top,Math.round(height*metadata.height));
  if (w<1 || h<1 || left<0 || top<0) throw new Error('Invalid crop.');
  image = image.extract({left,top,width:w,height:h});
}
await image.resize({width:2400,height:2400,fit:'inside',withoutEnlargement:true}).png().toFile(preview);
if (bitmap !== '-') {
  const {data,info}=await sharp(preview).flatten({background:'#fff'}).greyscale().threshold(settings.threshold??160).raw().toBuffer({resolveWithObject:true});
  const out=Buffer.alloc(info.width*info.height*3);
  let ink=0;
  for(let i=0;i<info.width*info.height;i++){const black=data[i*info.channels]<128;out[i*3]=out[i*3+1]=out[i*3+2]=black?0:255;if(black)ink++;}
  if (!ink || ink>=info.width*info.height*.99) throw new Error('The logo has no usable foreground.');
  await sharp(out,{raw:{width:info.width,height:info.height,channels:3}}).png().toFile(bitmap);
}

if (bitmap === '-') {
  const {data,info}=await sharp(preview).ensureAlpha().raw().toBuffer({resolveWithObject:true});
  let ink=0,clear=0;
  for(let i=0;i<data.length;i+=info.channels){
    const alpha=data[i+3];
    if(!alpha)clear++;
    else {if(data[i]||data[i+1]||data[i+2])throw new Error('Vector artwork must be black only.');ink++;}
  }
  if(!ink||!clear)throw new Error('Vector artwork must be nonempty with a transparent background.');
}
