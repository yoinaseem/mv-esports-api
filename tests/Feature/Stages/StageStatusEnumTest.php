<?php

use App\Enums\StageStatus;

test('legal transitions', function () {
    expect(StageStatus::Pending->canTransitionTo(StageStatus::InProgress))->toBeTrue();
    expect(StageStatus::InProgress->canTransitionTo(StageStatus::Completed))->toBeTrue();
});

test('illegal transitions are rejected', function () {
    expect(StageStatus::Pending->canTransitionTo(StageStatus::Completed))->toBeFalse();
    expect(StageStatus::InProgress->canTransitionTo(StageStatus::Pending))->toBeFalse();
    expect(StageStatus::Completed->canTransitionTo(StageStatus::Pending))->toBeFalse();
    expect(StageStatus::Completed->canTransitionTo(StageStatus::InProgress))->toBeFalse();
});

test('self-transitions are rejected', function () {
    foreach (StageStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});

test('isTerminal correctly identifies Completed', function () {
    expect(StageStatus::Pending->isTerminal())->toBeFalse();
    expect(StageStatus::InProgress->isTerminal())->toBeFalse();
    expect(StageStatus::Completed->isTerminal())->toBeTrue();
});
