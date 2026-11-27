import './medical-documents.css';

export function medicalDocumentPanel(spaceId, needId = null) {
  return `<details class="tr-case-docs" data-medical-documents="${needId === null ? '' : Number(needId)}" data-space="${Number(spaceId)}">
    <summary>${needId === null ? 'Household medical documents' : 'Original documents'}</summary>
    <div data-case-files aria-live="polite"></div>
    <form data-case-upload>
      <label>Original bill, statement or letter<input type="file" name="document" accept=".pdf,.png,.jpg,.jpeg,.heic,.heif" required><small>PDF or photo · up to 8 MB</small></label>
      <label>What this confirms (optional)<textarea name="note" maxlength="1000" rows="2" placeholder="For example, the payment amount and start date"></textarea></label>
      <div><button type="submit">Save original</button><span role="status"></span></div>
    </form></details>`;
}

export function initMedicalDocuments(root, {api, endpoint, esc, currentSpace}) {
  function current(panel) {
    return panel.isConnected && Number(panel.dataset.space) === currentSpace();
  }
  const path = panel => endpoint('/medical/' + (panel.dataset.medicalDocuments ? 'needs/' + Number(panel.dataset.medicalDocuments) + '/' : '') + 'documents', Number(panel.dataset.space));

  async function list(panel) {
    const target = panel.querySelector('[data-case-files]');
    const revision = String(Number(target.dataset.revision || 0) + 1);
    target.dataset.revision = revision;
    try {
      const result = await api(path(panel));
      if (!current(panel) || target.dataset.revision !== revision) return;
      target.innerHTML = result.documents.length ? '<ul>' + result.documents.map(doc =>
        `<li><a download href="${esc(path(panel) + '/' + Number(doc.id))}">${esc(doc.filename)}</a><small>Saved ${esc(doc.saved_on)}</small>${doc.note ? '<p>' + esc(doc.note) + '</p>' : ''}</li>`
      ).join('') + '</ul>' : '<p>No originals saved here yet.</p>';
    } catch (error) {
      if (current(panel) && target.dataset.revision === revision) target.textContent = error.message;
    }
  }

  root.addEventListener('toggle', event => {
    const panel = event.target;
    if (panel.matches?.('[data-medical-documents]') && panel.open && current(panel)) void list(panel);
  }, true);

  root.addEventListener('submit', async event => {
    const form = event.target;
    if (!form.matches('[data-case-upload]')) return;
    event.preventDefault();
    const panel = form.closest('[data-medical-documents]');
    if (!current(panel)) return;
    const button = form.querySelector('button'), status = form.querySelector('[role="status"]');
    if (button.disabled) return;
    const file = form.elements.document.files[0];
    if (!file?.size || file.size > 8 * 1024 * 1024) {
      status.textContent = 'Choose a nonempty PDF or photo up to 8 MB.';
      return;
    }
    const body = new FormData(form);
    form.elements.document.disabled = form.elements.note.disabled = button.disabled = true;
    status.textContent = 'Saving…';
    try {
      const result = await api(path(panel), 'POST', body);
      if (!current(panel)) return;
      form.reset();
      status.textContent = result.created ? 'Original saved.' : 'This original is already saved.';
      await list(panel);
    } catch (error) {
      if (current(panel)) status.textContent = error.message;
    } finally {
      form.elements.document.disabled = form.elements.note.disabled = button.disabled = false;
    }
  });
}
