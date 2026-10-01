<?php

test('public registration routes are not available', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register')->assertNotFound();
});
