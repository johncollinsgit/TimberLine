import {test} from 'node:test';
import assert from 'node:assert/strict';
import {planOverview} from '../../resources/js/trajectory/plan-overview.js';
const data={workspace_settings:{debt_extra_cents:10000},planning:{expected_monthly_cents:100000,fixed_monthly_cents:60000,flexible_monthly_cents:20000,goals_monthly_cents:20000,monthly:[{date:'2028-02-29',expected_income_cents:100000}]}};
test('optional goals are limited to surplus after expenses and extra debt without changing forecast or saved targets',()=>{
 const copy=structuredClone(data),view=planOverview(data);assert.equal(view.core,80000);assert.equal(view.extra,10000);assert.equal(view.goals,10000);assert.equal(view.surplus,0);assert.equal(view.gap,0);assert.deepEqual(data,copy);
 const short=planOverview({...data,planning:{...data.planning,expected_monthly_cents:70000}});assert.equal(short.gap,10000);assert.equal(short.extra,0);assert.equal(short.goals,0);
});
