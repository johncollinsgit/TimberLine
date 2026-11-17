import {test} from 'node:test';
import assert from 'node:assert/strict';
import {salesTaxHtml,taxRemittanceReview} from '../../resources/js/trajectory/sales-tax.js';
import {html,esc,precise,btn} from './trajectory-sales-tax-fixture.mjs';
test('sales tax table preserves cash components unknown types periods and bank evidence IDs',()=>{
 const rendered=html();
 for(const text of ['Sales tax payments','Confirmed sales-tax remittance cash','$451.23','$100.00','$351.23','Tax $450.00','Interest $1.23','Unknown split','Unknown tax type','2028-06-01','data-ids="[42]"','tax-annotate'])assert.ok(rendered.includes(text),text);
 assert.ok(rendered.includes('&lt;script&gt;'));assert.ok(!rendered.includes('<script>'));
 const absent=salesTaxHtml({report:{rows:[],notes:[]},esc,precise,btn});assert.equal((absent.match(/Unavailable/g)||[]).length,4);assert.ok(!absent.includes('$0.00'));
});

test('tax forms bind current bank facts and require explicit review on each edit',()=>{
 const fingerprint='a'.repeat(64);
 const row={transaction_id:42,date:'2028-07-20',account:'Example bank',merchant:'Current revenue agency',amount_cents:-45123,bank_source_fingerprint:fingerprint};
 const record={data:{transaction_id:42,paid_on:row.date,amount_cents:row.amount_cents,bank_source_fingerprint:fingerprint,agency:'Verified agency',tax_type:'sales_tax',movement:'payment',source:'Verified receipt',reviewed:true,tax_principal_cents:45123,interest_cents:0,penalty_cents:0,other_fees_cents:0}};
 const edit=taxRemittanceReview(record,row);
 assert.equal(edit.expected_bank_source_fingerprint,fingerprint);
 assert.equal(edit.reviewed,false);
 assert.equal(edit.agency,'Verified agency');
 assert.equal(edit.interest_cents,0);
 assert.equal(record.data.reviewed,true);
 const changed=taxRemittanceReview(record,{...row,merchant:'Different agency',bank_source_fingerprint:'b'.repeat(64)});
 assert.equal(changed.agency,'Different agency');
 assert.equal(changed.tax_type,'unknown');
 assert.equal(changed.source,'');
 assert.equal(changed.reviewed,false);
 assert.ok(!('tax_principal_cents' in changed));
 assert.equal(changed.expected_bank_source_fingerprint,'b'.repeat(64));
 const credit=taxRemittanceReview(null,{...row,amount_cents:45123});
 assert.equal(credit.movement,'');
 assert.equal(credit.reviewed,false);
 assert.equal(taxRemittanceReview(record,{...row,bank_source_fingerprint:undefined}),null);
 assert.equal(taxRemittanceReview(record,null),null);
});

test('draft tax classification is labeled unconfirmed near both agency and tax type',()=>{
 const draft=salesTaxHtml({report:{rows:[{date:'2028-07-20',agency:'Fictional revenue agency',tax_type:'sales_tax',reviewed:false,amount_cents:-12345}],notes:[]},esc,precise,btn});
 assert.ok(draft.includes('Sales tax · not confirmed'));
 assert.ok(draft.includes('Tax type not confirmed'));
 const reviewed=salesTaxHtml({report:{rows:[{date:'2028-07-20',agency:'Fictional revenue agency',tax_type:'sales_tax',reviewed:true,amount_cents:-12345}],notes:[]},esc,precise,btn});
 assert.ok(!reviewed.includes('not confirmed'));
});

test('verified tax filing still exposes a separate bank purpose review without promoting draft evidence',()=>{
 const reviewed=salesTaxHtml({report:{rows:[{transaction_id:77,date:'2028-07-20',agency:'Fictional <agency>',tax_type:'sales_tax',reviewed:true,bank_reviewed:false,bank_flow:'expense',amount_cents:-10000}],notes:[]},esc,precise,btn});
 assert.ok(reviewed.includes('Review bank purpose'));assert.ok(reviewed.includes('tax-bank-review'));assert.ok(reviewed.includes('Fictional &lt;agency&gt;'));
 const bound=salesTaxHtml({report:{rows:[{transaction_id:77,tax_type:'sales_tax',reviewed:true,bank_flow:'sales_tax_remittance'}],notes:[]},esc,precise,btn});
 assert.ok(!bound.includes('tax-bank-review'));
});
