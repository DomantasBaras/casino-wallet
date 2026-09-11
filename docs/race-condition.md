## Result

Same script, unchanged, after replacing read-check-write with the atomic
conditional UPDATE:

| Concurrency | Accepted | Rejected | Ledger sum | Final balance | Invariant |
|-------------|----------|----------|------------|---------------|-----------|
| 50          | 10       | 40       | -100.00    | 0.00          | holds     |
| 50          | 10       | 40       | -100.00    | 0.00          | holds     |
| 50          | 10       | 40       | -100.00    | 0.00          | holds     |
| 50          | 10       | 40       | -100.00    | 0.00          | holds     |
| 100         | 10       | 90       | -100.00    | 0.00          | holds     |

Before the fix, six runs produced six different answers. After it, five runs
produce one answer, and doubling the concurrency changes nothing. Raw captures
in `docs/race-condition-fixed.txt`.