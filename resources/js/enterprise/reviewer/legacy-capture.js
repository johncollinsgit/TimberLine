/** Explicit owner-side observation primitive only. Unwired; no authentication, import or automatic storage access. */
const KEY = 'everbranch-enterprise-reviewer-v1:sample-campus';
const MAX_BYTES = 4 * 1024 * 1024;
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const HASH = /^[0-9a-f]{64}$/;
const FIELDS = ['schema_version', 'capture_id', 'observed_at', 'observed_version_id', 'observed_version_hash', 'observed_source_hash', 'observed_build_id'];

function binding(value) {
    const invalid = () => { throw Object.assign(new TypeError('Explicit caller-verified capture context is required.'), {code: 'capture_binding_required'}); };
    if (!value || typeof value !== 'object' || ![Object.prototype, null].includes(Object.getPrototypeOf(value))) invalid();
    const descriptors = Object.getOwnPropertyDescriptors(value), keys = Reflect.ownKeys(descriptors);
    if (keys.length !== FIELDS.length || !FIELDS.every(key => Object.hasOwn(descriptors, key) && Object.hasOwn(descriptors[key], 'value'))) invalid();
    const v = Object.fromEntries(FIELDS.map(key => [key, descriptors[key].value]));
    if (v.schema_version !== 1 || typeof v.capture_id !== 'string' || !UUID.test(v.capture_id)
        || typeof v.observed_version_id !== 'string' || !UUID.test(v.observed_version_id)
        || typeof v.observed_version_hash !== 'string' || !HASH.test(v.observed_version_hash)
        || typeof v.observed_source_hash !== 'string' || !HASH.test(v.observed_source_hash)
        || typeof v.observed_build_id !== 'string' || !/^[A-Za-z0-9._:-]{1,120}$/.test(v.observed_build_id)
        || typeof v.observed_at !== 'string' || !/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/.test(v.observed_at)) invalid();
    const time = new Date(v.observed_at);
    if (!Number.isFinite(time.getTime()) || time.toISOString().replace('.000Z', 'Z') !== v.observed_at) invalid();
    return Object.freeze(v);
}

// Count without replacement or normalization. TextEncoder alone would replace lone UTF16 surrogates.
function scalarSize(text) {
    if (text.length > MAX_BYTES) return {reason: 'size_limit'};
    let length = 0;
    for (let i = 0; i < text.length; i++) {
        const unit = text.charCodeAt(i);
        if (unit >= 0xd800 && unit <= 0xdbff) {
            const low = text.charCodeAt(++i);
            if (!(low >= 0xdc00 && low <= 0xdfff)) return {reason: 'invalid_unicode_scalar'};
            length += 4;
        } else if (unit >= 0xdc00 && unit <= 0xdfff) return {reason: 'invalid_unicode_scalar'};
        else length += unit < 0x80 ? 1 : unit < 0x800 ? 2 : 3;
        if (length > MAX_BYTES) return {reason: 'size_limit'};
    }
    return {length};
}

function base64(bytes) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/', parts = [];
    let block = '';
    for (let i = 0; i < bytes.length; i += 3) {
        const a = bytes[i], b = bytes[i + 1], c = bytes[i + 2], word = (a << 16) | ((b || 0) << 8) | (c || 0);
        block += alphabet[(word >>> 18) & 63] + alphabet[(word >>> 12) & 63]
            + (i + 1 < bytes.length ? alphabet[(word >>> 6) & 63] : '=') + (i + 2 < bytes.length ? alphabet[word & 63] : '=');
        if (block.length >= 8192) { parts.push(block); block = ''; }
    }
    parts.push(block);
    return parts.join('');
}

/** Caller must authenticate and explicitly confirm the actual owner BEFORE calling. Shape checks do not establish that authority. */
export async function captureLegacySample(storage, captureBinding) {
    const observation = binding(captureBinding);
    const metadata = Object.freeze({schema_version: 1, storage_key: KEY, max_utf8_bytes: MAX_BYTES, observation,
        binding_assurance: 'caller_supplied_observation_not_signed_authority', provenance: 'version_and_authorship_unknown',
        completeness: 'one_storage_handle_one_key_not_all_tabs_or_devices', duplicate_keys: 'not_checked', schema_fields: 'not_checked',
        conversion_allowed: false, storage_mutation: 'none_by_this_module'});
    const result = (status, reason, extra = {}) => Object.freeze({status, reason, metadata, ...extra});
    let raw;
    try {
        const read = storage?.getItem;
        if (typeof read !== 'function') return result('unavailable', 'storage_unavailable');
        raw = Reflect.apply(read, storage, [KEY]);
    } catch { return result('unavailable', 'storage_read_failed'); }
    if (raw === null) return result('not_observed', 'key_missing');
    if (typeof raw !== 'string') return result('refused', 'storage_value_not_string');
    const size = scalarSize(raw);
    if (size.reason) return result('refused', size.reason, {observed_utf16_units: raw.length});
    let bytes;
    try {
        bytes = new TextEncoder().encode(raw);
        if (bytes.byteLength !== size.length) return result('unavailable', 'utf8_encoder_unavailable');
    } catch { return result('unavailable', 'utf8_encoder_unavailable'); }
    let digest;
    try {
        const hashed = new Uint8Array(await globalThis.crypto.subtle.digest('SHA-256', bytes));
        if (hashed.length !== 32) return result('unavailable', 'digest_unavailable');
        digest = [...hashed].map(n => n.toString(16).padStart(2, '0')).join('');
    } catch { return result('unavailable', 'digest_unavailable'); }
    const original = Object.freeze({text: raw, utf8_base64: base64(bytes), byte_length: bytes.length, sha256: digest});
    // Parsed state is never returned, traversed, used as configuration, merged, upgraded or reserialized.
    let parsed;
    try { parsed = JSON.parse(raw); }
    catch { return result('refused', 'invalid_json_syntax', {original, format: 'invalid_json_original_preserved'}); }
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return result('refused', 'top_level_not_object', {original, format: 'nonobject_json_original_preserved'});
    return result('captured_opaque', 'unvalidated_object_original', {original, format: 'json_syntax_top_object_only'});
}
