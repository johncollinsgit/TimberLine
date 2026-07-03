@php
    $contextPairs = collect($context ?? [])
        ->filter(fn ($value) => filled($value))
        ->map(fn ($value, $key) => [\Illuminate\Support\Str::headline((string) $key), (string) $value])
        ->values();
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Bud support conversation</title>
</head>
<body style="margin:0;background:#f4f7f6;color:#102024;font-family:Inter,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7f6;margin:0;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:760px;background:#ffffff;border:1px solid #dbe4e3;border-radius:24px;overflow:hidden;">
                    <tr>
                        <td style="padding:28px 32px 20px;background:#123c43;color:#ffffff;">
                            <div style="font-size:12px;letter-spacing:0.14em;text-transform:uppercase;opacity:0.82;">Everbranch</div>
                            <h1 style="margin:10px 0 0;font-size:28px;line-height:1.2;">New Bud support conversation</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px;">
                            <div style="display:grid;gap:18px;">
                                <section style="padding:20px;border:1px solid #dbe4e3;border-radius:18px;background:#f8fbfa;">
                                    <div style="font-size:12px;font-weight:800;letter-spacing:0.14em;text-transform:uppercase;color:#1e5a63;">Latest question</div>
                                    <p style="margin:10px 0 0;font-size:18px;line-height:1.6;color:#102024;">{{ $question }}</p>
                                </section>

                                <section style="padding:20px;border:1px solid #dbe4e3;border-radius:18px;background:#ffffff;">
                                    <div style="font-size:12px;font-weight:800;letter-spacing:0.14em;text-transform:uppercase;color:#1e5a63;">Bud reply</div>
                                    <p style="margin:10px 0 0;font-size:16px;line-height:1.7;color:#294148;">{{ $reply }}</p>
                                </section>

                                @if($contextPairs->isNotEmpty())
                                    <section style="padding:20px;border:1px solid #dbe4e3;border-radius:18px;background:#ffffff;">
                                        <div style="font-size:12px;font-weight:800;letter-spacing:0.14em;text-transform:uppercase;color:#1e5a63;">Context</div>
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top:12px;border-collapse:collapse;">
                                            @foreach($contextPairs as [$label, $value])
                                                <tr>
                                                    <td style="padding:8px 0;font-size:13px;font-weight:700;color:#4a676d;vertical-align:top;width:160px;">{{ $label }}</td>
                                                    <td style="padding:8px 0;font-size:14px;color:#102024;">{{ $value }}</td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    </section>
                                @endif

                                <section style="padding:20px;border:1px solid #dbe4e3;border-radius:18px;background:#ffffff;">
                                    <div style="font-size:12px;font-weight:800;letter-spacing:0.14em;text-transform:uppercase;color:#1e5a63;">Transcript</div>
                                    <div style="margin-top:12px;display:grid;gap:10px;">
                                        @foreach($transcript as $entry)
                                            <div style="padding:14px 16px;border-radius:16px;background:{{ ($entry['role'] ?? '') === 'user' ? '#eef5f3' : '#f8f4ec' }};border:1px solid #dbe4e3;">
                                                <div style="font-size:11px;font-weight:800;letter-spacing:0.14em;text-transform:uppercase;color:#4a676d;">{{ strtoupper((string) ($entry['role'] ?? 'note')) }}</div>
                                                <div style="margin-top:6px;font-size:14px;line-height:1.7;color:#102024;">{{ $entry['text'] ?? '' }}</div>
                                            </div>
                                        @endforeach
                                    </div>
                                </section>

                                <section style="padding:20px;border:1px solid #dbe4e3;border-radius:18px;background:#ffffff;">
                                    <div style="font-size:12px;font-weight:800;letter-spacing:0.14em;text-transform:uppercase;color:#1e5a63;">Stored inquiry</div>
                                    <div style="margin-top:12px;font-size:14px;line-height:1.8;color:#294148;">
                                        <div>Inquiry ID: {{ $inquiry->id }}</div>
                                        <div>Source: {{ $inquiry->source_page ?: 'everbranch_promo_bud' }}</div>
                                        @if($inquiry->website)
                                            <div>Page: <a href="{{ $inquiry->website }}" style="color:#1e5a63;">{{ $inquiry->website }}</a></div>
                                        @endif
                                        <div>Status: {{ strtoupper((string) $inquiry->status) }}</div>
                                    </div>
                                </section>
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
