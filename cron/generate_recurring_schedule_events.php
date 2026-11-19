<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/CronController.php';

// Usage (Windows Task Scheduler / cron):
//   php cron/generate_recurring_schedule_events.php
// Optionnel pour les liens email en contexte CLI:
//   set APP_BASE_URL=https://votre-domaine.tld

try {
    $res = (new CronController())->generateRecurringScheduleEvents(new DateTimeImmutable('now'), 30, 60);

    if (empty($res['ok'])) {
        fwrite(STDERR, "ERR: " . (string)($res['error'] ?? 'Echec generation') . "\n");
        exit(1);
    }

    $msg = sprintf(
        "OK: rules=%d created=%d skipped=%d errors=%d\n",
        (int)($res['rules'] ?? 0),
        (int)($res['created'] ?? 0),
        (int)($res['skipped'] ?? 0),
        (int)($res['errors'] ?? 0)
    );
    echo $msg;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "ERR: Exception\n");
    exit(3);
}

