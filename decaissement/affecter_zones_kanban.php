<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once '../model/Database.php';
require_once 'model/Dashboard.php';
require_once '../model/Devis.php';
require_once '../model/Chantier.php';
require_once __DIR__ . '/../services/ChantierPauseService.php';

AuditTrail::logPageConsultation([
    'module' => 'decaissement',
    'action' => 'affecter_zones_kanban_view',
    'entity_type' => 'devis_zone_assignment',
    'entity_id' => $_GET['devis_id'] ?? null,
    'entity_label' => 'Affectation des zones',
    'page_name' => 'affecter_zones_kanban.php',
    'details' => [
        'devis_id' => $_GET['devis_id'] ?? null,
        'view_mode' => 'kanban',
    ],
]);

$pdo = Database::getConnection();
$dashboardModel = new Dashboard($pdo);
$devisModel = new Devis($pdo);
$chantierModel = new Chantier($pdo);

// Récupérer l'ID du devis
$devisId = $_GET['devis_id'] ?? null;

if (!$devisId) {
    header('Location: liste_devis.php');
    exit;
}
$activeDevisCheck = $pdo->prepare('SELECT COUNT(*) FROM chantier_banamur WHERE devis_id=? AND archived_at IS NULL AND '.ChantierPauseService::activePredicate($pdo,'id'));
$activeDevisCheck->execute([(int)$devisId]);
if (!(int)$activeDevisCheck->fetchColumn()) { header('Location: gestion_zones.php?error=chantier_en_pause'); exit; }

// Récupérer les informations du devis
$devis = $devisModel->getDevisById($devisId);
if (!$devis) {
    header('Location: liste_devis.php?error=devis_not_found');
    exit;
}

// Récupérer les lignes de devis
$lignesDevis = $devisModel->getLignesByDevisId($devisId);

