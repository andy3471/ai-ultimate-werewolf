<?php

test('guests can view the rules page', function () {
    $this->get(route('rules'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Rules'));
});
