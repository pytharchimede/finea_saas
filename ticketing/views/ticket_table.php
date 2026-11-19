<div class="ticket-table-wrap"><table class="ticket-table"><thead><tr><th>Ticket</th><th>Objet</th><th>Assigné à</th><th>Priorité</th><th>Statut</th><th>Échéance</th></tr></thead><tbody>
<?php if (empty($tickets)): ?><tr><td colspan="6" class="ticket-empty">Aucun ticket trouvé.</td></tr><?php endif; ?>
<?php foreach ($tickets as $t): ?>
<tr onclick="location.href='index.php?action=show&id=<?= (int)$t['id'] ?>'">
<td><b><?= ticket_h($t['ticket_no']) ?></b><small><?= date('d/m/Y H:i', strtotime($t['created_at'])) ?></small></td>
<td><?= ticket_category_pill($t['category_name'] ?? null, $t['category_icon'] ?? null, $t['category_color'] ?? null) ?><strong><?= ticket_h($t['title']) ?></strong><small><?= ticket_h($t['chantier'] ?: ($t['related_module'] ?: 'Demande interne')) ?></small></td>
<td><span class="ticket-assignee"><?= ticket_h($t['assigned_user_name'] ?: ($t['assigned_service'] ?: 'Non assigné')) ?></span></td>
<td><?= ticket_priority_badge($t['priority_name'] ?? '-', $t['priority_color'] ?? '#1d2b57') ?></td>
<td><?= ticket_status_badge($t['status']) ?></td>
<td><?= $t['due_at'] ? date('d/m/Y H:i', strtotime($t['due_at'])) : '-' ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
