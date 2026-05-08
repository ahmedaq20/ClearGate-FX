<?php

namespace App\Events;

use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExchangeRateUpdated
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public ExchangeRate $exchangeRate,
        public User $actor,
        public ?float $oldRate,
    ) {
        //
    }
}
