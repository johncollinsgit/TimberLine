import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
const fixture = fileURLToPath(new URL('../Fixtures/proposals/workspace-canonical.php', import.meta.url));
const canonical = value => JSON.stringify((function sort(v) {
  if (Array.isArray(v)) return v.map(sort);
  if (v && typeof v === 'object') return Object.fromEntries(Object.keys(v).sort().map(k => [k, sort(v[k])]));
  return v;
})(value));
const base = {binding:{version_hash:'a'.repeat(64),build_id:'fictional',source_hash:'b'.repeat(64)},selection:{persona:'manager',area_ids:['81000000-0000-4000-8000-000000000001'],person_id:null},request_id:'89000000-0000-4000-8000-000000000001',expected_revision:1,action:'plan.cancel',record_id:'87000000-0000-4000-8000-000000000001',expected_record_version:1,payload:{}};
for (const [name, command] of [
  ['empty object payload',base],
  ['Unicode slash and line terminators',{...base,action:'project.update',payload:{title:'Fictional café ⚡ / north\u2028hall\u2029',description:'A/B',owner_id:null,due_on:null}}],
  ['arrays preserve order and type',{...base,selection:{...base.selection,area_ids:[]},payload:{list:[],other:['x','y'],enabled:false,value:0}}],
]) test(`PHP and JavaScript closed command digest: ${name}`, () => {
  const expected = canonical(command);
  const actual = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php',[fixture],{input:JSON.stringify(command),encoding:'utf8',env:{PATH:process.env.PATH}}));
  assert.equal(actual.canonical,expected);
  assert.equal(actual.digest,createHash('sha256').update(expected).digest('hex'));
});
test('wire [] differs from {} and must not be treated as a second legal empty command representation',()=>{
  assert.notEqual(canonical(base),canonical({...base,payload:[]}));
  // The HTTP regression verifies raw [] is refused; internal PHP arrays represent the one legal empty object.
});
