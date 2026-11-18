import {test} from 'node:test';
import assert from 'node:assert/strict';
import {visualBootstrapMetadata,isVisualContext,visualContextPath,validateVisualContext} from '../../resources/js/trajectory/bootstrap.js';

const space={id:7,name:'Fictional household',kind:'household'};
const report={today:'2028-02-15',range:{from:'2028-02-01',through:'2028-02-15'},scope:{space_id:7,kind:'household',account_id:null,program_id:null,basis:'spending'},account_options:[{id:9,name:'Fictional checking'}]};
const budget={month:'2028-02',actual_through:'2028-02-15'};
test('primary catalogs are explicitly metadata, scoped and never mistaken for a full Dashboard',()=>{
 const value={projection:'visual_context',available:['editing_catalogs','monthly_bills'],space,today:'2028-02-15',scope_filter:{account_id:null},records:[],category_options:[],accounts:[],definitions:{}};
 assert.equal(isVisualContext(value),true);assert.equal(isVisualContext({summary:{}}),false);
 assert.equal(visualContextPath(),'/visual-context');assert.equal(visualContextPath(9),'/visual-context?account_id=9');
 assert.equal(validateVisualContext(value,space),value);
 for(const changed of [{projection:'dashboard'},{space:{...space,id:8}},{scope_filter:{account_id:9}},{today:null},{available:[]}])assert.throws(()=>validateVisualContext({...value,...changed},space));
});
test('progressive metadata uses only authorized server context and contains no invented financial fields',()=>{
  const value=visualBootstrapMetadata(report,budget,space);
  assert.deepEqual(value.range,{start:'2028-02-01',end:'2028-02-15'});
  assert.deepEqual(value.space,space);assert.equal(value.today,'2028-02-15');
  for(const key of ['summary','coverage','records','planning','accounts'])assert.equal(Object.hasOwn(value,key),false,key);
});
test('cross-space, wrong-account, mismatched dates/programs and stale bootstrap cohorts are rejected',()=>{
  for(const changed of [{scope:{...report.scope,space_id:8}},{scope:{...report.scope,account_id:9}},{scope:{...report.scope,program_id:11}},{scope:{...report.scope,kind:'business'}},{scope:{...report.scope,basis:'cash'}},{range:{from:'2028-01-01',through:'2028-01-31'}}])assert.throws(()=>visualBootstrapMetadata({...report,...changed},budget,space));
  assert.throws(()=>visualBootstrapMetadata(report,{...budget,month:'2028-01'},space));
  assert.throws(()=>visualBootstrapMetadata(report,{...budget,actual_through:'2028-02-14'},space));
  const account=visualBootstrapMetadata({...report,scope:{...report.scope,account_id:9}},budget,space,9);
  assert.equal(account.scope_filter.label,'Fictional checking');
});

