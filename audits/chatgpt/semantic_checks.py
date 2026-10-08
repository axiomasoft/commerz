#!/usr/bin/env python3
"""Executable counterexamples for the proposed Commerce architecture.

Python 3.10+, standard library only. No network access or production database.
These are small semantic tests, NOT a Laravel implementation, benchmark, or proof
of PostgreSQL/MySQL transaction behavior. SQLite serializes writers here.

Run: python semantic_checks.py
"""
from __future__ import annotations

import json
import sqlite3
import tempfile
import threading
import unittest
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass
from decimal import Decimal
from pathlib import Path


@dataclass
class Balance:
    on_hand: Decimal
    reserved: Decimal = Decimal('0')
    unavailable: Decimal = Decimal('0')
    safety_stock: Decimal = Decimal('0')

    @property
    def available(self) -> Decimal:
        return max(Decimal('0'), self.on_hand - self.unavailable - self.reserved - self.safety_stock)

    def reserve(self, quantity: Decimal) -> None:
        if quantity <= 0 or quantity > self.available:
            raise ValueError('Insufficient available stock or invalid quantity')
        self.reserved += quantity

    def ship_reserved(self, quantity: Decimal) -> None:
        if quantity <= 0 or quantity > self.reserved or quantity > self.on_hand - self.unavailable:
            raise ValueError('Invalid reserved shipment')
        self.on_hand -= quantity
        self.reserved -= quantity

    def release(self, quantity: Decimal) -> None:
        if quantity <= 0 or quantity > self.reserved:
            raise ValueError('Invalid release')
        self.reserved -= quantity


def catalog_fixture() -> sqlite3.Connection:
    db = sqlite3.connect(':memory:')
    db.executescript('''
        CREATE TABLE variants (
            id INTEGER PRIMARY KEY,
            product_id INTEGER NOT NULL,
            color TEXT NOT NULL,
            size TEXT NOT NULL,
            price_minor INTEGER NOT NULL,
            available INTEGER NOT NULL
        );
        INSERT INTO variants VALUES
            (1, 1, 'red',  'S', 100, 0),
            (2, 1, 'blue', 'M', 200, 5),
            (3, 2, 'red',  'M', 150, 2),
            (4, 2, 'red',  'L', 180, 3);
        CREATE TABLE parts (
            product_id INTEGER NOT NULL,
            group_key TEXT NOT NULL,
            memory_type TEXT NOT NULL,
            capacity INTEGER NOT NULL
        );
        INSERT INTO parts VALUES (1, 'row-a', 'DDR4', 16), (1, 'row-b', 'DDR5', 8);
    ''')
    return db


def initialize_stock(path: str) -> None:
    with sqlite3.connect(path) as db:
        db.executescript('''
            CREATE TABLE balances (
                id INTEGER PRIMARY KEY,
                on_hand INTEGER NOT NULL,
                reserved INTEGER NOT NULL DEFAULT 0 CHECK(reserved >= 0)
            );
            INSERT INTO balances VALUES (1, 1, 0);
            CREATE TABLE commands (
                command_key TEXT PRIMARY KEY,
                quantity INTEGER NOT NULL,
                accepted INTEGER NOT NULL
            );
            CREATE TABLE reservation_events (
                command_key TEXT PRIMARY KEY REFERENCES commands(command_key),
                quantity INTEGER NOT NULL CHECK(quantity > 0)
            );
        ''')


def reserve_sqlite(path: str, key: str, quantity: int) -> bool:
    """Tiny idempotent operation; stock unit is integer in this fixture only."""
    if quantity <= 0:
        raise ValueError('Quantity must be positive')
    db = sqlite3.connect(path, isolation_level=None, timeout=10)
    db.execute('PRAGMA foreign_keys = ON')
    try:
        db.execute('BEGIN IMMEDIATE')  # SQLite writer serialization, not a row lock.
        existing = db.execute(
            'SELECT quantity, accepted FROM commands WHERE command_key = ?', (key,)
        ).fetchone()
        if existing is not None:
            if existing[0] != quantity:
                raise ValueError('Idempotency key reused with a different payload')
            db.commit()
            return bool(existing[1])
        changed = db.execute(
            '''UPDATE balances SET reserved = reserved + ?
               WHERE id = 1 AND on_hand - reserved >= ?''',
            (quantity, quantity),
        ).rowcount
        accepted = changed == 1
        db.execute('INSERT INTO commands VALUES (?, ?, ?)', (key, quantity, int(accepted)))
        if accepted:
            db.execute('INSERT INTO reservation_events VALUES (?, ?)', (key, quantity))
        db.commit()
        return accepted
    except Exception:
        db.rollback()
        raise
    finally:
        db.close()


