@php
    $reviewer = $review->displayReviewerName();
    $submittedAt = optional($review->submitted_at ?: $review->created_at)?->timezone(config('app.timezone'))->format('F j, Y');
    $rating = max(0, min(5, (int) $review->rating));
    $stars = str_repeat('★', $rating) . str_repeat('☆', max(0, 5 - $rating));
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New review on Modern Forestry</title>
</head>
<body style="margin:0;background:#f6f6f6;color:#111111;font-family:Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f6f6;margin:0;padding:0;">
        <tr>
            <td align="center" style="padding:0;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:720px;background:#ffffff;">
                    <tr>
                        <td style="padding:18px 24px 0;"></td>
                    </tr>
                    <tr>
                        <td style="padding:0 24px 20px;text-align:center;">
                            <div style="font-size:64px;line-height:1;font-weight:700;color:#000000;">Hi,</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 24px 34px;">
                            <p style="margin:0;font-size:22px;line-height:1.45;color:#111111;">
                                You just received a new review on Modern Forestry.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 24px 40px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:4px solid #ededed;border-radius:24px;">
                                <tr>
                                    <td style="padding:34px 32px;">
                                        @if($productUrl)
                                            <a href="{{ $productUrl }}" style="font-size:22px;line-height:1.35;font-weight:700;color:#163a70;text-decoration:underline;">{{ $productTitle }}</a>
                                        @else
                                            <div style="font-size:22px;line-height:1.35;font-weight:700;color:#163a70;">{{ $productTitle }}</div>
                                        @endif

                                        <div style="margin-top:26px;font-size:38px;line-height:1;color:#f6b400;letter-spacing:2px;">{{ $stars }}</div>

                                        @if($review->title)
                                            <div style="margin-top:28px;font-size:24px;line-height:1.4;font-weight:700;color:#111111;">{{ $review->title }}</div>
                                        @endif

                                        <div style="margin-top:26px;font-size:21px;line-height:1.6;color:#111111;">
                                            {{ $review->body }}
                                        </div>

                                        <div style="margin-top:24px;font-size:14px;line-height:1.6;color:#5c5c5c;">
                                            {{ $reviewer }}@if($submittedAt) · {{ $submittedAt }}@endif
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:0 24px 44px;">
                            <a href="{{ $adminUrl }}" style="display:inline-block;min-width:260px;padding:22px 34px;border-radius:18px;background:#f6b400;color:#000000;font-size:22px;line-height:1.2;font-weight:700;text-decoration:none;">
                                Open admin panel
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#132855;padding:34px 24px;text-align:center;">
                            <div style="font-size:18px;line-height:1.6;color:#ffffff;">
                                Sent on behalf of Modern Forestry
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
