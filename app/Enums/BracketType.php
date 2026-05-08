<?php

namespace App\Enums;

/**
 * Where a match sits in the bracket structure. Set by the bracket
 * generator (commit 8) at match creation; used by the advancement
 * service to know which advancement rules apply.
 */
enum BracketType: string
{
    case Winners    = 'winners';
    case Losers     = 'losers';
    case GrandFinal = 'grand_final';
    case Group      = 'group';
}
