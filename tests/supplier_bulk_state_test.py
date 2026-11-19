"""Execute the bulk repository's SQL assignments against SQLite."""
from pathlib import Path
import re
import sqlite3
import unittest


class SupplierBulkStateTest(unittest.TestCase):
    def test_mixed_selection_is_atomic_and_preserves_archives(self):
        text = (Path(__file__).parents[1] / 'repositories/FournisseurRepository.php').read_text()
        actions = dict((action, (assignment, condition)) for action, assignment, condition in re.findall(r"'(archive|restore|activate|deactivate)' => \['([^']+)', '([^']+)'\]", text))
        self.assertEqual(set(actions), {'archive', 'restore', 'activate', 'deactivate'})
        db = sqlite3.connect(':memory:')
        db.create_function('NOW', 0, lambda: '2026-10-15 13:00:00')
        db.executescript('''CREATE TABLE fournisseur_banamur(id_fournisseur INTEGER PRIMARY KEY, actif INTEGER, archived_at TEXT, archived_by_user_id INTEGER, archive_previous_active INTEGER);
        INSERT INTO fournisseur_banamur VALUES(1,1,NULL,NULL,NULL),(2,0,NULL,NULL,NULL),(3,0,'old',5,1);
        CREATE TABLE invoices(supplier_id INTEGER, amount INTEGER);
        INSERT INTO invoices VALUES(1,325000),(3,10000);''')

        def run(action, ids=(1, 2, 3, 999)):
            assignment, condition = actions[action]
            params = ([7] if action == 'archive' else []) + list(ids)
            return db.execute('UPDATE fournisseur_banamur SET ' + assignment + ' WHERE id_fournisseur IN (' + ','.join('?' for _ in ids) + ') AND ' + condition, params).rowcount

        self.assertEqual(run('activate'), 1)
        self.assertEqual(db.execute('SELECT archived_at FROM fournisseur_banamur WHERE id_fournisseur=3').fetchone(), ('old',))
        self.assertEqual(run('deactivate', (2,)), 1)
        self.assertEqual(run('archive'), 2)
        self.assertEqual(run('archive'), 0)
        self.assertEqual(db.execute('SELECT archived_by_user_id FROM fournisseur_banamur WHERE id_fournisseur=3').fetchone(), (5,))
        self.assertEqual(run('activate'), 0)
        self.assertEqual(run('deactivate'), 0)
        self.assertEqual(run('restore'), 3)
        self.assertEqual(db.execute('SELECT actif FROM fournisseur_banamur ORDER BY id_fournisseur').fetchall(), [(1,), (0,), (1,)])
        self.assertEqual(run('restore'), 0)
        self.assertEqual(db.execute('SELECT SUM(i.amount) FROM invoices i JOIN fournisseur_banamur f ON f.id_fournisseur=i.supplier_id').fetchone()[0], 335000)


if __name__ == '__main__':
    unittest.main()
