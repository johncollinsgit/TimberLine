(()=>{
'use strict';
const config=document.getElementById('mf-quantity-config');if(!config)return;
const copy=JSON.parse(config.textContent);
function validate(){
 let invalid=null;
 document.querySelectorAll('input.quantity__input').forEach(input=>{
  const step=Number(input.step||1),value=Number(input.value);
  const bad=step===3&&value!==0&&(!Number.isInteger(value)||value<3||value%3!==0);
  input.setCustomValidity(bad?copy.message:'');input.setAttribute('aria-invalid',String(bad));
  const row=input.closest('.cart-item,.variant-item');
  if(row){let note=row.querySelector('[data-mf-quantity-warning]');if(bad&&!note){note=document.createElement('p');note.dataset.mfQuantityWarning='';note.className='mf-quantity-warning';note.setAttribute('role','alert');note.style.cssText='font-size:13px;color:#793b28;line-height:1.5;margin:8px 0';(row.querySelector('.cart-item__details,.variant-item__inner')||input.parentElement).append(note);}if(note){if(note.textContent!==copy.message)note.textContent=copy.message;note.hidden=!bad;}}
  if(bad&&!invalid)invalid=input;
 });
 return invalid;
}
function check(event){if(!event.target.closest?.('[name="checkout"],a[href*="/checkout"],[data-mf-label-checkout]'))return;const invalid=validate();if(invalid){event.preventDefault();event.stopImmediatePropagation();invalid.reportValidity();invalid.focus();}}
document.addEventListener('click',check,true);
document.addEventListener('submit',event=>{if(event.submitter?.name==='checkout')check({target:event.submitter,preventDefault:()=>event.preventDefault(),stopImmediatePropagation:()=>event.stopImmediatePropagation()});},true);
document.addEventListener('change',validate);
let timer;new MutationObserver(()=>{clearTimeout(timer);timer=setTimeout(validate,80);}).observe(document.querySelector('#MainContent')||document.body,{childList:true,subtree:true});
validate();
})();
