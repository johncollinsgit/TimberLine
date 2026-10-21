const entry = document.querySelector('#viewer-entry');
const status = document.querySelector('#viewer-status');

try {
  await import('./viewer.js');
  await customElements.whenDefined('everbranch-product-viewer');
  const viewer = document.querySelector('#avery-knife-viewer');
  viewer.querySelector('.pv-launch').textContent = 'Open illustrative 3D preview';
  viewer.querySelector('[data-action=camera]').textContent = 'Start camera';
  viewer.mv.setAttribute('camera-orbit', '0deg 90deg 135%');
  viewer.defaultNote = 'Fictional model and preset scale. Hand tracking and finger masking are approximate. This is not an Avery sizing, fit, comfort, or safety assessment.';
  viewer.querySelector('.pv-note').textContent = viewer.defaultNote;
  entry.setAttribute('aria-busy', 'false');
  status.textContent = 'Ready to explore. Camera access is optional.';
} catch {
  entry.setAttribute('aria-busy', 'false');
  status.textContent = 'The visualizer could not load. Check your connection and reload, or return to the Avery demo. Your camera has not started.';
}
