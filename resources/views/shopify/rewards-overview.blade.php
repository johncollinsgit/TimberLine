@extends('shopify.rewards-layout')

@section('rewards-content')
    <style>
        .rewards-overview-stack {
            display: grid;
            gap: 18px;
        }

        .rewards-overview-card,
        .rewards-overview-panel {
            border-radius: 22px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.88);
            padding: 22px;
            box-shadow: 0 14px 32px rgba(15, 23, 42, 0.05);
        }

        .rewards-overview-eyebrow,
        .rewards-overview-summary-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.46);
        }

        .rewards-overview-title {
            margin: 8px 0 0;
            font-size: 1.25rem;
            font-weight: 700;
            line-height: 1.2;
            color: #0f172a;
        }

        .rewards-overview-intro-body,
        .rewards-overview-note-body {
            max-width: 760px;
        }

        .rewards-overview-intro-body > * + * {
            margin-top: 12px;
        }

        .rewards-overview-copy {
            margin: 0;
            font-size: 14px;
            line-height: 1.7;
            color: rgba(15, 23, 42, 0.72);
        }

        .rewards-overview-copy-spaced {
            margin-top: 12px;
        }

        .rewards-overview-header {
            max-width: 760px;
        }

        .rewards-overview-grid {
            display: grid;
            gap: 14px;
            margin-top: 20px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .rewards-overview-summary-card {
            border-radius: 18px;
            border: 1px solid rgba(15, 23, 42, 0.07);
            background: rgba(248, 250, 252, 0.95);
            padding: 18px;
        }

        .rewards-overview-summary-value {
            margin-top: 10px;
            font-size: 1.6rem;
            font-weight: 700;
            line-height: 1.15;
            color: #0f172a;
        }

        .rewards-overview-summary-detail {
            margin: 10px 0 0;
            font-size: 13px;
            line-height: 1.6;
            color: rgba(15, 23, 42, 0.64);
        }

        .rewards-overview-structure {
            display: grid;
            gap: 18px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .rewards-overview-panel-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
        }

        .rewards-overview-panel-text {
            max-width: 560px;
        }

        .rewards-overview-button {
            display: inline-flex;
            flex-shrink: 0;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(248, 250, 252, 1);
            padding: 10px 14px;
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
            text-decoration: none;
        }

        .rewards-overview-previews {
            display: grid;
            gap: 10px;
            margin-top: 18px;
        }

        .rewards-overview-preview {
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, 0.07);
            background: rgba(248, 250, 252, 0.95);
            padding: 14px 16px;
        }

        .rewards-overview-preview-title {
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
        }

        .rewards-overview-preview-detail {
            margin-top: 4px;
            font-size: 13px;
            color: rgba(15, 23, 42, 0.58);
        }

        @media (max-width: 900px) {
            .rewards-overview-grid,
            .rewards-overview-structure {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 640px) {
            .rewards-overview-grid,
            .rewards-overview-structure {
                grid-template-columns: 1fr;
            }

            .rewards-overview-panel-head {
                flex-direction: column;
            }

            .rewards-overview-button {
                width: 100%;
            }
        }
    </style>

    @include('shared.candle-cash.rewards-overview', [
        'overview' => $dashboard ?? [],
        'earnUrl' => route('shopify.embedded.rewards.earn'),
        'redeemUrl' => route('shopify.embedded.rewards.redeem'),
        'theme' => 'embedded',
    ])
@endsection
