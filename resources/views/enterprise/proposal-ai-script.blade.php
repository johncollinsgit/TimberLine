@if($enterpriseSample && ($enterprisePresentation['reviewerAi']['enabled'] ?? false) === true)
  try {
    const binding = @json(['version_hash' => $campusReviewContext['version_hash'], 'build_id' => $campusReviewContext['build_id'], 'source_hash' => $campusReviewContext['source_hash']]);
    const csrf = @json($campusReviewContext['csrf_token']);
    const askPage = new URL(@json($campusReviewContext['urls']['pages']['ask']), location.href);
    const actions = ['preview', 'confirm', 'status', 'cancel'];
    const endpoints = presentation.reviewerAi.endpoints;
    if (askPage.origin !== location.origin || askPage.search || askPage.hash || askPage.username || askPage.password
      || !askPage.pathname.startsWith('/enterprise-owner/service-proposals/') || !askPage.pathname.endsWith('/campus/ask')
      || Object.keys(endpoints).sort().join() !== [...actions].sort().join()) throw Error('Invalid private AI endpoints.');
    for (const action of actions) {
      if (endpoints[action] !== askPage.origin + askPage.pathname + '/ai/' + action) throw Error('Invalid private AI endpoint.');
    }
    const adapter = EverbranchSampleTransport.create(presentation);
    const key = 'everbranch-proposal-ai-receipt:' + binding.version_hash;
    let ownedReceipt = null, savedReceipt = null;
    try { ownedReceipt = sessionStorage.getItem(key); savedReceipt = ownedReceipt ? JSON.parse(ownedReceipt) : null; } catch {}
    const persistReceipt = receipt => {
      if (receipt === null) {
        if (sessionStorage.getItem(key) !== ownedReceipt) return false;
        sessionStorage.removeItem(key); ownedReceipt = null; return true;
      }
      const serialized = JSON.stringify(receipt);
      sessionStorage.setItem(key, serialized);
      if (sessionStorage.getItem(key) !== serialized) return false;
      ownedReceipt = serialized; return true;
    };
    const request = async (action, body) => {
      if (!actions.includes(action)) throw Error('Unsupported private AI action.');
      const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 45000);
      try {
        const response = await fetch(endpoints[action], {method:'POST', credentials:'same-origin', redirect:'error', signal:controller.signal,
          headers:{'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':csrf}, body:JSON.stringify(body)});
        if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw Error('Private AI request unavailable.');
        const text = await response.text();
        if (text.length > 100000) throw Error('Private AI response exceeded its limit.');
        return JSON.parse(text);
      } catch { throw Error('The private AI request could not be verified. Check saved status before sending again.'); }
      finally { clearTimeout(timer); }
    };
    proposalAiUi = EverbranchProposalAiControls.mount({document, host:$('proposal-ai-controls'), form:$('proposal-ai-form'),
      question:$('proposal-ai-question'), submit:$('proposal-ai-submit'), log:$('proposal-ai-log'), adapter,
      context:() => ({area_id:area, binding}), request, persistReceipt, savedReceipt,
      async openSource(ref) {
        const source = await proposalAiUi.client.source(ref);
        if (!source) return;
        document.dispatchEvent(new CustomEvent('enterprise:navigate', {detail:{section:source.kind === 'project' ? 'projects' : 'requests',
          area:source.area_id, selected:source.ref.slice(source.ref.indexOf(':') + 1)}}));
      }});
    $('proposal-ai-mount-status').textContent = '';
    addEventListener('pagehide', () => proposalAiUi?.invalidate());
    addEventListener('pageshow', event => {if (event.persisted) proposalAiUi?.invalidate();});
  } catch {
    $('proposal-ai-submit').disabled = true;
    $('proposal-ai-mount-status').textContent = 'Chat is temporarily unavailable. You can continue exploring the demo.';
  }
@endif
