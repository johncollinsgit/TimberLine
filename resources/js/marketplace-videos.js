import '../css/marketplace-videos.css';
import {attachStudioImporter} from './marketplace-studio-package.js';

const player = document.querySelector('[data-watch-player]');
if (player) {
    player.addEventListener('playing', () => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        if (token) fetch(player.dataset.playUrl, {method: 'POST', headers: {'X-CSRF-TOKEN': token}, credentials: 'same-origin'}).catch(() => {});
    }, {once: true});
    const products = [...document.querySelectorAll('[data-tag-start]')];
    player.addEventListener('timeupdate', () => {
        for (const product of products) {
            const {tagStart, tagEnd} = product.dataset;
            const inScene = (tagStart !== '' || tagEnd !== '') && player.currentTime >= Number(tagStart || 0)
                && (tagEnd === '' || player.currentTime < Number(tagEnd));
            product.classList.toggle('watch-in-scene', inScene);
        }
    });
    for (const button of document.querySelectorAll('[data-seek]')) {
        button.hidden = false;
        button.addEventListener('click', () => {
            player.currentTime = Number(button.dataset.seek);
            player.play().catch(() => {});
        });
    }
}

const editor = document.querySelector('[data-video-editor]');
if (editor) {
    attachStudioImporter(editor);
    const file = editor.querySelector('[data-video-file]');
    const preview = editor.querySelector('[data-source-preview]');
    const status = editor.querySelector('[data-record-status]');
    let previewUrl;
    function showFile() {
        editor.elements.source_sha256.value = '';
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        if (!file.files.length) return;
        previewUrl = URL.createObjectURL(file.files[0]);
        preview.src = previewUrl;
        preview.hidden = false;
    }
    file.addEventListener('change', showFile);
    for (const control of editor.querySelectorAll('[data-trim]')) {
        control.hidden = false;
        control.addEventListener('click', () => {
            if (Number.isFinite(preview.duration)) editor.elements[control.dataset.trim].value = Math.floor(preview.currentTime);
        });
    }
    const importVtt = editor.querySelector('[data-vtt-import]');
    importVtt.hidden = false;
    importVtt.querySelector('input').addEventListener('change', async (event) => {
        const selected = event.target.files[0];
        if (!selected) return;
        if (selected.size > 1_000_000) { status.textContent = 'Captions must be under 1 MB.'; return; }
        editor.elements.captions.value = await selected.text();
    });
    const add = editor.querySelector('[data-add-tag]');
    const rows = editor.querySelector('[data-tag-list]');
    add.hidden = rows.children.length >= 12;
    add.addEventListener('click', () => {
        const position = rows.children.length;
        if (position >= 12) return;
        const row = rows.lastElementChild.cloneNode(true);
        for (const field of row.querySelectorAll('input, select')) {
            field.name = field.name.replace(/tags\[\d+\]/, 'tags[' + position + ']');
            field.value = '';
        }
        rows.append(row);
        row.querySelector('select').focus();
        add.hidden = rows.children.length >= 12;
    });

    const record = editor.querySelector('[data-record]');
    const stop = editor.querySelector('[data-stop]');
    const media = navigator.mediaDevices;
    if (media?.getUserMedia && window.MediaRecorder && window.DataTransfer) {
        let recorder;
        let stream;
        let recording = false;
        const stopTracks = () => stream?.getTracks().forEach(track => track.stop());
        record.hidden = false;
        record.addEventListener('click', async () => {
            record.disabled = true;
            let chunks = [];
            let size = 0;
            let oversized = false;
            const maxBytes = Number(editor.dataset.maxMb) * 1024 * 1024;
            try {
                stream = await media.getUserMedia({video: {facingMode: {ideal: 'environment'}}, audio: true});
                const type = ['video/mp4', 'video/webm;codecs=vp8,opus', 'video/webm'].find(t => MediaRecorder.isTypeSupported(t));
                if (!type) throw new Error('Unsupported recording format');
                recorder = new MediaRecorder(stream, {mimeType: type});
                const mime = type.split(';')[0];
                recorder.addEventListener('dataavailable', event => {
                    size += event.data.size;
                    if (size > maxBytes) {
                        oversized = true;
                        chunks = [];
                        if (recorder.state === 'recording') recorder.stop();
                    } else if (!oversized) chunks.push(event.data);
                });
                recorder.addEventListener('stop', () => {
                    stopTracks();
                    preview.srcObject = null;
                    preview.muted = false;
                    preview.controls = true;
                    stop.hidden = true;
                    record.disabled = false;
                    recording = false;
                    file.disabled = false;
                    if (oversized) {
                        status.textContent = 'Recording exceeded the upload limit. Record a shorter clip or upload an edited file.';
                        return;
                    }
                    const transfer = new DataTransfer();
                    const extension = mime === 'video/mp4' ? 'mp4' : 'webm';
                    transfer.items.add(new File(chunks, 'maker-recording.' + extension, {type: mime}));
                    file.files = transfer.files;
                    showFile();
                    status.textContent = 'Recording ready. Preview the footage before saving.';
                }, {once: true});
                recorder.start(1000);
                recording = true;
                file.disabled = true;
                preview.srcObject = stream;
                preview.hidden = false;
                preview.muted = true;
                preview.controls = false;
                preview.play().catch(() => {});
                stop.hidden = false;
                status.textContent = 'Recording video and audio…';
            } catch {
                stopTracks();
                record.disabled = false;
                status.textContent = 'Camera recording is unavailable. Use the file picker to choose or record a video on your device.';
            }
        });
        stop.addEventListener('click', () => {
            if (recorder?.state === 'recording') recorder.stop();
        });
        editor.addEventListener('submit', event => {
            if (recording) { event.preventDefault(); status.textContent = 'Finish the recording before saving.'; }
        });
        window.addEventListener('pagehide', stopTracks);
    }
}
