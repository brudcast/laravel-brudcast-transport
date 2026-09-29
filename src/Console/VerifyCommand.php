<?php

declare(strict_types=1);

namespace Brudcast\LaravelTransport\Console;

use Brudcast\LaravelTransport\BrudcastTransportServiceProvider;
use Brudcast\LaravelTransport\Transport\BrudcastApiTransport;
use Illuminate\Console\Command;
use Illuminate\Mail\MailManager;
use InvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class VerifyCommand extends Command
{
    protected $signature = 'brudcast:verify {mailer? : The mailer to verify, the default mailer when omitted}';

    protected $description = 'Check that Brudcast accepts a mailer\'s credentials, without sending an email';

    public function handle(MailManager $mail): int
    {
        $argument = $this->argument('mailer');
        $name = is_string($argument) ? $argument : $mail->getDefaultDriver();

        if ($this->laravel->make('config')->get("mail.mailers.{$name}.transport") !== BrudcastTransportServiceProvider::TRANSPORT) {
            $this->components->error("The [{$name}] mailer does not use the brudcast transport.");

            return self::FAILURE;
        }

        try {
            $transport = $mail->mailer($name)->getSymfonyTransport();
            if (! $transport instanceof BrudcastApiTransport) {
                $this->components->error(sprintf(
                    'The [%s] mailer resolved to %s, not the Brudcast transport.',
                    $name,
                    get_debug_type($transport),
                ));

                return self::FAILURE;
            }
            $transport->verify();
        } catch (TransportExceptionInterface|InvalidArgumentException $exception) {
            $this->components->error("The [{$name}] mailer could not be verified: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info("Brudcast accepted the [{$name}] mailer's credentials.");

        return self::SUCCESS;
    }
}
