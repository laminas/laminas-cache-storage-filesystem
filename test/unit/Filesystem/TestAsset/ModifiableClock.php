<?php

declare(strict_types=1);

namespace LaminasTest\Cache\Storage\Adapter\Filesystem\TestAsset;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

use function sprintf;

final class ModifiableClock implements ClockInterface
{
    private DateTimeZone $timeZone;

    /** @var non-negative-int */
    private int $secondsToAdd = 0;

    public function __construct(DateTimeZone $timeZone)
    {
        $this->timeZone = $timeZone;
    }

    public function now(): DateTimeImmutable
    {
        $interval = DateInterval::createFromDateString(sprintf('%d seconds', $this->secondsToAdd));
        return (new DateTimeImmutable(timezone: $this->timeZone))->add($interval);
    }

    /**
     * @param non-negative-int $seconds
     */
    public function addSeconds(int $seconds): void
    {
        $this->secondsToAdd = $seconds;
    }
}
