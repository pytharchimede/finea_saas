"""Exercise repository archive SQL without a production database (Python 3 / SQLite)."""
from pathlib import Path
import re
import sqlite3
import unittest


class SupplierArchiveTest(unittest.TestCase):
    def test_archive_restore_keeps_history_and_previous_active_state(self):
        source = (Path(__file__).parents[1] / 'repositories/FournisseurRepository.php').read_text()
        statements = re.findall(r"'((?:UPDATE fournisseur_banamur SET (?:archive_previous_active|actif = COALESCE))[^']+)'", source)
        self.assertEqual(len(statements), 2)
        archive, restore = statements
        db = sqlite3.connect(':memory:')
        db.create_function('NOW', 0, lambda: '2026-10-15 12:00:00')
        db.executescript('''
            CREATE TABLE fournisseur_banamur (
                id_fournisseur INTEGER PRIMARY KEY, actif INTEGER,
                archive_previous_active INTEGER, archived_at TEXT, archived_by_user_id INTEGER
            );
            CREATE TABLE invoices (id INTEGER, supplier_id INTEGER, amount INTEGER);
            INSERT INTO fournisseur_banamur VALUES (1,1,NULL,NULL,NULL),(2,0,NULL,NULL,NULL);
            INSERT INTO invoices VALUES (10,1,325000);
        ''')
        for supplier_id, previous_active in [(1, 1), (2, 0)]:
            self.assertEqual(db.execute(archive, {'id': supplier_id, 'actor': 7}).rowcount, 1)
            self.assertEqual(db.execute(archive, {'id': supplier_id, 'actor': 9}).rowcount, 0)
            row = db.execute('SELECT actif, archive_previous_active, archived_by_user_id FROM fournisseur_banamur WHERE id_fournisseur=?', (supplier_id,)).fetchone()
            self.assertEqual(row, (0, previous_active, 7))
            self.assertEqual(db.execute('SELECT COUNT(*) FROM fournisseur_banamur WHERE id_fournisseur=? AND actif=1 AND archived_at IS NULL', (supplier_id,)).fetchone()[0], 0)
            self.assertEqual(db.execute(restore, {'id': supplier_id}).rowcount, 1)
            self.assertEqual(db.execute(restore, {'id': supplier_id}).rowcount, 0)
            self.assertEqual(db.execute('SELECT actif, archived_at FROM fournisseur_banamur WHERE id_fournisseur=?', (supplier_id,)).fetchone(), (previous_active, None))
        self.assertEqual(db.execute('SELECT i.amount FROM invoices i JOIN fournisseur_banamur f ON f.id_fournisseur=i.supplier_id').fetchall(), [(325000,)])
        self.assertEqual(db.execute(archive, {'id': 999, 'actor': 7}).rowcount, 0)


if __name__ == '__main__':
    unittest.main()
