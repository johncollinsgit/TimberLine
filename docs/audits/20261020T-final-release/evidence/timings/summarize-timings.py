#!/usr/bin/env python3
import collections, hashlib, json, pathlib, statistics, sys
source=pathlib.Path(sys.argv[1])
data=json.loads(source.read_text())
assert data['expectedRelease']==data['readyBefore']['release']==data['readyAfter']['release']
groups=collections.defaultdict(list)
for sample in data['samples']:
    groups[(sample['profile'],sample['route'])].append(sample)
summary=[]
def series(values):
    values=[v for v in values if v is not None]
    return {'count':len(values),'min':min(values),'median':statistics.median(values),'max':max(values)} if values else None
for (profile,route),samples in sorted(groups.items()):
    assert len(samples)==3
    row={'profile':profile,'route':route,'samples':len(samples),'status_samples':[s.get('status') for s in samples],
        'load_failure_count':sum(not s.get('loaded',False) for s in samples),
        'blocked_request_count':sum(len(s['blocked']) for s in samples),
        'failed_resource_count':sum(len(s['failedResources']) for s in samples),
        'error_response_count':sum(len(s['errorResponses']) for s in samples),
        'page_error_count':sum(len(s['pageErrorTypes']) for s in samples),'metrics_ms':{}}
    for name in ['responseStart','domContentLoadedEventEnd','loadEventEnd','duration']:
        row['metrics_ms'][name]=series([(s.get('metrics',{}).get('navigation') or {}).get(name) for s in samples])
    row['metrics_ms']['lcpObservedMs']=series([s.get('metrics',{}).get('lcpMs') for s in samples])
    for paint in ['first-paint','first-contentful-paint']:
        row['metrics_ms'][paint]=series([next((p['startTime'] for p in s.get('metrics',{}).get('paints',[]) or [] if p['name']==paint),None) for s in samples])
    row['unsupported_or_unobserved_reasons']=sorted(set(reason for s in samples for reason in [s.get('metrics',{}).get('lcpUnavailableReason'),s.get('metrics',{}).get('paintUnavailableReason')] if reason))
    summary.append(row)
assert len(groups)==16 and len(data['samples'])==48
out={'release':data['expectedRelease'],'raw_samples_sha256':hashlib.sha256(source.read_bytes()).hexdigest(),
    'sample_count':len(data['samples']),'groups':summary,'limits':data['method']['limits'],
    'not_a_lighthouse_speed_score':True,'not_real_user_percentiles':True,'physical_device_acceptance':False,
    'all_slow_failed_samples_preserved':True,'ready_pinned_before_after':True}
destination=source.with_name('summary.json')
destination.write_text(json.dumps(out,indent=2)+'\n')
print(json.dumps({'output':str(destination),'samples':len(data['samples']),'groups':len(groups),'failed_loads':sum(r['load_failure_count'] for r in summary)}))
