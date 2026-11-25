# Sync coverage and outage recovery

Ranges are stored as half-open Unix seconds. Finance coverage means every requested transaction type and page returned successfully, not that the provider has finalized the ledger. Failed jobs can leave valid partial rows, but cannot claim a new completed interval. Old sync timestamps are not backfilled into verified ranges.

Orders record last-modified-time windows atomically with order writes. The next sync starts at the earlier of its requested lookback or five minutes before the last completed range end. The 89-day ceiling is a conservative application policy; older gaps are flagged rather than silently discarded. It is not a provider retention guarantee. Order-created-date completeness is not inferred from modified-time ranges. The current implementation re-reads at least seven days on normal polling, prioritizing correctness over traffic. Real stage/load testing is still required.

Manual finance backfills support 7/30/90 days and split into 14-day requests (official maximum 15 days). Reports show known gaps. No live store or server changes in this commit.
