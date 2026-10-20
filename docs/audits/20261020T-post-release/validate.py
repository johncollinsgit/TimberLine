from pathlib import Path
from decimal import Decimal,ROUND_HALF_UP
import json,csv,hashlib,tarfile,subprocess,re
root=Path(__file__).resolve().parents[3];p=root/'docs/audits/20261020T-post-release';s=json.loads((p/'scorecard.json').read_text());r=json.loads((p/'rubric-v1.json').read_text());base=json.loads((p/'evidence/baseline-scorecard.json').read_text());e=json.loads((p/'evidence-index.json').read_text())
def d(x):return Decimal(str(x))
def q(x):return float(d(x).quantize(Decimal('.1'),rounding=ROUND_HALF_UP))
assert hashlib.sha256((p/'rubric-v1.json').read_bytes()).hexdigest()=='f4ecefe6861587da7b611eca3a7016f9597f74d8037b276a507eaa701ac71048'
assert s['rating_scale']==r['rating_scale']==base['rating_scale']
criteria=[c for g in s['categories'] for c in g['criteria']];original=[c for g in base['categories'] for c in g['criteria']];rubric=[c for g in r['categories'] for c in g['criteria']]
assert len(criteria)==27 and sum(d(c['weight']) for c in criteria)==100
for a,b,c in zip(criteria,original,rubric):
 for k in ['id','name','weight','acceptance_checks']:assert a[k]==b[k]==c[k],(a['id'],k)
 assert a['baseline_rating']==b['rating']
 assert all(x in e for x in a['evidence_ids'])
 assert a['earned_points']==(None if a['rating'] is None else float(d(a['weight'])*d(a['rating'])/4))
 assert a['subtopic_score_out_of_100']==(None if a['rating'] is None else a['rating']*25)
for g,rg in zip(s['categories'],r['categories']):
 assert (g['id'],g['name'],g['weight'])==(rg['id'],rg['name'],rg['weight'])
 earned=sum(d(c['earned_points'] or 0) for c in g['criteria']);unknown=sum(d(c['weight']) for c in g['criteria'] if c['rating'] is None)
 assert d(g['earned_points'])==earned and d(g['unverified_weight'])==unknown
 assert g['verified_minimum_out_of_100']==q(earned/d(g['weight'])*100)
 assert g['possible_maximum_out_of_100']==q((earned+unknown)/d(g['weight'])*100)
raw=sum(d(c['earned_points'] or 0) for c in criteria);unknown=sum(d(c['weight']) for c in criteria if c['rating'] is None)
assert raw==d('87.25') and s['raw_score']==s['adjusted_score']==q(raw)==87.3
assert d(base['raw_score_unrounded'])==d('75.25') and base['raw_score']==75.3
assert s['unverified_weight']==float(unknown)==8.5 and s['assessed_weight']==s['evidence_coverage_percent']==91.5
assert {c['id'] for c in criteria if c['rating'] is None}=={'R4','I2','X3'}
assert s['possible_score_interval']==[q(raw),q(raw+unknown)]==[87.3,95.8]
assert s['readiness_cap'] is None and s['provisional'] is True
for item in e.values():
 for path in item.get('sources',[item.get('source')]):
  if path:assert (p/path).is_file(),path
rows=list(csv.DictReader((p/'flow-matrix.csv').open()));old=list(csv.DictReader((p/'evidence/baseline-flow-matrix.csv').open()));assert len(rows)==83 and len({x['id'] for x in rows})==83
assert {x['id'] for x in old}.issubset({x['id'] for x in rows});assert all(next(x for x in rows if x['id']==o['id'])['baseline_status']==o['status'] for o in old)
module=json.loads((p/'module-inventory.json').read_text());assert len(module)==57 and {x['module_key'] for x in rows if x['id'].startswith('M')}==set(module)
for row in rows:assert all(i in e for i in row['evidence_ids'].split(';') if i)
findings=json.loads((p/'findings.json').read_text());ids={x['id'] for x in findings};assert all(i in ids for row in rows for i in row['finding_ids'].split(';') if i)
assert next(x for x in rows if x['id']=='J26')['status']=='VERIFIED BROKEN'
assert all(x['audited_release']==s['identifiers']['audited_production_release'] for x in rows)
with tarfile.open(p/'evidence/baseline-original-artifacts.tar.gz') as t:
 assert t.extractfile('20261020T063354Z/rubric-v1.json').read()==(p/'rubric-v1.json').read_bytes()
 assert t.extractfile('20261020T063354Z/scorecard.json').read()==(p/'evidence/baseline-scorecard.json').read_bytes()
