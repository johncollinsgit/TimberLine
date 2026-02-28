<div class="min-w-0">
  @livewire(
    \App\Livewire\Retail\Markets\EventMatchWizard::class,
    [
      'planId' => $planId,
    ],
    key('markets-event-match-wizard-'.(int) $planId)
  )
</div>
