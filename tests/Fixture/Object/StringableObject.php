<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Tests\Fixture\Object;

use Stringable;

final class StringableObject implements Stringable
{
    private string $value;

    public function __construct(string $value = 'foo')
    {
        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
