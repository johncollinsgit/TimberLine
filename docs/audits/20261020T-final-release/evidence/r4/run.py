#!/usr/bin/env python3
"""Private synthetic recovery drill. No external services or existing DB instance."""
import datetime, gzip, hashlib, json, os, pathlib, subprocess, time
ROOT = pathlib.Path(__file__).resolve().parent
REPO = ROOT.parents[1] / 'integration'
MYSQL = '/opt/homebrew/bin/mysql'
SOURCE, TARGET = 'everbranch_r4_source_test', 'everbranch_r4_restore_test'
GUARD = 'everbranch_r4_guard_production'
OUT = ROOT / ('evidence-' + datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ'))
OUT.mkdir(mode=0o700)
BIN = ROOT / 'bin'
BIN.mkdir(exist_ok=True)
(BIN/'mysql').write_text('#!/bin/sh\nexec /opt/homebrew/bin/mysql --no-defaults "$@"\n')
(BIN/'mysql').chmod(0o700)
env = os.environ.copy()
env.update(APP_ENV='testing', APP_KEY='base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
    APP_URL='http://localhost', DB_CONNECTION='mysql', DB_HOST='127.0.0.1', DB_PORT='33318',
    DB_DATABASE=SOURCE, DB_USERNAME='root', DB_PASSWORD='', CACHE_STORE='array', SESSION_DRIVER='array',
    QUEUE_CONNECTION='sync', MAIL_MAILER='array', LOG_CHANNEL='stderr',
    VIEW_COMPILED_PATH=str(OUT/'views'), R4_REPO=str(REPO), PATH=str(BIN)+':'+env['PATH'],
    PULSE_ENABLED='false', TELESCOPE_ENABLED='false', NIGHTWATCH_ENABLED='false')
commands=[]
def run(args, label, database=SOURCE, stdin=None, expected=0):
    local=env.copy(); local['DB_DATABASE']=database
    start=time.monotonic()
    result=subprocess.run(args, cwd=REPO, env=local, input=stdin, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    commands.append(dict(label=label, argv=args, database=database, exit_status=result.returncode, seconds=round(time.monotonic()-start,3)))
    (OUT/(label+'.stdout')).write_bytes(result.stdout)
    (OUT/(label+'.stderr')).write_bytes(result.stderr)
    (OUT/'commands-private.json').write_text(json.dumps(commands,indent=2))
    if result.returncode != expected:
        raise RuntimeError(f'{label}: exit {result.returncode}, expected {expected}; see local logs {OUT.name}')
    return result.stdout
base=[MYSQL,'--no-defaults','--protocol=TCP','--host=127.0.0.1','--port=33318','--user=root','--batch','--skip-column-names']
def sql(query, database=None):
    args=base+([database] if database else [])+['-e',query]
    r=subprocess.run(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=True)
    return r.stdout.decode()
def data_identity(database):
    result={}
    tables=sql('SHOW TABLES',database).splitlines()
    for table in tables:
        columns=sql('SHOW COLUMNS FROM `'+table+'`',database).splitlines()
        names=[x.split('\t')[0] for x in columns]
        expr=','.join('`'+x.replace('`','``')+'`' for x in names)
        rows=sql('SELECT JSON_ARRAY('+expr+') FROM `'+table+'`',database).splitlines()
        canonical=sorted(json.dumps(json.loads(row),sort_keys=True,separators=(',',':'),ensure_ascii=False) for row in rows)
        result[table]={'rows':len(rows),'sha256':hashlib.sha256(('\n'.join(canonical)).encode()).hexdigest()}
    return result
def sql_relationships(database):
    rows=sql('SELECT j.id,j.tenant_id,p.id,p.tenant_id,t.id,t.tenant_id,f.id,f.tenant_id,f.marketing_profile_id,u.id '
        'FROM field_service_jobs j JOIN marketing_profiles p ON p.id=j.marketing_profile_id '
        'JOIN field_service_tasks t ON t.field_service_job_id=j.id '
        'JOIN field_service_financial_documents f ON f.field_service_job_id=j.id '
        'JOIN users u ON u.id=j.assigned_user_id ORDER BY j.id',database).splitlines()
    parsed=[list(map(int,row.split('\t'))) for row in rows]
    assert len(parsed)==4
    for row in parsed:
        assert row[1]==row[3]==row[5]==row[7] and row[2]==row[8]
    totals=sql('SELECT tenant_id,COUNT(*),SUM(total_amount),SUM(balance) FROM field_service_financial_documents GROUP BY tenant_id ORDER BY tenant_id',database).splitlines()
    assert [r.split('\t')[1:] for r in totals]==[['2','246.90','246.90'],['2','246.90','246.90']]
    ledger=sql('SELECT a.tenant_id,a.balance_micros,SUM(l.amount_micros),COUNT(*) FROM tenant_messaging_credit_accounts a '
        'JOIN tenant_messaging_ledger_entries l ON l.tenant_messaging_credit_account_id=a.id AND l.tenant_id=a.tenant_id '
        'GROUP BY a.id,a.tenant_id,a.balance_micros ORDER BY a.tenant_id',database).splitlines()
    assert [r.split('\t')[1:] for r in ledger]==[['5000000','5000000','1'],['5000000','5000000','1']]
    return {'job_customer_task_invoice_user_joins':parsed,'invoice_totals':totals,'ledger_totals':ledger}
sha=run(['git','rev-parse','HEAD'],'source-sha').decode().strip()
tree=run(['git','rev-parse','HEAD^{tree}'],'source-tree').decode().strip()
assert not run(['git','status','--porcelain'],'source-clean').strip(), 'Drill requires a clean exact-source checkout'
run(['php','-l',str(ROOT/'probe.php')],'php-lint')
assert pathlib.Path(sql('SELECT @@datadir').strip()).resolve() == (ROOT/'mysql-private-data').resolve(), 'Wrong MySQL instance'
sql(f'DROP DATABASE IF EXISTS `{SOURCE}`; DROP DATABASE IF EXISTS `{TARGET}`; DROP DATABASE IF EXISTS `{GUARD}`; CREATE DATABASE `{SOURCE}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE `{TARGET}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE `{GUARD}`; CREATE TABLE `{GUARD}`.sentinel (id INT PRIMARY KEY, value VARCHAR(20)); INSERT INTO `{GUARD}`.sentinel VALUES (1,"unchanged");')
run(['php','artisan','migrate','--force','--no-interaction'],'source-schema')
run(['php','artisan','schema:fingerprint','--expect-file=database/schema/mysql-schema.sha256','--no-interaction'],'source-fingerprint')
run(['php',str(ROOT/'probe.php'),str(REPO),'seed',str(OUT)],'app-seed-and-export')
before=data_identity(SOURCE)
relations_before=sql_relationships(SOURCE)
(OUT/'source-sql-identity.json').write_text(json.dumps(before,indent=2))
raw=run(['/opt/homebrew/bin/mysqldump','--no-defaults','--protocol=TCP','--host=127.0.0.1','--port=33318','--user=root',
    '--single-transaction','--quick','--skip-lock-tables','--routines','--triggers','--events','--no-tablespaces','--set-gtid-purged=OFF',SOURCE],'mysqldump')
backup=OUT/'synthetic-backup.sql.gz'
backup.write_bytes(gzip.compress(raw,mtime=0))
assert gzip.decompress(backup.read_bytes())==raw
guard_before=sql('SELECT * FROM sentinel',GUARD)
run(['bash','scripts/ops/verify-mysql-backup-restore.sh',str(backup)],'reject-non-test-target',database=GUARD,expected=1)
assert sql('SELECT * FROM sentinel',GUARD)==guard_before
run(['bash','scripts/ops/verify-mysql-backup-restore.sh',str(backup)],'guarded-restore',database=TARGET)
after=data_identity(TARGET)
relations_after=sql_relationships(TARGET)
assert relations_before==relations_after
assert before==after,'Independent SQL data identity differs after restore'
(OUT/'restored-sql-identity.json').write_text(json.dumps(after,indent=2))
run(['php','artisan','schema:fingerprint','--expect-file=database/schema/mysql-schema.sha256','--no-interaction'],'restore-fingerprint',database=TARGET)
run(['php',str(ROOT/'probe.php'),str(REPO),'verify',str(OUT)],'app-read-restored-and-export',database=TARGET)
source=json.loads((OUT/'seed-app-read.json').read_text()); restored=json.loads((OUT/'verify-app-read.json').read_text())
assert source['graph']==restored['graph'] and source['relationships']==restored['relationships']
for tenant in ['r4-fictional-a','r4-fictional-b']:
    a=json.loads((OUT/('seed-'+tenant+'.json')).read_text()); b=json.loads((OUT/('verify-'+tenant+'.json')).read_text())
    assert a['data']==b['data'] and a['row_counts']==b['row_counts']
    for payload in [a,b]:
        fields={k:v for k,v in payload.items() if k!='checksum_sha256'}
        encoded=json.dumps(fields,ensure_ascii=False,separators=(',',':'))
        assert hashlib.sha256(encoded.encode()).hexdigest()==payload['checksum_sha256']
    # Independently verify actual file checksum, tenant ownership, required identities and links.
    assert len(a['data']['marketing_profiles'])==2
    assert len(a['data']['marketing_profile_links'])==2
    assert len(a['data']['customer_external_profiles'])==2
    ids={r['id'] for r in a['data']['marketing_profiles']}
    for table in ['marketing_profile_links','customer_external_profiles']:
        assert all(r['marketing_profile_id'] in ids and r['tenant_id']==a['tenant']['id'] for r in a['data'][table])
run(['php',str(ROOT/'probe.php'),str(REPO),'customer-recovery',str(OUT)],'customer-export-recovery-and-boundaries',database=TARGET)
customer=json.loads((OUT/'customer-recovery-result.json').read_text())
assert all(customer['blocked'].values()) and customer['all_export_data_equal'] and customer['foreign_rows_unchanged']
summary={'source_sha':sha,'source_tree':tree,'mysql_version':source['mysql_version'],'synthetic_only':True,'production_backup_restore_assurance':False,
    'sql_tables_compared':len(before),'populated_tables':{k:v['rows'] for k,v in before.items() if v['rows']},
    'all_table_row_hashes_equal':before==after,'app_relationships':source['relationships'],'app_reads_equal':True,
    'independent_sql_relationships_equal':relations_before==relations_after,'sql_relationships':relations_before,
    'actual_operator_export_route':True,'export_scope':'11-table landlord marketing/customer snapshot; excludes jobs/ledger',
    'customer_export_recovery':customer,'guard_non_test_rejected_unchanged':True,
    'backup_sha256':hashlib.sha256(backup.read_bytes()).hexdigest(),'backup_bytes':backup.stat().st_size,
    'uncompressed_bytes':len(raw),'export_hashes':{p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in OUT.glob('*.json') if p.name.startswith(('seed-r4','verify-r4','customer-before','customer-after'))},
    'private_payloads_in_committed_evidence':False,'commands':[{'label':c['label'],'exit_status':c['exit_status'],'seconds':c['seconds']} for c in commands]}
(OUT/'summary.json').write_text(json.dumps(summary,indent=2))
print(json.dumps({'passed':True,'evidence_directory':str(OUT),'source_sha':sha,'tables':len(before),'backup_sha256':summary['backup_sha256']}))
