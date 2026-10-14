# Performance targets: Magento extension

No changes were made to the extension for performance, so no new targets. The checks that guard it: `bash tests/regression/run.sh` (all files compile, every class loads against the installed Magento, log rotator) and the page timings in `BASELINE.md`, which must not get worse by more than 10 % (admin Flipick page time to first byte p50 105 ms, 18 extension queries).
