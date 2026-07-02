<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', ['title' => (string) ($poll['title'] ?? 'Candle Club Vote')])
    <style>
        body {
            background: #f8fafc;
            color: #111827;
        }

        .eg-public-poll {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
        }

        .eg-public-poll__panel {
            width: min(680px, 100%);
            background: #fff;
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 8px;
            padding: 22px;
            display: grid;
            gap: 16px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
        }

        .eg-public-poll h1,
        .eg-public-poll p {
            margin: 0;
        }

        .eg-public-poll__muted {
            color: rgba(15, 23, 42, 0.65);
            line-height: 1.5;
        }

        .eg-public-poll__options {
            display: grid;
            gap: 8px;
        }

        .eg-public-poll__option {
            border: 1px solid rgba(15, 23, 42, 0.12);
            border-radius: 8px;
            padding: 12px;
            display: flex;
            justify-content: space-between;
            gap: 10px;
        }
    </style>
</head>
<body>
    <main class="eg-public-poll">
        <section class="eg-public-poll__panel">
            <div>
                <h1>{{ $poll['title'] ?? 'Candle Club Vote' }}</h1>
                <p class="eg-public-poll__muted">{{ $poll['description'] ?? 'Vote for the next Candle Club scent.' }}</p>
            </div>
            <div class="eg-public-poll__options">
                @foreach((array) ($poll['options'] ?? []) as $option)
                    <div class="eg-public-poll__option">
                        <strong>{{ $option['label'] }}</strong>
                        <span>{{ $option['votes'] ?? 0 }} votes</span>
                    </div>
                @endforeach
            </div>
            <p class="eg-public-poll__muted">To vote, enter the email or phone tied to your active Candle Club subscription. Evergrove will send a one-time code before recording the vote.</p>
        </section>
    </main>
</body>
</html>
