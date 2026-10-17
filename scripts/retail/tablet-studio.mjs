import {chromium} from '@playwright/test';
const b=await chromium.connectOverCDP('http://127.0.0.1:9224');
const context=b.contexts()[0];
const page=context.pages().find(p=>p.url().includes('/studio/'))??context.pages().find(p=>p.url().includes('/retail/'));await page.goto('http://127.0.0.1:8797/studio/');await page.waitForSelector('.cover-choice');
console.log(JSON.stringify({bookCount:await page.locator('.cover-choice').count(),title:await page.title(),width:await page.evaluate(()=>innerWidth)}));await page.screenshot({path:process.cwd()+'/output/retail/screenshots/tablet-studio-three-books.png'});
process.exit(0);
