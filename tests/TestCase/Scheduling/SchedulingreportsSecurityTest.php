<?php
declare(strict_types=1);

namespace App\Test\TestCase\Scheduling;

use App\Controller\Admin\SchedulingreportsController;
use PHPUnit\Framework\TestCase;

class SchedulingreportsSecurityTest extends TestCase
{
    private function controller(): SchedulingreportsController
    {
        $ref = new \ReflectionClass(SchedulingreportsController::class);

        return $ref->newInstanceWithoutConstructor();
    }

    private function invokePrivate(object $object, string $method, array $args = []): mixed
    {
        return (function () use ($method, $args) {
            return $this->{$method}(...$args);
        })->call($object);
    }

    public function testParsePositiveIntRejectsInjectionLikeInput(): void
    {
        $controller = $this->controller();

        $this->assertSame(123, $this->invokePrivate($controller, 'parsePositiveInt', ['123']));
        $this->assertNull($this->invokePrivate($controller, 'parsePositiveInt', ['1 OR 1=1']));
        $this->assertNull($this->invokePrivate($controller, 'parsePositiveInt', ['1;DROP TABLE users']));
        $this->assertNull($this->invokePrivate($controller, 'parsePositiveInt', ['-4']));
        $this->assertNull($this->invokePrivate($controller, 'parsePositiveInt', ['0']));
        $this->assertNull($this->invokePrivate($controller, 'parsePositiveInt', ['abc']));
    }

    public function testSanitizeIdListKeepsOnlyPositiveUniqueIntegers(): void
    {
        $controller = $this->controller();
        $raw = ['5', '05', '1 OR 1=1', '7', '0', '', null, 7];

        $clean = $this->invokePrivate($controller, 'sanitizeIdList', [$raw]);

        $this->assertSame([5, 7], $clean);
    }
}
