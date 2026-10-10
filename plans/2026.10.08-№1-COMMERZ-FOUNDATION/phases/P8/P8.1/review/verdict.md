# P8.1 review — grok-4.7 (effort high), session 01a12534-5f66-7331-a4e7-46115edc7bc6

Verdict: fail (round 1), two test-harness findings, both confirmed by the executor and corrected in one owning fix:
- Medium: `bootInReverseOrder()` left the `RefreshDatabase` transaction, so a write after the reverse boot committed (reproduced: row stayed in commerz_test). Fixed: the transaction is begun on the new application; regression `tests/EndToEnd/ReverseBootTest.php`.
- Low: the reverse test asserted only that the providers were loaded, not their order. Fixed: asserts the loaded order equals the reversed list.
- Residual risk accepted and closed: the quote test now also locks included VAT (425.00) and the −250.00 order promotion adjustment.
No defect in the core `PluginCatalog` fix (red without it), manifests, or ShopWorld. Cost 1.39 USD, 64 turns, 1467 s.
