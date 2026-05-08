<?php

namespace App\Policies;

use App\Models\MatchEvent;
use App\Models\User;

/**
 * Match events are read-only from the API perspective — emission goes
 * through the MatchEventLogger service, not user requests.
 */
class MatchEventPolicy
{
    public function view(?User $user, MatchEvent $event): bool
    {
        return true; // Events inherit the parent match's visibility.
    }
}
