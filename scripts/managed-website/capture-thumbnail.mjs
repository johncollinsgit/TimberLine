import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import path from "node:path";

const [source, destination] = process.argv.slice(2);
if (!source || !destination) {
  throw new Error("Usage: capture-thumbnail.mjs <signed-preview-url> <output-path>");
}

await mkdir(path.dirname(destination), { recursive: true });
const browser = await chromium.launch({ headless: true });
try {
  const page = await browser.newPage({ viewport: { width: 1440, height: 960 }, deviceScaleFactor: 1 });
  await page.goto(source, { waitUntil: "networkidle", timeout: 45_000 });
  await page.screenshot({ path: destination, fullPage: false, type: "png" });
} finally {
  await browser.close();
}
