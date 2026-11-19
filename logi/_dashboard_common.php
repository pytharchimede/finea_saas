<?php
require_once __DIR__ . '/_logistics_common.php';

function logi_dashboard_scalar(PDO $pdo, string $sql, array $params = [], $default = 0)
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : $value;
    } catch (Throwable $e) {
        return $default;
    }
}

function logi_dashboard_rows(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function logi_dashboard_money($value): string
{
    return number_format((float) $value, 0, ',', ' ') . ' F';
}

function logi_dashboard_number($value, int $decimals = 0): string
{
    return number_format((float) $value, $decimals, ',', ' ');
}

function logi_dashboard_status_label(?string $status): string
{
    $map = [
        'available' => 'Disponible',
        'disponible' => 'Disponible',
        'busy' => 'En mission',
        'assigned' => 'Affecte',
        'maintenance' => 'Maintenance',
        'breakdown' => 'En panne',
        'unavailable' => 'Indisponible',
        'submitted' => 'Soumise',
        'en attente' => 'En attente',
        'approved' => 'Accordee',
        'accordee' => 'Accordee',
        'in_progress' => 'En cours',
        'remis' => 'Remis',
        'completed' => 'Terminee',
        'recuperee' => 'Recuperee',
        'rejected' => 'Refusee',
        'refusee' => 'Refusee',
        'planned' => 'Planifie',
        'open' => 'Ouvert',
        'resolved' => 'Resolu',
    ];
    $key = strtolower(trim((string) $status));
    return $map[$key] ?? ucfirst(str_replace('_', ' ', $key));
}

function logi_dashboard_tabs(string $active = 'exploitation'): string
{
    $tabs = [
        'exploitation' => ['href' => 'dashboard_logistique.php', 'label' => 'Exploitation', 'icon' => 'fa-gauge-high', 'desc' => 'Vue terrain, validations et alertes du jour.'],
        'statistique' => ['href' => 'dashboard_statistique.php', 'label' => 'Statistique', 'icon' => 'fa-chart-column', 'desc' => 'Volumes, repartitions et exports rapides.'],
        'analytic' => ['href' => 'dashboard_analytic.php', 'label' => 'Analytic', 'icon' => 'fa-chart-line', 'desc' => 'Couts, tendances et risques pour la direction.'],
    ];
    $html = '<section class="mt-6 mb-8 grid gap-4 lg:grid-cols-3">';
    foreach ($tabs as $key => $tab) {
        $isActive = $active === $key;
        $cardClass = $isActive
            ? 'border-slate-950 bg-slate-950 text-white'
            : 'border-white/80 bg-white/90 text-slate-800 hover:bg-slate-50';
        $iconClass = $isActive ? 'bg-white/15 text-[#fabd02]' : 'bg-orange-50 text-orange-700';
        $descClass = $isActive ? 'text-slate-300' : 'text-slate-500';
        $html .= '<a href="' . logi_h($tab['href']) . '" class="rounded-[26px] border p-5 shadow-[0_16px_45px_rgba(15,23,42,0.05)] transition hover:-translate-y-0.5 ' . $cardClass . '">';
        $html .= '<div class="flex items-start gap-4">';
        $html .= '<span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl ' . $iconClass . '"><i class="fas ' . logi_h($tab['icon']) . '"></i></span>';
        $html .= '<span><span class="block font-semibold">' . logi_h($tab['label']) . '</span><span class="mt-1 block text-sm ' . $descClass . '">' . logi_h($tab['desc']) . '</span></span>';
        $html .= '</div></a>';
    }
    return $html . '</section>';
}

function logi_dashboard_summary(PDO $pdo): array
{
    $fuelCostSql = "SELECT COALESCE(SUM(CASE WHEN total_cost > 0 THEN total_cost ELSE quantity_liters * unit_cost END),0) FROM logistics_fuel_logs WHERE COALESCE(fuel_date, DATE(created_at)) >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
    $maintenanceCostSql = "SELECT COALESCE(SUM(cost_amount),0) FROM logistics_maintenance WHERE COALESCE(intervention_at, planned_at, created_at) >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
    $summary = [
        'assets_total' => (int) logi_dashboard_scalar($pdo, 'SELECT COUNT(*) FROM logistics_assets'),
        'assets_available' => (int) logi_dashboard_scalar($pdo, "SELECT COUNT(*) FROM logistics_assets WHERE COALESCE(status, statut) IN ('available','disponible','active')"),
        'assets_busy' => (int) logi_dashboard_scalar($pdo, "SELECT COUNT(*) FROM logistics_assets WHERE COALESCE(status, statut) IN ('busy','mission','assigned','occupe')"),
        'assets_maintenance' => (int) logi_dashboard_scalar($pdo, "SELECT COUNT(*) FROM logistics_assets WHERE COALESCE(status, statut) IN ('maintenance','repair')"),
        'assets_breakdown' => (int) logi_dashboard_scalar($pdo, "SELECT COUNT(*) FROM logistics_assets WHERE COALESCE(status, statut) IN ('breakdown','panne','unavailable')"),
        'drivers_total' => (int) logi_dashboard_scalar($pdo, 'SELECT COUNT(*) FROM logistics_drivers'),
        'drivers_available' => (int) logi_dashboard_scalar($pdo, "SELECT COUNT(*) FROM logistics_drivers WHERE availability_status IN ('available','disponible')"),
        'requests_pending' => (int) logi_dashboard_scalar($pdo, "SELECT COUNT(*) FROM logistics_requests WHERE COALESCE(NULLIF(status,''), NULLIF(statut,''), '') IN ('submitted','en attente','approved','accordee','in_progress','remis','planned')"),
        'requests_urgent' => (int) logi_dashboard_scalar($pdo, "SELECT COUNT(*) FROM logistics_requests WHERE priority IN ('urgent','high','critique','critical') AND COALESCE(NULLIF(status,''), NULLIF(statut,''), '') NOT IN ('completed','recuperee','rejected','refusee','cancelled')"),
        'fuel_liters_month' => (float) logi_dashboard_scalar($pdo, "SELECT COALESCE(SUM(quantity_liters),0) FROM logistics_fuel_logs WHERE COALESCE(fuel_date, DATE(created_at)) >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
        'fuel_cost_month' => (float) logi_dashboard_scalar($pdo, $fuelCostSql),
        'maintenance_open' => (int) logi_dashboard_scalar($pdo, "SELECT COUNT(*) FROM logistics_maintenance WHERE status IN ('planned','in_progress','open')"),
        'maintenance_cost_month' => (float) logi_dashboard_scalar($pdo, $maintenanceCostSql),
        'incidents_open' => (int) logi_dashboard_scalar($pdo, "SELECT COUNT(*) FROM logistics_incidents WHERE status IN ('open','in_progress')"),
        'critical_incidents' => (int) logi_dashboard_scalar($pdo, "SELECT COUNT(*) FROM logistics_incidents WHERE severity IN ('critical','high','critique') AND status IN ('open','in_progress')"),
        'documents_expiring' => (int) logi_dashboard_scalar($pdo, "SELECT COUNT(*) FROM logistics_documents WHERE expires_at IS NOT NULL AND expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"),
        'documents_expired' => (int) logi_dashboard_scalar($pdo, "SELECT COUNT(*) FROM logistics_documents WHERE expires_at IS NOT NULL AND expires_at < CURDATE()"),
        'stock_critical' => (int) logi_dashboard_scalar($pdo, "SELECT COUNT(*) FROM logistics_stock_items WHERE COALESCE(current_stock,current_quantity,0) <= COALESCE(minimum_stock,minimum_quantity,0) AND status = 'active'"),
        'suppliers_total' => (int) logi_dashboard_scalar($pdo, "SELECT COUNT(*) FROM logistics_suppliers WHERE status = 'active'"),
        'trip_batches_open' => (int) logi_dashboard_scalar($pdo, "SELECT COUNT(*) FROM logistics_trip_batches WHERE status IN ('planned','in_progress')"),
        'active_chantiers' => (int) logi_dashboard_scalar($pdo, "SELECT COUNT(DISTINCT COALESCE(NULLIF(site_label,''), current_site)) FROM logistics_assets WHERE COALESCE(NULLIF(site_label,''), current_site) IS NOT NULL"),
        'daily_active_drivers' => (int) logi_dashboard_scalar($pdo, "SELECT COUNT(DISTINCT driver_id) FROM logistics_asset_daily_logs WHERE activity_date = CURDATE() AND driver_id IS NOT NULL"),
        'daily_active_assets' => (int) logi_dashboard_scalar($pdo, "SELECT COUNT(DISTINCT asset_id) FROM logistics_asset_daily_logs WHERE activity_date = CURDATE() AND asset_id IS NOT NULL"),
        'downtime_month' => (float) logi_dashboard_scalar($pdo, "SELECT COALESCE(SUM(downtime_hours),0) FROM logistics_asset_daily_logs WHERE activity_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
        'repair_cost_month' => (float) logi_dashboard_scalar($pdo, "SELECT COALESCE(SUM(repair_amount),0) FROM logistics_asset_daily_logs WHERE activity_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
    ];
    $summary['logistics_cost_month'] = $summary['fuel_cost_month'] + $summary['maintenance_cost_month'];
    return $summary;
}

function logi_dashboard_field_rows(PDO $pdo): array
{
    return [
        'occupation' => logi_dashboard_rows($pdo, "SELECT COALESCE(a.designation, CONCAT('Ressource #', l.asset_id)) label, COUNT(*) jours, COALESCE(SUM(l.downtime_hours),0) downtime FROM logistics_asset_daily_logs l LEFT JOIN logistics_assets a ON a.id=l.asset_id WHERE l.activity_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY l.asset_id, a.designation ORDER BY jours DESC LIMIT 8"),
        'fuel_by_site' => logi_dashboard_rows($pdo, "SELECT COALESCE(site_label,'Non affecte') label, COALESCE(SUM(quantity_liters),0) liters, COALESCE(SUM(CASE WHEN total_cost > 0 THEN total_cost ELSE quantity_liters * unit_cost END),0) amount FROM logistics_fuel_logs WHERE COALESCE(fuel_date, DATE(created_at)) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY COALESCE(site_label,'Non affecte') ORDER BY liters DESC LIMIT 8"),
        'fuel_by_asset' => logi_dashboard_rows($pdo, "SELECT COALESCE(a.designation, CONCAT('Ressource #', f.asset_id)) label, COALESCE(SUM(f.quantity_liters),0) liters FROM logistics_fuel_logs f LEFT JOIN logistics_assets a ON a.id=f.asset_id WHERE COALESCE(f.fuel_date, DATE(f.created_at)) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY f.asset_id, a.designation ORDER BY liters DESC LIMIT 8"),
        'maintenance_due' => logi_dashboard_rows($pdo, "SELECT m.*, a.designation asset_name FROM logistics_maintenance m LEFT JOIN logistics_assets a ON a.id=m.asset_id WHERE m.status IN ('planned','in_progress','open') ORDER BY COALESCE(m.planned_at,m.created_at) ASC LIMIT 8"),
        'terrain' => logi_dashboard_rows($pdo, "SELECT l.*, a.designation asset_name, d.full_name driver_name FROM logistics_asset_daily_logs l LEFT JOIN logistics_assets a ON a.id=l.asset_id LEFT JOIN logistics_drivers d ON d.id=l.driver_id WHERE l.activity_date >= DATE_SUB(CURDATE(), INTERVAL 2 DAY) ORDER BY l.activity_date DESC, l.id DESC LIMIT 10"),
        'documents' => logi_dashboard_rows($pdo, "SELECT d.*, a.designation asset_name, dr.full_name driver_name FROM logistics_documents d LEFT JOIN logistics_assets a ON a.id=d.asset_id LEFT JOIN logistics_drivers dr ON dr.id=d.driver_id WHERE d.expires_at IS NOT NULL AND d.expires_at <= DATE_ADD(CURDATE(), INTERVAL 45 DAY) ORDER BY d.expires_at ASC LIMIT 10"),
        'finance_by_site' => logi_dashboard_rows($pdo, "SELECT label, SUM(fuel_cost) fuel_cost, SUM(maintenance_cost) maintenance_cost, SUM(downtime_cost) downtime_cost FROM (SELECT COALESCE(site_label,'Non affecte') label, COALESCE(SUM(CASE WHEN total_cost > 0 THEN total_cost ELSE quantity_liters * unit_cost END),0) fuel_cost, 0 maintenance_cost, 0 downtime_cost FROM logistics_fuel_logs GROUP BY COALESCE(site_label,'Non affecte') UNION ALL SELECT COALESCE(site_label,'Non affecte') label, 0 fuel_cost, 0 maintenance_cost, COALESCE(SUM(downtime_hours),0) downtime_cost FROM logistics_asset_daily_logs GROUP BY COALESCE(site_label,'Non affecte') UNION ALL SELECT COALESCE(a.current_site,'Non affecte') label, 0 fuel_cost, COALESCE(SUM(m.cost_amount),0) maintenance_cost, 0 downtime_cost FROM logistics_maintenance m LEFT JOIN logistics_assets a ON a.id=m.asset_id GROUP BY COALESCE(a.current_site,'Non affecte')) x GROUP BY label ORDER BY (SUM(fuel_cost)+SUM(maintenance_cost)) DESC LIMIT 8"),
    ];
}

function logi_dashboard_alerts(array $summary): array
{
    $alerts = [];
    foreach ([
        ['critical_incidents', 'Incident critique', 'incident(s) critique(s) a traiter.'],
        ['assets_breakdown', 'Parc immobilise', 'engin(s) ou vehicule(s) en panne ou indisponible(s).'],
        ['documents_expired', 'Documents expires', 'document(s) administratif(s) expires.'],
        ['stock_critical', 'Stock critique', 'article(s) au seuil critique.'],
        ['requests_urgent', 'Demandes urgentes', 'demande(s) prioritaire(s).'],
    ] as $rule) {
        if (($summary[$rule[0]] ?? 0) > 0) {
            $alerts[] = ['title' => $rule[1], 'message' => (int) $summary[$rule[0]] . ' ' . $rule[2]];
        }
    }
    return $alerts ?: [['title' => 'Situation maitrisee', 'message' => 'Aucune alerte critique sur le perimetre logistique.']];
}

function logi_dashboard_recent_requests(PDO $pdo, int $limit = 8): array
{
    return logi_dashboard_rows($pdo, "SELECT r.*, u.nom, u.prenom, a.designation, a.current_site, a.current_holder,
        COALESCE(NULLIF(r.status,''), NULLIF(r.statut,''), 'submitted') workflow_status
        FROM logistics_requests r
        LEFT JOIN utilisateurs u ON r.user_id = u.id
        LEFT JOIN logistics_assets a ON r.asset_id = a.id
        ORDER BY r.created_at DESC
        LIMIT " . (int) $limit);
}

function logi_dashboard_due_returns(PDO $pdo, int $limit = 8): array
{
    return logi_dashboard_rows($pdo, "SELECT r.*, u.nom, u.prenom, a.designation, a.current_site, a.current_holder,
        COALESCE(NULLIF(r.status,''), NULLIF(r.statut,''), 'submitted') workflow_status
        FROM logistics_requests r
        LEFT JOIN utilisateurs u ON r.user_id = u.id
        LEFT JOIN logistics_assets a ON r.asset_id = a.id
        WHERE r.date_end IS NOT NULL
          AND r.date_end <= NOW()
          AND COALESCE(NULLIF(r.status,''), NULLIF(r.statut,''), '') IN ('approved','in_progress','accordee','remis','validee')
        ORDER BY r.date_end ASC
        LIMIT " . (int) $limit);
}

function logi_dashboard_charts(PDO $pdo): array
{
    $assetStatusRows = logi_dashboard_rows($pdo, "SELECT COALESCE(status, statut, 'available') status, COUNT(*) total FROM logistics_assets GROUP BY COALESCE(status, statut, 'available') ORDER BY total DESC");
    $fuelRows = logi_dashboard_rows($pdo, "SELECT DATE_FORMAT(COALESCE(fuel_date, DATE(created_at)), '%d/%m') label, COALESCE(SUM(quantity_liters),0) liters, COALESCE(SUM(CASE WHEN total_cost > 0 THEN total_cost ELSE quantity_liters * unit_cost END),0) amount FROM logistics_fuel_logs WHERE COALESCE(fuel_date, DATE(created_at)) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY COALESCE(fuel_date, DATE(created_at)) ORDER BY COALESCE(fuel_date, DATE(created_at)) ASC LIMIT 16");
    $siteRows = logi_dashboard_rows($pdo, "SELECT COALESCE(site_label, 'Non affecte') site_label, COALESCE(SUM(CASE WHEN total_cost > 0 THEN total_cost ELSE quantity_liters * unit_cost END),0) amount FROM logistics_fuel_logs GROUP BY COALESCE(site_label, 'Non affecte') ORDER BY amount DESC LIMIT 10");
    return [
        'asset_labels' => array_map(fn($r) => logi_dashboard_status_label($r['status'] ?? ''), $assetStatusRows),
        'asset_data' => array_map('intval', array_column($assetStatusRows, 'total')),
        'fuel_labels' => array_column($fuelRows, 'label'),
        'fuel_liters' => array_map('floatval', array_column($fuelRows, 'liters')),
        'fuel_amounts' => array_map('floatval', array_column($fuelRows, 'amount')),
        'site_labels' => array_column($siteRows, 'site_label'),
        'site_amounts' => array_map('floatval', array_column($siteRows, 'amount')),
    ];
}

function logi_dashboard_export_rows(PDO $pdo, string $dataset): array
{
    $q = trim((string)($_GET['q'] ?? ''));
    if ($dataset === 'assets') {
        $rows = logi_dashboard_rows($pdo, "SELECT asset_code Code, designation Designation, asset_type Type, registration_number Immatriculation, current_site Chantier, current_holder Detenteur, status Statut FROM logistics_assets ORDER BY designation ASC");
        return logi_filter_rows($rows, ['Code','Designation','Type','Immatriculation','Chantier','Detenteur','Statut'], $q);
    }
    if ($dataset === 'requests') {
        $rows = logi_dashboard_rows($pdo, "SELECT request_code Code, requester_name Demandeur, requesting_service Service, need_type Besoin, desired_at Date_souhaitee, date_end Retour_prevu, status Statut FROM logistics_requests ORDER BY created_at DESC LIMIT 1000");
        return logi_filter_rows($rows, ['Code','Demandeur','Service','Besoin','Date_souhaitee','Retour_prevu','Statut'], $q);
    }
    if ($dataset === 'stock') {
        $rows = logi_dashboard_rows($pdo, "SELECT category Categorie, designation Designation, reference_code Reference, COALESCE(current_stock,current_quantity,0) Stock, COALESCE(minimum_stock,minimum_quantity,0) Seuil, location_label Emplacement, status Statut FROM logistics_stock_items ORDER BY designation ASC");
        return logi_filter_rows($rows, ['Categorie','Designation','Reference','Emplacement','Statut'], $q);
    }
    if ($dataset === 'drivers') {
        $rows = logi_dashboard_rows($pdo, "SELECT full_name Chauffeur, driver_type Type, phone Telephone, license_number Permis, license_category Categorie, license_expires_at Expiration, service_label Service, availability_status Statut FROM logistics_drivers ORDER BY full_name ASC");
        return logi_filter_rows($rows, ['Chauffeur','Type','Telephone','Permis','Categorie','Service','Statut'], $q);
    }
    if ($dataset === 'documents') {
        $rows = logi_dashboard_rows($pdo, "SELECT document_type Type, reference_number Reference, issued_at Emission, expires_at Expiration, status Statut FROM logistics_documents ORDER BY expires_at ASC");
        return logi_filter_rows($rows, ['Type','Reference','Emission','Expiration','Statut'], $q);
    }
    if ($dataset === 'incidents') {
        $rows = logi_dashboard_rows($pdo, "SELECT incident_type Incident, site_label Chantier, severity Gravite, status Statut, incident_at Date_incident FROM logistics_incidents ORDER BY id DESC");
        return logi_filter_rows($rows, ['Incident','Chantier','Gravite','Statut','Date_incident'], $q);
    }
    if ($dataset === 'maintenance') {
        $rows = logi_dashboard_rows($pdo, "SELECT title Intervention, maintenance_type Type, planned_at Date_prevue, intervention_at Date_intervention, intervenant_name Intervenant, cost_amount Cout, status Statut FROM logistics_maintenance ORDER BY id DESC");
        return logi_filter_rows($rows, ['Intervention','Type','Date_prevue','Date_intervention','Intervenant','Statut'], $q);
    }
    if ($dataset === 'suppliers') {
        $rows = logi_dashboard_rows($pdo, "SELECT supplier_type Type, name Nom, contact_name Contact, phone Telephone, email Email, tax_number Identifiant, status Statut FROM logistics_suppliers ORDER BY name ASC");
        return logi_filter_rows($rows, ['Type','Nom','Contact','Telephone','Email','Identifiant','Statut'], $q);
    }
    if ($dataset === 'daily_activity') {
        $rows = logi_dashboard_rows($pdo, "SELECT l.activity_date Date, COALESCE(a.designation,'') Ressource, COALESCE(d.full_name,'') Chauffeur, l.site_label Chantier, l.activity_type Activite, l.fuel_liters Carburant_L, l.fuel_amount Montant_carburant, l.repair_amount Montant_reparation, l.downtime_hours Immobilisation_h, l.status Statut FROM logistics_asset_daily_logs l LEFT JOIN logistics_assets a ON a.id=l.asset_id LEFT JOIN logistics_drivers d ON d.id=l.driver_id ORDER BY l.activity_date DESC, l.id DESC LIMIT 2000");
        return logi_filter_rows($rows, ['Date','Ressource','Chauffeur','Chantier','Activite','Statut'], $q);
    }
    if ($dataset === 'planning') {
        $rows = logi_dashboard_rows($pdo, "SELECT a.mission_title Mission, COALESCE(la.designation,'') Ressource, COALESCE(ld.full_name,'') Chauffeur, a.site_label Chantier, a.debourse_label Debourse, a.progress_percent Avancement, a.blocked_reason Blocage, a.start_at Debut, a.end_at Fin, a.status Statut FROM logistics_assignments a LEFT JOIN logistics_assets la ON la.id=a.asset_id LEFT JOIN logistics_drivers ld ON ld.id=a.driver_id ORDER BY COALESCE(a.start_at, a.created_at) DESC LIMIT 2000");
        return logi_filter_rows($rows, ['Mission','Ressource','Chauffeur','Chantier','Debut','Fin','Statut'], $q);
    }
    if ($dataset === 'trip_batches') {
        $rows = logi_dashboard_rows($pdo, "SELECT b.batch_code Code, b.site_label Chantier, b.debourse_label Debourse, b.material_label Materiau, b.planned_quantity Quantite_prevue, b.unit_label Unite, COALESCE(SUM(t.transported_quantity),0) Quantite_transportee, COALESCE(SUM(t.trip_count),0) Voyages, COALESCE(SUM(t.fuel_liters),0) Carburant_L, COALESCE(SUM(t.fuel_amount),0) Cout_carburant, COALESCE(SUM(t.road_fees_amount),0) Frais_route, b.responsible_name Responsable, b.status Statut FROM logistics_trip_batches b LEFT JOIN logistics_trips t ON t.batch_id=b.id GROUP BY b.id ORDER BY b.id DESC LIMIT 2000");
        return logi_filter_rows($rows, ['Code','Chantier','Debourse','Materiau','Responsable','Statut'], $q);
    }
    if ($dataset === 'trips') {
        $rows = logi_dashboard_rows($pdo, "SELECT t.id Rotation, b.batch_code Vague, b.site_label Chantier, b.debourse_label Debourse, COALESCE(a.designation,'') Vehicule, COALESCE(d.full_name,'') Chauffeur, t.trip_count Voyages, t.transported_quantity Quantite, t.fuel_liters Carburant_L, t.fuel_amount Cout_carburant, t.road_fees_amount Frais_route, t.departure_at Depart, t.arrival_at Arrivee, t.delivery_note Numero_bon, t.delivery_note_file_path Document_bon, t.status Statut FROM logistics_trips t LEFT JOIN logistics_trip_batches b ON b.id=t.batch_id LEFT JOIN logistics_assets a ON a.id=t.asset_id LEFT JOIN logistics_drivers d ON d.id=t.driver_id ORDER BY t.id DESC LIMIT 2000");
        return logi_filter_rows($rows, ['Vague','Chantier','Debourse','Vehicule','Chauffeur','Numero_bon','Document_bon','Statut'], $q);
    }
    return logi_dashboard_rows($pdo, "SELECT 'Parc total' Indicateur, COUNT(*) Valeur FROM logistics_assets
        UNION ALL SELECT 'Demandes ouvertes', COUNT(*) FROM logistics_requests WHERE COALESCE(NULLIF(status,''), NULLIF(statut,''), '') NOT IN ('completed','recuperee','rejected','refusee','cancelled')
        UNION ALL SELECT 'Incidents ouverts', COUNT(*) FROM logistics_incidents WHERE status IN ('open','in_progress')
        UNION ALL SELECT 'Documents expires', COUNT(*) FROM logistics_documents WHERE expires_at IS NOT NULL AND expires_at < CURDATE()");
}

function logi_dashboard_handle_request_action(PDO $pdo): string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['logi_request_action'], $_POST['request_id'])) {
        return '';
    }
    $requestId = (int) $_POST['request_id'];
    $action = (string) $_POST['logi_request_action'];
    if ($requestId <= 0 || !in_array($action, ['approve', 'reject', 'details', 'handover', 'recover'], true)) {
        return '';
    }
    return logi_process_request_action($pdo, $requestId, $action, $_POST);
}
