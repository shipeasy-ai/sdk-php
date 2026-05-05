<?php

declare(strict_types=1);

namespace Shipeasy;

final class ExperimentResult
{
    public function __construct(
        public bool $inExperiment,
        public string $group,
        public mixed $params,
    ) {}
}
