<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the Laravel application instance for the example guide app.
     *
     * Boots `bootstrap/app.php` (the same bootstrap `php artisan serve` uses)
     * so that `$this->get('/')` exercises the real route in-process.
     */
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
