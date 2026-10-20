"""Validate audit arithmetic/provenance/artifacts; does not exercise the application."""
from pathlib import Path
from decimal import Decimal, ROUND_HALF_UP
import collections, csv, hashlib, json, re, statistics, subprocess, tarfile
P = Path(__file__).resolve().parent
ROOT = P.parents[2]
RELEASE = '0169e0e85cb08b83dc50e072bde9672db4e3d94e'
PREVIOUS = 'fb2a5550e177e18aebbe755440ad55ec09625b34'
def read(path): return json.loads((P/path).read_text())
def digest(path): return hashlib.sha256(path.read_bytes()).hexdigest()
def d(value): return Decimal(str(value))
def q(value): return float(d(value).quantize(Decimal('.1'), rounding=ROUND_HALF_UP))
def git(*args): return subprocess.check_output(['git', '-C', str(ROOT), *args])
s, r, prev = read('scorecard.json'), read('rubric-v1.json'), read('prior-audit/scorecard.json')
base = read('prior-audit/evidence/baseline-scorecard.json')
assert digest(P/'rubric-v1.json') == 'f4ecefe6861587da7b611eca3a7016f9597f74d8037b276a507eaa701ac71048'
assert s['rating_scale'] == r['rating_scale'] == prev['rating_scale'] == base['rating_scale']
criteria = [c for g in s['categories'] for c in g['criteria']]
original = [c for g in base['categories'] for c in g['criteria']]
prior = [c for g in prev['categories'] for c in g['criteria']]
rubric = [c for g in r['categories'] for c in g['criteria']]
assert len(criteria) == 27 and sum(d(c['weight']) for c in criteria) == 100
index = read('evidence-index.json')
for a,b,c,e in zip(criteria,original,rubric,prior):
    for key in ['id','name','weight','acceptance_checks']: assert a[key] == b[key] == c[key] == e[key]
    assert a['baseline_rating'] == b['rating'] and a['previous_rating'] == e['rating']
    assert a['audited_release'] == RELEASE
    assert a['rating'] in [None,0,1,2,3,4]
    assert a['earned_points'] == (None if a['rating'] is None else float(d(a['weight'])*d(a['rating'])/4))
    assert a['subtopic_score_out_of_100'] == (None if a['rating'] is None else a['rating']*25)
    assert all(x in index for x in a['evidence_ids'])
for g,rg in zip(s['categories'],r['categories']):
    assert (g['id'],g['name'],g['weight']) == (rg['id'],rg['name'],rg['weight'])
    earned=sum(d(c['earned_points'] or 0) for c in g['criteria'])
    unknown=sum(d(c['weight']) for c in g['criteria'] if c['rating'] is None)
    assert d(g['earned_points']) == earned and d(g['unverified_weight']) == unknown
    assert g['verified_minimum_out_of_100'] == q(earned/d(g['weight'])*100)
    assert g['possible_maximum_out_of_100'] == q((earned+unknown)/d(g['weight'])*100)
raw=sum(d(c['earned_points'] or 0) for c in criteria)
unknown=sum(d(c['weight']) for c in criteria if c['rating'] is None)
assert d(s['raw_score_unrounded']) == d(s['adjusted_score_unrounded']) == raw
assert s['raw_score'] == s['adjusted_score'] == q(raw)
assert d(s['unverified_weight']) == unknown
assert d(s['assessed_weight']) == d(s['evidence_coverage_percent']) == 100-unknown
assert s['possible_score_interval'] == [q(raw),q(raw+unknown)]
assert {c['id'] for c in criteria if c['rating'] is None} == {'I2'}
assert s['provisional'] and s['readiness_cap'] is None
comparison=list(csv.DictReader((P/'criterion-comparison.csv').open()))
assert len(comparison)==27
for row,criterion in zip(comparison,criteria):
    for key in ['id','name','acceptance_checks','reason']:assert row[key]==criterion[key]
    assert row['rating']==('' if criterion['rating'] is None else str(criterion['rating']))
assert base['raw_score_unrounded']==75.25 and base['raw_score']==75.3
assert prev['raw_score_unrounded']==87.25 and prev['raw_score']==87.3
# The accepted previous report remains byte-identical, including its own original archive.
paths=git('ls-tree','-r','--name-only',PREVIOUS,'docs/audits/20261020T-post-release').decode().splitlines()
assert len(paths)==43
for path in paths:
    relative=Path(path).relative_to('docs/audits/20261020T-post-release')
    assert (P/'prior-audit'/relative).read_bytes()==git('show',PREVIOUS+':'+path),str(relative)
