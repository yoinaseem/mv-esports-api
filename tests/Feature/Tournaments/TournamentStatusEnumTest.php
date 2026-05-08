<?php

use App\Enums\TournamentStatus;

test('terminal states reject every transition', function () {
    foreach ([TournamentStatus::Completed, TournamentStatus::Cancelled] as $terminal) {
        foreach (TournamentStatus::cases() as $other) {
            expect($terminal->canTransitionTo($other))
                ->toBeFalse("Terminal {$terminal->value} should not allow transition to {$other->value}");
        }
    }
});

test('legal transitions are accepted', function () {
    $legal = [
        [TournamentStatus::DraftPendingReview, TournamentStatus::Draft],
        [TournamentStatus::DraftPendingReview, TournamentStatus::Cancelled],
        [TournamentStatus::Draft,              TournamentStatus::RegistrationOpen],
        [TournamentStatus::Draft,              TournamentStatus::Cancelled],
        [TournamentStatus::RegistrationOpen,   TournamentStatus::RegistrationClosed],
        [TournamentStatus::RegistrationOpen,   TournamentStatus::Cancelled],
        [TournamentStatus::RegistrationClosed, TournamentStatus::InProgress],
        [TournamentStatus::RegistrationClosed, TournamentStatus::Cancelled],
        [TournamentStatus::InProgress,         TournamentStatus::Completed],
        [TournamentStatus::InProgress,         TournamentStatus::Cancelled],
    ];

    foreach ($legal as [$from, $to]) {
        expect($from->canTransitionTo($to))
            ->toBeTrue("Transition {$from->value} -> {$to->value} should be legal");
    }
});

test('illegal forward jumps are rejected', function () {
    $illegal = [
        [TournamentStatus::DraftPendingReview, TournamentStatus::RegistrationOpen],
        [TournamentStatus::DraftPendingReview, TournamentStatus::InProgress],
        [TournamentStatus::Draft,              TournamentStatus::RegistrationClosed],
        [TournamentStatus::Draft,              TournamentStatus::Completed],
        [TournamentStatus::RegistrationOpen,   TournamentStatus::InProgress],
        [TournamentStatus::RegistrationClosed, TournamentStatus::Completed],
    ];

    foreach ($illegal as [$from, $to]) {
        expect($from->canTransitionTo($to))
            ->toBeFalse("Transition {$from->value} -> {$to->value} should be illegal");
    }
});

test('backward transitions are universally rejected', function () {
    $backward = [
        [TournamentStatus::Draft,              TournamentStatus::DraftPendingReview],
        [TournamentStatus::RegistrationOpen,   TournamentStatus::Draft],
        [TournamentStatus::RegistrationClosed, TournamentStatus::RegistrationOpen],
        [TournamentStatus::InProgress,         TournamentStatus::RegistrationClosed],
    ];

    foreach ($backward as [$from, $to]) {
        expect($from->canTransitionTo($to))
            ->toBeFalse("Backward transition {$from->value} -> {$to->value} should be illegal");
    }
});

test('self-transitions are rejected', function () {
    foreach (TournamentStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});

test('isTerminal correctly identifies Completed and Cancelled', function () {
    expect(TournamentStatus::Completed->isTerminal())->toBeTrue();
    expect(TournamentStatus::Cancelled->isTerminal())->toBeTrue();
    expect(TournamentStatus::Draft->isTerminal())->toBeFalse();
    expect(TournamentStatus::InProgress->isTerminal())->toBeFalse();
});

test('isPublic hides drafts and exposes everything else', function () {
    expect(TournamentStatus::DraftPendingReview->isPublic())->toBeFalse();
    expect(TournamentStatus::Draft->isPublic())->toBeFalse();
    expect(TournamentStatus::RegistrationOpen->isPublic())->toBeTrue();
    expect(TournamentStatus::RegistrationClosed->isPublic())->toBeTrue();
    expect(TournamentStatus::InProgress->isPublic())->toBeTrue();
    expect(TournamentStatus::Completed->isPublic())->toBeTrue();
    expect(TournamentStatus::Cancelled->isPublic())->toBeTrue();
});
