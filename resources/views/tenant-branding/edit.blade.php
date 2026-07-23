@php
    $field = static fn (string $key, mixed $fallback = ''): mixed => old($key, $profile->{$key} ?? $fallback);
    $brandState = [
        'name' => $field('display_name', $theme['display_name']),
        'tagline' => $field('tagline', $theme['tagline']),
        'primary' => $field('primary_color', $theme['primary_color']),
        'accent' => $field('accent_color', $theme['accent_color']),
        'surface' => $field('surface_color', $theme['surface_color']),
        'text' => $field('text_color', $theme['text_color']),
        'style' => $field('display_style', $theme['display_style']),
        'corners' => $field('corner_style', $theme['corner_style']),
        'decor' => $field('decor_preset', $theme['decor_preset']),
    ];
    $logoUrl = $theme['light_logo_url'];
    $darkLogoUrl = $theme['dark_logo_url'];
    $phoneToken = chr(123).chr(123).'PHONE'.chr(125).chr(125);
    $websiteToken = chr(123).chr(123).'WEBSITE'.chr(125).chr(125);
    $emailToken = chr(123).chr(123).'EMAIL'.chr(125).chr(125);
@endphp

<x-layouts::app.sidebar :title="'Customize '.$theme['display_name']">
    <div
        class="tenant-brand-editor mx-auto w-full max-w-[1360px] space-y-6 px-4 py-6 sm:px-6"
        x-data='@json($brandState)'
        :style="`--preview-primary:${primary};--preview-accent:${accent};--preview-surface:${surface};--preview-text:${text};`"
    >
        <header class="tenant-brand-editor__hero">
            <div>
                <div class="tenant-brand-editor__eyebrow">Workspace identity</div>
                <h1>Customize workspace</h1>
                <p>Control the name, mark, palette, texture, and downloadable launch assets your team sees inside this workspace.</p>
            </div>
            <div class="tenant-brand-editor__hero-mark" aria-hidden="true">
                <img src="{{ $logoUrl }}" alt="">
            </div>
        </header>

        @if (session('status'))
            <div class="tenant-brand-editor__notice" role="status">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="tenant-brand-editor__error" role="alert">
                <strong>One or more brand settings need attention.</strong>
                <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <section class="tenant-brand-preview" :data-corners="corners" :data-style="style" :data-decor="decor">
            <div class="tenant-brand-preview__decor"></div>
            <div class="tenant-brand-preview__sidebar">
                <img src="{{ $logoUrl }}" alt="" class="tenant-brand-preview__logo tenant-brand-preview__logo--light">
                <img src="{{ $darkLogoUrl }}" alt="" class="tenant-brand-preview__logo tenant-brand-preview__logo--dark">
                <span>Overview</span><span>Customers</span><span class="is-active">Work</span><span>Settings</span>
            </div>
            <div class="tenant-brand-preview__canvas">
                <div class="tenant-brand-preview__topline"><span x-text="name || 'Workspace'">{{ $theme['display_name'] }}</span><span>Team workspace</span></div>
                <div class="tenant-brand-preview__content">
                    <div><small>YOUR WORKSPACE</small><h2 x-text="name || 'Workspace'">{{ $theme['display_name'] }}</h2><p x-text="tagline || 'A workspace that looks like your business.'">{{ $theme['tagline'] }}</p></div>
                    <button type="button">New work order</button>
                </div>
                <div class="tenant-brand-preview__metrics"><div><small>OPEN</small><strong>12</strong></div><div><small>SCHEDULED</small><strong>8</strong></div><div><small>COMPLETE</small><strong>27</strong></div></div>
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-[minmax(0,1.12fr)_minmax(320px,.88fr)]">
            <form method="POST" action="{{ route('tenant.brand.update') }}" class="tenant-brand-card tenant-brand-form">
                @csrf @method('PUT')
                <div class="tenant-brand-card__heading"><div><span>Brand controls</span><h2>Make it yours</h2></div><p>Colors are checked before saving so controls stay readable.</p></div>
                <div class="tenant-brand-field-grid">
                    <label>Workspace display name<input name="display_name" x-model="name" maxlength="120" required></label>
                    <label>Tagline <span class="tenant-brand-optional">optional</span><input name="tagline" x-model="tagline" maxlength="180" placeholder="What your business is known for"></label>
                </div>
                <div class="tenant-brand-color-grid">
                    @foreach ([
                        ['primary_color', 'primary', 'Primary', 'Navigation, primary actions'],
                        ['accent_color', 'accent', 'Accent', 'Active states and calls to action'],
                        ['surface_color', 'surface', 'Surface', 'Workspace card and canvas'],
                        ['text_color', 'text', 'Text', 'Primary readable text'],
                    ] as [$input, $model, $label, $help])
                        <label class="tenant-brand-color-control">
                            <span><strong>{{ $label }}</strong><small>{{ $help }}</small></span>
                            <span class="tenant-brand-color-input"><input type="color" x-model="{{ $model }}" aria-label="{{ $label }} color"><input name="{{ $input }}" x-model="{{ $model }}" pattern="#[0-9A-Fa-f]{6}" maxlength="7" required></span>
                        </label>
                    @endforeach
                </div>
                <div class="tenant-brand-field-grid tenant-brand-field-grid--three">
                    <label>Display style<select name="display_style" x-model="style"><option value="classic">Classic</option><option value="technical">Technical</option><option value="editorial">Editorial</option><option value="bold">Bold</option></select></label>
                    <label>Corner style<select name="corner_style" x-model="corners"><option value="soft">Soft</option><option value="standard">Standard</option><option value="sharp">Sharp</option></select></label>
                    <label>Decor preset<select name="decor_preset" x-model="decor"><option value="none">Quiet</option><option value="signal">Signal</option><option value="grid">Grid</option><option value="dawn">Dawn</option></select></label>
                </div>
                <div class="tenant-brand-form__actions"><button type="submit" class="tenant-brand-button tenant-brand-button--primary">Save workspace brand</button><span>Applied to signed-in workspace pages only.</span></div>
            </form>

            <aside class="tenant-brand-card tenant-brand-assets">
                <div class="tenant-brand-card__heading"><div><span>Brand assets</span><h2>Marks &amp; icon</h2></div><p>PNG, JPG, or WebP · 2 MB max</p></div>
                @foreach ([
                    ['light_logo', 'Light logo', $logoUrl, 'Used on light workspace surfaces.'],
                    ['dark_logo', 'Dark logo', $darkLogoUrl, 'Used automatically in dark mode.'],
                    ['icon', 'App icon', $theme['icon_url'], 'Used for compact workspace UI.'],
                ] as [$slot, $label, $url, $description])
                    <form method="POST" action="{{ route('tenant.brand.assets.upload', $slot) }}" enctype="multipart/form-data" class="tenant-brand-upload">
                        @csrf
                        <img src="{{ $url }}" alt="{{ $label }} preview"><div><strong>{{ $label }}</strong><small>{{ $description }}</small><input type="file" name="asset" accept="image/png,image/jpeg,image/webp" required><button type="submit">Replace</button></div>
                    </form>
                @endforeach
                <form method="POST" action="{{ route('tenant.brand.reset') }}" class="mt-4">@csrf<button type="submit" class="tenant-brand-reset">Reset to safe default</button></form>
            </aside>
        </section>

        @if($kit !== [])
            <section class="tenant-brand-card tenant-brand-kit">
                <div class="tenant-brand-card__heading"><div><span>Collins launch kit</span><h2>Ready to customize and use</h2></div><p>Templates use editable {{ $phoneToken }}, {{ $websiteToken }}, and {{ $emailToken }} tokens. Contact details are intentionally not prefilled.</p></div>
                <div class="tenant-brand-kit__grid">
                    @foreach($kit as $item)
                        <a href="{{ route('tenant.brand.kit.download', $item['key']) }}" class="tenant-brand-kit__item">
                            <span class="tenant-brand-kit__file">{{ strtoupper(pathinfo($item['download'], PATHINFO_EXTENSION)) }}</span>
                            <span><strong>{{ $item['label'] }}</strong><small>{{ $item['type'] }}</small></span><span aria-hidden="true">↓</span>
                        </a>
                    @endforeach
                </div>
                @if($assets->isNotEmpty())
                    <details class="tenant-brand-library"><summary>View uploaded brand asset history ({{ $assets->count() }})</summary><div>@foreach($assets as $asset)<span>{{ $asset->label }} · {{ $asset->created_at?->format('M j, Y') }}</span>@endforeach</div></details>
                @endif
            </section>
        @elseif($assets->isNotEmpty())
            <section class="tenant-brand-card tenant-brand-kit"><details class="tenant-brand-library"><summary>View uploaded brand asset history ({{ $assets->count() }})</summary><div>@foreach($assets as $asset)<span>{{ $asset->label }} · {{ $asset->created_at?->format('M j, Y') }}</span>@endforeach</div></details></section>
        @endif
    </div>

    <style>
      .tenant-brand-editor{color:#0b1b36}.tenant-brand-editor__hero{background:linear-gradient(120deg,#061d42,#0d3475 60%,#1464e8);border-radius:28px;box-shadow:0 25px 70px -45px #061d42;display:flex;gap:2rem;justify-content:space-between;overflow:hidden;padding:2.5rem;position:relative}.tenant-brand-editor__hero:after{background:radial-gradient(circle,rgba(118,184,255,.42),transparent 68%);content:"";height:430px;position:absolute;right:-125px;top:-210px;width:430px}.tenant-brand-editor__hero h1{color:#fff;font:800 clamp(2rem,4vw,3.25rem)/1 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;letter-spacing:-.045em;margin:.45rem 0 .7rem;position:relative;z-index:1}.tenant-brand-editor__hero p{color:#cce0ff;line-height:1.6;margin:0;max-width:680px;position:relative;z-index:1}.tenant-brand-editor__eyebrow,.tenant-brand-card__heading>div>span{color:#86bdff;font-size:.7rem;font-weight:850;letter-spacing:.16em;text-transform:uppercase}.tenant-brand-editor__hero-mark{align-items:center;background:#061d42;border:1px solid rgba(255,255,255,.28);border-radius:22px;display:flex;justify-content:center;min-width:220px;overflow:hidden;padding:1.25rem;position:relative;z-index:1}.tenant-brand-editor__hero-mark img{display:block;max-height:100px;max-width:200px}.tenant-brand-editor__notice,.tenant-brand-editor__error{border-radius:16px;padding:1rem 1.15rem}.tenant-brand-editor__notice{background:#ecfdf5;border:1px solid #9ee7c4;color:#065f46;font-weight:700}.tenant-brand-editor__error{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239}.tenant-brand-editor__error ul{font-size:.9rem;margin:.5rem 0 0;padding-left:1.2rem}.tenant-brand-preview{background:var(--preview-surface);border:1px solid color-mix(in srgb,var(--preview-primary) 18%,#dfe6ee);border-radius:22px;box-shadow:0 22px 55px -42px var(--preview-primary);display:grid;grid-template-columns:206px minmax(0,1fr);min-height:330px;overflow:hidden;position:relative}.tenant-brand-preview__decor{inset:0;opacity:0;pointer-events:none;position:absolute}.tenant-brand-preview[data-decor=signal] .tenant-brand-preview__decor{background:radial-gradient(circle at 88% 12%,color-mix(in srgb,var(--preview-accent) 35%,transparent),transparent 36%),linear-gradient(135deg,transparent 60%,color-mix(in srgb,var(--preview-primary) 12%,transparent));opacity:1}.tenant-brand-preview[data-decor=grid] .tenant-brand-preview__decor{background-image:linear-gradient(color-mix(in srgb,var(--preview-primary) 8%,transparent) 1px,transparent 1px),linear-gradient(90deg,color-mix(in srgb,var(--preview-primary) 8%,transparent) 1px,transparent 1px);background-size:28px 28px;opacity:1}.tenant-brand-preview[data-decor=dawn] .tenant-brand-preview__decor{background:radial-gradient(circle at 90% 20%,color-mix(in srgb,var(--preview-accent) 32%,transparent),transparent 38%);opacity:1}.tenant-brand-preview__sidebar{background:var(--preview-primary);color:#dbeafe;display:flex;flex-direction:column;gap:.55rem;padding:1.35rem 1rem;position:relative;z-index:1}.tenant-brand-preview__logo{height:56px;margin:0 0 1.35rem;max-width:178px;object-fit:contain;object-position:left center}.tenant-brand-preview__logo--dark{display:none}.tenant-brand-preview__sidebar span{border-radius:10px;font-size:.82rem;font-weight:700;padding:.65rem .75rem}.tenant-brand-preview__sidebar .is-active{background:var(--preview-accent);color:#fff}.tenant-brand-preview__canvas{padding:1.4rem 1.55rem;position:relative;z-index:1}.tenant-brand-preview__topline{color:color-mix(in srgb,var(--preview-text) 58%,transparent);display:flex;font-size:.75rem;font-weight:750;justify-content:space-between}.tenant-brand-preview__content{align-items:end;display:flex;gap:1rem;justify-content:space-between;margin:3.5rem 0 1.8rem}.tenant-brand-preview__content small,.tenant-brand-preview__metrics small{color:var(--preview-accent);font-size:.66rem;font-weight:850;letter-spacing:.13em}.tenant-brand-preview h2{color:var(--preview-text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:2rem;letter-spacing:-.04em;margin:.25rem 0}.tenant-brand-preview[data-style=editorial] h2{font-family:Georgia,serif}.tenant-brand-preview[data-style=bold] h2{font-size:2.35rem;text-transform:uppercase}.tenant-brand-preview p{color:color-mix(in srgb,var(--preview-text) 70%,transparent);margin:0}.tenant-brand-preview button,.tenant-brand-button{background:var(--preview-primary,#061d42);border:0;border-radius:11px;color:#fff;cursor:pointer;font-weight:800;padding:.8rem 1rem;white-space:nowrap}.tenant-brand-preview__metrics{display:grid;gap:.75rem;grid-template-columns:repeat(3,1fr)}.tenant-brand-preview__metrics>div{background:color-mix(in srgb,var(--preview-primary) 5%,var(--preview-surface));border:1px solid color-mix(in srgb,var(--preview-primary) 10%,transparent);border-radius:14px;padding:.85rem}.tenant-brand-preview__metrics strong{color:var(--preview-text);display:block;font-size:1.35rem;margin-top:.25rem}.tenant-brand-preview[data-corners=sharp],.tenant-brand-preview[data-corners=sharp] *{border-radius:0!important}.tenant-brand-preview[data-corners=standard]{border-radius:14px}.tenant-brand-card{background:#fff;border:1px solid #dfe6ee;border-radius:20px;box-shadow:0 18px 45px -42px #061d42;padding:1.35rem}.tenant-brand-card__heading{align-items:start;display:flex;gap:1rem;justify-content:space-between;margin-bottom:1.4rem}.tenant-brand-card__heading h2{color:#0b1b36;font-size:1.35rem;font-weight:800;letter-spacing:-.02em;margin:.2rem 0 0}.tenant-brand-card__heading p{color:#65748a;font-size:.8rem;line-height:1.45;margin:0;max-width:280px;text-align:right}.tenant-brand-field-grid{display:grid;gap:1rem;grid-template-columns:repeat(2,minmax(0,1fr))}.tenant-brand-field-grid--three{grid-template-columns:repeat(3,minmax(0,1fr));margin-top:1rem}.tenant-brand-form label{color:#293a54;display:grid;font-size:.78rem;font-weight:800;gap:.42rem}.tenant-brand-form input:not([type=color]),.tenant-brand-form select{background:#fbfdff;border:1px solid #cdd8e5;border-radius:10px;color:#0b1b36;min-height:43px;padding:.6rem .72rem;width:100%}.tenant-brand-optional{color:#7b8798;font-weight:600}.tenant-brand-color-grid{display:grid;gap:.75rem;grid-template-columns:repeat(2,minmax(0,1fr));margin-top:1rem}.tenant-brand-color-control{align-items:center;background:#fbfdff;border:1px solid #e0e8f1;border-radius:13px;display:flex!important;justify-content:space-between;padding:.72rem}.tenant-brand-color-control small{color:#718096;font-size:.69rem;font-weight:600}.tenant-brand-color-input{align-items:center;display:flex;gap:.4rem}.tenant-brand-color-input input[type=color]{border:0;border-radius:8px;height:32px;padding:0;width:32px}.tenant-brand-color-input input:not([type=color]){font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.75rem;min-height:32px;padding:.3rem;width:82px}.tenant-brand-form__actions{align-items:center;display:flex;gap:1rem;margin-top:1.35rem}.tenant-brand-form__actions span{color:#718096;font-size:.75rem}.tenant-brand-button--primary{background:#061d42}.tenant-brand-assets{align-self:start}.tenant-brand-upload{align-items:center;border-top:1px solid #e8eef5;display:grid;gap:.7rem;grid-template-columns:65px 1fr;padding:1rem 0}.tenant-brand-upload:first-of-type{border-top:0;padding-top:0}.tenant-brand-upload img{background:#f5f8fc;border:1px solid #dbe5f0;border-radius:12px;height:58px;object-fit:contain;padding:.35rem;width:65px}.tenant-brand-upload strong,.tenant-brand-upload small{display:block}.tenant-brand-upload small{color:#718096;font-size:.7rem;line-height:1.4;margin:.1rem 0 .45rem}.tenant-brand-upload input{font-size:.7rem;max-width:100%}.tenant-brand-upload button,.tenant-brand-reset{background:#fff;border:1px solid #b9c7d7;border-radius:8px;color:#17345d;font-size:.7rem;font-weight:800;margin-left:.4rem;padding:.38rem .55rem}.tenant-brand-reset{margin:0;width:100%}.tenant-brand-kit__grid{display:grid;gap:.65rem;grid-template-columns:repeat(4,minmax(0,1fr))}.tenant-brand-kit__item{align-items:center;background:#f8fbff;border:1px solid #dbe5f0;border-radius:14px;color:#0b1b36;display:flex;gap:.65rem;min-height:76px;padding:.75rem;text-decoration:none}.tenant-brand-kit__item:hover{border-color:#1464e8;box-shadow:0 13px 25px -22px #1464e8;transform:translateY(-1px)}.tenant-brand-kit__file{background:#061d42;border-radius:8px;color:#d7e9ff;font-size:.62rem;font-weight:850;letter-spacing:.05em;padding:.42rem}.tenant-brand-kit__item strong,.tenant-brand-kit__item small{display:block}.tenant-brand-kit__item strong{font-size:.78rem}.tenant-brand-kit__item small{color:#718096;font-size:.66rem;margin-top:.15rem}.tenant-brand-kit__item>span:last-child{color:#1464e8;font-size:1.15rem;margin-left:auto}.tenant-brand-library{color:#4b5b70;font-size:.78rem;margin-top:1rem}.tenant-brand-library summary{cursor:pointer;font-weight:800}.tenant-brand-library div{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.6rem}.tenant-brand-library span{background:#eff4fa;border-radius:999px;padding:.3rem .55rem}
      @media (max-width:900px){.tenant-brand-editor__hero{padding:1.6rem}.tenant-brand-editor__hero-mark{display:none}.tenant-brand-preview{grid-template-columns:150px 1fr}.tenant-brand-kit__grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media (max-width:640px){.tenant-brand-editor{padding-inline:0}.tenant-brand-editor__hero{border-radius:18px}.tenant-brand-preview{grid-template-columns:1fr}.tenant-brand-preview__sidebar{display:none}.tenant-brand-preview__canvas{padding:1.15rem}.tenant-brand-preview__content{align-items:start;flex-direction:column;margin:2rem 0 1.25rem}.tenant-brand-field-grid,.tenant-brand-field-grid--three,.tenant-brand-color-grid{grid-template-columns:1fr}.tenant-brand-card__heading{display:block}.tenant-brand-card__heading p{text-align:left;margin-top:.5rem}.tenant-brand-form__actions{align-items:start;flex-direction:column}.tenant-brand-kit__grid{grid-template-columns:1fr}}
      .dark .tenant-brand-preview__logo--light{display:none}.dark .tenant-brand-preview__logo--dark{display:block}
    </style>
</x-layouts::app.sidebar>
