<?php

interface AoSourceAdapterInterface
{
    public function getSourceName(): string;
    public function fetchPublicOpportunities(): array;
    public function normalizeOpportunity(array $raw): array;
}
