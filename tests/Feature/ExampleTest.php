<?php

test('home redirects to games index', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('games.index'));
});
