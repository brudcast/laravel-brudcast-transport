<?php

declare(strict_types=1);

namespace Brudcast\LaravelTransport\Tests\Console;

use Brudcast\LaravelTransport\Tests\TestCase;
use GuzzleHttp\Psr7\Response;
use Illuminate\Mail\MailManager;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Mailer\Transport\NullTransport;

final class VerifyCommandTest extends TestCase
{
    /**
     * @param  array<string, string>  $parameters
     */
    private function verify(array $parameters = []): PendingCommand
    {
        $command = $this->artisan('brudcast:verify', $parameters);
        self::assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    public function test_succeeds_when_the_key_is_accepted(): void
    {
        $this->responses->append(new Response(422, [], '{"status":false,"message":"Validation failed","errors":[]}'));

        $this->verify()
            ->expectsOutputToContain("Brudcast accepted the [brudcast] mailer's credentials.")
            ->assertSuccessful();
    }

    public function test_fails_when_a_422_is_not_the_services_validation_response(): void
    {
        $this->responses->append(new Response(422, [], '<html>Unprocessable</html>'));

        $this->verify()
            ->expectsOutputToContain('The [brudcast] mailer could not be verified')
            ->assertFailed();
    }

    public function test_fails_when_the_key_is_rejected(): void
    {
        $this->responses->append(new Response(401, [], '{"status":false,"message":"Invalid sending key"}'));

        $this->verify(['mailer' => 'brudcast'])
            ->expectsOutputToContain('Invalid sending key')
            ->assertFailed();
    }

    public function test_fails_when_credentials_are_missing(): void
    {
        config()->set('mail.mailers.empty', ['transport' => 'brudcast']);

        $this->verify(['mailer' => 'empty'])
            ->expectsOutputToContain('requires a key')
            ->assertFailed();
    }

    public function test_fails_when_the_transport_is_not_the_brudcast_transport(): void
    {
        app(MailManager::class)->extend('brudcast', fn () => new NullTransport);

        $this->verify()
            ->expectsOutputToContain('not the Brudcast transport')
            ->assertFailed();
        self::assertNull($this->responses->getLastRequest());
    }

    public function test_refuses_mailers_that_do_not_use_brudcast(): void
    {
        config()->set('mail.mailers.log', ['transport' => 'log']);

        $this->verify(['mailer' => 'log'])
            ->expectsOutputToContain('does not use the brudcast transport')
            ->assertFailed();
    }
}
