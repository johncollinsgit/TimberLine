<details class="panel" data-social-import data-accounts="{{ route('marketplace.public.maker.social.accounts', $maker->id) }}">
    <summary>Import an Instagram Reel, Facebook video or YouTube Short</summary>
    <label>Original post link (optional)<input name="source_post_url" type="url" maxlength="1024" placeholder="https://…" value="{{ old('source_post_url', $video->source_post_url) }}"></label>
    <p>Choose your original video or the copy saved from your creator account. You can edit it before submitting it to Watch.</p>
    <label class="button secondary compact">Choose saved video<input type="file" accept="video/mp4,video/quicktime,video/webm,.mp4,.mov,.webm" data-social-file></label>
    <p class="fine-print"><a href="https://accountscenter.instagram.com/info_and_permissions/" target="_blank" rel="noopener noreferrer">Instagram exports</a> · <a href="https://accountscenter.facebook.com/info_and_permissions/" target="_blank" rel="noopener noreferrer">Facebook exports</a> · <a href="https://support.google.com/youtube/answer/56100" target="_blank" rel="noopener noreferrer">Save your YouTube Short</a></p>
    @if($maker->tenant_id)
        <button class="button secondary compact" type="button" data-social-connect hidden>Browse connected Instagram and Facebook videos</button>
        <div data-social-choices hidden><label>Connected creator account<select data-social-accounts></select></label>
            <label>Recent video<select data-social-clips></select></label><button type="button" class="button secondary compact" data-social-load>Use this video</button></div>
    @endif
    <span data-social-status role="status" aria-live="polite"></span>
</details>
