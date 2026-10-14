# Performance audit: Magento extension

Phase 1 of the performance work (2026-10-13). Method: read the code paths of the main requests, turned on `pg_stat_statements` and per-request statement counting, CPU-profiled the status route, measured WordPress/Magento page costs with the component on and off. Findings are ranked by measured impact. Only findings with a measured cost were changed; each change is one commit `perf(<ID>): ...` on the branch `perf-optimization` (tag `pre-perf` marks the start). Items that are not performance changes are listed at the end and reported, not fixed.

### EX-1  (not changed)

- **Where:** whole extension
- **What:** Audited: admin page issues 18 database statements (config reads), the storefront block 0; no remote call is made while a page renders (the app runs in a frame).
- **Evidence:** BASELINE.md: Flipick admin page 105 ms to first byte against 155 ms for the Magento dashboard
- **Decision:** NOT CHANGED: nothing the measurements showed to be slow
- **Risk:** n/a

### F-FRAME  (not fixed, reported)

- **Where:** src/middleware (frameAncestors cache)
- **What:** The adapter remembers the list of store addresses allowed to show its app in a frame for 60 s. A store connected less than a minute ago can show a blocked frame ("refused to connect") until the cache expires.
- **Evidence:** seen on both platforms when a new store connects; the store scripts wait 65 s
- **Decision:** NOT FIXED: behaviour change outside the performance task (cache invalidation on register); needs a decision
- **Risk:** low impact (first minute only)
