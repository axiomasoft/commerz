# Commerz Stored Value (`axiomasoft/commerz-stored-value`)

Prepaid gift cards, customer store-credit wallets, immutable double-entry ledger, and post-tax payment tender settlement for Commerz.

## Core Guarantees

- **Post-tax tender semantics**: Gift cards and store credit are payment tenders deducted from `grandTotal` **after** taxes are calculated. They never reduce the taxable VAT base.
- **Hashed gift-card codes**: Plaintext codes are returned once at issuance; only the canonical SHA-256 `code_hash` and `code_last4` are persisted in `cmz_stored_value_accounts`.
- **Immutable double-entry ledger**: Every `issue`, `hold`, `release`, `capture`, `refund_credit`, and `expire` mutation posts a balanced debit/credit journal pair into `cmz_stored_value_ledger`.
- **INV-SV-001 (`balance_minor - held_minor >= 0`)**: Enforced atomically via row locking (`FOR UPDATE`), conditional SQL `UPDATE` predicates, and PostgreSQL `CHECK` constraints so concurrent double-spend fails cleanly with `InsufficientStoredValue`.
