import {test, expect} from '@playwright/test';
import {createServer, type IncomingMessage} from 'node:http';
import {createHash} from 'node:crypto';
import {readFileSync} from 'node:fs';
import {once} from 'node:events';
import {join} from 'node:path';

async function readForm(req: IncomingMessage) {
    const chunks: Buffer[] = [];
    for await (const chunk of req) chunks.push(chunk);
    const bytes = Buffer.concat(chunks);
    return {
        size: bytes.length,
        fields: await new Response(new Uint8Array(bytes), {
            headers: {'Content-Type': String(req.headers['content-type'])},
        }).formData(),
    };
}

test('large maker upload retries a lost acknowledgement and sends the draft only a verified ticket', async ({page}) => {
    const video = Buffer.concat([readFileSync('public/images/public-site/everbranch-studio-hero-loop.mp4'), Buffer.alloc(1400000)]);
    const sha256 = (input: Uint8Array | string) => createHash('sha256').update(input).digest('hex');
    const parts = new Map<number, Buffer>();
    const visits: number[] = [];
    const errors: string[] = [];
    const html = readFileSync('storage/framework/testing/marketplace-console/video-editor.html', 'utf8');
    let saved = false;
    let fingerprint = '';
    let origin: string;

    // Read requests from the actual wire. WebKit debugging APIs omit binary file parts.
    const server = createServer(async (req, res) => {
        try {
            const path = new URL(req.url!, origin).pathname;
            if (path === '/qa/video') {
                res.writeHead(200, {'Content-Type':'text/html'}).end(html.replaceAll('https://marketsandmakers.test', origin).replaceAll('http://localhost', origin));
                return;
            }
            if (/^\/build\/assets\/[\w.-]+\.(css|js)$/.test(path)) {
                res.writeHead(200, {'Content-Type':path.endsWith('.js') ? 'application/javascript' : 'text/css'}).end(readFileSync(join('public',path)));
                return;
            }
            if (req.method !== 'POST') {
                res.writeHead(404).end();
                return;
            }
            const {fields, size} = await readForm(req);
            const match = path.match(/\/parts\/(\d+)$/);
            if (match) {
                const i = Number(match[1]);
                const part = Buffer.from(await (fields.get('part') as File).arrayBuffer());
                expect(part.length).toBeLessThanOrEqual(1048576);
                expect(size).toBeLessThan(2 * 1048576);
                expect(fields.get('checksum')).toBe(sha256(part));
                visits.push(i);
                parts.set(i, part);
                if (visits.length === 1) { req.socket.destroy(); return; }
            } else if (path.endsWith('/finish')) {
                expect(sha256(Buffer.concat([...parts.values()]))).toBe(sha256(video));
                expect(sha256([...parts.values()].map(sha256).join(''))).toBe(fingerprint);
            } else if (path.includes('/uploads/')) {
                expect(Number(fields.get('size'))).toBe(video.length);
                fingerprint = String(fields.get('fingerprint'));
            } else if (/\/maker\/\d+\/studio$/.test(path)) {
                expect(size).toBeLessThan(10000);
                expect(fields.get('source_upload_id')).toMatch(/^[a-f\d-]{36}$/);
                expect(fields.has('source')).toBe(false);
                expect(fields.get('action')).toBe('draft');
                expect(fields.get('title')).toBe('Chunked video test');
                saved = true;
                res.writeHead(200, {'Content-Type':'text/html'}).end('<p>Draft saved</p>');
                return;
            } else {
                res.writeHead(404).end();
                return;
            }
            res.writeHead(200, {'Content-Type':'application/json'}).end(JSON.stringify({state:'receiving',next_index:parts.size,chunk_bytes:1048576}));
        } catch (error) {
            errors.push(String(error));
            res.writeHead(422).end();
        }
    });
    server.listen(0,'127.0.0.1');
    await once(server,'listening');
    const address = server.address();
    if (!address || typeof address === 'string') throw Error('Missing HTTP test port');
    origin = 'http://127.0.0.1:' + address.port;
    try {
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(origin+'/qa/video');
        await page.locator('[data-video-file]').setInputFiles({name:'workshop.mp4',mimeType:'video/mp4',buffer:video});
        await page.getByLabel('Title',{exact:true}).fill('Chunked video test');
        await page.getByRole('button',{name:'Save draft'}).click();
        await expect.poll(() => errors.length ? errors.join('\n') : saved).toBe(true);
        await expect(page.getByText('Draft saved')).toBeVisible();
        expect(visits).toEqual([0,0,1,2]);
    } finally {
        server.closeAllConnections();
        await new Promise<void>(done => server.close(() => done()));
    }
});