class CommerceSemanticChecks(unittest.TestCase):
    def test_01_numeric_sort_is_not_lexical_sort(self) -> None:
        values = ['100', '20', '3']
        self.assertEqual(sorted(values), ['100', '20', '3'])
        self.assertEqual(sorted(values, key=Decimal), ['3', '20', '100'])

    def test_02_color_and_size_must_match_the_same_variant(self) -> None:
        with catalog_fixture() as db:
            wrong = db.execute('''
                SELECT DISTINCT a.product_id FROM variants a
                WHERE EXISTS (SELECT 1 FROM variants b WHERE b.product_id=a.product_id AND b.color='red')
                  AND EXISTS (SELECT 1 FROM variants c WHERE c.product_id=a.product_id AND c.size='M')
            ''').fetchall()
            correct = db.execute("SELECT DISTINCT product_id FROM variants WHERE color='red' AND size='M'").fetchall()
        self.assertEqual(wrong, [(1,), (2,)])
        self.assertEqual(correct, [(2,)])

    def test_03_price_and_availability_must_match_the_selected_variant(self) -> None:
        with catalog_fixture() as db:
            wrong = db.execute('''
                SELECT DISTINCT a.product_id FROM variants a
                WHERE EXISTS (SELECT 1 FROM variants b WHERE b.product_id=a.product_id AND b.color='red')
                  AND EXISTS (SELECT 1 FROM variants c WHERE c.product_id=a.product_id AND c.available>0)
            ''').fetchall()
            correct = db.execute("SELECT DISTINCT product_id FROM variants WHERE color='red' AND available>0").fetchall()
            matching_price = db.execute("SELECT MIN(price_minor) FROM variants WHERE product_id=1 AND color='blue' AND available>0").fetchone()[0]
        self.assertEqual(wrong, [(1,), (2,)])
        self.assertEqual(correct, [(2,)])
        self.assertEqual(matching_price, 200)  # Not the unavailable red variant's 100.

    def test_04_repeated_object_predicates_must_match_the_same_row(self) -> None:
        with catalog_fixture() as db:
            wrong = db.execute('''
                SELECT DISTINCT a.product_id FROM parts a
                WHERE EXISTS (SELECT 1 FROM parts b WHERE b.product_id=a.product_id AND b.memory_type='DDR5')
                  AND EXISTS (SELECT 1 FROM parts c WHERE c.product_id=a.product_id AND c.capacity>=16)
            ''').fetchall()
            correct = db.execute("SELECT product_id FROM parts WHERE memory_type='DDR5' AND capacity>=16").fetchall()
        self.assertEqual(wrong, [(1,)])
        self.assertEqual(correct, [])

    def test_05_facets_count_distinct_products_not_variants(self) -> None:
        with catalog_fixture() as db:
            counts = db.execute("SELECT COUNT(*), COUNT(DISTINCT product_id) FROM variants WHERE color='red' AND available>0").fetchone()
        self.assertEqual(counts, (2, 1))

    def test_06_disjunctive_size_facet_excludes_only_the_size_filter(self) -> None:
        with catalog_fixture() as db:
            # Active query is red AND M AND available. Size facet keeps red+available.
            facets = dict(db.execute("SELECT size, COUNT(DISTINCT product_id) FROM variants WHERE color='red' AND available>0 GROUP BY size"))
        self.assertEqual(facets, {'L': 1, 'M': 1})

    def test_07_clear_is_not_missing_in_scope_fallback(self) -> None:
        missing = object()
        def resolve(override: object, inherited: object) -> object:
            return inherited if override is missing else override
        self.assertEqual(resolve(missing, ['tag-a']), ['tag-a'])
        self.assertEqual(resolve([], ['tag-a']), [])
        self.assertIsNone(resolve(None, 'inherited'))  # Explicit clear policy in this fixture.

    def test_08_shared_warehouse_does_not_multiply_inventory(self) -> None:
        physical = Balance(Decimal('1'))
        naive_a = Balance(physical.on_hand)
        naive_b = Balance(physical.on_hand)
        naive_a.reserve(Decimal('1'))
        naive_b.reserve(Decimal('1'))
        self.assertEqual(naive_a.reserved + naive_b.reserved, Decimal('2'))
        physical.reserve(Decimal('1'))
        with self.assertRaises(ValueError):
            physical.reserve(Decimal('1'))

    def test_09_concurrent_last_unit_has_one_success_in_sqlite_fixture(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = str(Path(directory) / 'stock.sqlite3')
            initialize_stock(path)
            workers = 16
            barrier = threading.Barrier(workers)
            def task(index: int) -> bool:
                barrier.wait(timeout=10)
                return reserve_sqlite(path, f'order-{index}', 1)
            with ThreadPoolExecutor(max_workers=workers) as executor:
                results = list(executor.map(task, range(workers)))
            with sqlite3.connect(path) as db:
                balance = db.execute('SELECT on_hand, reserved FROM balances').fetchone()
                events = db.execute('SELECT COUNT(*) FROM reservation_events').fetchone()[0]
            self.assertEqual(sum(results), 1)
            self.assertEqual(balance, (1, 1))
            self.assertEqual(events, 1)

    def test_10_duplicate_command_has_no_duplicate_effect(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = str(Path(directory) / 'stock.sqlite3')
            initialize_stock(path)
            self.assertTrue(reserve_sqlite(path, 'order-1', 1))
            self.assertTrue(reserve_sqlite(path, 'order-1', 1))
            with self.assertRaises(ValueError):
                reserve_sqlite(path, 'order-1', 2)
            with sqlite3.connect(path) as db:
                self.assertEqual(db.execute('SELECT reserved FROM balances').fetchone()[0], 1)

    def test_11_shipment_reduces_physical_and_reserved_once(self) -> None:
        stock = Balance(Decimal('10'))
        stock.reserve(Decimal('4'))
        self.assertEqual(stock.available, Decimal('6'))
        # Confirmation is only a reservation state change, not another subtraction.
        stock.ship_reserved(Decimal('2'))
        self.assertEqual((stock.on_hand, stock.reserved, stock.available), (Decimal('8'), Decimal('2'), Decimal('6')))
        stock.release(Decimal('2'))
        self.assertEqual(stock.available, Decimal('8'))

    def test_12_stocktake_can_reveal_a_shortage_without_erasing_reservations(self) -> None:
        stock = Balance(Decimal('5'))
        stock.reserve(Decimal('4'))
        stock.on_hand = Decimal('3')  # Controlled stocktake adjustment, not a checkout action.
        self.assertEqual(stock.available, Decimal('0'))
        self.assertEqual(stock.reserved, Decimal('4'))
        self.assertEqual(stock.reserved - (stock.on_hand - stock.unavailable), Decimal('1'))

    def test_13_measurement_normalization_is_exact_for_finite_decimal_conversion(self) -> None:
        self.assertEqual(Decimal('1.25') * Decimal('1000'), Decimal('1250'))
        self.assertEqual(Decimal('0.1') + Decimal('0.2'), Decimal('0.3'))

    def test_14_bundle_co_location_changes_availability(self) -> None:
        # A kit needs one of each item. W1 has only item A; W2 has only item B.
        warehouses = [{'A': 10, 'B': 0}, {'A': 0, 'B': 10}]
        pooled = min(sum(w[item] for w in warehouses) for item in ['A', 'B'])
        colocated = sum(min(w.values()) for w in warehouses)
        self.assertEqual(pooled, 10)
        self.assertEqual(colocated, 0)

    def test_15_snapshot_projection_rejects_older_versions(self) -> None:
        projection = {'version': 0, 'stock': 0}
        for event in [{'version': 2, 'stock': 9}, {'version': 1, 'stock': 10}]:
            if event['version'] > projection['version']:
                projection.update(event)
        self.assertEqual(projection, {'version': 2, 'stock': 9})
        # This rule is valid for complete snapshots, NOT for skipping delta events.

    def test_16_nullable_unique_scope_is_not_a_portable_uniqueness_guarantee(self) -> None:
        with sqlite3.connect(':memory:') as db:
            db.execute('CREATE TABLE bad (product_id INTEGER, channel_id INTEGER, UNIQUE(product_id, channel_id))')
            db.execute('INSERT INTO bad VALUES (1, NULL)')
            db.execute('INSERT INTO bad VALUES (1, NULL)')
            self.assertEqual(db.execute('SELECT COUNT(*) FROM bad').fetchone()[0], 2)
            db.execute('CREATE TABLE good (product_id INTEGER, scope_key TEXT NOT NULL, UNIQUE(product_id, scope_key))')
            db.execute("INSERT INTO good VALUES (1, 'global')")
            with self.assertRaises(sqlite3.IntegrityError):
                db.execute("INSERT INTO good VALUES (1, 'global')")


if __name__ == '__main__':
    unittest.main(verbosity=2)
