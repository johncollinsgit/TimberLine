@php
    $columns = $columns ?? [];
    $tickets = collect($tickets ?? []);
    $rankedRequests = collect($rankedRequests ?? []);
    $activeTicket = is_array($activeTicket ?? null) ? $activeTicket : null;
    $status = (string) ($status ?? session('status', ''));
    $formAction = (string) ($formAction ?? '');
    $appScreenshotUrl = (string) ($appScreenshotUrl ?? asset('brand/modern-forestry-app-home.png'));
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Modern Forestry App Roadmap</title>
    <style>
        :root {
            color-scheme: light;
            --ink: #181716;
            --muted: #6d6760;
            --soft: #f6f2eb;
            --paper: #fffdf8;
            --line: rgba(24, 23, 22, .12);
            --forest: #1f3329;
            --leaf: #5f856b;
            --mint: #baf3d4;
            --blossom: #c66aa1;
            --gold: #d9a441;
        }

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            background: var(--paper);
            color: var(--ink);
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "SF Pro Display", "Segoe UI", sans-serif;
            line-height: 1.45;
        }

        a { color: inherit; text-decoration: none; }
        button, input, select, textarea { font: inherit; }

        .page {
            overflow: hidden;
            background:
                radial-gradient(circle at 10% 10%, rgba(198, 106, 161, .14), transparent 28rem),
                radial-gradient(circle at 90% 0%, rgba(186, 243, 212, .28), transparent 32rem),
                linear-gradient(180deg, #fffdf8 0%, #f7f3ec 54%, #fffdf8 100%);
        }

        .wrap {
            width: min(1180px, calc(100% - 32px));
            margin: 0 auto;
        }

        .hero {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(280px, 410px);
            gap: clamp(26px, 6vw, 72px);
            align-items: center;
            min-height: min(820px, 100svh);
            padding: clamp(36px, 7vw, 78px) 0 clamp(30px, 5vw, 64px);
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: var(--forest);
            font-size: 13px;
            font-weight: 800;
            letter-spacing: .16em;
            text-transform: uppercase;
        }

        .eyebrow::before {
            content: "";
            width: 9px;
            height: 9px;
            border-radius: 999px;
            background: var(--mint);
            box-shadow: 0 0 0 5px rgba(186, 243, 212, .38);
        }

        h1 {
            max-width: 760px;
            margin: 18px 0 18px;
            font-size: clamp(48px, 9vw, 112px);
            line-height: .92;
            letter-spacing: 0;
            font-weight: 800;
        }

        .lede {
            max-width: 680px;
            margin: 0;
            color: var(--muted);
            font-size: clamp(18px, 2vw, 23px);
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 28px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: white;
            color: var(--forest);
            cursor: pointer;
            font-weight: 800;
            padding: 10px 18px;
        }

        .button.primary {
            border-color: var(--forest);
            background: var(--forest);
            color: white;
        }

        .button:hover { transform: translateY(-1px); }

        .phone-frame {
            position: relative;
            width: min(410px, 92vw);
            margin: 0 auto;
            border: 1px solid rgba(24, 23, 22, .18);
            border-radius: 48px;
            background: #0e0e0e;
            padding: 12px;
            box-shadow: 0 36px 80px rgba(31, 51, 41, .24);
        }

        .phone-frame img {
            display: block;
            width: 100%;
            max-height: 720px;
            object-fit: cover;
            object-position: top;
            border-radius: 38px;
        }

        .floating-card {
            position: absolute;
            right: -18px;
            bottom: 54px;
            width: min(260px, 72vw);
            border: 1px solid rgba(255, 255, 255, .45);
            border-radius: 18px;
            background: rgba(255, 255, 255, .86);
            backdrop-filter: blur(18px);
            padding: 16px;
            box-shadow: 0 18px 50px rgba(31, 51, 41, .18);
        }

        .floating-card strong {
            display: block;
            color: var(--forest);
            font-size: 15px;
        }

        .floating-card span {
            display: block;
            margin-top: 4px;
            color: var(--muted);
            font-size: 13px;
        }

        .status-note,
        .errors {
            margin-top: 18px;
            border-radius: 18px;
            padding: 14px 16px;
            font-weight: 750;
        }

        .status-note {
            border: 1px solid rgba(95, 133, 107, .22);
            background: rgba(186, 243, 212, .34);
            color: var(--forest);
        }

        .errors {
            border: 1px solid rgba(198, 106, 161, .32);
            background: rgba(198, 106, 161, .10);
            color: #7c315d;
        }

        .section {
            padding: clamp(32px, 7vw, 78px) 0;
            border-top: 1px solid var(--line);
        }

        .section-head {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 24px;
            margin-bottom: 22px;
        }

        .section h2 {
            margin: 0;
            color: var(--forest);
            font-size: clamp(30px, 4vw, 56px);
            line-height: 1;
            letter-spacing: 0;
        }

        .section-copy {
            max-width: 580px;
            color: var(--muted);
            font-size: 17px;
        }

        .request-form {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(280px, .8fr);
            gap: 18px;
            border: 1px solid rgba(24, 23, 22, .10);
            border-radius: 28px;
            background: rgba(255, 255, 255, .74);
            backdrop-filter: blur(20px);
            padding: clamp(18px, 4vw, 28px);
            box-shadow: 0 24px 70px rgba(31, 51, 41, .10);
        }

        .form-grid {
            display: grid;
            gap: 12px;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        label {
            display: grid;
            gap: 6px;
            color: var(--forest);
            font-size: 12px;
            font-weight: 850;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        input, select, textarea {
            width: 100%;
            border: 1px solid rgba(24, 23, 22, .14);
            border-radius: 16px;
            background: white;
            color: var(--ink);
            padding: 13px 14px;
            outline: none;
        }

        textarea {
            min-height: 146px;
            resize: vertical;
        }

        input:focus, select:focus, textarea:focus {
            border-color: rgba(31, 51, 41, .72);
            box-shadow: 0 0 0 4px rgba(186, 243, 212, .38);
        }

        .hidden-field {
            position: absolute;
            left: -10000px;
            width: 1px;
            height: 1px;
            overflow: hidden;
        }

        .launch-copy {
            border-radius: 22px;
            background: var(--forest);
            color: white;
            padding: 22px;
        }

        .launch-copy h3 {
            margin: 0;
            font-size: 24px;
            line-height: 1.05;
        }

        .launch-copy p {
            margin: 12px 0 0;
            color: rgba(255, 255, 255, .78);
        }

        .ranking {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 12px;
        }

        .rank-card,
        .ticket-card,
        .detail-panel {
            border: 1px solid rgba(24, 23, 22, .10);
            border-radius: 24px;
            background: rgba(255, 255, 255, .74);
            box-shadow: 0 18px 50px rgba(31, 51, 41, .08);
        }

        .rank-card {
            display: grid;
            gap: 12px;
            padding: 16px;
        }

        .rank-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 850;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .vote-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 48px;
            border-radius: 999px;
            background: var(--forest);
            color: white;
            padding: 6px 10px;
            font-weight: 850;
        }

        .rank-card h3,
        .ticket-card h3 {
            margin: 0;
            color: var(--ink);
            font-size: 17px;
            line-height: 1.18;
        }

        .board-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }

        .column-head {
            min-height: 92px;
            margin-bottom: 12px;
        }

        .column-head h3 {
            margin: 0;
            color: var(--forest);
            font-size: 24px;
            line-height: 1.1;
        }

        .column-head p {
            margin: 7px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .ticket-stack {
            display: grid;
            gap: 12px;
        }

        .ticket-card {
            display: grid;
            gap: 13px;
            padding: 17px;
            transition: transform .18s ease, box-shadow .18s ease;
        }

        .ticket-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 22px 60px rgba(31, 51, 41, .14);
        }

        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }

        .pill {
            border: 1px solid rgba(24, 23, 22, .10);
            border-radius: 999px;
            background: rgba(255, 255, 255, .74);
            color: var(--forest);
            font-size: 11px;
            font-weight: 850;
            letter-spacing: .07em;
            line-height: 1;
            padding: 7px 9px;
            text-transform: uppercase;
        }

        .pill.hot { background: rgba(186, 243, 212, .52); }
        .pill.shipped { background: rgba(217, 164, 65, .18); }

        .ticket-card p,
        .detail-panel p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
        }

        .card-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .mini-form { margin: 0; }

        .vote-button {
            border: 0;
            border-radius: 999px;
            background: #111;
            color: white;
            cursor: pointer;
            font-size: 13px;
            font-weight: 850;
            padding: 9px 12px;
        }

        .open-link {
            color: var(--forest);
            font-size: 13px;
            font-weight: 850;
        }

        .empty {
            border: 1px dashed rgba(24, 23, 22, .18);
            border-radius: 20px;
            color: var(--muted);
            padding: 18px;
        }

        .detail-panel {
            display: grid;
            gap: 18px;
            padding: clamp(18px, 4vw, 28px);
        }

        .detail-top {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 16px;
            align-items: start;
        }

        .detail-panel h2 {
            font-size: clamp(28px, 4vw, 52px);
        }

        .detail-copy {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .detail-copy article {
            border-radius: 20px;
            background: rgba(246, 242, 235, .82);
            padding: 18px;
        }

        .detail-copy h3,
        .comments h3 {
            margin: 0 0 8px;
            color: var(--forest);
            font-size: 15px;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .comments {
            display: grid;
            grid-template-columns: minmax(0, .9fr) minmax(0, 1.1fr);
            gap: 16px;
        }

        .comment-list {
            display: grid;
            gap: 10px;
        }

        .comment {
            border-radius: 18px;
            background: white;
            padding: 14px;
        }

        .comment strong {
            display: block;
            color: var(--forest);
            font-size: 14px;
        }

        .comment span {
            color: var(--muted);
            font-size: 12px;
        }

        .comment p {
            margin-top: 8px;
        }

        .comment-form {
            display: grid;
            gap: 12px;
        }

        @media (max-width: 980px) {
            .hero,
            .request-form,
            .comments,
            .detail-copy {
                grid-template-columns: 1fr;
            }

            .ranking,
            .board-grid {
                grid-template-columns: 1fr;
            }

            .phone-frame {
                width: min(360px, 86vw);
            }

            .floating-card {
                right: 8px;
                bottom: 26px;
            }
        }

        @media (max-width: 560px) {
            .wrap { width: min(100% - 24px, 1180px); }
            .hero { min-height: auto; }
            .form-row,
            .detail-top {
                grid-template-columns: 1fr;
            }

            .section-head {
                display: grid;
            }
        }
    </style>
</head>
<body>
    <main class="page">
        <section class="hero wrap">
            <div>
                <div class="eyebrow">Modern Forestry app launch board</div>
                <h1>Help shape what ships next.</h1>
                <p class="lede">Vote on app ideas, ask questions on each request, and see the fixes and features already moving from customer feedback into the Modern Forestry app.</p>
                <div class="hero-actions">
                    <a class="button primary" href="#request">Share an idea</a>
                    <a class="button" href="#roadmap">Explore the roadmap</a>
                </div>

                @if($status !== '')
                    <div class="status-note">{{ $status }}</div>
                @endif

                @if($errors->any())
                    <div class="errors">{{ $errors->first() }}</div>
                @endif
            </div>

            <div class="phone-frame" aria-label="Modern Forestry app home screenshot">
                <img src="{{ $appScreenshotUrl }}" alt="Modern Forestry app home screen showing the Spring Collection and browse collections.">
                <div class="floating-card">
                    <strong>Now shipping from feedback</strong>
                    <span>Better login, clearer rewards, smoother wishlist saves, and new shopping surfaces.</span>
                </div>
            </div>
        </section>

        <section id="request" class="section">
            <div class="wrap">
                <div class="section-head">
                    <div>
                        <h2>Make a request.</h2>
                        <p class="section-copy">Tell us what would make the app easier, faster, or more fun to use. Requests appear on the board so other customers can vote and add questions.</p>
                    </div>
                </div>

                <form class="request-form" method="POST" action="{{ $formAction }}">
                    <div class="form-grid">
                        <label>
                            Type
                            <select name="request_type" required>
                                <option value="feature" @selected(old('request_type') === 'feature')>Feature idea</option>
                                <option value="improvement" @selected(old('request_type') === 'improvement')>Improvement</option>
                                <option value="bug" @selected(old('request_type') === 'bug')>Bug</option>
                                <option value="question" @selected(old('request_type') === 'question')>Question</option>
                            </select>
                        </label>
                        <label>
                            Short title
                            <input name="title" value="{{ old('title') }}" maxlength="120" required>
                        </label>
                        <label>
                            What would you like to see?
                            <textarea name="detail" maxlength="2500" required>{{ old('detail') }}</textarea>
                        </label>
                        <div class="form-row">
                            <label>
                                Name
                                <input name="name" value="{{ old('name') }}" maxlength="80">
                            </label>
                            <label>
                                Email
                                <input name="email" type="email" value="{{ old('email') }}" maxlength="190">
                            </label>
                        </div>
                        <div class="hidden-field">
                            <label>Website<input name="website" tabindex="-1" autocomplete="off"></label>
                        </div>
                    </div>
                    <div class="launch-copy">
                        <h3>Customers are part of the launch.</h3>
                        <p>Feature requests are ranked by anonymous upvotes. Questions and comments help us understand the real-world use case before we build.</p>
                        <button class="button primary" type="submit">Submit request</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="section">
            <div class="wrap">
                <div class="section-head">
                    <div>
                        <h2>Most requested.</h2>
                        <p class="section-copy">Anonymous votes help the most useful ideas rise first.</p>
                    </div>
                </div>
                <div class="ranking">
                    @forelse($rankedRequests as $ticket)
                        <a class="rank-card" href="{{ $ticket['url'] }}">
                            <div class="rank-top">
                                <span>#{{ $loop->iteration }}</span>
                                <span class="vote-count">{{ $ticket['votes'] }}</span>
                            </div>
                            <h3>{{ $ticket['title'] }}</h3>
                            <span class="open-link">Open request</span>
                        </a>
                    @empty
                        <div class="empty">Votes will appear here as customers start ranking feature ideas.</div>
                    @endforelse
                </div>
            </div>
        </section>

        @if($activeTicket)
            <section id="ticket" class="section">
                <div class="wrap">
                    <article class="detail-panel">
                        <div class="detail-top">
                            <div>
                                <div class="meta">
                                    <span class="pill hot">{{ $activeTicket['status_label'] }}</span>
                                    <span class="pill">{{ $activeTicket['type_label'] }}</span>
                                    <span class="pill">{{ $activeTicket['votes'] }} votes</span>
                                    <span class="pill">{{ $activeTicket['comments_count'] }} comments</span>
                                </div>
                                <h2>{{ $activeTicket['title'] }}</h2>
                            </div>
                            <form class="mini-form" method="POST" action="{{ $activeTicket['vote_action'] }}">
                                <button class="vote-button" type="submit">Upvote</button>
                            </form>
                        </div>

                        <div class="detail-copy">
                            <article>
                                <h3>What customers are asking for</h3>
                                <p>{{ $activeTicket['summary'] }}</p>
                            </article>
                            <article>
                                <h3>What is happening</h3>
                                <p>{{ $activeTicket['update'] }}</p>
                            </article>
                        </div>

                        <div class="comments">
                            <div>
                                <h3>Questions and comments</h3>
                                <div class="comment-list">
                                    @forelse($activeTicket['comments'] as $comment)
                                        <article class="comment">
                                            <strong>{{ $comment['author_name'] }}</strong>
                                            @if($comment['created_at'])
                                                <span>{{ $comment['created_at'] }}</span>
                                            @endif
                                            <p>{{ $comment['body'] }}</p>
                                        </article>
                                    @empty
                                        <div class="empty">No comments yet. Ask a question or add more context.</div>
                                    @endforelse
                                </div>
                            </div>

                            <form class="comment-form" method="POST" action="{{ $activeTicket['comment_action'] }}">
                                <h3>Add a comment</h3>
                                <label>
                                    Name
                                    <input name="author_name" maxlength="80">
                                </label>
                                <label>
                                    Question or comment
                                    <textarea name="body" maxlength="1600" required></textarea>
                                </label>
                                <div class="hidden-field">
                                    <label>Website<input name="website" tabindex="-1" autocomplete="off"></label>
                                </div>
                                <button class="button primary" type="submit">Post comment</button>
                            </form>
                        </div>
                    </article>
                </div>
            </section>
        @endif

        <section id="roadmap" class="section">
            <div class="wrap">
                <div class="section-head">
                    <div>
                        <h2>Roadmap.</h2>
                        <p class="section-copy">Tap any request to vote, add a comment, or ask a question.</p>
                    </div>
                </div>

                <div class="board-grid">
                    @foreach($columns as $column)
                        @php $columnTickets = collect($column['tickets'] ?? []); @endphp
                        <div>
                            <div class="column-head">
                                <h3>{{ $column['label'] }}</h3>
                                <p>{{ $column['description'] }}</p>
                            </div>

                            <div class="ticket-stack">
                                @forelse($columnTickets as $ticket)
                                    <article class="ticket-card">
                                        <a href="{{ $ticket['url'] }}">
                                            <div class="meta">
                                                <span class="pill {{ $ticket['status'] === 'done' ? 'shipped' : 'hot' }}">{{ $ticket['status_label'] }}</span>
                                                <span class="pill">{{ $ticket['type_label'] }}</span>
                                                <span class="pill">{{ $ticket['votes'] }} votes</span>
                                            </div>
                                            <h3>{{ $ticket['title'] }}</h3>
                                            <p>{{ $ticket['summary'] }}</p>
                                        </a>
                                        <div class="card-actions">
                                            <a class="open-link" href="{{ $ticket['url'] }}">Comment or ask</a>
                                            <form class="mini-form" method="POST" action="{{ $ticket['vote_action'] }}">
                                                <button class="vote-button" type="submit">Upvote</button>
                                            </form>
                                        </div>
                                    </article>
                                @empty
                                    <div class="empty">Nothing here right now.</div>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    </main>
</body>
</html>
