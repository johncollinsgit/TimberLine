import {readFile,writeFile,mkdir} from 'node:fs/promises';
import {resolve} from 'node:path';
import sharp from 'sharp';

// Supply project root plus its existing vector master and adaptive foreground.
// Android 26+ uses drawable-v24 vectors directly. PNGs support older launchers
// and a resource-only refresh of an already-built local demonstration APK.
const [project,masterPath,foregroundPath]=process.argv.slice(2);
if(!project||!masterPath||!foregroundPath)throw new Error('Usage: node export-android-icons.mjs PROJECT MASTER_SVG FOREGROUND_SVG');
const root=resolve(project);
const master=await readFile(resolve(root,masterPath));
const foreground=await readFile(resolve(root,foregroundPath));
for(const [density,scale] of [['ldpi',.75],['mdpi',1],['hdpi',1.5],['xhdpi',2],['xxhdpi',3],['xxxhdpi',4]]){
  const dir=resolve(root,`android/app/src/main/res/mipmap-${density}`);
  await mkdir(dir,{recursive:true});
  const icon=await sharp(master,{density:384}).resize(48*scale,48*scale).png({compressionLevel:9}).toBuffer();
  await writeFile(`${dir}/ic_launcher.png`,icon);
  await writeFile(`${dir}/ic_launcher_round.png`,icon);
  await sharp(foreground,{density:384}).resize(108*scale,108*scale).png({compressionLevel:9}).toFile(`${dir}/ic_launcher_foreground.png`);
}
console.log('Android launcher PNGs exported directly from vector masters at all six densities.');
