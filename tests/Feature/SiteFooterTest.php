<?php

test('home and rules pages render with the site chrome', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Welcome'));

    $this->get(route('rules'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Rules'));
});
