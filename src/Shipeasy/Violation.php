<?php

declare(strict_types=1);

namespace Shipeasy;

/**
 * A non-exception problem reported via seeViolation(). The name is a stable
 * fingerprint key — put variable data in ->extras(), never in the name.
 */
final class Violation
{
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
