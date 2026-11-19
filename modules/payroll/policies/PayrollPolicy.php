<?php

class PayrollPolicy
{
    public function canGenerate(array $user): bool
    {
        return !empty($user);
    }

    public function canConfigure(array $user): bool
    {
        return !empty($user);
    }
}
