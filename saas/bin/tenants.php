<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require dirname(__DIR__) . '/src/Database/PlatformDatabase.php';
require dirname(__DIR__) . '/src/Services/TenantService.php';

use Finea\Saas\Services\TenantService;

$service = new TenantService();
$command = $argv[1] ?? 'list';

try {
    if ($command === 'list') {
        $tenants = $service->list();

        if (!$tenants) {
            echo "Aucune entreprise enregistrée.\n";
            exit(0);
        }

        foreach ($tenants as $tenant) {
            printf(
                "#%d | %s | %s | %s | %s\n",
                $tenant['id'],
                $tenant['company_name'],
                $tenant['slug'],
                $tenant['status'],
                $tenant['database_name']
            );
        }

        exit(0);
    }

    if ($command === 'create') {
        echo "Identifiant entreprise : ";
        $slug = trim((string) fgets(STDIN));

        echo "Nom commercial : ";
        $companyName = trim((string) fgets(STDIN));

        echo "Raison sociale (facultatif) : ";
        $legalName = trim((string) fgets(STDIN));

        echo "\nConfirmer la création ? (oui/non) : ";
        $confirmation = strtolower(trim((string) fgets(STDIN)));

        if ($confirmation !== 'oui') {
            echo "Création annulée.\n";
            exit(0);
        }

        $id = $service->create(
            $slug,
            $companyName,
            $legalName !== '' ? $legalName : null
        );

        echo "Entreprise enregistrée avec succès. ID : {$id}\n";
        echo "Statut : pending\n";
        echo "Base métier : réservée, non provisionnée.\n";
        exit(0);
    }

    echo "Usage : php saas/bin/tenants.php [list|create]\n";
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "Opération impossible : " . $e->getMessage() . "\n");
    exit(1);
}
