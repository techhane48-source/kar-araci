# Trendyol cron and connection safety

Configure on a test server first; not enabled by this commit.

- `php scripts/sync.php products`: hourly; full approved product scan.
- `php scripts/sync.php orders`: every 10 minutes; seven-day modified order window.
- `php scripts/sync.php finance`: daily, outside peak hours; 30-day finance/documents.
- Use the same OS user and database directory for web and CLI. Locks are local-file locks, not distributed. Multi-host deployment needs a distributed lock.
- Do not run parallel syncs for the same user. Job status is in sync_status; failed sync preserves prior successful timestamp.
- GET pages no longer fetch orders; explicit POST or cron does.
- Seller and environment are bound to account. Switching requires another account or a deliberately designed migration. Existing disconnected accounts with historical data need support review.
- Back up the database and data/.enc-key together; protect the key separately. New credentials use AES-GCM; legacy CBC remains readable until re-saved.
- CI uses only synthetic data and a temporary database; no real seller credentials or marketplace writes.
