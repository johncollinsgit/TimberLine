import '@shopify/ui-extensions/preact';
import {render} from 'preact';
import {useState} from 'preact/hooks';
export default () => render(<OrderRequest />, document.body);
function OrderRequest() {
  const [note,setNote]=useState(''),[busy,setBusy]=useState(false),[saved,setSaved]=useState(false),[error,setError]=useState('');
  const copy=key=>shopify.i18n.translate(key);
  async function save(){
    if(busy||!note.trim())return;
    setBusy(true);setError('');
    try {
      const token=await shopify.sessionToken.get();
      const response=await fetch(`https://${shopify.shop.myshopifyDomain}/apps/forestry/wholesale/order-note`,{method:'POST',headers:{Authorization:`Bearer ${token}`,'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({order_id:shopify.orderConfirmation.value.order.id,note})});
      const result=await response.json();if(!response.ok)throw new Error(result.message||copy('retry'));
      setSaved(true);
    }catch(error){setError(error.message||copy('retry'));}finally{setBusy(false);}
  }
  return <s-section heading={copy('heading')}><s-stack gap="base">
    <s-paragraph>{copy('question')}</s-paragraph>
    <s-paragraph>{copy('optional')}</s-paragraph>
    {saved?<s-banner tone="success">{copy('saved')}</s-banner>:<>
      <s-text-area label={copy('field')} value={note} rows={3} maxLength={2000} disabled={busy} onInput={event=>setNote(/** @type {{value:string}} */ (/** @type {unknown} */ (event.currentTarget)).value)} />
      {error&&<s-banner tone="critical">{error}</s-banner>}
      <s-button disabled={!note.trim()||busy} loading={busy} onClick={save}>{copy('save')}</s-button>
    </>}
  </s-stack></s-section>;
}
