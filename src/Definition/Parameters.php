<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Definition;

use Countable;
use CuyZ\Valinor\Definition\Exception\ParameterNotFound;
use IteratorAggregate;
use Traversable;

/**
 * @api
 *
 * @implements IteratorAggregate<string, ParameterDefinition>
 */
final class Parameters implements IteratorAggregate, Countable
{
    /** @var ParameterDefinition[] */
    private array $parameters = [];

    public function __construct(ParameterDefinition ...$parameters)
    {
        foreach ($parameters as $parameter) {
            $this->parameters[$parameter->name()] = $parameter;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->parameters[$name]);
    }

    public function get(string $name): ParameterDefinition
    {
        if (! $this->has($name)) {
            throw new ParameterNotFound($name);
        }

        return $this->parameters[$name];
    }

    public function count(): int
    {
        return count($this->parameters);
    }

    /**
     * @return Traversable<string, ParameterDefinition>
     */
    public function getIterator(): Traversable
    {
        yield from $this->parameters;
    }
}
