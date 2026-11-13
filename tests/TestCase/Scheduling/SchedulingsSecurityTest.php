<?php
declare(strict_types=1);

namespace App\Test\TestCase\Scheduling;

use App\Controller\Admin\SchedulingsController;
use PHPUnit\Framework\TestCase;

class SchedulingsSecurityTest extends TestCase
{
    private function controller(): SchedulingsController
    {
        $ref = new \ReflectionClass(SchedulingsController::class);

        return $ref->newInstanceWithoutConstructor();
    }

    private function invokePrivate(object $object, string $method, array $args = []): mixed
    {
        return (function () use ($method, $args) {
            return $this->{$method}(...$args);
        })->call($object);
    }

    public function testParsePositiveIntRejectsMalformedValues(): void
    {
        $controller = $this->controller();

        $this->assertSame(9, $this->invokePrivate($controller, 'parsePositiveInt', ['9']));
        $this->assertNull($this->invokePrivate($controller, 'parsePositiveInt', ['1 OR 1=1']));
        $this->assertNull($this->invokePrivate($controller, 'parsePositiveInt', ['2;DROP TABLE schedulingtimings']));
        $this->assertNull($this->invokePrivate($controller, 'parsePositiveInt', ['']));
        $this->assertNull($this->invokePrivate($controller, 'parsePositiveInt', ['0']));
        $this->assertNull($this->invokePrivate($controller, 'parsePositiveInt', ['-3']));
    }
}

