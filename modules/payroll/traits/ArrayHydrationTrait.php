<?php

trait ArrayHydrationTrait
{
    protected function stringValue(array $row, string $key, string $default = ''): string
    {
        return trim((string) ($row[$key] ?? $default));
    }
}
