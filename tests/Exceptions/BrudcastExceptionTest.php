<?php

declare(strict_types=1);

namespace Brudcast\LaravelTransport\Tests\Exceptions;

use Brudcast\LaravelTransport\Exceptions\BrudcastException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BrudcastExceptionTest extends TestCase
{
    /**
     * 2015-10-21 07:26:40 UTC, 80 seconds before the dates below
     */
    private const NOW = 1445412400;

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function retryAfterValues(): iterable
    {
        yield 'seconds' => ['120', 120];
        yield 'seconds between spaces and tabs' => [" \t7 ", 7];
        yield 'an IMF-fixdate' => ['Wed, 21 Oct 2015 07:28:00 GMT', 80];
        yield 'an RFC 850 date' => ['Wednesday, 21-Oct-15 07:28:00 GMT', 80];
        yield 'an asctime date' => ['Wed Oct 21 07:28:00 2015', 80];
        yield 'an asctime date with a one-digit day' => ['Thu Oct  1 07:28:00 2015', 0];
        yield 'a date in the past' => ['Wed, 21 Oct 2015 07:00:00 GMT', 0];
        yield 'a date that does not exist' => ['Sat, 31 Feb 2015 07:28:00 GMT', null];
        yield 'the wrong weekday' => ['Thu, 21 Oct 2015 07:28:00 GMT', null];
        yield 'another time zone' => ['Wed, 21 Oct 2015 07:28:00 CET', null];
        yield 'an IMF-fixdate with a run of spaces' => ['Wed,  21 Oct 2015 07:28:00 GMT', 80];
        yield 'the largest integer JavaScript represents exactly' => ['9007199254740991', 9007199254740991];
        yield 'one more than that' => ['9007199254740992', null];
        yield 'more seconds than an integer holds' => ['99999999999999999999', null];
        yield 'an hour that rolls over' => ['Wed, 21 Oct 2015 24:00:00 GMT', null];
        yield 'a lowercase weekday and month' => ['wed, 21 oct 2015 07:28:00 GMT', null];
        yield 'an asctime date with a zero-padded day' => ['Wed Oct 01 07:28:00 2015', null];
        yield 'a negative number' => ['-1', null];
        yield 'a fraction' => ['1.5', null];
        yield 'a trailing line feed' => ["5\n", null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('retryAfterValues')]
    public function test_parses_retry_after_as_seconds_from_now(string $value, ?int $seconds): void
    {
        self::assertSame($seconds, BrudcastException::parseRetryAfter($value, self::NOW));
    }

    public function test_has_no_retry_after_by_default(): void
    {
        self::assertNull(BrudcastException::fromResponse(429, ['status' => false])->getRetryAfter());
    }

    public function test_keeps_the_retry_after_it_is_given(): void
    {
        $exception = BrudcastException::fromResponse(429, ['status' => false, 'message' => 'Slow down'], 60);

        self::assertSame(60, $exception->getRetryAfter());
        self::assertSame('Slow down', $exception->getMessage());
    }
}