with tarfile.open(P/'prior-audit/evidence/baseline-original-artifacts.tar.gz') as archive:
    assert archive.extractfile('20261020T063354Z/rubric-v1.json').read()==(P/'rubric-v1.json').read_bytes()
    assert archive.extractfile('20261020T063354Z/scorecard.json').read()==(P/'prior-audit/evidence/baseline-scorecard.json').read_bytes()
for item in index.values():
    for source in item.get('sources',[item.get('source')]):
        if source: assert (P/source).is_file(),source
rows=list(csv.DictReader((P/'flow-matrix.csv').open()))
old=list(csv.DictReader((P/'prior-audit/flow-matrix.csv').open()))
assert len(rows)==len(old)==83 and len({x['id'] for x in rows})==83
assert {x['id'] for x in rows}=={x['id'] for x in old}
assert dict(collections.Counter(x['status'] for x in rows))==s['flow_inventory']['status_counts']
for a,b in zip(rows,old):
    assert a['id']==b['id'] and a['previous_status']==b['status'] and a['previous_actual_result']==b['actual_result']
    assert a['baseline_status']==b['baseline_status'] and a['audited_release']==RELEASE
    assert all(x in index for x in a['evidence_ids'].split(';') if x)
findings={f['id']:f for f in read('findings.json')}
assert all(x in findings for row in rows for x in row['finding_ids'].split(';') if x)
assert all(findings[x]['status']=='RESOLVED WITHIN STATED SCOPE' for x in ['F06','F07','F09'])
assert next(x for x in rows if x['id']=='J22')['status']=='PARTIAL'
assert all(next(x for x in rows if x['id']==i)['status']=='VERIFIED WORKING' for i in ['J18','J26'])
modules=read('module-inventory.json')
assert len(modules)==57 and {x['module_key'] for x in rows if x['id'].startswith('M')}==set(modules)
assert read('evidence/final-ci-metadata.json')['conclusion']=='success'
assert read('evidence/final-ci-metadata.json')['headSha']==s['identifiers']['checked_ci_head']
assert read('evidence/code-quality-metadata.json')['conclusion']=='success'
dep=read('evidence/deployment.json');assert dep['conclusion']=='success' and dep['headSha']==RELEASE
jobs={j['name']:j['conclusion'] for j in dep['jobs']}
assert jobs['Test And Build Gate']==jobs['Migration Safety Gate']=='skipped'
assert 'verified_pr_tree' in (P/'evidence/independent-deploy-reuse.txt').read_text()
assert read('evidence/live-ready.json')['ready']=={'status':'ok','release':RELEASE}
smoke=read('evidence/live-smoke.json')['stats'];assert smoke['expected']==22 and smoke['unexpected']==smoke['flaky']==smoke['skipped']==0
runtime=read('evidence/runtime-after.json');assert runtime['release']==RELEASE and not any(runtime['marketplace_flags'].values()) and not runtime['verified_host_configured']
source=read('evidence/source-and-preservation.json')
assert source['audited_tree']==source['checked_tree']==git('rev-parse',RELEASE+'^{tree}').decode().strip()
assert source['checked_head']==s['identifiers']['checked_ci_head']
assert source['audit_branch']==s['identifiers']['audit_branch']
for file in read('evidence/source-review.json')['files']:assert digest(ROOT/file['path'])==file['sha256']
for ref,sha in source['source_tips'].items():assert git('rev-parse',ref).decode().strip()==sha
assert git('rev-parse','codex/everbranch-post-release-audit-20261020').decode().strip()==PREVIOUS
assert source['original_dirty_diff_sha256']=='b3a08d4f7b42156b1ad3df2879f63104110c56673354b7879e82c8ed33dbc874'
theme=ROOT.parent/'everbranch-theme'
if theme.exists():assert hashlib.sha256(subprocess.check_output(['git','-C',str(theme),'diff'])).hexdigest()==source['original_dirty_diff_sha256']
r4=read('evidence/r4/summary.json');assert r4['source_tree']==source['audited_tree'] and r4['source_sha']==s['identifiers']['checked_ci_head']
assert r4['synthetic_only'] and not r4['production_backup_restore_assurance']
assert read('evidence/r4/source-sql-identity.json')==read('evidence/r4/restored-sql-identity.json')
assert len(read('evidence/r4/source-sql-identity.json'))==475
for name,h in read('evidence/r4/independent-validation.json')['manifest'].items():assert digest(P/'evidence/r4'/name)==h
assert all(r4['customer_export_recovery']['blocked'].values())
timing=read('evidence/timing-independent-validation.json');assert timing['validation']=='PASS' and timing['criterion_X3_rating']==4
# The timing validator records which corrected run was accepted. All initial samples remain archived.
assert timing['selected_samples']==48 and timing['initial_samples_preserved']==48
samples=read('evidence/timings/samples.json')
summary=read('evidence/timings/summary.json')
initial=read('evidence/timings/initial-image-filtered/samples.json')
assert samples['expectedRelease']==samples['readyBefore']['release']==samples['readyAfter']['release']==RELEASE
assert samples['completedAt'] and 'failure' not in samples
assert len(samples['samples'])==len(initial['samples'])==48
assert digest(P/'evidence/timings/samples.json')==summary['raw_samples_sha256']==timing['raw_samples_sha256']
assert digest(P/'evidence/timings/collector.cjs')==samples['runner']['collectorSha256']
assert digest(P/'evidence/timings/initial-image-filtered/samples.json')==timing['initial_raw_samples_sha256']
groups=collections.defaultdict(list)
for sample in samples['samples']:
    assert sample['loaded'] and sample['status']==200 and not sample['errorResponses'] and not sample['pageErrorTypes']
    assert len(sample['blocked'])==len(sample['failedResources'])==1
    assert all(x['url'].startswith('https://static.cloudflareinsights.com/beacon.min.js/') and x['method']=='GET' for x in sample['blocked']+sample['failedResources'])
    assert all(x['complete'] and x['naturalWidth']>0 and x['naturalHeight']>0 for x in sample['metrics']['images'] if x['renderedInViewport'])
    groups[sample['profile'],sample['route']].append(sample)
