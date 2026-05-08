<?php

namespace App\Enums;

/**
 * Which slot of a downstream match a winner / loser advances into.
 * Used by the four advancement-FK pairs on matches:
 *
 *    matches.winner_advances_to_match_id  +  winner_advances_to_slot
 *    matches.loser_advances_to_match_id   +  loser_advances_to_slot
 *
 * The advancement service (commit 9) reads these to populate the
 * downstream match's participant_a or participant_b.
 */
enum BracketSlot: string
{
    case A = 'a';
    case B = 'b';
}
