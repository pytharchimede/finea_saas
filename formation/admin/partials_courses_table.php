<?php if (!isset($h)) { $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } ?>
<div class="finea-table-wrap academy-table-wrap">
    <table class="finea-table">
        <thead><tr><th>Parcours</th><th>Module</th><th>Score</th><th>Leçons</th><th>Permissions</th><th>Règles</th><th>Statut</th><th></th></tr></thead>
        <tbody>
        <?php foreach (($admin['courses'] ?? []) as $c): ?>
            <tr>
                <td><strong><?= $h($c['title']) ?></strong><br><small><?= $h($c['slug']) ?></small></td>
                <td><span class="academy-pill"><?= $h($c['module_slug']) ?></span></td>
                <td><?= (int)$c['required_score'] ?>%</td>
                <td><?= (int)$c['lessons_count'] ?></td>
                <td><?= (int)$c['permissions_count'] ?></td>
                <td><?= (int)$c['requirements_count'] ?></td>
                <td><?= !empty($c['is_active']) ? '<span class="academy-ok">Actif</span>' : '<span class="academy-muted">Inactif</span>' ?></td>
                <td><a class="finea-action-btn finea-action-btn--secondary" href="<?= $h($adminUrl . '?action=edit_course&id=' . (int)$c['id']) ?>">Modifier</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
