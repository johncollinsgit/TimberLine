/** One set of real controls: an anchored phone popover, the full desktop toolbar. */
export function initReportControls(root) {
  const doc=root.ownerDocument,win=doc.defaultView,compact=win.matchMedia('(max-width: 900px)');
  const panel=root.querySelector('#tr-report-controls'),trigger=root.querySelector('#tr-date-toggle'),actions=root.querySelector('#tr-mobile-top-actions');
  if(!panel||!trigger||!actions)return {sync(){},destroy(){}};
  const context=root.querySelector('#tr-context-controls'),analysis=root.querySelector('#tr-analysis-controls'),period=root.querySelector('#tr-mobile-period-label');
  const permanent=[],dynamic=[];let opened=false;
  const keep=(node,list)=>{if(!node)return;const marker=doc.createComment('report-control-position');node.before(marker);list.push({node,marker});};
  for(const node of [root.querySelector('[data-action="vf-inbox"]'),root.querySelector('#tr-add'),trigger,root.querySelector('#tr-space-tabs'),root.querySelector('#tr-company-label')])keep(node,permanent);
  const restore=items=>items.forEach(({node,marker})=>{if(marker.isConnected){if(marker.nextSibling!==node)marker.after(node);}else node.remove();});
  const updateCaption=()=>{if(!period)return;const dates=root.querySelector('#tr-context')?.textContent.split(' · ')[0]??'',label=root.querySelector('#tr-company-label'),company=!label?.hidden?root.querySelector('#tr-company')?.selectedOptions[0]?.textContent?.trim():null;period.textContent=company?`${company} · ${dates}`:dates;period.title=period.textContent;};
  function close(focus=true){if(!opened)return;opened=false;sync();if(focus)trigger.focus({preventScroll:true});}
  function sync(){
    const phone=compact.matches;
    if(!phone)opened=false;
    restore(dynamic);dynamic.length=0;
    if(phone){
      for(const {node} of permanent){const destination=node.matches('#tr-space-tabs,#tr-company-label')?context:actions;if(node.parentNode!==destination)destination.append(node);}
      for(const node of root.querySelectorAll('#tr-content .vf-controls,#tr-content .vf-section-heading>label,#tr-content .vf-section-heading>[data-action="vf-legacy-budget"],#tr-content .vf-section-heading>[data-action="vf-definitions"]')){keep(node,dynamic);analysis.append(node);}
    }else restore(permanent);
    panel.hidden=phone&&!opened;trigger.setAttribute('aria-expanded',String(opened));
    if(phone){panel.setAttribute('role','dialog');panel.setAttribute('aria-labelledby','tr-report-controls-title');}
    else{panel.removeAttribute('role');panel.removeAttribute('aria-labelledby');}
    panel.toggleAttribute('data-open',phone&&opened);
    updateCaption();
  }
  const toggle=()=>{if(!compact.matches)return;if(opened){close();return;}opened=true;sync();(panel.querySelector('#tr-range [aria-pressed="true"]')||panel.querySelector('button')).focus({preventScroll:true});};
  const escape=event=>{if(opened&&event.key==='Escape'){event.preventDefault();close();}};
  const outside=event=>{if(opened&&!panel.contains(event.target)&&!trigger.contains(event.target))close(false);};
  const focusOutside=event=>{if(opened&&!panel.contains(event.target)&&!trigger.contains(event.target))close(false);};
  const choose=event=>{if(event.target.closest('[data-range]:not([data-range="custom"]),[data-scope],#tr-refresh'))close();};
  const observer=new win.MutationObserver(updateCaption);
  observer.observe(root.querySelector('#tr-context'),{childList:true,subtree:true,characterData:true});
  trigger.addEventListener('click',toggle);root.querySelector('#tr-date-close').addEventListener('click',closeClicked);
  doc.addEventListener('keydown',escape);doc.addEventListener('pointerdown',outside);doc.addEventListener('focusin',focusOutside);panel.addEventListener('click',choose);compact.addEventListener('change',sync);
  function closeClicked(){close();}
  sync();
  return {sync,close,destroy(){close(false);restore(dynamic);restore(permanent);panel.hidden=false;observer.disconnect();trigger.removeEventListener('click',toggle);root.querySelector('#tr-date-close').removeEventListener('click',closeClicked);doc.removeEventListener('keydown',escape);doc.removeEventListener('pointerdown',outside);doc.removeEventListener('focusin',focusOutside);panel.removeEventListener('click',choose);compact.removeEventListener('change',sync);}};
}
