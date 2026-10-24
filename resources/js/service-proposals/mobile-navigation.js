/* Fixed local navigation only: no proposal values, requests, storage or business actions. */
(() => {
  'use strict';
  const mobile=matchMedia('(max-width:800px)');
  for(const group of document.querySelectorAll('.proposal-section-tabs')){
    const toggle=group.querySelector('.proposal-menu-toggle'),menu=group.querySelector('.proposal-section-controls'),close=group.querySelector('.proposal-menu-close');
    if(!toggle||!menu||!close||typeof menu.showPopover!=='function')continue;
    let restoreFocus=false;
    toggle.addEventListener('click',()=>toggle.focus({preventScroll:true}));
    close.addEventListener('click',()=>{restoreFocus=true;});
    document.addEventListener('keydown',event=>{if(event.key==='Escape'&&menu.matches(':popover-open'))restoreFocus=true;});
    menu.addEventListener('toggle',()=>{
      const open=menu.matches(':popover-open');toggle.setAttribute('aria-expanded',String(open));
      if(!open){if(mobile.matches&&(restoreFocus||menu.contains(document.activeElement)||document.activeElement===document.body))toggle.focus({preventScroll:true});restoreFocus=false;}
    });
    mobile.addEventListener('change',()=>{if(!mobile.matches&&menu.matches(':popover-open')){restoreFocus=false;menu.hidePopover();}});
    group.classList.add('proposal-menu-ready');
  }
})();
