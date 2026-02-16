<?php declare(strict_types=1);
namespace Gohany\Rtry\Tests;

use Gohany\Rtry\Impl\Duration;
use PHPUnit\Framework\TestCase;

class RtryDurationTest extends TestCase
{

    public function test_sub_second_always_ms(): void
    {
        // Arrange
        $vals = [1 => '1', 250 => '250', 999 => '999'];

        foreach ($vals as $in => $expected) {
            // Act
            $out = Duration::formatMs($in);
            // Assert
            $this->assertSame($expected, $out);
        }
    }

    public function test_seconds_and_above_choose_compact_unit(): void
    {
        $cases = [
            1000    => '1s',
            1500    => '1.5s',
            60000  => '1m',
            61000  => '61s',
            3600000 => '1h',
        ];

        foreach ($cases as $in => $expected) {
            $this->assertSame($expected, Duration::formatMs($in));
        }
    }

}