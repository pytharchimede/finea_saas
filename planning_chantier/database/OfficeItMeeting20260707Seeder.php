<?php
declare(strict_types=1);

/** Compatibility shim: historical company data must never be seeded in SaaS. */
final class OfficeItMeeting20260707Seeder
{
    public function __construct(PDO $pdo) {}
    public function seed(): void {}
}
