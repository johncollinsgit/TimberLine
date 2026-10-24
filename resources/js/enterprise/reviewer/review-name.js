/* Read only the current chosen display name through the existing private review context. */
(() => {
    'use strict';
    const root = globalThis;
    const unavailable = () => new Error('The current review name could not be verified. Open the review panel and refresh.');
    const url = value => {try {return new URL(value, root.location.href);} catch {throw unavailable();}};
    function create(boot, descriptor) {
        const binding = descriptor?.binding;
        const projection = url(descriptor?.endpoints?.projection);
        if (!boot || !binding || projection.origin !== root.location.origin || projection.search || projection.hash
            || projection.username || projection.password || !/^\/enterprise-owner\/service-proposals\/[A-Za-z0-9]{1,200}\/campus\/workspace\/projection$/.test(projection.pathname)
            || !['version_hash','build_id','source_hash'].every(key => boot[key] === binding[key])
            || !Array.isArray(boot.pages) || !Array.isArray(boot.reviewer_names)) throw unavailable();
        const pages = new Set(boot.pages.map(row => row.id));
        const base = projection.pathname.slice(0, -'/workspace/projection'.length);
        const destinations = new Map();
        for (const page of pages) {
            if (typeof page !== 'string' || !/^[a-z_]{1,40}$/.test(page)) throw unavailable();
            const destination = url(boot.urls?.data_pages?.[page]);
            if (destination.origin !== projection.origin || destination.pathname !== base+'/'+page+'/review'
                || destination.search || destination.hash || destination.username || destination.password) throw unavailable();
            destinations.set(page, destination.href);
        }
        const names = [...boot.reviewer_names];
        if (!names.every(name => typeof name === 'string' && [...name].length <= 80)) throw unavailable();
        const expected = Object.fromEntries(['version_hash','build_id','source_hash'].map(key => [key, binding[key]]));
        return Object.freeze({async read(page, signal) {
            if (!destinations.has(page)) throw unavailable();
            let reader;
            try {
                const response = await root.fetch(destinations.get(page), {method:'GET', credentials:'same-origin', redirect:'error', cache:'no-store', headers:{Accept:'application/json'}, signal});
                if (!response.ok || !/^application\/json(?:;|$)/i.test(response.headers.get('Content-Type') || '') || !response.body?.getReader) throw unavailable();
                // Existing context includes at most200 bounded proposal comments.
                // This fixed ceiling accommodates their maximum JSON escaping;
                // none of that feedback is retained by the returned object.
                reader = response.body.getReader();
                const chunks = []; let total = 0;
                while (true) {
                    const chunk = await reader.read(); if (chunk.done) break;
                    total += chunk.value.byteLength;
                    if (total > 10*1024*1024) { await reader.cancel(); throw unavailable(); }
                    chunks.push(chunk.value);
                }
                const bytes = new Uint8Array(total); let offset = 0;
                for (const chunk of chunks) {bytes.set(chunk,offset);offset += chunk.byteLength;}
                const review = JSON.parse(new TextDecoder('utf-8',{fatal:true}).decode(bytes)).review;
                if (!review || review.page !== page || !Object.entries(expected).every(([key,value]) => review[key] === value)
                    || !(review.reviewer_name === null || names.includes(review.reviewer_name))) throw unavailable();
                return Object.freeze({...expected, reviewer_name:review.reviewer_name});
            } catch { throw unavailable(); }
            finally { try {reader?.releaseLock();} catch {} }
        }});
    }
    root.EverbranchReviewerNameReader = Object.freeze({create});
})();
