<?php

use App\Enums\MatchStatus;

test('legal transitions', function () {
    $legal = [
        [MatchStatus::Pending,     MatchStatus::Scheduled],
        [MatchStatus::Pending,     MatchStatus::Cancelled],
        [MatchStatus::Conditional, MatchStatus::Pending],
        [MatchStatus::Conditional, MatchStatus::Cancelled],
        [MatchStatus::Scheduled,   MatchStatus::InProgress],
        [MatchStatus::Scheduled,   MatchStatus::Walkover],
        [MatchStatus::Scheduled,   MatchStatus::Cancelled],
        [MatchStatus::InProgress,  MatchStatus::Completed],
        [MatchStatus::InProgress,  MatchStatus::Walkover],
        [MatchStatus::InProgress,  MatchStatus::Cancelled],
    ];

    foreach ($legal as [$from, $to]) {
        expect($from->canTransitionTo($to))->toBeTrue("{$from->value} → {$to->value} should be legal");
    }
});

test('terminal states reject all transitions', function () {
    foreach ([MatchStatus::Completed, MatchStatus::Walkover, MatchStatus::Cancelled] as $terminal) {
        foreach (MatchStatus::cases() as $other) {
            expect($terminal->canTransitionTo($other))->toBeFalse();
        }
    }
});

test('illegal forward jumps are rejected', function () {
    $illegal = [
        [MatchStatus::Pending,    MatchStatus::InProgress],
        [MatchStatus::Pending,    MatchStatus::Completed],
        [MatchStatus::Scheduled,  MatchStatus::Completed],
        [MatchStatus::Scheduled,  MatchStatus::Pending],
        [MatchStatus::InProgress, MatchStatus::Scheduled],
        [MatchStatus::InProgress, MatchStatus::Pending],
    ];

    foreach ($illegal as [$from, $to]) {
        expect($from->canTransitionTo($to))->toBeFalse("{$from->value} → {$to->value} should be illegal");
    }
});

test('self-transitions are rejected', function () {
    foreach (MatchStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});

test('isTerminal correctly identifies terminal states', function () {
    expect(MatchStatus::Completed->isTerminal())->toBeTrue();
    expect(MatchStatus::Walkover->isTerminal())->toBeTrue();
    expect(MatchStatus::Cancelled->isTerminal())->toBeTrue();
    expect(MatchStatus::Pending->isTerminal())->toBeFalse();
    expect(MatchStatus::Conditional->isTerminal())->toBeFalse();
});
