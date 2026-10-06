<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class TrajectoryReviewMail extends Mailable
{
    public function __construct(public string $recipientName, public string $spaceName, public int $spaceId, public int $reviewCount, public array $transactions) {}

    public function build(): self
    {
        return $this->from(config('mail.from.address'), 'Trajectory')
            ->subject('Trajectory: '.$this->reviewCount.' transaction'.($this->reviewCount === 1 ? '' : 's').' to review')
            ->view('emails.trajectory-review')->text('emails.trajectory-review-text')
            ->with([
                'rows' => array_map(fn ($row) => [...$row, 'amount' => ($row['amount_cents'] < 0 ? '−' : '+').' $'.number_format(intdiv(abs($row['amount_cents']), 100)).'.'.str_pad((string) (abs($row['amount_cents']) % 100), 2, '0', STR_PAD_LEFT)], $this->transactions),
                'reviewUrl' => route('trajectory.index', ['space' => $this->spaceId, 'tab' => 'transactions']),
                'settingsUrl' => route('trajectory.index', ['space' => $this->spaceId, 'tab' => 'accounts']),
            ]);
    }
}
