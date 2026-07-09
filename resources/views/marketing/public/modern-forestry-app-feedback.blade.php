@php
    use App\Models\ClientProjectTicket;
    use Illuminate\Support\Str;

    $columns = $columns ?? [];
    $status = (string) ($status ?? session('status', ''));
    $formAction = (string) ($formAction ?? '');

    $statusLabel = static function (ClientProjectTicket $ticket): string {
        return match ((string) $ticket->status) {
            'done' => 'Done',
            'in_progress' => 'In progress',
            'in_review' => 'Testing',
            'scoped' => 'Planned',
            'new' => 'New',
            'needs_discovery' => 'Considering',
            default => $ticket->statusLabel(),
        };
    };

    $typeLabel = static function (ClientProjectTicket $ticket): string {
        return match ((string) $ticket->type) {
            'feature' => 'Feature',
            'app_request' => 'App',
            'change_request' => 'Improvement',
            'question' => 'Question',
            default => $ticket->typeLabel(),
        };
    };
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Modern Forestry App Requests</title>
    <style>
        :root {
            color-scheme: light;
            --forest: #1f3329;
            --leaf: #52735a;
            --moss: #8fa58c;
            --bark: #594b3f;
            --paper: #faf8f3;
            --cream: #fffdf8;
            --line: #ded8cd;
            --ink: #24201c;
            --muted: #6f6a62;
            --rose: #b55c55;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--paper);
            color: var(--ink);
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.5;
        }

        a { color: inherit; }
        .mf-board {
            min-height: 100vh;
            padding: clamp(24px, 5vw, 56px) clamp(16px, 4vw, 44px);
        }

        .mf-wrap {
            width: min(1160px, 100%);
            margin: 0 auto;
        }

        .mf-hero {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(280px, 420px);
            gap: clamp(22px, 4vw, 44px);
            align-items: end;
            padding-bottom: 28px;
            border-bottom: 1px solid var(--line);
        }

        .mf-kicker {
            color: var(--leaf);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .16em;
            text-transform: uppercase;
        }

        h1 {
            max-width: 760px;
            margin: 10px 0 12px;
            color: var(--forest);
            font-family: Georgia, "Times New Roman", serif;
            font-size: clamp(36px, 7vw, 78px);
            font-weight: 700;
            letter-spacing: 0;
            line-height: .98;
        }

        .mf-lede {
            max-width: 680px;
            margin: 0;
            color: var(--muted);
            font-size: clamp(16px, 2vw, 19px);
        }

        .mf-form {
            border: 1px solid var(--line);
            background: var(--cream);
            border-radius: 8px;
            padding: 18px;
            box-shadow: 0 18px 40px rgba(31, 51, 41, .08);
        }

        .mf-form h2,
        .mf-column h2 {
            margin: 0;
            color: var(--forest);
            font-size: 18px;
            line-height: 1.2;
        }

        .mf-field {
            display: grid;
            gap: 6px;
            margin-top: 13px;
        }

        .mf-field label {
            color: var(--bark);
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .mf-field input,
        .mf-field select,
        .mf-field textarea {
            width: 100%;
            border: 1px solid #cfc7ba;
            border-radius: 6px;
            background: white;
            color: var(--ink);
            font: inherit;
            padding: 11px 12px;
        }

        .mf-field textarea {
            min-height: 110px;
            resize: vertical;
        }

        .mf-grid-two {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .mf-hidden {
            position: absolute;
            left: -10000px;
            top: auto;
            width: 1px;
            height: 1px;
            overflow: hidden;
        }

        .mf-button {
            width: 100%;
            margin-top: 15px;
            border: 0;
            border-radius: 6px;
            background: var(--forest);
            color: white;
            cursor: pointer;
            font-weight: 800;
            padding: 13px 16px;
        }

        .mf-button:hover { background: #17251f; }

        .mf-alert {
            margin: 18px 0 0;
            border: 1px solid rgba(82, 115, 90, .35);
            border-radius: 8px;
            background: rgba(143, 165, 140, .18);
            color: var(--forest);
            padding: 12px 14px;
            font-weight: 700;
        }

        .mf-errors {
            margin-top: 14px;
            border: 1px solid rgba(181, 92, 85, .35);
            border-radius: 8px;
            background: rgba(181, 92, 85, .08);
            color: #7b332e;
            padding: 12px 14px;
            font-size: 14px;
        }

        .mf-board-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-top: 28px;
        }

        .mf-column {
            min-width: 0;
        }

        .mf-column-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            min-height: 66px;
            padding-bottom: 12px;
        }

        .mf-column p {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .mf-count {
            flex: 0 0 auto;
            min-width: 32px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: var(--cream);
            color: var(--forest);
            font-size: 12px;
            font-weight: 800;
            text-align: center;
            padding: 5px 9px;
        }

        .mf-ticket {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--cream);
            padding: 15px;
            margin-bottom: 12px;
        }

        .mf-ticket-top {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 9px;
        }

        .mf-pill {
            border: 1px solid rgba(82, 115, 90, .28);
            border-radius: 999px;
            color: var(--leaf);
            font-size: 11px;
            font-weight: 800;
            line-height: 1;
            padding: 6px 8px;
            text-transform: uppercase;
            letter-spacing: .07em;
        }

        .mf-ticket h3 {
            margin: 0;
            color: var(--forest);
            font-size: 16px;
            line-height: 1.28;
        }

        .mf-ticket p {
            margin: 9px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .mf-empty {
            border: 1px dashed var(--line);
            border-radius: 8px;
            color: var(--muted);
            padding: 16px;
            font-size: 14px;
        }

        @media (max-width: 900px) {
            .mf-hero,
            .mf-board-grid {
                grid-template-columns: 1fr;
            }

            .mf-form {
                order: -1;
            }
        }

        @media (max-width: 560px) {
            .mf-grid-two {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <main class="mf-board">
        <div class="mf-wrap">
            <section class="mf-hero">
                <div>
                    <div class="mf-kicker">Modern Forestry app</div>
                    <h1>Requests, fixes, and what shipped.</h1>
                    <p class="mf-lede">Share what would make the app better, and follow along as login fixes, rewards improvements, quiz requests, wishlist issues, and new shopping features move through the queue.</p>

                    @if($status !== '')
                        <div class="mf-alert">{{ $status }}</div>
                    @endif

                    @if($errors->any())
                        <div class="mf-errors">
                            {{ $errors->first() }}
                        </div>
                    @endif
                </div>

                <form class="mf-form" method="POST" action="{{ $formAction }}">
                    <h2>Add a request</h2>
                    <div class="mf-field">
                        <label for="request_type">Type</label>
                        <select id="request_type" name="request_type" required>
                            <option value="feature" @selected(old('request_type') === 'feature')>Feature idea</option>
                            <option value="improvement" @selected(old('request_type') === 'improvement')>Improvement</option>
                            <option value="bug" @selected(old('request_type') === 'bug')>Bug</option>
                            <option value="question" @selected(old('request_type') === 'question')>Question</option>
                        </select>
                    </div>
                    <div class="mf-field">
                        <label for="title">Title</label>
                        <input id="title" name="title" value="{{ old('title') }}" maxlength="120" required>
                    </div>
                    <div class="mf-field">
                        <label for="detail">Details</label>
                        <textarea id="detail" name="detail" maxlength="2500" required>{{ old('detail') }}</textarea>
                    </div>
                    <div class="mf-grid-two">
                        <div class="mf-field">
                            <label for="name">Name</label>
                            <input id="name" name="name" value="{{ old('name') }}" maxlength="80">
                        </div>
                        <div class="mf-field">
                            <label for="email">Email</label>
                            <input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="190">
                        </div>
                    </div>
                    <div class="mf-hidden">
                        <label for="website">Website</label>
                        <input id="website" name="website" tabindex="-1" autocomplete="off">
                    </div>
                    <button class="mf-button" type="submit">Submit request</button>
                </form>
            </section>

            <section class="mf-board-grid" aria-label="Modern Forestry app request board">
                @foreach($columns as $column)
                    @php
                        $columnTickets = collect($column['tickets'] ?? []);
                    @endphp
                    <div class="mf-column">
                        <div class="mf-column-head">
                            <div>
                                <h2>{{ $column['label'] }}</h2>
                                <p>{{ $column['description'] }}</p>
                            </div>
                            <span class="mf-count">{{ $columnTickets->count() }}</span>
                        </div>

                        @forelse($columnTickets as $ticket)
                            <article class="mf-ticket">
                                <div class="mf-ticket-top">
                                    <span class="mf-pill">{{ $statusLabel($ticket) }}</span>
                                    <span class="mf-pill">{{ $typeLabel($ticket) }}</span>
                                </div>
                                <h3>{{ $ticket->title }}</h3>
                                @if($ticket->scope_notes)
                                    <p>{{ Str::limit(strip_tags((string) $ticket->scope_notes), 240) }}</p>
                                @else
                                    <p>{{ Str::limit(strip_tags((string) $ticket->problem_summary), 220) }}</p>
                                @endif
                            </article>
                        @empty
                            <div class="mf-empty">Nothing here right now.</div>
                        @endforelse
                    </div>
                @endforeach
            </section>
        </div>
    </main>
</body>
</html>