const {visualBootstrapRequest,deriveVisualPeriod,createRangeSnapshotCache}=await import('../../resources/js/trajectory/bootstrap.js');
const monthReport={...structuredClone(report),year:2028,scope:{...report.scope,calendar_range:'month'},
 budget_context:{month:'2028-02',actual_through:'2028-02-15'},
 calendar_ranges:{day:{from:'2028-02-15',through:'2028-02-15',covered:false,has_evidence:true},week:{from:'2028-02-14',through:'2028-02-15',covered:false,has_evidence:true},month:{from:'2028-02-01',through:'2028-02-15',covered:false,has_evidence:true},year:{from:'2028-01-01',through:'2028-02-15',covered:false,has_evidence:true}},
 summary:{inflow_cents:40000,spending_cents:null,recorded_spending_cents:16000},
 entries:[{id:1,entry_key:'e:1:food',date:'2028-02-02',amount_cents:-12500,direction:'out'},
  {id:1,entry_key:'e:1:office',date:'2028-02-02',amount_cents:-1000,direction:'out'},
  {id:2,entry_key:'e:2',date:'2028-02-15',amount_cents:-3000,direction:'out'},
  {id:3,entry_key:'e:3:refund',date:'2028-02-15',amount_cents:500,direction:'out'},
  {id:10,entry_key:'cash:10',date:'2028-02-14',amount_cents:40000,direction:'in'}],
 categories:[{key:'food',amount_cents:15000,ids:[1,2,3],entry_keys:['e:1:food','e:2','e:3:refund'],children:[
  {key:'groceries',amount_cents:12500,ids:[1],entry_keys:['e:1:food']},
  {key:'dining',amount_cents:2500,ids:[2,3],entry_keys:['e:2','e:3:refund']}]},
  {key:'office',amount_cents:1000,ids:[1],entry_keys:['e:1:office'],children:[]}],
 inflows:[{key:'business',amount_cents:40000,ids:[10],entry_keys:['cash:10'],children:[]}],
 coverage:{history_available:true,latest_recorded_on:'2028-02-15',selected_range_has_evidence:true,spending:{complete:false,known_cost_cents:16000,unresolved_ids:[20],unresolved_gross_cents:12345,review_rows:[{id:20,date:'2028-02-03',merchant:'Fictional tax',bank_amount_cents:-12345}]}}
};
test('period requests use canonical server presets and explicitly dated historical budget months',()=>{
 assert.equal(visualBootstrapRequest({range:'day',accountId:9}).analysisPath,'/analysis?grain=month&basis=spending&range=day&account_id=9');
 assert.equal(visualBootstrapRequest({range:'custom',from:'2028-01-01',through:'2028-01-31'}).budgetPath,'/budget-envelopes?month=2028-01');
 assert.throws(()=>visualBootstrapRequest({range:'custom',from:'2028-02-15',through:'2028-02-01'}));
 assert.throws(()=>visualBootstrapRequest({range:'unsupported'}));
});
test('a current month snapshot produces exact day/week subsets without duplicate attributed cents or a data request',()=>{
 const before=structuredClone(monthReport),day=deriveVisualPeriod(monthReport,budget,{range:'day'});
 assert.deepEqual(day.report.summary,{inflow_cents:0,spending_cents:2500,recorded_spending_cents:2500});
 assert.equal(day.report.categories[0].children.length,1);assert.equal(day.report.categories[0].children[0].key,'dining');
 assert.equal(day.report.categories.reduce((sum,row)=>sum+row.amount_cents,0),2500);
 assert.equal(day.report.coverage.spending.complete,true);assert.deepEqual(day.report.coverage.spending.review_rows,[]);
 const week=deriveVisualPeriod(monthReport,budget,{range:'week'});assert.equal(week.report.summary.inflow_cents,40000);
 assert.equal(visualBootstrapMetadata(day.report,day.budgets,space,null,{range:'day'}).range.start,'2028-02-15');
 assert.deepEqual(monthReport,before);assert.deepEqual(day.budgets,budget);
 assert.equal(deriveVisualPeriod(monthReport,budget,{range:'year'}),null);
});
test('no recorded activity with incomplete history remains unknown rather than becoming a fake zero',()=>{
 const empty=structuredClone(monthReport);empty.entries=empty.entries.filter(row=>row.date!=='2028-02-15');
 empty.calendar_ranges.day.has_evidence=false;
 const day=deriveVisualPeriod(empty,budget,{range:'day'}).report;
 assert.equal(day.summary.spending_cents,null);assert.equal(day.summary.inflow_cents,null);assert.equal(day.summary.recorded_spending_cents,null);
 assert.equal(day.coverage.history_available,true);assert.deepEqual(day.entries,[]);
 const exact=deriveVisualPeriod(monthReport,budget,{range:'month'}).report;assert.equal(exact.summary.spending_cents,null);assert.equal(exact.coverage.spending.unresolved_gross_cents,12345);
});
test('range snapshots are memory-only, scoped, bounded by age, and fully cleared by a real refresh',()=>{
 let at=0;const cache=createRangeSnapshotCache({now:()=>at});cache.remember('household:7:all:spending',monthReport,budget);
 assert.equal(cache.find('business:8:all:spending',{range:'day'}),null);
 assert.equal(cache.find('household:7:account9:spending',{range:'day'}),null);
 assert.equal(cache.find('household:7:all:spending',{range:'day'}).report.summary.spending_cents,2500);
 at=120000;assert.equal(cache.find('household:7:all:spending',{range:'day'}).report.summary.spending_cents,2500,'Returning after reading a category still uses the exact dated snapshot');
 at=300000;assert.equal(cache.find('household:7:all:spending',{range:'day'}),null);
 at=-1;assert.equal(cache.find('household:7:all:spending',{range:'day'}),null);
 at=1;cache.clear();assert.equal(cache.find('household:7:all:spending',{range:'day'}),null);
});
