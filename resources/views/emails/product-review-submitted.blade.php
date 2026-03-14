@php
    $reviewer = $review->displayReviewerName();
    $submittedAt = optional($review->submitted_at ?: $review->created_at)?->timezone(config('app.timezone'))->format('F j, Y g:i A');
    $stars = str_repeat('★', max(0, min(5, (int) $review->rating))) . str_repeat('☆', max(0, 5 - (int) $review->rating));
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>New product review</title>
</head>
<body style="margin:0;background:#f7f3ee;color:#1f1610;font-family:Georgia,'Times New Roman',serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f7f3ee;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#fffaf5;border:1px solid rgba(31,22,16,.08);border-radius:28px;overflow:hidden;">
                    <tr>
                        <td style="padding:32px 32px 20px;background:linear-gradient(135deg,#2f241a 0%,#6d4f2c 100%);color:#fffaf5;">
                            <div style="font-size:11px;letter-spacing:.22em;text-transform:uppercase;opacity:.72;">Forestry review inbox</div>
                            <h1 style="margin:14px 0 0;font-size:28px;line-height:1.15;font-weight:600;">New product review received</h1>
                            <p style="margin:12px 0 0;font-size:15px;line-height:1.6;opacity:.88;">A fresh product review just came in through the live storefront review form.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px 12px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="padding:0 0 18px;">
                                        <div style="font-size:12px;letter-spacing:.18em;text-transform:uppercase;color:#8b6f56;">Product</div>
                                        <div style="margin-top:8px;font-size:24px;line-height:1.25;font-weight:600;">{{ $productTitle }}</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:0 0 18px;">
                                        <div style="font-size:12px;letter-spacing:.18em;text-transform:uppercase;color:#8b6f56;">Rating</div>
                                        <div style="margin-top:8px;font-size:24px;line-height:1;color:#a56a1f;">{{ $stars }}</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:0 0 18px;">
                                        <div style="font-size:12px;letter-spacing:.18em;text-transform:uppercase;color:#8b6f56;">Reviewer</div>
                                        <div style="margin-top:8px;font-size:16px;line-height:1.5;">{{ $reviewer }}</div>
                                    </td>
                                </tr>
                                @if($review->title)
                                    <tr>
                                        <td style="padding:0 0 18px;">
                                            <div style="font-size:12px;letter-spacing:.18em;text-transform:uppercase;color:#8b6f56;">Headline</div>
                                            <div style="margin-top:8px;font-size:18px;line-height:1.45;font-weight:600;">{{ $review->title }}</div>
                                        </td>
                                    </tr>
                                @endif
                                <tr>
                                    <td style="padding:0 0 18px;">
                                        <div style="font-size:12px;letter-spacing:.18em;text-transform:uppercase;color:#8b6f56;">Review</div>
                                        <div style="margin-top:10px;border:1px solid rgba(31,22,16,.08);border-radius:22px;background:#fff;padding:18px 20px;font-size:16px;line-height:1.7;color:#2f241a;">
                                            {{ $review->body }}
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:0 0 22px;">
                                        <div style="font-size:12px;letter-spacing:.18em;text-transform:uppercase;color:#8b6f56;">Status</div>
                                        <div style="margin-top:8px;font-size:16px;line-height:1.5;">{{ $statusLabel }}</div>
                                        <div style="margin-top:6px;font-size:14px;line-height:1.6;color:#6b5648;">Submitted {{ $submittedAt ?: 'just now' }}</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 32px;">
                            <table role="presentation" cellspacing="0" cellpadding="0">
                                <tr>
                                    @if($productUrl)
                                        <td style="padding-right:12px;">
                                            <a href="{{ $productUrl }}" style="display:inline-block;border-radius:999px;background:#2f241a;color:#fffaf5;padding:12px 18px;font-size:13px;font-weight:600;text-decoration:none;">Open product</a>
                                        </td>
                                    @endif
                                    <td>
                                        <a href="{{ $adminUrl }}" style="display:inline-block;border-radius:999px;border:1px solid rgba(31,22,16,.14);background:#fffaf5;color:#2f241a;padding:12px 18px;font-size:13px;font-weight:600;text-decoration:none;">Open admin review</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
