import path from 'path';
import { fileURLToPath } from 'url';
import sharp from 'sharp';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const width = 1200;
const height = 630;

const svg = Buffer.from(`
  <svg width="${width}" height="${height}" viewBox="0 0 ${width} ${height}" xmlns="http://www.w3.org/2000/svg">
    <defs>
      <linearGradient id="wash" x1="0" y1="0" x2="1" y2="0">
        <stop offset="0" stop-color="#f5f0e4" stop-opacity="0.96"/>
        <stop offset="0.62" stop-color="#f5f0e4" stop-opacity="0.62"/>
        <stop offset="1" stop-color="#f5f0e4" stop-opacity="0"/>
      </linearGradient>
      <filter id="shadow" x="-20%" y="-20%" width="140%" height="140%">
        <feDropShadow dx="0" dy="14" stdDeviation="18" flood-color="#10251e" flood-opacity="0.16"/>
      </filter>
    </defs>
    <rect width="${width}" height="${height}" fill="url(#wash)"/>
    <rect x="28" y="28" width="1144" height="574" rx="30" fill="none" stroke="#f8f5ed" stroke-opacity="0.82" stroke-width="2"/>
    <text x="140" y="111" fill="#143f35" font-family="Arial, Helvetica, sans-serif" font-size="42" font-weight="700" letter-spacing="-1.8">Everbranch</text>
    <text x="76" y="326" fill="#102a24" font-family="Georgia, 'Times New Roman', serif" font-size="78" font-weight="700" letter-spacing="-3.4">Work, in</text>
    <text x="76" y="410" fill="#102a24" font-family="Georgia, 'Times New Roman', serif" font-size="78" font-weight="700" letter-spacing="-3.4">one place.</text>
    <line x1="78" y1="460" x2="250" y2="460" stroke="#c96b43" stroke-width="6" stroke-linecap="round"/>
    <text x="78" y="530" fill="#24493f" font-family="Arial, Helvetica, sans-serif" font-size="24" font-weight="600" letter-spacing="0.2">theeverbranch.com</text>
  </svg>
`);

async function generate() {
  const background = await sharp(path.join(root, 'public/images/public-site/everbranch-og-background-v2.png'))
    .resize(width, height, { fit: 'cover', position: 'centre' })
    .png()
    .toBuffer();

  const mark = await sharp(path.join(root, 'public/brand/everbranch-mark.png'))
    .resize(48, 48)
    .png()
    .toBuffer();

  await sharp(background)
    .composite([
      { input: svg },
      { input: mark, left: 76, top: 74 },
    ])
    .png({ compressionLevel: 9, quality: 92 })
    .toFile(path.join(root, 'public/og-image-v2.png'));
}

generate().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
