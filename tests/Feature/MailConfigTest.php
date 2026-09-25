<?php

it('configures the resend mailer transport', function () {
    expect(config('mail.mailers.resend.transport'))->toBe('resend');
});

it('reads the resend api key from services config', function () {
    config(['services.resend.key' => 're_test_key']);

    expect(config('services.resend.key'))->toBe('re_test_key');
});

it('can use resend as the default mailer', function () {
    config([
        'mail.default' => 'resend',
        'services.resend.key' => 're_test_key',
    ]);

    expect(config('mail.default'))->toBe('resend')
        ->and(config('mail.mailers.resend.transport'))->toBe('resend')
        ->and(config('services.resend.key'))->not->toBeEmpty();
});
