<?php

declare(strict_types=1);

it('can run the status command', function (): void {
    $this->artisan('config-cache-guard:status')
        ->assertExitCode(0);
});
