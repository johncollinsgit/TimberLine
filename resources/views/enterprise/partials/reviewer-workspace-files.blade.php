<section data-workspace-files hidden aria-label="Files and photos">
  <header class="detail-actions"><strong>Files &amp; photos</strong><button class="button" type="button" data-work-files-refresh>Refresh</button></header>
  <div class="detail-actions" data-work-files-picker>
    <button class="button" type="button" data-work-files-choose>Add image file</button><input type="file" data-work-files-input accept="image/jpeg,image/png" hidden>
    <button class="button" type="button" data-work-photo-choose>Add photo</button><input type="file" data-work-photo-input accept="image/jpeg,image/png" capture="environment" hidden>
    <button class="button primary" type="button" data-work-files-upload disabled>Upload</button>
  </div>
  <p class="muted" data-work-files-hint>JPEG or PNG images, up to 5 MiB. Other file types are unavailable.</p>
  <p data-work-files-selected role="status" aria-live="polite"></p>
  <div data-work-files-pending hidden>
    <p data-work-files-pending-label></p>
    <div class="detail-actions">
      <button class="button" type="button" data-work-files-open-original hidden>Open original work</button>
      <button class="button" type="button" data-work-files-recover>Recover upload</button>
      <button class="button" type="button" data-work-files-retry>Retry reservation</button>
      <button class="button primary" type="button" data-work-files-resume>Resume selected file</button>
      <button class="button" type="button" data-work-files-cancel>Cancel upload</button>
    </div>
  </div>
  <p data-work-files-status role="status" aria-live="polite"></p>
  <ul data-work-files-list aria-label="Saved files and photos"></ul>
</section>
