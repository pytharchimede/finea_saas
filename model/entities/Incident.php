<?php

declare(strict_types=1);

final class Incident
{
    public function __construct(private array $data = []) {}
    public static function fromArray(array $data): self { return new self($data); }
    public function toArray(): array { return $this->data; }
    public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
}
