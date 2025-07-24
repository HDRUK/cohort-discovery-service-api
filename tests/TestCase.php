<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Database\Eloquent\Model;
use Tests\Traits\RefreshDatabaseLite;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabaseLite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liteSetUp();

        $this->disableMiddleware();
        $this->disableObservers();
    }

    protected function disableMiddleware(): void
    {
        $this->withoutMiddleware();
    }

    protected function enableMiddleware(): void
    {
        $this->withMiddleware();
    }

    protected function disableObservers(): void
    {
        Model::unsetEventDispatcher();
    }

    protected function enableObservers(): void
    {
        Model::setEventDispatcher(app('events'));
    }
}