// Métadonnées utilisées par les filtres (catégories des sous-lignes de déboursé).
$metaStmt = $pdo->prepare("SELECT db.ligne_devis_id,
        GROUP_CONCAT(DISTINCT TRIM(ldb.categorie) ORDER BY ldb.categorie SEPARATOR '||') AS categories
    FROM debourse_banamur db
    INNER JOIN ligne_debourse_banamur ldb ON ldb.debourse_id = db.id
    WHERE db.devis_id = ? AND db.ligne_devis_id IS NOT NULL
    GROUP BY db.ligne_devis_id");
$metaStmt->execute([$devisId]);
$ligneCategories = [];
foreach ($metaStmt->fetchAll(PDO::FETCH_ASSOC) as $meta) {
    $ligneCategories[(string)$meta['ligne_devis_id']] = array_values(array_filter(explode('||', (string)$meta['categories'])));
}
$titresDebourses = [];
$categoriesDebourses = [];
foreach ($lignesDevis as $ligne) {
    $titre = trim((string)($ligne['designation'] ?? ''));
    if ($titre !== '') $titresDebourses[$titre] = $titre;
    foreach ($ligneCategories[(string)$ligne['id']] ?? [] as $categorie) {
        $categoriesDebourses[$categorie] = $categorie;
    }
}
natcasesort($titresDebourses);
natcasesort($categoriesDebourses);

// Récupérer toutes les zones distinctes pour ce devis depuis la base de données
$zonesStmt = $pdo->prepare("SELECT DISTINCT zone FROM ligne_devis_banamur WHERE devis_id = ? AND zone IS NOT NULL AND zone != '' ORDER BY zone");
$zonesStmt->execute([$devisId]);
$zonesFromDb = $zonesStmt->fetchAll(PDO::FETCH_COLUMN);

// intégrer les zones vides stockées en session (créées mais sans lignes)
$sessionKey = 'zones_' . $devisId;
if (isset($_SESSION[$sessionKey]) && is_array($_SESSION[$sessionKey])) {
    $zonesFromSession = $_SESSION[$sessionKey];
    // conserver l'ordre : d'abord base, puis session
    $zonesFromDb = array_unique(array_merge($zonesFromDb, $zonesFromSession));
}

// Si pas de zones définies en base/session, créer des zones par défaut
$zones = !empty($zonesFromDb) ? $zonesFromDb : ['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4'];

//Récupérer le chantier associé au devis
$chantier = $chantierModel->getByDevisId($devisId);

// Si pas de zones définies, créer des zones par défaut
if (empty($zones)) {
    $zones = ['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4'];
}

$pageTitle = 'Affectation des Zones - ' . htmlspecialchars($devis['numero_devis']);
$activeMenu = 'devis';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="zones-page min-h-screen">
    <div class="container mx-auto py-10 px-4">
        <!-- En-tête -->
        <div class="zones-hero flex flex-col gap-5 rounded-[2rem] border p-6 shadow-sm md:flex-row md:items-center md:justify-between md:p-8 mb-8">
            <div>
                <a href="detail_budgetaire_chantier.php?chantier_id=<?= $chantier['id'] ?>" class="zones-back inline-flex items-center mb-4 font-bold">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Retour détails budgetaires
                </a>
                <h1 class="zones-title text-4xl font-black">
                    🏗️ Affectation des Zones
                </h1>
                <p class="zones-subtitle text-xl mt-2">Devis : <?= htmlspecialchars($devis['numero_devis']) ?></p>
            </div>
            <div class="flex flex-wrap gap-3">
                <button id="add-zone-btn" class="zones-button-secondary px-6 py-3 rounded-xl transition-colors font-bold">
                    <i class="fas fa-plus mr-2"></i>
                    Ajouter une zone
                </button>
                <button onclick="saveZones()" class="zones-button-primary px-6 py-3 rounded-xl transition-colors font-bold">
                    <i class="fas fa-save mr-2"></i>
                    Enregistrer
                </button>
            </div>
        </div>

        <!-- Kanban Board -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6" id="kanban-board">
            <?php foreach ($zones as $zoneName): ?>
                <div class="zone-column rounded-2xl shadow-sm border min-h-96">
                    <div class="zone-column-header px-6 py-4 rounded-t-2xl">
                        <h3 class="text-lg font-bold flex items-center zone-header" contenteditable="true">
                            <i class="fas fa-building mr-3"></i>
                            <?= htmlspecialchars($zoneName) ?>
                            <i class="fas fa-times ml-2 text-red-200 hover:text-red-100 cursor-pointer zone-delete" title="Supprimer la zone"></i>
                        </h3>
                    </div>

                    <div class="p-4 space-y-3 drop-zone" data-zone="<?= htmlspecialchars($zoneName) ?>">
                        <?php
                        $lignesInZone = array_filter($lignesDevis, function ($ligne) use ($zoneName) {
                            return $ligne['zone'] === $zoneName;
                        });
                        foreach ($lignesInZone as $ligne):
                        ?>
                            <div class="zone-line-card rounded-xl p-4 border cursor-move hover:shadow-md transition-shadow ligne-card"
                                data-ligne-id="<?= $ligne['id'] ?>"
                                data-designation="<?= htmlspecialchars($ligne['designation']) ?>"
                                data-categories="<?= htmlspecialchars(implode('||', $ligneCategories[(string)$ligne['id']] ?? [])) ?>">
                                <div class="flex items-start justify-between">
                                    <label class="mr-3 mt-1 cursor-pointer" title="Sélectionner cette ligne">
                                        <input type="checkbox" class="ligne-select h-4 w-4 rounded border-indigo-300 text-indigo-600">
                                    </label>
                                    <div class="flex-1">
                                        <h4 class="zone-line-title font-bold text-sm mb-2">
                                            <?= htmlspecialchars($ligne['designation']) ?>
                                        </h4>
                                        <div class="zone-line-meta text-xs space-y-1">
                                            <div>Prix: <?= number_format($ligne['prix'], 0, ',', ' ') ?> F</div>
                                            <div>Quantité: <?= number_format($ligne['quantite'], 0, ',', ' ') ?> <?= htmlspecialchars($ligne['unite_libelle'] ?? '') ?></div>
                                            <div class="font-bold">Total: <?= number_format($ligne['total'], 0, ',', ' ') ?> F</div>
                                        </div>
                                    </div>
                                    <div class="ml-3">
                                        <i class="fas fa-grip-vertical text-indigo-400"></i>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Zone pour les lignes non affectées -->
        <div class="mt-8">
            <div class="zone-column rounded-2xl shadow-sm border">
                <div class="zone-column-header px-6 py-4 rounded-t-2xl">
                    <h3 class="text-lg font-bold flex items-center">
                        <i class="fas fa-question-circle mr-3"></i>
                        Lignes Non Affectées
                    </h3>
                </div>

                <div class="p-4 border-b border-gray-200 bg-gray-50 grid grid-cols-1 md:grid-cols-3 gap-3">
                    <label class="relative block">
                        <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                        <input id="ligne-search" type="search" placeholder="Rechercher une ligne..." class="w-full pl-10 pr-3 py-2.5 rounded-lg border border-gray-300 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    </label>
                    <input id="titre-filter" list="titres-debourses" placeholder="Tous les titres déboursés" class="w-full px-3 py-2.5 rounded-lg border border-gray-300 focus:ring-2 focus:ring-indigo-500">
                    <datalist id="titres-debourses">
                        <?php foreach ($titresDebourses as $titre): ?><option value="<?= htmlspecialchars($titre) ?>"><?php endforeach; ?>
                    </datalist>
                    <input id="categorie-filter" list="categories-debourses" placeholder="Toutes les catégories (RH, ACHAT...)" class="w-full px-3 py-2.5 rounded-lg border border-gray-300 focus:ring-2 focus:ring-indigo-500">
                    <datalist id="categories-debourses">
                        <?php foreach ($categoriesDebourses as $categorie): ?><option value="<?= htmlspecialchars($categorie) ?>"><?php endforeach; ?>
                    </datalist>
                    <p id="filter-result-count" class="md:col-span-3 text-sm text-gray-500"></p>
                </div>

                <div class="p-4 space-y-3 drop-zone" data-zone="non-affectees">
                    <?php
                    $lignesNonAffectees = array_filter($lignesDevis, function ($ligne) {
                        return empty($ligne['zone']);
                    });
                    foreach ($lignesNonAffectees as $ligne):
                    ?>
                        <div class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-xl p-4 border border-gray-200 cursor-move hover:shadow-md transition-shadow ligne-card"
                            data-ligne-id="<?= $ligne['id'] ?>"
                            data-designation="<?= htmlspecialchars($ligne['designation']) ?>"
                            data-categories="<?= htmlspecialchars(implode('||', $ligneCategories[(string)$ligne['id']] ?? [])) ?>">
                            <div class="flex items-start justify-between">
                                <label class="mr-3 mt-1 cursor-pointer" title="Sélectionner cette ligne">
                                    <input type="checkbox" class="ligne-select h-4 w-4 rounded border-gray-300 text-indigo-600">
                                </label>
                                <div class="flex-1">
                                    <h4 class="font-semibold text-gray-800 text-sm mb-2">
                                        <?= htmlspecialchars($ligne['designation']) ?>
                                    </h4>
                                    <div class="text-xs text-gray-600 space-y-1">
                                        <div>Prix: <?= number_format($ligne['prix'], 0, ',', ' ') ?> F</div>
                                        <div>Quantité: <?= number_format($ligne['quantite'], 0, ',', ' ') ?> <?= htmlspecialchars($ligne['unite_libelle'] ?? '') ?></div>
                                        <div class="font-bold">Total: <?= number_format($ligne['total'], 0, ',', ' ') ?> F</div>
                                    </div>
                                </div>
                                <div class="ml-3">
                                    <i class="fas fa-grip-vertical text-gray-400"></i>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="selection-toolbar" class="zones-selection-toolbar hidden fixed bottom-6 left-1/2 -translate-x-1/2 z-40 max-w-[calc(100vw-2rem)] shadow-2xl rounded-2xl border px-4 py-3 flex flex-wrap items-center justify-center gap-3">
    <span id="selection-count" class="font-semibold whitespace-nowrap">0 ligne</span>
    <select id="selection-zone" class="text-gray-900 rounded-lg px-3 py-2 min-w-48">
        <option value="">Choisir la zone...</option>
    </select>
    <button id="move-selection" type="button" class="bg-indigo-500 hover:bg-indigo-600 rounded-lg px-4 py-2 font-semibold disabled:opacity-50" disabled>Déplacer</button>
    <button id="clear-selection" type="button" class="text-gray-300 hover:text-white px-2" title="Annuler la sélection"><i class="fas fa-times"></i></button>
</div>

<script>
    // Variables globales
    let draggedElement = null;
    let draggedFromZone = null;
    let zoneNames = {}; // Mappe: originalName => currentName
    let unsavedChanges = false; // suivi des modifications non sauvegardées

    // Initialiser
    document.addEventListener('DOMContentLoaded', function() {
        // prévenir suppression non intentionnelle
        window.addEventListener('beforeunload', function(e) {
            if (unsavedChanges) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
        // Sauvegarder les noms originaux des zones
        document.querySelectorAll('.drop-zone').forEach(zone => {
            const zoneName = zone.getAttribute('data-zone');
            zoneNames[zoneName] = zoneName;
        });

        initDragAndDrop();
        attachHeaderListeners();
        initFiltersAndSelection();
        const addBtn = document.getElementById('add-zone-btn');
        if (addBtn) addBtn.addEventListener('click', addZone);
    });

    function initDragAndDrop() {
        const cards = document.querySelectorAll('.ligne-card');
        const zones = document.querySelectorAll('.drop-zone');

        cards.forEach(card => {
            card.setAttribute('draggable', 'true');
            if (card.dataset.dragReady === '1') return;
            card.dataset.dragReady = '1';
            card.addEventListener('dragstart', handleDragStart);
            card.addEventListener('dragend', handleDragEnd);
        });

        zones.forEach(zone => {
            if (zone.dataset.dropReady === '1') return;
            zone.dataset.dropReady = '1';
            zone.addEventListener('dragover', handleDragOver);
            zone.addEventListener('drop', handleDrop);
        });
    }

    function handleDragStart(e) {
        draggedElement = e.target.closest('.ligne-card');
        draggedFromZone = draggedElement.parentElement.getAttribute('data-zone');
        draggedElement.classList.add('opacity-50');
        e.dataTransfer.effectAllowed = 'move';
    }

    function handleDragEnd(e) {
        draggedElement.classList.remove('opacity-50');
        draggedElement = null;
        draggedFromZone = null;
    }

    function handleDragOver(e) {
        e.preventDefault();
        e.stopPropagation();
        e.dataTransfer.dropEffect = 'move';
    }

    function handleDrop(e) {
        e.preventDefault();
        e.stopPropagation();

        if (!draggedElement) return;

        // La destination est toujours le conteneur de zone, jamais la carte survolée.
        const targetZone = e.currentTarget;
        if (!targetZone.classList.contains('drop-zone')) return;

        const targetZoneName = targetZone.getAttribute('data-zone');

        // Ne pas déplacer dans la même zone
        if (targetZoneName === draggedFromZone) return;

        // Déplacer l'élément
        targetZone.appendChild(draggedElement);

        // Mettre à jour la zone de l'élément
        draggedElement.setAttribute('data-zone', targetZoneName);
        unsavedChanges = true;
        applyUnassignedFilters();
    }

    // Sauvegarder les affectations
    function saveZones() {
        const affectations = {};
        const zoneRenames = {};

        document.querySelectorAll('.drop-zone').forEach(zone => {
            const zoneName = zone.getAttribute('data-zone');
            const lignes = Array.from(zone.children).filter(child => child.classList.contains('ligne-card'));

            lignes.forEach(ligne => {
                const ligneId = ligne.getAttribute('data-ligne-id');
                if (!affectations[ligneId]) {
                    affectations[ligneId] = zoneName === 'non-affectees' ? null : zoneName;
                }
            });
        });

        // Construire la liste des renommages
        for (let oldName in zoneNames) {
            if (zoneNames[oldName] !== oldName) {
                zoneRenames[oldName] = zoneNames[oldName];
            }
        }

        console.log('Affectations à envoyer:', affectations);
        console.log('Zone renames:', zoneRenames);

        // Envoyer au serveur
        // collecter la liste complète des zones visibles (y compris vides)
        const allZones = [];
        document.querySelectorAll('.drop-zone').forEach(zone => {
            allZones.push(zone.getAttribute('data-zone'));
        });

        fetch('../api/update_zones.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    devis_id: <?= json_encode($devisId) ?>,
                    affectations: affectations,
                    zoneRenames: zoneRenames,
                    allZones: allZones
                })
            })
            .then(response => {
                console.log('Réponse reçue, statut:', response.status);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.text().then(text => {
                    console.log('Réponse brute:', text);
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Erreur parse JSON:', e, 'Text:', text);
                        throw new Error('Réponse non-JSON: ' + text);
                    }
                });
            })
            .then(data => {
                console.log('Données JSON parsées:', data);
                if (data.success) {
                    showNotification('Affectations sauvegardées avec succès!', 'success');
                    unsavedChanges = false;
                    // Recharger la page pour refléter les changements
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showNotification('Erreur lors de la sauvegarde: ' + data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Erreur fetch:', error);
                showNotification('Erreur : ' + error.message, 'error');
            });
    }

    function showNotification(message, type) {
        // Créer une notification temporaire
        const notification = document.createElement('div');
        notification.className = `fixed top-4 right-4 px-6 py-3 rounded-lg text-white font-semibold z-50 ${
            type === 'success' ? 'bg-green-500' : 'bg-red-500'
        }`;
        notification.textContent = message;

        document.body.appendChild(notification);

        setTimeout(() => {
            notification.remove();
        }, 3000);
    }

    // Permet de renommer la zone et synchroniser l'attribut data-zone
    function attachHeaderListeners() {
        document.querySelectorAll('.zone-header').forEach(h => {
            if (h.dataset.headerReady === '1') return;
            h.dataset.headerReady = '1';
            // Stocker le contenu initial (nom sans icônes)
            const initialContent = h.innerText.trim();
            h.dataset.initialContent = initialContent;

            h.addEventListener('blur', function() {
                const oldName = this.dataset.initialContent;
                const newName = this.innerText.trim();

                // localiser la drop-zone associée en remontant jusqu'au wrapper de la zone
                const zoneWrapper = this.closest('.zone-column');
                const drop = zoneWrapper ? zoneWrapper.querySelector('.drop-zone') : null;

                if (drop) {
                    // mettre à jour l'attribut data-zone pour qu'il reste synchronisé
                    drop.setAttribute('data-zone', newName);
                }

                if (oldName && newName && oldName !== newName) {
                    // mémoriser le renommage dans la map
                    zoneNames[oldName] = newName;
                    console.log('Zone renommée: ' + oldName + ' => ' + newName);
                    unsavedChanges = true;
                }

                // Mettre à jour le contenu stocké
                this.dataset.initialContent = newName;
                refreshZoneChoices();
            });
        });

        document.querySelectorAll('.zone-delete').forEach(btn => {
            if (btn.dataset.deleteReady === '1') return;
            btn.dataset.deleteReady = '1';
            btn.addEventListener('click', removeZone);
        });
    }

    function normalizeSearch(value) {
        return (value || '').toLocaleLowerCase('fr').normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
    }

    function applyUnassignedFilters() {
        const container = document.querySelector('.drop-zone[data-zone="non-affectees"]');
        if (!container) return;
        const search = normalizeSearch(document.getElementById('ligne-search')?.value);
        const titre = normalizeSearch(document.getElementById('titre-filter')?.value);
        const categorie = normalizeSearch(document.getElementById('categorie-filter')?.value);
        let visible = 0;
        const cards = Array.from(container.children).filter(child => child.classList.contains('ligne-card'));
        cards.forEach(card => {
            const designation = normalizeSearch(card.dataset.designation);
            const categories = normalizeSearch(card.dataset.categories);
            const matches = (!search || designation.includes(search) || categories.includes(search))
                && (!titre || designation === titre || designation.includes(titre))
                && (!categorie || categories.split('||').some(value => value.includes(categorie)));
            card.classList.toggle('hidden', !matches);
            if (matches) visible++;
        });
        const count = document.getElementById('filter-result-count');
        if (count) count.textContent = `${visible} ligne${visible > 1 ? 's' : ''} affichée${visible > 1 ? 's' : ''} sur ${cards.length}`;
    }

    function refreshZoneChoices() {
        const select = document.getElementById('selection-zone');
        if (!select) return;
        const selectedValue = select.value;
        select.innerHTML = '<option value="">Choisir la zone...</option>';
        document.querySelectorAll('.drop-zone').forEach(zone => {
            const value = zone.dataset.zone;
            const option = document.createElement('option');
            option.value = value;
            option.textContent = value === 'non-affectees' ? 'Lignes Non Affectées' : value;
            select.appendChild(option);
        });
        if (Array.from(select.options).some(option => option.value === selectedValue)) select.value = selectedValue;
    }

    function updateSelectionToolbar() {
        const selected = document.querySelectorAll('.ligne-select:checked');
        const toolbar = document.getElementById('selection-toolbar');
        toolbar.classList.toggle('hidden', selected.length === 0);
        document.getElementById('selection-count').textContent = `${selected.length} ligne${selected.length > 1 ? 's' : ''}`;
        refreshZoneChoices();
    }

    function initFiltersAndSelection() {
        ['ligne-search', 'titre-filter', 'categorie-filter'].forEach(id => {
            document.getElementById(id)?.addEventListener('input', applyUnassignedFilters);
        });
        document.addEventListener('change', e => {
            if (e.target.matches('.ligne-select')) updateSelectionToolbar();
            if (e.target.id === 'selection-zone') document.getElementById('move-selection').disabled = !e.target.value;
        });
        document.getElementById('clear-selection')?.addEventListener('click', () => {
            document.querySelectorAll('.ligne-select:checked').forEach(input => input.checked = false);
            updateSelectionToolbar();
        });
        document.getElementById('move-selection')?.addEventListener('click', () => {
            const zoneName = document.getElementById('selection-zone').value;
            const target = Array.from(document.querySelectorAll('.drop-zone')).find(zone => zone.dataset.zone === zoneName);
            if (!target) return;
            const selected = Array.from(document.querySelectorAll('.ligne-select:checked'));
            selected.forEach(input => {
                const card = input.closest('.ligne-card');
                target.appendChild(card);
                card.dataset.zone = zoneName;
                card.classList.remove('hidden');
                input.checked = false;
            });
            if (selected.length) unsavedChanges = true;
            document.getElementById('selection-zone').value = '';
            document.getElementById('move-selection').disabled = true;
            updateSelectionToolbar();
            applyUnassignedFilters();
            showNotification(`${selected.length} ligne${selected.length > 1 ? 's déplacées' : ' déplacée'}. Pensez à enregistrer.`, 'success');
        });
        applyUnassignedFilters();
    }

    function removeZone(e) {
        const btn = e.currentTarget;
        const zoneWrapper = btn.closest('.zone-column');
        const drop = zoneWrapper.querySelector('.drop-zone');
        if (drop) {
            const cards = Array.from(drop.children).filter(child => child.classList.contains('ligne-card'));
            const unassigned = document.querySelector('[data-zone="non-affectees"]');
            if (unassigned) {
                cards.forEach(card => unassigned.appendChild(card));
            }
        }
        zoneWrapper.remove();
        unsavedChanges = true;
        refreshZoneChoices();
        applyUnassignedFilters();
    }

    function addZone() {
        const kanban = document.getElementById('kanban-board');
        const zoneName = 'Nouvelle zone';
        const zoneWrapper = document.createElement('div');
        unsavedChanges = true;
        zoneWrapper.className = 'zone-column rounded-2xl shadow-sm border min-h-96';
        zoneWrapper.innerHTML = `
        <div class="zone-column-header px-6 py-4 rounded-t-2xl">
            <h3 class="text-lg font-bold flex items-center zone-header" contenteditable="true">
                <i class="fas fa-building mr-3"></i>${zoneName}
                <i class="fas fa-times ml-2 text-red-200 hover:text-red-100 cursor-pointer zone-delete" title="Supprimer la zone"></i>
            </h3>
        </div>
        <div class="p-4 space-y-3 drop-zone" data-zone="${zoneName}">
        </div>`;
        kanban.appendChild(zoneWrapper);
        // enregistrer la nouvelle zone dans la map pour suivre les renommages
        zoneNames[zoneName] = zoneName;
        initDragAndDrop();
        attachHeaderListeners();
        refreshZoneChoices();
    }
</script>

<style>
    .zones-page {
        color: var(--brand-text, #0f172a) !important;
        background:
            radial-gradient(900px 440px at 0 0, rgb(var(--brand-accent-rgb, 255 204 32) / .14), transparent 68%),
            linear-gradient(180deg, #fff 0%, color-mix(in srgb, var(--brand-accent, #ffcc20) 5%, #f8fafc) 100%);
    }
    .zones-page .zones-hero,
    .zones-page .zone-column {
        background: #fff !important;
        color: var(--brand-text, #0f172a) !important;
        border-color: color-mix(in srgb, var(--brand-primary, #0f172a) 14%, #fff) !important;
    }
    .zones-title { color: var(--brand-text, #0f172a) !important; }
    .zones-subtitle { color: var(--brand-muted, #64748b) !important; }
    .zones-back { color: color-mix(in srgb, var(--brand-primary, #0f172a) 82%, #2563eb) !important; }
    .zones-back:hover { color: var(--brand-accent, #d39e00) !important; }
    .zones-button-primary {
        background: var(--brand-accent, #ffcc20) !important;
        color: var(--brand-on-accent, #050505) !important;
        box-shadow: 0 10px 24px rgb(var(--brand-accent-rgb, 255 204 32) / .20);
    }
    .zones-button-secondary {
        background: #fff !important;
        color: var(--brand-text, #0f172a) !important;
        border: 1px solid color-mix(in srgb, var(--brand-primary, #0f172a) 20%, #fff) !important;
    }
    .zones-button-primary:hover,.zones-button-secondary:hover { filter: brightness(.96); transform: translateY(-1px); }
    .zone-column-header {
        background: color-mix(in srgb, var(--brand-accent, #ffcc20) 15%, #fff) !important;
        color: var(--brand-text, #0f172a) !important;
        border-bottom: 3px solid var(--brand-accent, #ffcc20);
    }
    .zone-column-header .zone-header,
    .zone-column-header h3,
    .zone-column-header i { color: var(--brand-text, #0f172a) !important; }
    .zone-column-header .zone-delete { color: #dc2626 !important; }
    .zones-page .drop-zone { background: #fff !important; color: var(--brand-text, #0f172a) !important; min-height: 8rem; }
    .zones-page .ligne-card,
    .zones-page .zone-line-card {
        background: color-mix(in srgb, var(--brand-accent, #ffcc20) 5%, #fff) !important;
        color: var(--brand-text, #0f172a) !important;
        border-color: color-mix(in srgb, var(--brand-accent, #ffcc20) 30%, #cbd5e1) !important;
    }
    .zones-page .ligne-card h4,.zone-line-title { color: var(--brand-text, #0f172a) !important; }
    .zones-page .ligne-card .text-xs,.zone-line-meta { color: var(--brand-muted, #475569) !important; }
    .zones-page input,.zones-page select,.zones-selection-toolbar select {
        background: #fff !important;
        color: #0f172a !important;
        border-color: #cbd5e1 !important;
    }
    .zones-page input::placeholder { color: #64748b !important; opacity: 1; }
    .zones-page .bg-gray-50 { background: #f8fafc !important; color: #0f172a !important; }
    .zones-page .text-gray-500,.zones-page .text-gray-600,.zones-page .text-gray-800 { color: #475569 !important; }
    .zones-selection-toolbar {
        background: #fff !important;
        color: var(--brand-text, #0f172a) !important;
        border-color: color-mix(in srgb, var(--brand-accent, #ffcc20) 45%, #cbd5e1) !important;
    }
    .zones-selection-toolbar #move-selection { background: var(--brand-accent, #ffcc20) !important; color: var(--brand-on-accent, #050505) !important; }
    .zones-selection-toolbar #clear-selection { color: #64748b !important; }
    .ligne-card {
        transition: all 0.2s ease;
    }

    .ligne-card:hover {
        transform: translateY(-2px);
    }

    .ligne-card.opacity-50 {
        opacity: 0.5;
    }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
