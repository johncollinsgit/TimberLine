  let readOnlyGeneration = 0;
  function resetReadOnlyAssistant() {
    readOnlyGeneration++;
    $('read-only-answer').replaceChildren();
    $('read-only-status').textContent = '';
    $('read-only-submit').disabled = false;
    $('read-only-building').replaceChildren();
    $('read-only-assistant').hidden = true;
  }
  function readOnlyFields() {
    const building = $('read-only-intent').value === 'building';
    $('read-only-building-field').hidden = !building;
    $('read-only-building-coverage').hidden = !building;
    $('read-only-building').required = building;
  }
  function renderReadOnlyAssistant() {
    const enabled = data.features?.read_only_assistant === true && transport.mode === 'private';
    $('read-only-assistant').hidden = !enabled;
    $('read-only-label').textContent = data.sample_data === true || presentation.sample_data === true ? 'Sample data · current saved records · deterministic · no AI model' : 'Current saved records · deterministic · no AI model';
    $('read-only-cost-option').hidden = !data.features?.read_only_costs;
    $('read-only-cost-option').disabled = !data.features?.read_only_costs;
    $('read-only-intent').querySelector('[value="overview"]').disabled = !data.features?.briefing;
    $('read-only-intent').querySelector('[value="building"]').disabled = !data.features?.resources;
    if ($('read-only-intent').selectedOptions[0]?.disabled) {
      $('read-only-intent').value = data.features?.briefing ? 'overview' : data.features?.resources ? 'building' : data.features?.read_only_costs ? 'costs' : '';
    }
    const previous = $('read-only-building').value;
    $('read-only-building').replaceChildren(new Option('Choose a saved building', ''));
    for (const building of data.buildings?.items || []) $('read-only-building').append(new Option(building.title, building.id));
    if ([...$('read-only-building').options].some(option => option.value === previous)) $('read-only-building').value = previous;
    $('read-only-building-coverage').textContent = data.buildings?.coverage || 'Saved building selection is unavailable. Typed labels are not linked buildings.';
    $('read-only-submit').disabled = !$('read-only-intent').value;
    readOnlyFields();
  }
  function readOnlyText(host, tag, text, className) {
    const node = document.createElement(tag); node.textContent = text; if (className) node.className = className; host.append(node); return node;
  }
  function readOnlyLink(host, title, href) {
    const url = new URL(href, location.href);
    if (url.origin !== location.origin || !['http:', 'https:'].includes(url.protocol)) return;
    const link = readOnlyText(host, 'a', title); link.href = transport.href(url.href); return link;
  }
  function showReadOnlyAnswer(result) {
    const host = $('read-only-answer'); host.replaceChildren();
    readOnlyText(host, 'p', result.sample_data ? 'Sample data · current saved records' : 'Current saved records', 'caption');
    if (result.building) readOnlyText(host, 'h4', result.building.title);
    readOnlyText(host, 'p', result.reply);
    for (const group of result.comparisons || []) {
      const card = document.createElement('section'); card.className = 'planning-record';
      readOnlyLink(card, group.project_title, group.project_url);
      const amount = (basis) => group[basis + '_refs'].length ? `${group[basis + '_minor']} ${group.currency} minor units` : 'Not recorded in the checked inputs';
      readOnlyText(card, 'p', `Checked estimates: ${amount('estimate')}. Checked recorded costs: ${amount('recorded_cost')}.`);
      readOnlyText(card, 'p', group.difference_minor === null ? group.comparison_label : `Difference: ${group.difference_minor} ${group.currency} minor units. ${group.comparison_label}`);
      readOnlyText(card, 'p', `Inputs: ${[...group.estimate_refs, ...group.recorded_cost_refs].join(', ')}`, 'caption');
      host.append(card);
    }
    for (const job of result.jobs || []) {
      const card = document.createElement('section'); card.className = 'planning-record';
      readOnlyLink(card, job.title, job.url);
      readOnlyText(card, 'p', `Priority: ${job.priority || 'not recorded'} · Status: ${job.status} · Due: ${job.due_on || 'not recorded'}`);
      readOnlyText(card, 'p', `Next action: ${job.next_action || 'not recorded'}`); host.append(card);
    }
    const details = document.createElement('details');
    readOnlyText(details, 'summary', `Inspect ${result.sources.length} source records and calculation inputs`);
    for (const source of result.sources) {
      const card = document.createElement('section'); card.className = 'planning-record';
      readOnlyLink(card, `View source: ${source.title}`, source.url);
      const labels = {amount_minor:'Amount in minor units',basis:'Basis',due_on:'Due date',updated_at:'Recorded at',priority:'Priority',status:'Status'};
      readOnlyText(card, 'p', Object.entries(source.facts).map(([key, value]) => `${labels[key] || key}: ${value ?? 'not recorded'}`).join(' · '));
      readOnlyText(card, 'p', source.ref, 'caption'); details.append(card);
    }
    host.append(details);
    readOnlyText(host, 'p', `As of ${result.as_of}. ${result.coverage}`, 'caption');
    readOnlyText(host, 'p', result.notice, 'caption');
  }
  $('read-only-intent').onchange = () => { readOnlyGeneration++; $('read-only-answer').replaceChildren(); $('read-only-status').textContent = ''; $('read-only-submit').disabled = false; readOnlyFields(); };
  $('read-only-building').onchange = () => { readOnlyGeneration++; $('read-only-answer').replaceChildren(); $('read-only-status').textContent = ''; $('read-only-submit').disabled = false; };
  $('read-only-form').onsubmit = async event => {
    event.preventDefault(); if (!area || !data.features?.read_only_assistant || transport.mode !== 'private') return;
    const generation = ++readOnlyGeneration, requestedArea = area, intent = $('read-only-intent').value;
    const body = {area_id: requestedArea, intent};
    if (intent === 'building') body.building_id = $('read-only-building').value;
    $('read-only-answer').replaceChildren(); $('read-only-submit').disabled = true; $('read-only-status').textContent = 'Checking your current authorized records…';
    const current = () => generation === readOnlyGeneration && area === requestedArea && section === 'ask'
      && data.features?.read_only_assistant === true && (intent !== 'costs' || data.features?.read_only_costs === true);
    try {
      const result = await api(`${nextBase}/read-only-assistant${nextQuery()}`, body);
      if (!current()) return;
      if (result.provider_dispatch !== false || result.mode !== 'deterministic_records'
        || result.scope?.user_id !== String(viewer) || result.scope?.tenant_id !== String(workspace) || result.scope?.area_id !== requestedArea)
        throw new Error('This answer does not match your current signed-in workspace. Check current records again.');
      showReadOnlyAnswer(result); $('read-only-status').textContent = 'Deterministic record check complete. Open a source to inspect it.';
    } catch (error) { if (current()) { $('read-only-answer').replaceChildren(); $('read-only-status').textContent = error.message; } }
    finally { if (current()) $('read-only-submit').disabled = false; }
  };
