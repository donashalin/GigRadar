<?php

it('generates https URLs behind the local Tailscale proxy', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'gigradar.example.ts.net'])
        ->get('/login')
        ->assertOk()
        ->assertSee('https://gigradar.example.ts.net/build/', false);
});
