import {test} from 'node:test';
import assert from 'node:assert/strict';
import {cardActivityHtml,cardMoney} from '../../resources/js/trajectory/card-activity.js';
import {esc,btn} from './trajectory-money-flow-fixture.mjs';

const card={account_id:71,name:'Fictional card <script>',currency:'EUR',from:'2028-01-01',through:'2028-07-25',posted_count:3,review_count:1,
 groups:[{key:'purchases',label:'Other card purchases',debits_cents:10001,credits_cents:1,net_outlay_cents:10000,ids:[701]},{key:'metals',label:'Gold and silver',debits_cents:25000,credits_cents:5000,net_outlay_cents:20000,ids:[702,703]}],
 latest_balance:{amount_cents:130000,observed_on:'2028-07-25'},purchase_allocation:{space_share_cents:0,outside_share_cents:10000},
 opening:{amount_cents:100000,sources:[{name:'Fictional RECONSTRUCTED opening',source:'A <reviewed> statement reconstruction',observed_on:'2028-01-01'}]},closing:{amount_cents:130000,sources:[]},
 jan1_balance_change_cents:30000,recorded_charges_cents:35001,recorded_credits_cents:5001,bridge:{reconciles:true,difference_cents:0},notes:['Posted records only; pending coverage unknown.','Repayments are not new purchases or proof that a particular payment retired prior-year principal.']};
const render=cards=>cardActivityHtml({cards,space_kind:'household'},{esc,btn});

test('card figures retain exact cents currency zero and unknown distinctions',()=>{
 assert.equal(cardMoney(10001,'EUR'),'€100.01');assert.equal(cardMoney(0,'USD'),'$0.00');
 assert.equal(cardMoney(null,'USD'),'Unavailable');assert.equal(cardMoney(1,null),'Unavailable');assert.equal(cardMoney(1,'US'),'Unavailable');assert.equal(cardMoney(2**54,'USD'),'Unavailable');
 const html=render([card]);assert.ok(html.includes('€200.00'));assert.ok(html.includes('Household share'));assert.ok(html.includes('€0.00'));
 assert.ok(html.includes('already within the card amount'));assert.ok(html.includes('data-account-id="71"'));assert.ok(html.includes('data-ids="[702,703]"'));
 assert.ok(html.includes('Fictional card &lt;script&gt;'));assert.ok(!html.includes('<script>'));assert.ok(html.includes('A &lt;reviewed&gt; statement'));
 assert.ok(html.includes('RECONSTRUCTED'));assert.ok(html.includes('History coverage may still be partial'));assert.ok(html.includes('not verified purchases or repayments'));
});

test('missing activity and missing boundary evidence never render verified zero purchases or a fabricated opening',()=>{
 const html=render([{...card,posted_count:0,review_count:0,groups:[],opening:{amount_cents:null},closing:{amount_cents:null},jan1_balance_change_cents:null,bridge:{reconciles:false,difference_cents:null}}]);
 assert.ok(html.includes('No posted card activity'));assert.ok(html.includes('does not establish zero'));assert.ok(!html.includes('tr-card-figures'));
 assert.ok(html.includes('balance equation is unavailable'));assert.ok(!html.includes('Recorded charges and credits reconcile'));assert.ok(!html.includes('higher since January 1'));
});

test('an unresolved balance difference remains visible and no complete source or prior-principal payoff is claimed',()=>{
 const html=render([{...card,bridge:{reconciles:false,difference_cents:1234}}]);
 assert.ok(html.includes('does not reconcile'));assert.ok(html.includes('€12.34'));assert.ok(!html.includes('tr-card-equation'));
 assert.ok(html.includes('not new purchases or proof that a particular payment retired prior-year principal'));
 assert.ok(html.includes('Pending activity may be missing'));assert.ok(!html.includes('pending $0'));
 const empty=render([]);assert.ok(empty.includes('No owned credit cards'));
});

test('future period is unavailable while its separately dated latest balance remains visible',()=>{
 const html=render([{...card,from:'2029-01-01',through:'2029-03-31',period_available:false,posted_count:0,groups:[],opening:{amount_cents:null},closing:{amount_cents:null},jan1_balance_change_cents:null,bridge:{reconciles:false,difference_cents:null}}]);
 assert.ok(html.includes('report period is in the future'));assert.ok(html.includes('separate dated observation'));
 assert.ok(html.includes('As of 2028-07-25'));assert.ok(!html.includes('tr-card-figures'));
});
