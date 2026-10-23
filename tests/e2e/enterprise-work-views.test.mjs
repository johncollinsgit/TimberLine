import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';
const script=await readFile(new URL('../../resources/js/enterprise/work-views.js',import.meta.url),'utf8');
const context=vm.createContext({Intl,Date});vm.runInContext(script,context);const views=context.EverbranchWorkViews,plain=value=>JSON.parse(JSON.stringify(value));
test('month grids contain 42 consecutive literal dates, leap days and Sunday-first weekday alignment',()=>{
 for(const month of ['2026-10','2026-11','2024-02','2027-01']){const days=views.monthDays(month);assert.equal(days.length,42);assert.equal(new Date(days[0].date+'T12:00:00Z').getUTCDay(),0);for(let i=1;i<42;i++)assert.equal(Date.parse(days[i].date)-Date.parse(days[i-1].date),86400000);}
 assert.ok(views.monthDays('2024-02').some(day=>day.date==='2024-02-29'&&day.inMonth));assert.equal(views.dateOnly('2026-02-29'),null);assert.equal(views.dateOnly('2026-10-23T12:00:00Z'),null);assert.equal(views.shiftMonth('2026-12',1),'2027-01');assert.equal(views.shiftMonth('1900-01',-1),null);assert.throws(()=>views.monthDays('2026-13'));
});
test('map points require current saved identity/version and exact source provenance; supplied labels have no authority',()=>{
 const buildings=[{id:'building',version:2,map_number:'52',source_url:views.mapSource}],row={data:{location_id:'building',location_version:2}};
 assert.equal(views.mapNumber(row,buildings),'52');
 for(const bad of [{data:{location:'Bryan Hall',latitude:36,longitude:-81}},{data:{location_id:'foreign',location_version:2}},{data:{location_id:'building',location_version:1}},{location_reference:{id:'building',version:2,map_number:'52',source_url:views.mapSource}}])assert.equal(views.mapNumber(bad,buildings),null);
 assert.equal(views.mapNumber({...row,location_reference:{id:'fake',map_number:'1'}},buildings),'52','Current saved identity wins; supplied reference labels cannot relocate it');
 assert.equal(views.mapNumber(row,[{...buildings[0],source_url:'https://untrusted.invalid/map.pdf'}]),null);assert.equal(views.mapNumber(row,[{...buildings[0],map_number:'69'}]),null);
});
test('project tasks keep current native assignments and saved-building joins without duplicate task events or foreign-project leakage',()=>{
 const data={projects:[{id:'p',name:'Project',tasks:[{id:'t',title:'Task',assignee_id:'u',due_on:'2026-11-03'}]}],members:[{id:'u',name:'Synthetic worker'}],buildings:{items:[{id:'b',version:1,map_number:'2',source_url:views.mapSource}]},requests:[{id:'r',data:{project_id:'p',location_id:'b',location_version:1},task:{id:'t',title:'Task'}},{id:'hidden',data:{project_id:'other'},task:{id:'not-visible',title:'Not visible'}}]};
 const events=plain(views.workEvents(data,'projects',data.projects));assert.equal(events.length,1);assert.equal(events[0].assignee,'Synthetic worker');assert.equal(events[0].mapNumber,'2');assert.equal(events[0].dueOn,'2026-11-03');assert.equal(events[0].openId,'p');assert.equal(events[0].title,'Task');
 assert.deepEqual(plain(views.workEvents(data,'projects',[])),[]);
});
test('resource reservations preserve half-open UTC instants and Eastern DST date boundaries',()=>{
 const days=views.monthDays('2026-11').map(day=>day.date);
 assert.deepEqual(plain(views.reservationDates({data:{starts_at:'2026-11-01T04:00:00Z',ends_at:'2026-11-02T05:00:00Z'}},days)),['2026-11-01']);
 assert.deepEqual(plain(views.reservationDates({data:{starts_at:'2026-11-01T05:30:00Z',ends_at:'2026-11-01T06:30:00Z'}},days)),['2026-11-01']);
 assert.deepEqual(plain(views.reservationDates({data:{starts_at:'bad',ends_at:'2026-11-02T05:00:00Z'}},days)),[]);
});
test('all 68 source positions are finite page percentages and the source artifact remains hash-pinned',async()=>{
 const points=JSON.parse(await readFile(new URL('../../resources/data/enterprise/campus-map-points.json',import.meta.url),'utf8'));assert.equal(points.source_url,views.mapSource);assert.equal(points.source_page,1);assert.equal(Object.keys(points.points).length,68);for(let i=1;i<=68;i++){const point=points.points[i];assert.ok(point&&Number.isFinite(point.x)&&Number.isFinite(point.y)&&point.x>=0&&point.x<=100&&point.y>=0&&point.y<=100);}assert.equal(points.source_sha256,'d2daae03a679d9c93f8b62dbe289734c09e4c57f127f09ca258f85713eae5839');
});
