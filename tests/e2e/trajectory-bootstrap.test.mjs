import {test} from 'node:test';
import assert from 'node:assert/strict';
import {visualBootstrapMetadata} from '../../resources/js/trajectory/bootstrap.js';

const space={id:7,name:'Fictional household',kind:'household'};
const report={today:'2028-02-15',range:{from:'2028-02-01',through:'2028-02-15'},scope:{space_id:7,kind:'household',account_id:null,program_id:null,basis:'spending'},account_options:[{id:9,name:'Fictional checking'}]};
const budget={month:'2028-02',actual_through:'2028-02-15'};
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
