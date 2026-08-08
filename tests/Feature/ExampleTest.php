<?php

test('a guest visiting the root route is redirected to login', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('auth.login'));
});
