import {money} from './commerce.mjs';

const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const features = [
  ['voice','AI Voice Assistant','Talk naturally to Everbranch about your business.','↗'],
  ['email','Email Marketing Assistant','Create personalized promotional emails and campaigns.','✉'],
  ['sms','SMS Marketing Assistant','Draft customer text campaigns and follow-ups.','↗'],
  ['admin','Virtual Business Assistant','Help with daily administrative and customer-management tasks.','✓'],
  ['personal','Personalized AI Assistance',"Tailor assistance to your individual business.",'✦'],
  ['social','Social Media Marketing','Coming Soon — AI-assisted social content and marketing campaigns.','◷'],
];

export function createRetailSales({context, openModal, closeModal, toast, api, navigate}) {
  let draft = null, recognition = null, speechTimer = null, thread = [], lastReply = "";
  const live = () => context().owner && !context().offline && navigator.onLine && context().pack?.assistant?.available;
  const currentOffer = () => context().pack?.offer;
  const validOffer = o => o?.key === 'retail_launch_partner' && o.quote_version && Number.isInteger(o.introductory_months);
  const terms = o => `<div class="launch-price-grid"><div><span>Months 1–${o.introductory_months}</span><strong>${money(o.introductory_monthly_cents)}<small>/month</small></strong></div><div><span>From month ${o.introductory_months + 1}</span><strong>${money(o.ongoing_monthly_cents)}<small>/month</small></strong></div></div><div class="offer-setup">${money(o.setup_cents)} one-time setup</div>`;
  const eligibility = o => `<p class="launch-eligibility">First <strong>${o.qualifying_business_limit} qualifying businesses</strong>. Eligibility and written confirmation required; requesting does not reserve a place.</p>`;

  function pricingPage() {
    const o = currentOffer();
    if (!validOffer(o)) return '<main class="wrap pricing-page"><h1>Refresh the Retail offer</h1><p>Reconnect and reload for current pricing and eligibility. An older offline pack cannot submit a signup request.</p></main>';
    return `<main class="wrap pricing-page"><section class="pricing-hero"><div><div class="eyebrow">EVERBRANCH RETAIL · LAUNCH PARTNER OFFER</div><h1>Your next chapter.<br>A clear place to start.</h1><p>A storefront with personality. Customer relationships with context. A connected workspace for what comes next.</p><button class="button light" data-action="home">Explore the business demos →</button></div><article class="offer-card"><span class="pill">Limited launch offer</span><h2>Launch Partner Offer</h2>${terms(o)}<p class="intro-expiry">The introductory price ends after six months. Your ongoing rate is ${money(o.ongoing_monthly_cents)}/month beginning with month ${o.introductory_months + 1}.</p>${eligibility(o)}<button class="button" data-sales-action="signup">Get Started →</button><small class="compact-info">Request activation. No payment is taken here.</small></article></section><div class="section-head"><div><div class="eyebrow">WHAT’S INCLUDED IN THE PROPOSED SCOPE</div><h2>Make room for the work that matters.</h2></div></div><section class="offer-inclusions">${o.included.map((v,i)=>`<article><span class="offer-number">0${i+1}</span><h3>${esc(v.title)}</h3><p>${esc(v.body)}</p></article>`).join('')}</section><section class="partner-band"><div><div class="eyebrow">MORE THAN SOFTWARE</div><h2>A strategic partner.<br>One hour, every week.</h2><p>Dedicated time to talk through your business and decide what to do next.</p></div><ul class="offer-features">${(o.partnership??[]).map(v=>`<li>✓ ${esc(v)}</li>`).join('')}</ul></section><section class="offer-retail"><div><div class="eyebrow">A CONNECTED RETAIL EXPERIENCE</div><h2>From the first visit<br>to the next purchase.</h2><p>${esc(o.review_note)}</p><button class="button light" data-action="assistant">Meet your AI business assistant →</button></div><ul class="offer-features">${o.retail.map(v=>`<li>✓ ${esc(v)}</li>`).join('')}</ul></section><section class="offer-next"><div><div class="eyebrow">YOUR NEXT STEP</div><h2>Let’s make it yours.</h2><button class="button" data-sales-action="signup">Get Started →</button></div><div><h3>1. Tell us about your business</h3><p>Choose the launch offer and review both monthly rates.</p><h3>2. Request activation</h3><p>We confirm eligibility, your scope, and your own workspace setup.</p><h3>3. Approve your launch</h3><p>No subscription is active and no payment is taken until the authorized onboarding process is complete.</p></div></section><div class="policy-box"><strong>Clear expectations, before we start.</strong>${esc(o.additional)} AI examples are previews; live features, usage and provider readiness are confirmed in your written scope.</div><footer class="footer"><span>${money(o.introductory_monthly_cents)}/month for ${o.introductory_months} months → ${money(o.ongoing_monthly_cents)}/month ongoing · ${money(o.setup_cents)} setup.</span><button class="text-button" data-action="home">Back to the showroom</button></footer></main>`;
  }

  function assistantPage() {
    return `<main class="wrap assistant-page"><section class="assistant-hero"><div class="eyebrow">MEET YOUR AI BUSINESS ASSISTANT</div><h1>Good help.<br>Right where you work.</h1><p>Your business has a lot of moving parts. Everbranch helps you manage them.</p><div class="pill">${live()?'Live OpenAI · demo business':'Guided examples · live AI unavailable'}</div><p class="compact-info">${live()?'Ask questions and create drafts with OpenAI using the demonstration business.':'Try guided examples while the live AI connection is unavailable.'} No campaign is sent and no private customer information is accessed.</p></section><section class="assistant-grid" aria-label="Business assistant capabilities">${features.map(([key,title,body,icon])=>`<article class="assistant-card"><span class="assistant-symbol" aria-hidden="true">${icon}</span><span class="eyebrow">${key==='social'?'COMING SOON':live()?'ASK EVERBRANCH':'TRY A GUIDED EXAMPLE'}</span><h2>${title}</h2><p>${body}</p>${key==='social'?'<span class="pill">Coming Soon</span>':`<button class="button light" data-sales-action="assistant" data-mode="${key}">Try ${key==='voice'?'voice navigation':'an example'} →</button>`}</article>`).join('')}</section><section class="assistant-next"><div><h2>Picture it working for your business.</h2><p>Explore the offer, then request a private setup review.</p></div><button class="button" data-action="pricing">See launch pricing →</button></section></main>`;
  }

  function signup() {
    if (!context().owner || context().offline || !navigator.onLine) return toast('Connect with the showroom owner before entering personal information. Offline signup is unavailable.');
    const o = currentOffer();
    if (!validOffer(o)) return toast('Reconnect and reload to review the current offer.');
    openModal('Get Started with Everbranch Retail', `<p class="eyebrow">STEP 1 OF 2 · YOUR BUSINESS</p><p>Request your own workspace. You will not receive access to the demonstration tenant.</p><form id="retail-signup-details" autocomplete="off"><div class="signup-fields"><label>Business name<input name="business_name" required maxlength="190" autocomplete="off"></label><label>Contact name<input name="contact_name" required maxlength="190" autocomplete="off"></label><label>Email address<input name="email" type="email" required maxlength="190" autocomplete="off" autocapitalize="none"></label><label>Phone number<input name="phone" type="tel" required maxlength="40" autocomplete="off"></label></div><label>Subscription<select name="subscription"><option value="${esc(o.key)}">Retail Launch Partner — ${money(o.introductory_monthly_cents)} for ${o.introductory_months} months, then ${money(o.ongoing_monthly_cents)}/month</option></select></label><p class="compact-info">No card details are collected. Details stay in this form until submission and are not saved in tablet storage.</p><button class="button" type="submit">Review pricing & request →</button></form>`, true);
  }

  function assistant(mode, forceGuided=false) {
    const feature = features.find(v => v[0] === mode && v[0] !== 'social');
    if (!feature) return;
    const name = context().merchant?.name ?? 'Your sample business';
    const useLive=live()&&!forceGuided;
    openModal(feature[1], `<div class="policy-box"><strong>${useLive?'Live OpenAI · demonstration context':'Guided preview · sample context'}</strong>${useLive?'Your question and the sample business context go to OpenAI. Responses are drafts for review. No campaign, payment, or account activation is performed.':'These templates illustrate the workflow while live AI is unavailable. No campaign send or customer account is activated.'}</div><form id="retail-assistant-form" data-mode="${mode}" data-live="${useLive}" autocomplete="off"><label>Demonstration business<input name="business" maxlength="120" value="${esc(name)}"></label><label>${mode==='voice'?'Say or type a question or a page to open':'What are you working on?'}<textarea id="assistant-query" name="topic" rows="3" maxlength="300" required placeholder="${mode==='voice'?'Open pricing, show customer profiles, or show rewards':'For example: a weekend collection launch'}"></textarea></label><div class="assistant-controls"><button type="button" class="button light" data-sales-action="speak">Speak</button><button class="button" type="submit">${useLive?'Ask Everbranch':mode==='voice'?'Open page':'Create sample draft'}</button></div><p id="speech-status" class="compact-info" role="status">${mode==='voice'?'Speech uses your browser’s recognition service and may need internet. Typing is always available.':'Drafts stay here for review and are cleared when this dialog closes.'}</p></form><div id="assistant-result" class="assistant-result" aria-live="polite"></div>${useLive?`<button class="text-button" data-sales-action="guided" data-mode="${mode}">Use a guided example instead</button>`:''}`, true);
  }

  function clear() {
    draft = null; thread=[]; lastReply=""; window.speechSynthesis?.cancel();
    clearTimeout(speechTimer);
    if (recognition) { recognition.onresult = recognition.onerror = recognition.onend = null; recognition.abort(); recognition = null; }
  }

  function speak() {
    const status = document.querySelector('#speech-status');
    if (recognition) { recognition.stop(); return; }
    const Speech = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!Speech) { status.textContent = 'Voice recognition is unavailable in this browser. Type your request above.'; return; }
    const active = new Speech(); recognition = active;
    active.lang = navigator.language || 'en-US'; active.continuous = false; active.interimResults = false;
    active.onresult = event => { if (recognition!==active) return; document.querySelector('#assistant-query').value = event.results[0][0].transcript; status.textContent = 'Review the words, then submit your request.'; };
    active.onerror = () => { if (recognition===active) status.textContent = 'Voice input stopped or was unavailable. You can type your request.'; };
    active.onend = () => { if (recognition===active) { recognition=null; clearTimeout(speechTimer); if(status.textContent.startsWith('Listening')) status.textContent='Voice input ended. Review or type your request.'; } };
    try { active.start(); status.textContent = 'Listening… select Speak again to stop.'; speechTimer=setTimeout(()=>active.stop(),12000); }
    catch { recognition=null; status.textContent='Could not start voice input. Type your request instead.'; }
  }

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-sales-action]');
    if (!button) return;
    event.preventDefault();
    if (button.dataset.salesAction==='signup') signup();
    if (button.dataset.salesAction==='assistant') assistant(button.dataset.mode);
    if (button.dataset.salesAction==='speak') speak();
    if (button.dataset.salesAction==='guided') assistant(button.dataset.mode,true);
    if (button.dataset.salesAction==='navigate' && ['home','pricing','assistant','customers','rewards','team'].includes(button.dataset.destination)){closeModal();navigate(button.dataset.destination);}
    if (button.dataset.salesAction==='read' && lastReply && 'speechSynthesis' in window){window.speechSynthesis.cancel();window.speechSynthesis.speak(new SpeechSynthesisUtterance(lastReply));}
  });
  document.addEventListener('submit', async event => {
    const form = event.target;
    if (form.id==='retail-signup-details') {
      event.preventDefault();
      const data = Object.fromEntries(new FormData(form));
      const o = currentOffer();
      openModal('Review your activation request', `<p class="eyebrow">STEP 2 OF 2 · REVIEW BEFORE SUBMITTING</p><h3>${esc(data.business_name)}</h3><p>${esc(data.contact_name)} · ${esc(data.email)} · ${esc(data.phone)}</p>${terms(o)}<p class="intro-expiry">${money(o.introductory_monthly_cents)}/month expires after ${o.introductory_months} months. ${money(o.ongoing_monthly_cents)}/month applies from month ${o.introductory_months+1}.</p>${eligibility(o)}<form id="retail-signup-review" autocomplete="off"><label class="signup-accept"><input type="checkbox" name="pricing_accepted" required> I have reviewed the introductory and ongoing rates, the setup fee, and eligibility. This requests activation; it does not activate a subscription or authorize a payment.</label><div id="signup-error" role="alert"></div><button class="button" type="submit">Submit activation request</button></form>`,true);
      draft = {...data,quote_version:o.quote_version};
    }
    if (form.id==='retail-signup-review') {
      event.preventDefault();
      if (!draft || context().offline || !navigator.onLine) return toast('Reconnect and start again. Personal details were not saved on the tablet.');
      const payload={...draft,pricing_accepted:true}, button=form.querySelector('button');
      button.disabled=true; button.textContent='Saving request…';
      try {
        const result=await api('signup','POST',payload);
        if(!form.isConnected) { clear(); return toast('Activation request saved for review.'); }
        const message=result.confirmation_status==='submitted'?'A confirmation email has been submitted for delivery.':'Your request is saved. Confirmation email is pending; Evergrove will follow up after delivery setup or review.';
        closeModal();
        openModal('Request received',`<div class="order-success"><div class="tick">✓</div><h3>Your next chapter starts with a conversation.</h3><p>Activation pending review. No payment was taken and no place has been reserved.</p><p>${message}</p><p class="compact-info">Reference: ${esc(result.reference)}</p><button class="button" data-action="close">Done</button></div>`);
      } catch(error) { if(form.isConnected){ form.querySelector('#signup-error').textContent=error.message; button.disabled=false; button.textContent='Submit activation request'; } }
    }
    if (form.id==='retail-assistant-form') {
      event.preventDefault();
      const fd=new FormData(form), business=String(fd.get('business')).trim()||'Your sample business', topic=String(fd.get('topic')).trim(), mode=form.dataset.mode;
      const output=document.querySelector('#assistant-result');
      if(mode==='voice') {
        const query=topic.toLowerCase();
        const destination=/pricing|price|plan|subscription/.test(query)?'pricing':/customer|client|profile/.test(query)?'customers':/reward|point/.test(query)?'rewards':/team|staff/.test(query)?'team':/assistant|marketing/.test(query)?'assistant':/home|showroom/.test(query)?'home':null;
        if(destination && /^(?:(?:please|can you)\s+)?(?:open|show|go|take)\b/.test(query)) { closeModal(); navigate(destination); return; }
        if(form.dataset.live!=='true'){output.textContent='Try “open pricing,” “show customer profiles,” “show rewards,” “show team,” or “open the assistant.”'; return;}
      }
      if(form.dataset.live==='true') {
        const button=form.querySelector('button[type="submit"]');button.disabled=true;output.textContent='Asking Everbranch…';
        try {
          const result=await api('assistant','POST',{mode,question:topic,business,merchant:context().merchant?.slug??null,history:thread.slice(-4)});
          if(!form.isConnected)return;
          thread=[...thread,{role:'user',content:topic},{role:'assistant',content:result.reply.slice(0,1500)}].slice(-4);lastReply=result.reply;
          output.innerHTML=`<h3>Everbranch · OpenAI</h3><pre>${esc(result.reply)}</pre><small>Review before use. Demonstration business context.</small><div class="assistant-controls">${result.destination?`<button class="button light" data-sales-action="navigate" data-destination="${esc(result.destination)}">Open ${esc(result.destination)} →</button>`:''}${'speechSynthesis' in window?'<button class="button light" data-sales-action="read">Read aloud</button>':''}</div>`;
        } catch(error){if(form.isConnected)output.textContent=error.message;}
        finally{if(form.isConnected)button.disabled=false;}
        return;
      }
      const drafts={email:`Subject: A little something new from ${business}\n\nHi [first name],\n\nWe’re making room for ${topic}. Explore the collection, find something you love, and get in touch if we can help.\n\n[Approved offer, dates and store link]\n${business}\n[Mailing address and unsubscribe link]\n\nReview audience consent, claims and details before sending.`,sms:`${business}: ${topic}. Discover what’s new: [approved link]. [Approved dates/terms]. Reply STOP to opt out.\n\nSample only. Review consent, sender setup and campaign details before sending.`,admin:`A practical next step for ${business}:\n\n1. Review ${topic} and confirm the owner and due date.\n2. Check customer requests and record a clear follow-up.\n3. Review the draft with your team before taking action.\n\nThis example creates no task, message or customer record.`,personal:`For ${business}, start with ${topic}.\n\nChoose one clear customer goal, connect it to a product or service, and plan one useful follow-up. Bring the draft to your weekly strategy call to confirm the audience, scope and next step.\n\nThis is a guided template; live business context is connected during approved setup.`};
      output.innerHTML=`<h3>Sample draft · review before use</h3><pre>${esc(drafts[mode]??'')}</pre>`;
    }
  });
  window.addEventListener('pagehide',()=>{
    if(document.querySelector('#retail-signup-details,#retail-signup-review,#retail-assistant-form'))closeModal();
    else clear();
  });
  document.addEventListener('visibilitychange',()=>{ if(document.hidden && (draft || document.querySelector('#retail-signup-details'))) closeModal(); });
  return {pricingPage,assistantPage,clear};
}
