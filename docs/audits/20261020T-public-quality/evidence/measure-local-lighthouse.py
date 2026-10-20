import json, subprocess, time, urllib.request, glob, os, signal
from pathlib import Path
root=Path.cwd();out=Path(os.environ['TMPDIR'])/'everbranch-audit-r3ki6vn1'/'quality-lighthouse';out.mkdir(exist_ok=True)
chrome=sorted(glob.glob('/Users/johncollins/Library/Caches/ms-playwright/chromium-*/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing'))[-1]
env=dict(os.environ);env['CHROME_PATH']=chrome
serverlog=(out/'server.log').open('w')
server=subprocess.Popen(['sh','scripts/qa/serve-public-visual.sh'],stdout=serverlog,stderr=subprocess.STDOUT,env=env,start_new_session=True)
try:
 for _ in range(120):
  try:
   with urllib.request.urlopen('http://127.0.0.1:4177/platform/promo',timeout=1) as r:
    if r.status==200: break
  except Exception: time.sleep(.2)
 else: raise RuntimeError('Local server not ready')
 summary=[]
 for profile in ['desktop','mobile']:
  for name,path in [('home','/platform/promo'),('plans','/platform/plans'),('explorer','/explore/modules')]:
   for run in range(1,3):
    dest=out/f'{name}-{profile}-{run}.json'
    cmd=[str(root/'node_modules/.bin/lighthouse'),'http://127.0.0.1:4177'+path,'--only-categories=performance,accessibility,best-practices,seo','--output=json','--quiet','--output-path='+str(dest),'--chrome-flags=--headless --no-sandbox --disable-dev-shm-usage']
    if profile=='desktop':cmd.append('--preset=desktop')
    with dest.with_suffix('.log').open('w') as log:result=subprocess.run(cmd,env=env,stdout=log,stderr=subprocess.STDOUT,timeout=150)
    if result.returncode: raise RuntimeError(f'Lighthouse failed: {dest}')
    data=json.loads(dest.read_text());audits=data['audits']
    item={'route':path,'profile':profile,'run':run,'scores':{k:round(v['score']*100) for k,v in data['categories'].items()},'lcp_ms':audits['largest-contentful-paint']['numericValue'],'cls':audits['cumulative-layout-shift']['numericValue'],'tbt_ms':audits['total-blocking-time']['numericValue'],'failed_accessibility':[{'id':k,'title':v['title'],'details':v.get('details')} for k,v in audits.items() if v.get('score')==0 and v.get('scoreDisplayMode')=='binary' and k in [r['id'] for r in data['categories']['accessibility']['auditRefs']]]}
    summary.append(item);(out/'summary.json').write_text(json.dumps(summary,indent=2));print(json.dumps({k:v for k,v in item.items() if k!='failed_accessibility'}),flush=True)
finally:
 os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10);serverlog.close()
