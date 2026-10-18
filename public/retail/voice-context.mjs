// A voice turn needs current facts, rather than repeated UI/catalog snapshots.
// The full records remain available to the UI and never change during projection.
const pick=(value,keys)=>Object.fromEntries(keys.filter(k=>value?.[k]!==undefined).map(k=>[k,value[k]]));
function customer(c){return c?pick(c,['id','name','favorite_knife','orders','order_count','latest_order','notes','sample_staff_notes','signals','score','contact_permission','reward_points','reward_activity']):null;}
export function compactVoiceResult(result){
 if(!result||typeof result!=='object')return {ok:false,message:'No demo context was returned.'};
 const r=pick(result,['ok','merchant','fictional','view','form','message','reused','invoice','customer_count','invoice_count','ranking','delivery','selection','finder']);
 if(result.focused_customer)r.focused_customer=customer(result.focused_customer);
 if(result.marketing_draft)r.marketing_draft=pick(result.marketing_draft,['channel','customer_name','product_id','variant_id','subject','body','sms_body']);
 if(result.created_customer)r.created_customer=customer(result.created_customer);
 if(result.suggestions)r.suggestions=result.suggestions.slice(0,10).map(c=>pick(c,['id','name','signals','contact_permission']));
 if(result.customers){r.customers=result.customers.slice(0,50).map(c=>pick(c,['id','name','favorite_knife','order_count']));r.customers_truncated=result.customers.length>50;}
 // Every offered handle and its price remain usable by the tools; names are
 // accepted as exact variant references. Avoid repeating long Shopify IDs.
 if(result.catalog)r.catalog=result.catalog.map(p=>({id:p.id,name:p.name,variants:p.variants.map(v=>pick(v,['name','price_cents','available_snapshot']))}));
 if(result.invoices&&result.view==='invoices')r.invoices=result.invoices.slice(-10);
 if(result.workshop){const w=result.workshop;r.workshop=pick(w,['selection','finder']);
  if(['production','workspace'].includes(result.view))r.workshop.production=w.production;
  if(['inventory','production'].includes(result.view))r.workshop.materials=w.materials;
  if(result.view==='shipping')r.workshop.shipping=w.shipping;
  if(['care','passport'].includes(result.view))r.workshop.passport=w.passport;
 }
 return r;
}
