// Shared controls for dispatch, van stock and maintenance. IDs stay separate from labels.
export const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let sequence = 0;
export function picker(label, name, value, options, placeholder='Search…', required=false, freeText=false) {
    const id=`fleet-pick-${++sequence}`, selected=options.find(o=>String(o.id)===String(value));
    return `<div class="picker" data-picker data-free-text="${freeText}"><label for="${id}">${escape(label)}</label><input id="${id}" type="search" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="${id}-list" autocomplete="off" placeholder="${escape(placeholder)}" value="${escape(selected?.label||(freeText?value:''))}" ${required?'required':''} ${freeText?'maxlength="500"':''}><input type="hidden" name="${name}" value="${escape(selected?.id??(freeText?value:''))}"><div id="${id}-list" class="picker-options" role="listbox" hidden>${options.map((o,i)=>`<button type="button" role="option" aria-selected="${String(o.id)===String(value)}" id="${id}-opt-${i}" data-value="${escape(o.id)}" data-label="${escape(o.label)}"><strong>${escape(o.label)}</strong>${o.detail?`<small>${escape(o.detail)}</small>`:''}</button>`).join('')}<p class="picker-empty" hidden>No matches</p></div></div>`;
}
export function suggestions(label,name,value,values,extra='') {
    const id=`fleet-list-${++sequence}`;
    return `<label>${escape(label)}<input name="${name}" value="${escape(value)}" list="${id}" ${extra}><datalist id="${id}">${[...new Set(values.filter(Boolean))].map(v=>`<option value="${escape(v)}"></option>`).join('')}</datalist></label>`;
}
export function tags(label,values,options) {
    return `<fieldset class="tag-editor" data-tags><legend>${escape(label)}</legend><div class="chips">${values.map(v=>chip(v)).join('')}</div><div class="inline-input">${suggestions('Add job type','', '',options,'maxlength="80" placeholder="Search or add a job type"')}<button type="button" data-add-tag>Add</button></div></fieldset>`;
}
const chip=value=>`<button type="button" class="chip" data-tag="${escape(value)}" aria-label="Remove ${escape(value)}">${escape(value)} <span aria-hidden="true">×</span></button>`;
function materialRow(name='',qty=1,options=[]) {
    return `<div class="material-row">${suggestions('Material','',name,options,'data-material-name maxlength="80" placeholder="Search materials"')}<label>Quantity<input data-material-qty type="number" min="0" max="100000" step="any" value="${escape(qty)}"></label><button type="button" data-remove-material aria-label="Remove material">Remove</button></div>`;
}
export function materials(label,values,options) {
    return `<fieldset class="materials-editor" data-materials data-options="${escape(JSON.stringify(options))}"><legend>${escape(label)}</legend><div class="material-rows">${Object.entries(values||{}).map(([name,qty])=>materialRow(name,qty,options)).join('')}</div><button type="button" data-add-material>Add material</button></fieldset>`;
}
export function readRequirements(form) {
    const skills=[...form.querySelectorAll('[data-tag]')].map(el=>el.dataset.tag);
    const pending=form.querySelector('[data-tags] input');
    if(pending?.value.trim()) throw new Error('Choose Add to keep the job type you entered.');
    const materials=Object.create(null);
    for(const row of form.querySelectorAll('.material-row')) {
        const input=row.querySelector('[data-material-name]'),quantity=row.querySelector('[data-material-qty]');
        const name=input.value.trim().toLowerCase(),qty=Number(quantity.value);
        if(!name||!quantity.value||!Number.isFinite(qty)||qty<0||qty>100000) throw new Error('Give each material a name and quantity. Remove any empty rows.');
        if(Object.hasOwn(materials,name)) throw new Error(`Combine the two ${name} rows into one quantity.`);
        materials[name]=qty;
    }
    return {skills,materials};
}
export function bindInputs(root) {
    root.querySelectorAll('[data-picker]').forEach(el=>{
        const input=el.querySelector('[role=combobox]'),hidden=el.querySelector('[type=hidden]'),list=el.querySelector('[role=listbox]'),buttons=[...list.querySelectorAll('[role=option]')];
        let active=-1;
        const visible=()=>buttons.filter(b=>!b.hidden);
        const close=()=>{list.hidden=true;input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');active=-1;};
        const show=()=>{list.hidden=false;input.setAttribute('aria-expanded','true');const q=input.value.toLowerCase();buttons.forEach(b=>b.hidden=!`${b.dataset.label} ${b.textContent}`.toLowerCase().includes(q));list.querySelector('.picker-empty').hidden=visible().length>0;};
        const choose=b=>{hidden.value=b.dataset.value;input.value=b.dataset.label;input.setCustomValidity('');buttons.forEach(o=>o.setAttribute('aria-selected',String(o===b)));close();input.dispatchEvent(new Event('change',{bubbles:true}));input.focus();};
        input.onfocus=show;
        input.oninput=()=>{hidden.value=el.dataset.freeText==='true'?input.value:'';active=-1;input.setCustomValidity(el.dataset.freeText!=='true'&&input.value?'Choose an item from the list.':'');show();};
        input.onkeydown=event=>{
            if(event.key==='Escape'){close();return;}
            if(['ArrowDown','ArrowUp'].includes(event.key)){event.preventDefault();if(list.hidden)show();const items=visible();if(!items.length)return;active=active<0?(event.key==='ArrowDown'?0:items.length-1):(active+(event.key==='ArrowDown'?1:-1)+items.length)%items.length;input.setAttribute('aria-activedescendant',items[active].id);items.forEach((b,i)=>b.classList.toggle('highlight',i===active));items[active].scrollIntoView({block:'nearest'});}
            if(event.key==='Enter'&&!list.hidden){event.preventDefault();const items=visible();if(items.length===1||active>=0)choose(items[active>=0?active:0]);}
        };
        buttons.forEach(b=>{b.onmousedown=e=>e.preventDefault();b.onclick=()=>choose(b);});
        el.addEventListener('focusout',event=>{if(!el.contains(event.relatedTarget))close();});
    });
    root.querySelectorAll('[data-tags]').forEach(el=>{
        const input=el.querySelector('input'),add=()=>{const value=input.value.trim().toLowerCase();if(!value)return;if(![...el.querySelectorAll('[data-tag]')].some(b=>b.dataset.tag===value))el.querySelector('.chips').insertAdjacentHTML('beforeend',chip(value));input.value='';input.dispatchEvent(new Event('change',{bubbles:true}));};
        el.querySelector('[data-add-tag]').onclick=add;
        input.onkeydown=event=>{if(event.key==='Enter'){event.preventDefault();add();}};
        el.onclick=event=>{const button=event.target.closest('[data-tag]');if(button){button.remove();el.dispatchEvent(new Event('change',{bubbles:true}));}};
    });
    root.querySelectorAll('[data-materials]').forEach(el=>{
        el.querySelector('[data-add-material]').onclick=()=>{el.querySelector('.material-rows').insertAdjacentHTML('beforeend',materialRow('',1,JSON.parse(el.dataset.options)));el.querySelector('.material-row:last-child input').focus();el.dispatchEvent(new Event('change',{bubbles:true}));};
        el.onclick=event=>{const button=event.target.closest('[data-remove-material]');if(button){button.closest('.material-row').remove();el.dispatchEvent(new Event('change',{bubbles:true}));}};
    });
}
