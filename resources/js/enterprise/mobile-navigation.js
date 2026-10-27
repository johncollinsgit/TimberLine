/* Navigation presentation only. Existing controls retain their handlers, routes and authority. */
(() => {
  'use strict';
  function mount() {
    const toggle=document.getElementById('enterprise-navigation-toggle'),menu=document.getElementById('enterprise-mobile-navigation');
    if(!toggle||!menu||typeof menu.showPopover!=='function')return false;
    const mobile=matchMedia('(max-width:800px)');
    menu.setAttribute('popover','auto');toggle.setAttribute('popovertarget',menu.id);
    const close=document.createElement('button');close.type='button';close.className='enterprise-navigation-close';close.textContent='Close menu';close.setAttribute('popovertarget',menu.id);close.setAttribute('popovertargetaction','hide');close.setAttribute('autofocus','');menu.prepend(close);
    const actions=menu.querySelector('[data-mobile-nav-actions]');
    function hide(){if(menu.matches(':popover-open'))menu.hidePopover();}
    function forward(source,label){if(!source||source.disabled||!actions)return;const button=document.createElement('button');button.type='button';button.textContent=label;button.dataset.mobileAction=label;
      button.onclick=()=>{hide();source.click();const dialog=[...document.querySelectorAll('dialog[open]')].at(-1);dialog?.addEventListener('close',()=>toggle.focus({preventScroll:true}),{once:true});};actions.append(button);}
    const tour=document.querySelector('[data-reviewer-tour-root]:not([data-priority-only="true"]) [data-tour-open]');
    forward(tour,'Tour');forward(document.querySelector('[data-priorities-open]'),'Priorities');forward(document.getElementById('review-open'),'Review this page');
    const proposal=document.querySelector('.campus-review-tools a,[data-tour-proposal]');
    if(proposal&&actions){const link=document.createElement('a');link.href=proposal.href;link.textContent='Open proposal';actions.append(link);}
    const help=document.querySelector('.ert-help p');if(help&&actions){const details=document.createElement('details'),summary=document.createElement('summary'),copy=document.createElement('p');summary.textContent='About this demo';copy.textContent=help.textContent;details.append(summary,copy);actions.append(details);}
    toggle.addEventListener('click',()=>toggle.focus({preventScroll:true}));
    let restoreCloseFocus=false;close.addEventListener('click',()=>{restoreCloseFocus=true;});
    menu.addEventListener('toggle',()=>{const open=menu.matches(':popover-open');toggle.setAttribute('aria-expanded',String(open));if(!open&&restoreCloseFocus&&mobile.matches)toggle.focus({preventScroll:true});if(!open)restoreCloseFocus=false;});
    menu.addEventListener('click',event=>{if(event.target.closest('[data-section],.sidebar-project,.ew-library button,[aria-label="Workflow workspace"] button,.ew-back'))hide();});
    document.addEventListener('close',event=>{if(mobile.matches&&event.target.matches('dialog#review-dialog,dialog[data-tour-dialog],dialog[data-priorities-dialog]'))queueMicrotask(()=>toggle.focus({preventScroll:true}));},true);
    mobile.addEventListener('change',()=>{if(!mobile.matches)hide();});
    document.body.classList.add('enterprise-mobile-nav-ready');
    return true;
  }
  function start(){if(mount())return;const observer=new MutationObserver(()=>{if(mount())observer.disconnect();});observer.observe(document.body,{childList:true,subtree:true});globalThis.addEventListener('pagehide',()=>observer.disconnect(),{once:true});}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
