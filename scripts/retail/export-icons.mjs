import {readFile, writeFile} from 'node:fs/promises';
import sharp from 'sharp';

// Render every size directly from its vector master. Do not enlarge a small PNG.
// Maskable artwork needs an opaque full-bleed background; Android supplies its mask.
for (const [folder, master] of [
  ['public/retail/assets', 'public/retail/assets/icon.svg'],
  ['public/studio', 'public/studio/icon.svg'],
]) {
  const svg = await readFile(master, 'utf8');
  const maskable = svg.replace(/(<rect\b[^>]*?)\s+rx="[^"]*"/, '$1');
  await writeFile(`${folder}/icon-maskable.svg`, maskable);
  for (const size of [192, 512, 1024]) {
    await sharp(Buffer.from(svg), {density: 384}).resize(size, size).png({compressionLevel: 9}).toFile(`${folder}/icon-${size}.png`);
    if (size !== 1024) await sharp(Buffer.from(maskable), {density: 384}).resize(size, size).png({compressionLevel: 9}).toFile(`${folder}/icon-maskable-${size}.png`);
  }
}
console.log('Studio and Retail icons rendered from vector masters with separate maskable artwork.');
