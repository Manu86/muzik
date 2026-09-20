<?php

declare(strict_types=1);

final class CapturedJsonResponse extends RuntimeException
{
    /** @var array<mixed> */
    public readonly array $data;

    public function __construct(
        mixed $data,
        public readonly int $status,
    ) {
        if (!is_array($data)) {
            throw new InvalidArgumentException('A captured JSON response must be an array.');
        }
        $this->data = $data;
        parent::__construct('JSON response captured for a unit test');
    }
}
