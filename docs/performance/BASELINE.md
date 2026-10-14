# Performance baseline: Magento extension

Measured on 2026-10-13 on one Windows 11 development PC (Docker Desktop, PostgreSQL 16 in a container on the same machine, Node 24, adapter and load generator on the same CPU). Magento 2.4.9 in developer mode (so absolute times are slower than production mode), 8 warm requests per page (p50).
**Baseline build** = tag `pre-perf`. The extension was not changed by the performance work: the audit found nothing in it that the measurements showed to be slow (see `AUDIT.md`).

| page | time to first byte ms | load ms | database queries | queries by the extension | transferred KB |
|---|---|---|---|---|---|
| admin: dashboard | 155 | 643 | 94 | 0 | 975 |
| admin: Flipick page (extension) | 105 | 377 | 66 | 18 | 923 |
| admin: product grid | 149 | 481 | 94 | 0 | 975 |
| admin: system configuration | 147 | 588 | 94 | 0 | 975 |
| storefront: home | 41 | 86 | 4 | 0 | 832 |
| storefront: product WITH video | 51 | 350 | 4 | 0 | 980 |
| storefront: product without video | 31 | 75 | 4 | 0 | 1003 |

The extension's own admin page issues 18 database queries (reading its configuration) and adds 2 requests (the page and the embedded frame); the storefront video block adds no database query.
