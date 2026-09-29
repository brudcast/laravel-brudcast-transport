<?php

declare(strict_types=1);

namespace Brudcast\LaravelTransport\Tests;

use Brudcast\LaravelTransport\BrudcastTransportServiceProvider;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends Orchestra
{
    protected const SENDING_KEY = 'bk_test_sending_key';

    protected MockHandler $responses;

    protected function setUp(): void
    {
        $this->responses = new MockHandler;

        parent::setUp();
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [BrudcastTransportServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('mail.default', 'brudcast');
        $app['config']->set('mail.mailers.brudcast', [
            'transport' => 'brudcast',
            'key' => self::SENDING_KEY,
            'guzzle' => ['handler' => HandlerStack::create($this->responses)],
        ]);
    }

    protected function lastRequest(): RequestInterface
    {
        $request = $this->responses->getLastRequest();
        self::assertNotNull($request, 'No request was sent');

        return $request;
    }
}
