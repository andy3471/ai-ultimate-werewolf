<?php

it('injects soketi config into the inertia shell', function () {
    config([
        'broadcasting.connections.pusher.key' => 'test-key',
        'broadcasting.connections.pusher.options.host' => 'soketi',
        'broadcasting.connections.pusher.options.port' => 6001,
        'broadcasting.connections.pusher.options.scheme' => 'http',
        'broadcasting.connections.pusher.options.cluster' => 'mt1',
    ]);

    $this->get(route('login'))
        ->assertSuccessful()
        ->assertSee('window.__SOKETI__', false)
        ->assertSee('"key":"test-key"', false)
        ->assertSee('"port":6001', false)
        ->assertSee('"scheme":"http"', false)
        ->assertSee('"cluster":"mt1"', false);
});

it('uses the pusher broadcast connection for soketi', function () {
    expect(config('broadcasting.connections.pusher.driver'))->toBe('pusher')
        ->and(config('broadcasting.connections.pusher'))->toHaveKeys(['key', 'secret', 'app_id', 'options']);
});
