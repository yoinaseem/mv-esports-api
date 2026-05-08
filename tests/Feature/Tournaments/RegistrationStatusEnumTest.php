<?php

use App\Enums\RegistrationStatus;

test('legal transitions from Pending', function () {
    expect(RegistrationStatus::Pending->canTransitionTo(RegistrationStatus::Approved))->toBeTrue();
    expect(RegistrationStatus::Pending->canTransitionTo(RegistrationStatus::Rejected))->toBeTrue();
    expect(RegistrationStatus::Pending->canTransitionTo(RegistrationStatus::Withdrawn))->toBeTrue();
});

test('approved registrations can only withdraw', function () {
    expect(RegistrationStatus::Approved->canTransitionTo(RegistrationStatus::Withdrawn))->toBeTrue();
    expect(RegistrationStatus::Approved->canTransitionTo(RegistrationStatus::Pending))->toBeFalse();
    expect(RegistrationStatus::Approved->canTransitionTo(RegistrationStatus::Rejected))->toBeFalse();
});

test('terminal statuses (Rejected and Withdrawn) reject all transitions', function () {
    foreach ([RegistrationStatus::Rejected, RegistrationStatus::Withdrawn] as $terminal) {
        foreach (RegistrationStatus::cases() as $next) {
            expect($terminal->canTransitionTo($next))->toBeFalse();
        }
    }
});

test('self-transitions are rejected', function () {
    foreach (RegistrationStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});

test('isTerminal correctly identifies Rejected and Withdrawn', function () {
    expect(RegistrationStatus::Pending->isTerminal())->toBeFalse();
    expect(RegistrationStatus::Approved->isTerminal())->toBeFalse();
    expect(RegistrationStatus::Rejected->isTerminal())->toBeTrue();
    expect(RegistrationStatus::Withdrawn->isTerminal())->toBeTrue();
});
