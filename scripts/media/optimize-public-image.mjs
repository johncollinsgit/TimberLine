#!/usr/bin/env node
/**
 * Make public-site image derivatives without exposing original production media.
 * Usage: node scripts/media/optimize-public-image.mjs input.jpg output-stem
 */
import path from "node:path";
import { mkdir } from "node:fs/promises";
import sharp from "sharp";

const [input, outputStem] = process.argv.slice(2);
if (!input || !outputStem) {
    console.error("Usage: node scripts/media/optimize-public-image.mjs input.jpg output-stem");
    process.exit(1);
}

const outputDirectory = path.resolve("public/images/public-site");
await mkdir(outputDirectory, { recursive: true });

await Promise.all([
    sharp(input).resize({ width: 1920, withoutEnlargement: true }).jpeg({ quality: 78, mozjpeg: true }).toFile(path.join(outputDirectory, `${outputStem}-wide.jpg`)),
    sharp(input).resize({ width: 960, withoutEnlargement: true }).jpeg({ quality: 80, mozjpeg: true }).toFile(path.join(outputDirectory, `${outputStem}-card.jpg`)),
]);

console.log(`Created responsive derivatives for ${outputStem}.`);
