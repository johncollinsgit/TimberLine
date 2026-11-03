import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {fileURLToPath} from 'node:url';
import {execFileSync} from 'node:child_process';
import {buildClientSchema, buildSchema, parse, validate} from 'graphql';

// Source/type validation only. No app bootstrap, credentials, network or provider mutation.
const root=fileURLToPath(new URL('../../',import.meta.url));
const args=process.argv.slice(2);
if(args.length && (args.length!==2 || !['--schema-json','--schema-sdl'].includes(args[0]))) {
    throw new Error('Usage: node scripts/ci/validate-subscription-shopify-api.mjs [--schema-json CAPTURE | --schema-sdl SDL]');
}
const php=String.raw`require 'vendor/autoload.php'; $documents=[];
foreach (['capabilities','calculation','contract','customer_contracts','calculate','commit','pause','first_date'] as $o) $documents['contract.'.$o]=App\Services\Subscriptions\SubscriptionShopifyContractProtocol::document($o);
foreach (['billing_capabilities','billing_cycle','attempt','attempts','activate','bill','cancel'] as $o) $documents['billing.'.$o]=App\Services\Subscriptions\SubscriptionShopifyBillingProtocol::document($o);
foreach (['capabilities','catalog','contract','calculate','calculation','commit'] as $o) $documents['update.'.$o]=App\Services\Subscriptions\SubscriptionShopifyUpdateProtocol::document($o);
echo json_encode(['api_version'=>App\Services\Subscriptions\SubscriptionAccessService::API_VERSION,'documents'=>$documents],JSON_THROW_ON_ERROR);`;
const source=JSON.parse(execFileSync('php',['-r',php],{cwd:root,encoding:'utf8',maxBuffer:1048576}));
let schema;
let provenance;
if(args[0]==='--schema-json') {
    const capture=JSON.parse(fs.readFileSync(args[1],'utf8'));
    if(capture.api_version!==source.api_version || capture.http_status!==200 || !capture.data?.__schema) throw new Error('The capture must report the exact pinned successful original-app schema response.');
    schema=buildClientSchema(capture.data);
    provenance={api_version:capture.api_version,checked_at:capture.checked_at,source:'Provided pinned endpoint capture'};
} else {
    const defaultPath=path.join(root,'tests/Fixtures/Subscriptions/shopify-original-2026-10.graphql');
    const sdl=fs.readFileSync(args[1]??defaultPath,'utf8');
    provenance=JSON.parse(fs.readFileSync(path.join(root,'tests/Fixtures/Subscriptions/shopify-original-2026-10.provenance.json'),'utf8'));
    if(provenance.api_version!==source.api_version) throw new Error('Refresh the actual original-app schema projection for the pinned API version.');
    if(!args.length && crypto.createHash('sha256').update(sdl).digest('hex')!==provenance.projected_sdl_sha256) throw new Error('Schema projection differs from its captured provenance.');
    schema=buildSchema(sdl);
}
const results=Object.entries(source.documents).map(([document,code])=>({document,errors:validate(schema,parse(code)).map(e=>e.message)}));
console.log(JSON.stringify({api_version:source.api_version,checked_at:provenance.checked_at,documents:results.length,
    result:results.some(r=>r.errors.length)?'FAIL':'PASS',scope:'Captured schema syntax/types only; no provider execution or merchant acceptance',results},null,2));
if(results.some(r=>r.errors.length)) process.exitCode=1;
