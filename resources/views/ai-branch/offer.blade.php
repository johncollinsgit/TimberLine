<x-layouts::app.sidebar title="AI Voice Branch">
    <div class="mx-auto max-w-2xl space-y-5 p-6">
        <p>{{ $tenant->name }}</p><h1 class="text-3xl font-semibold">AI Voice Branch</h1>
        <p>Conversation by voice or text, permitted workspace context, and private saved memory.</p>
        @if($existing)
            <p>AI access is included in your existing plan. Setup is being prepared. Usage is charged at your approved model rate after you choose a spending cap.</p>
        @else
            <p><strong>$20/month access, plus metered model usage.</strong> No setup fee. Usage draws from prepaid Everbranch credit at the displayed rate for your selected model.</p>
            <form method="post" action="{{ route('ai-branch.checkout', ['tenant' => $tenant->slug]) }}" class="space-y-4">@csrf
                <label class="block"><input type="checkbox" name="confirmed" value="1" required> I approve $20/month AI access, with usage charged separately. I will choose a usage cap before enabling AI.</label>
                <button class="fb-btn fb-btn-primary">Review secure checkout</button>
            </form>
        @endif
        <p>AI starts off with a $0 usage cap. A subscription alone does not enable model requests. Provider readiness, current workspace access and owner approval are checked before use.</p>
    </div>
</x-layouts::app.sidebar>
