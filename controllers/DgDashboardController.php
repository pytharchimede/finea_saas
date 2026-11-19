<?php

require_once __DIR__ . '/../services/DgDashboardService.php';

class DgDashboardController
{
    private DgDashboardService $service;

    public function __construct(PDO $pdo)
    {
        $this->service = new DgDashboardService($pdo);
    }

    public function index(int $userId, array $query): array
    {
        $from=trim((string)($query['date_from']??''))?:null;$to=trim((string)($query['date_to']??''))?:null;if(!$from&&!$to){$today=new DateTimeImmutable('today');$from=$today->modify('monday this week')->format('Y-m-d');$to=$today->modify('sunday this week')->format('Y-m-d');}
        return $this->service->build($userId, trim((string) ($query['period'] ?? 'week')),$from,$to,trim((string)($query['trend_mode']??'month')));
    }
}
