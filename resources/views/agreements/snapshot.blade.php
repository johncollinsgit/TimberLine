<!doctype html><html lang="en"><head><meta charset="utf-8"><title>{{ $version->title }}</title></head><body>
<main>{!! $version->rendered_content !!}</main>
<hr><section id="acceptance"><h2>Electronic acceptance evidence</h2><p>Signed by {{ $evidence['signer_legal_name'] }}, {{ $evidence['signer_title'] }} ({{ $evidence['signer_email'] }}) on {{ $evidence['accepted_at'] }}.</p><p>Typed signature: {{ $evidence['electronic_signature_value'] }}</p><p>Agreement content SHA-256: {{ $evidence['content_hash'] }}</p><p>Acceptance evidence SHA-256: {{ $evidenceHash }}</p><p>All required authority, scope, pricing, subscription, $50/hour out-of-scope, termination, and electronic-record confirmations were accepted.</p></section>
</body></html>
