<?php

class SbsCalculationService extends SbiCalculationService
{
    public function calculate(array $lines, array $items, array $params): array
    {
        return $this->calculateBase($lines, $items, $params, 'taxable_sbs');
    }
}
