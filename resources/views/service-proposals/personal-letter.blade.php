<article class="proposal-personal-letter" aria-label="A personal letter from John Collins">
    <img class="proposal-letter-logo" src="{{ $letter['logo_asset'] }}" alt="Everbranch Enterprise by Evergrove Software" width="558" height="142">
    <p>{{ $letter['salutation'] }}</p>
    @foreach($letter['paragraphs'] as $paragraph)<p>{{ $paragraph }}</p>@endforeach
    <p class="proposal-letter-signature">Sincerely,<br><strong>{{ $letter['signer'] }}</strong><br><span>{{ $letter['role'] }}</span></p>
    <figure><img src="{{ $letter['family_asset'] }}" alt="The Collins family, in the watercolor-style image used on Free Range Humanity" width="1454" height="1082"><figcaption>{{ $letter['caption'] }}</figcaption></figure>
</article>