http=json.loads((p/'evidence/fresh-public-http.json').read_text());assert http[0]['ready']=={'status':'ok','release':s['identifiers']['audited_production_release']};assert all(x['status']==200 for x in http)
ci=json.loads((p/'evidence/checked-ci-metadata.json').read_text());dep=json.loads((p/'evidence/production-deploy-metadata.json').read_text());assert ci['conclusion']==dep['conclusion']=='success';assert ci['headSha']==s['identifiers']['checked_ci_head'] and dep['headSha']==s['identifiers']['audited_production_release']
smoke=json.loads((p/'evidence/final-live-smoke.json').read_text());assert smoke['stats']['expected']==22 and smoke['stats']['unexpected']==smoke['stats']['flaky']==smoke['stats']['skipped']==0
probe=json.loads((p/'evidence/premium-ownership-probe.json').read_text());assert probe['entitled_premium_access'] is True and probe['marketplace_in_your_branches_catalog'] is False and probe['http_sent_count']==0
runtime=json.loads((p/'evidence/final-runtime.json').read_text());assert runtime['release']==dep['headSha'] and not any(runtime['marketplace_flags'].values()) and runtime['verified_host_configured'] is False
source=json.loads((p/'evidence/source-and-preservation.json').read_text());assert source['audited_tree']==source['checked_tree'];assert source['original_dirty_diff_sha256']=='b3a08d4f7b42156b1ad3df2879f63104110c56673354b7879e82c8ed33dbc874'
for branch,expected in source['source_tips'].items():assert subprocess.check_output(['git','rev-parse',branch],cwd=root,text=True).strip()==expected
changes=subprocess.check_output(['git','status','--porcelain','--untracked-files=all'],cwd=root,text=True).splitlines();assert all(x[3:].startswith('docs/audits/20261020T-post-release/') for x in changes),changes
for file in p.glob('*.md'):
 for target in re.findall(r'\]\(([^)]+)\)',file.read_text()):
  if not target.startswith(('https:','http:','#')):assert (file.parent/target).exists(),target
manifest=p/'artifact-manifest.json'
if manifest.exists():
 for artifact in json.loads(manifest.read_text()):
  file=p/artifact['path'];assert file.stat().st_size==artifact['bytes'];assert hashlib.sha256(file.read_bytes()).hexdigest()==artifact['sha256'],file
committed_paths=subprocess.check_output(['git','diff','--name-only',s['identifiers']['audited_production_release'],'HEAD'],cwd=root,text=True).splitlines()
assert all(x.startswith('docs/audits/20261020T-post-release/') for x in committed_paths),committed_paths
result={'validation':'PASS','criteria_count':27,'original_rubric_bytes_unchanged':True,'raw_score':str(raw),'half_up_score':q(raw),'baseline_raw_score':'75.25','baseline_half_up_score':75.3,'assessed_weight':91.5,'unknown_weight':8.5,'unknown_ids':['R4','I2','X3'],'flow_count':83,'canonical_module_count':57,'audit_only_worktree_diff':True,'evidence_paths_exist':True,'production_release':dep['headSha'],'checked_tree_equals_release_tree':True,'source_tips_and_dirty_fingerprint_preserved':True,'note':'Artifact consistency check, not an app/provider/restore/device test.'}
(p/'validation.json').write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result))
