const composer = document.querySelector('[data-show-composer]');
if (composer) {
    const picker = composer.querySelector('[data-photo-input]');
    const drop = composer.querySelector('[data-photo-drop]');
    const status = composer.querySelector('[data-photo-status]');
    const preview = composer.querySelector('[data-photo-preview]');
    const choose = composer.querySelector('[data-photo-choose]');
    const remove = composer.querySelector('[data-photo-remove]');
    const review = composer.querySelector('[data-review]');
    const photo = composer.elements.photo_id;
    let upload;

    async function preparePhoto(file) {
        async function asJpeg() {
            const bitmap = await createImageBitmap(file);
            try {
                const canvas = document.createElement('canvas');
                const ratio = Math.min(1, 1600 / Math.max(bitmap.width, bitmap.height));
                canvas.width = Math.max(1, Math.round(bitmap.width * ratio));
                canvas.height = Math.max(1, Math.round(bitmap.height * ratio));
                const context = canvas.getContext('2d');
                context.fillStyle = '#fff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                return await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.87));
            } finally {
                bitmap.close();
            }
        }

        if (/^image\/(jpeg|png|gif)$/.test(file.type) && file.size <= 1_900_000) {
            return file;
        }
        if (file.size <= 24_000_000 && file.type !== 'image/gif') {
            const jpeg = await asJpeg().catch(() => null);
            if (jpeg && jpeg.size <= 5_000_000) {
                return new File([jpeg], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
            }
        }
        if (/^image\/(jpeg|png|gif)$/.test(file.type) && file.size <= 5_000_000) {
            return file;
        }
        throw new Error('This photo couldn’t be prepared. Choose a JPEG, PNG, or GIF up to 5 MB.');
    }

    function displayPhoto(id, url) {
        photo.value = id;
        preview.hidden = remove.hidden = !id;
        if (url) preview.src = url;
        else preview.removeAttribute('src');
        choose.textContent = id ? 'Replace photo' : 'Add photo';
    }

    async function uploadPhoto(file) {
        if (!file) return;
        upload?.abort();
        const request = new AbortController();
        upload = request;
        review.disabled = true;
        status.textContent = 'Uploading photo…';
        try {
            const payload = new FormData();
            payload.append('photo', await preparePhoto(file));
            if (request.signal.aborted) return;
            payload.append('store', composer.elements.store.value);
            const response = await fetch(composer.dataset.upload, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': composer.elements._token.value },
                body: payload,
                signal: request.signal,
            });
            const result = await response.json();
            if (!response.ok) {
                throw new Error(result.errors?.photo?.[0] || 'Choose a JPEG, PNG, or GIF up to 5 MB and try again.');
            }
            if (upload !== request) return;
            displayPhoto(result.id, result.url);
            status.textContent = 'Photo added.';
        } catch (error) {
            if (error.name !== 'AbortError' && upload === request) status.textContent = error.message;
        } finally {
            if (upload === request) {
                upload = null;
                review.disabled = false;
            }
        }
    }

    function clearPhoto() {
        upload?.abort();
        upload = null;
        review.disabled = false;
        status.textContent = '';
        displayPhoto('', null);
    }

    choose.addEventListener('click', () => picker.click());
    picker.addEventListener('change', () => {
        uploadPhoto(picker.files[0]);
        picker.value = '';
    });
    remove.addEventListener('click', clearPhoto);
    composer.elements.store.addEventListener('change', clearPhoto);
    drop.addEventListener('dragover', event => event.preventDefault());
    drop.addEventListener('drop', event => {
        event.preventDefault();
        uploadPhoto(event.dataTransfer.files[0]);
    });
    composer.addEventListener('submit', event => {
        if (upload) event.preventDefault();
    });
}

const audience = document.querySelector('[data-show-audience]');
if (audience) {
    const recipients = [...audience.querySelectorAll('[data-recipient]')];
    const send = audience.querySelector('[data-send]');
    const update = () => {
        const excluded = recipients.filter(recipient => !recipient.checked);
        const count = recipients.length - excluded.length;
        audience.elements.excluded.value = JSON.stringify(excluded.map(recipient => Number(recipient.value)));
        audience.querySelector('[data-count]').textContent = count;
        if (send) {
            send.disabled = count === 0;
            send.textContent = 'Send to ' + count + (count === 1 ? ' customer' : ' customers');
        }
    };
    audience.addEventListener('change', update);
    audience.addEventListener('submit', () => {
        update();
        if (send) send.disabled = true;
    });
    update();
}
