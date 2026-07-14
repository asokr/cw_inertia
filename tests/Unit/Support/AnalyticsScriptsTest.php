<?php

namespace Tests\Unit\Support;

use App\Support\AnalyticsScripts;
use Illuminate\Http\Request;
use Tests\TestCase;

class AnalyticsScriptsTest extends TestCase
{
    public function test_disabled_in_local_environment(): void
    {
        $this->app['env'] = 'local';
        $this->app->instance('request', Request::create('/', 'GET'));

        $this->assertFalse(AnalyticsScripts::shouldLoad());
    }

    public function test_disabled_in_testing_environment(): void
    {
        $this->app->instance('request', Request::create('/', 'GET'));

        $this->assertFalse(AnalyticsScripts::shouldLoad());
    }

    public function test_disabled_on_admin_pages_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->app->instance('request', Request::create('/cw-page/subscribers', 'GET'));

        $this->assertFalse(AnalyticsScripts::shouldLoad());
    }

    public function test_enabled_on_public_pages_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->app->instance('request', Request::create('/', 'GET'));

        $this->assertTrue(AnalyticsScripts::shouldLoad());
    }
}