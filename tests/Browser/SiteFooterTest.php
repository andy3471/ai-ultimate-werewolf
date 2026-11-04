<?php

it('credits Andrew Hargrave in the site footer', function () {
    $this->visit('/')
        ->assertSee('Andrew Hargrave')
        ->assertSee('Portfolio')
        ->assertSee('GitHub')
        ->assertDontSee('me@andyh.app')
        ->assertNoJavaScriptErrors();
});
