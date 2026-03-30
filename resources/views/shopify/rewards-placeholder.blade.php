@extends('shopify.rewards-layout')

@section('rewards-content')
    <style>
        .rewards-placeholder {
            border-radius: 20px;
            padding: 24px;
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid rgba(15, 23, 42, 0.08);
            box-shadow: 0 22px 50px rgba(15, 23, 42, 0.12);
        }

        .rewards-placeholder h2 {
            margin-top: 0;
            font-family: "Fraunces", serif;
            font-size: 1.8rem;
            color: #0f172a;
        }

        .rewards-placeholder p {
            margin: 12px 0 0;
            color: rgba(15, 23, 42, 0.72);
            line-height: 1.65;
            font-size: 15px;
        }
    </style>
    <div class="rewards-placeholder">
        <h2>{{ $title ?? 'Coming soon' }}</h2>
        <p>{{ $message ?? 'We are building this section so you can manage the live rewards program without leaving Shopify Admin.' }}</p>
    </div>
@endsection
