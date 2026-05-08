<?php

use App\Enums\MatchGameStatus;

test('legal transitions', function () {
    expect(MatchGameStatus::Pending->canTransitionTo(MatchGameStatus::InProgress))->toBeTrue();
    expect(MatchGameStatus::Pending->canTransitionTo(MatchGameStatus::Completed))->toBeTrue();
    expect(MatchGameStatus::InProgress->canTransitionTo(MatchGameStatus::Completed))->toBeTrue();
});

test('completed is terminal', function () {
    expect(MatchGameStatus::Completed->isTerminal())->toBeTrue();
    expect(MatchGameStatus::Completed->canTransitionTo(MatchGameStatus::Pending))->toBeFalse();
    expect(MatchGameStatus::Completed->canTransitionTo(MatchGameStatus::InProgress))->toBeFalse();
});

test('illegal backwards transitions are rejected', function () {
    expect(MatchGameStatus::InProgress->canTransitionTo(MatchGameStatus::Pending))->toBeFalse();
});

test('self-transitions are rejected', function () {
    foreach (MatchGameStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});