assert len(groups)==len(summary['groups'])==16
for group in summary['groups']:
    group_samples=groups[group['profile'],group['route']]
    assert len(group_samples)==3 and {x['sample'] for x in group_samples}=={1,2,3}
    for key,stats in group['metrics_ms'].items():
        if key=='lcpObservedMs':values=[x['metrics']['lcpMs'] for x in group_samples if x['metrics']['lcpMs'] is not None]
        elif key in ['first-paint','first-contentful-paint']:values=[v['startTime'] for x in group_samples for v in x['metrics']['paints'] or [] if v['name']==key]
        else:values=[x['metrics']['navigation'][key] for x in group_samples if x['metrics']['navigation'] and x['metrics']['navigation'].get(key) is not None]
        if not values:assert stats is None;continue
        assert stats=={'count':len(values),'min':min(values),'median':statistics.median(values),'max':max(values)}
assert sum(len(x['failedResources']) for x in initial['samples'])==132
assert sum(v['url'].startswith('blob:') for x in initial['samples'] for v in x['blocked'])==84
for file in P.glob('*.md'):
    for target in re.findall(r'\]\(([^)]+)\)',file.read_text()):
        if not re.match(r'^(https?:|mailto:|#)',target):assert (file.parent/target.split('#')[0]).exists(),target
changes=git('diff','--name-only',RELEASE).decode().splitlines()
untracked=git('ls-files','--others','--exclude-standard').decode().splitlines()
assert all(x.startswith('docs/audits/20261020T-final-release/') for x in changes+untracked),changes+untracked
result={'validation':'PASS','criterion_count':27,'rubric_unchanged':True,'original_audit_and_accepted_previous_43_files_byte_preserved':True,'raw_score':str(raw),'half_up_score':q(raw),'unverified_weight':float(unknown),'unknown_ids':['I2'],'flow_count':83,'canonical_module_count':57,'audited_release':RELEASE,'checked_tree_equals_live_release_tree':True,'audit_only_changes':True,'source_tips_and_original_dirty_fingerprint_preserved':True,'note':'Artifact/provenance/arithmetic validation only, not app/provider/device acceptance.'}
(P/'validation.json').write_text(json.dumps(result,indent=2)+'\n')
if (P/'artifact-manifest.json').exists():
    manifest=read('artifact-manifest.json')
    # Exclude only this directory's manifest, not the prior audit's preserved manifest.
    actual={str(f.relative_to(P)) for f in P.rglob('*') if f.is_file() and f!=P/'artifact-manifest.json'}
    assert actual=={x['path'] for x in manifest}
    for item in manifest:
        file=P/item['path'];assert file.stat().st_size==item['bytes'] and digest(file)==item['sha256'],item['path']
print(json.dumps(result))
