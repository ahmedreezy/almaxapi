<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PaymentFailureAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectText,
        public string $bodyText
    ) {
    }

    public function build(): self
    {
        return $this->subject($this->subjectText)
            ->text('emails.payment-failure-alert');
    }
}
