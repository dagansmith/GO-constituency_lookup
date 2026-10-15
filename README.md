
# Action Network constituency enrichment

Vanilla PHP service that enriches Action Network (AN) people with their current UK
parliamentary constituency. Webhooks provide the fast path: the endpoint validates
and **durably queues** each delivery, then a **queue worker run by cron every
minute** performs the enrichment. A **twice-daily full sweep** reconciles missed
webhooks, failed writes, and changed postcodes.

## Requirements and setup

- PHP **8.1+**, with cURL and JSON, on a trusted Linux filesystem.
- Action Network API access for the group whose people you want to enrich.
  The AN API token authenticates **both reads and writes** via `OSDI-API-Token`.
- Outbound HTTPS to Action Network and `members-api.parliament.uk`.
  Parliament lookups require **no authentication**.

1. Create/confirm the destination AN custom field (default:
   `parliamentary_constituency`) and obtain the group's API token.
2. Copy `.env.example` to `.env`, set the values, and `chmod 600 .env`.
   Generate a webhook secret with:
   ```sh
   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
   ```
3. **Configuration loading.** `src/bootstrap.php` reads the repository-root
   `.env` for web requests and CLI scripts alike (web servers often do not pass
   the shell/cron environment to PHP). The file is parsed as `KEY=value` **data and
   never executed**; a variable already present in the process environment always
   wins over the file, so server/pool/cron variables keep overriding it. Lines a
   shell would interpret differently (`$`/backticks outside single quotes,
   unquoted spaces) are ignored and only their line numbers are logged. See
   `.env.example` for the format; it also remains safe to `set -a; . .env` in a
   shell. Check a file without printing values:
   ```sh
   php -r 'require "src/Env.php"; foreach (file(".env") as $n => $l) { if (Constituency\Env::parseLine($l) === false) echo "Line ", $n + 1, " would be ignored\n"; }'
   ```
4. Serve **only `public/`** through HTTPS, with PHP execution enabled.
   Never expose the repository root, `.env`, `src/`, `scripts/`, or lock files.
   Disable PHP `display_errors`; send error logs to your monitoring system.
5. Set `LOCK_DIRECTORY` and `QUEUE_DIRECTORY` to writable private absolute
   directories shared by webhook and cron, outside the public root. Defaults are
   `var/locks` and `var/queue` in the repository. They are created with directory
   mode 0700 and file mode 0600, so **the web PHP process and the cron/SSH user
   must be the same OS user** (typical on shared hosting such as 20i). If they
   differ, pre-create the directories owned by that shared user, or ask your host
   to run both as one user; do not make them world-writable.
6. Configure AN webhooks for the relevant supporter/action events available to
   your account, targeting `/webhook.php`. Check an actual payload against the
   contract below. Not every AN event or manual edit necessarily emits a webhook;
   the full sweep is the safety net.

## Configuration

| Variable | Default / meaning |
| --- | --- |
| `AN_API_TOKEN` | Required; AN group API key. Never use as the webhook secret. |
| `AN_CONSTITUENCY_FIELD` | `parliamentary_constituency`; destination custom field. |
| `AN_POSTCODE_FIELD` | Empty: use primary `postal_addresses[].postal_code`, otherwise first address with a postcode. Set to a custom-field name if your group stores postcodes there. No fallback when an explicitly selected postcode is invalid. |
| `AN_API_BASE_URL` | `https://actionnetwork.org/api/v2` |
| `PARLIAMENT_LOOKUP_URL` | `https://members-api.parliament.uk/api/Location/Constituency/Search` |
| `PARLIAMENT_MEMBERS_LOOKUP_URL` | `https://members-api.parliament.uk/api/Members/Search`; documented postcode fallback. |
| `WEBHOOK_SECRET` | Required for webhook; at least 32 characters. Cron does not require it. |
| `LOCK_DIRECTORY` | Repository `var/locks`; private absolute directory outside the public root. |
| `QUEUE_DIRECTORY` | Repository `var/queue`; private absolute directory outside the public root (paths with `..` or inside `public/` are rejected). |
| `HTTP_RETRIES` | `2` additional attempts; allowed 0–5. |
| `HTTP_TIMEOUT_SECONDS` | `15` per attempt; allowed 1–60. Connection timeout is at most 5 seconds. |

API endpoint overrides must be trusted HTTPS URLs without embedded credentials,
query strings, or fragments. AN pagination links must stay under the configured
API origin/path; redirects are not followed.

## Webhook contract and security

Send a JSON `POST` (maximum 1 MiB) with `Content-Type: application/json`. Authenticate
with an `X-Webhook-Token` header equal to `WEBHOOK_SECRET`. If your AN webhook
configuration cannot set headers, use the HTTPS destination URL:

```text
https://your-service.example/webhook.php?token=YOUR_WEBHOOK_SECRET
```

This is a bearer-token gate, **not an AN-verified signature**. Restrict access to
webhook configuration, redact query strings from reverse-proxy/access logs, and
rotate the secret if the URL is exposed. Do not log request bodies. Authentication
happens before parsing. The token is never stored in queue jobs or logged.

Native AN deliveries are JSON arrays of event wrappers such as
`[{"osdi:submission": {...}, "idempotency_key": "..."}]`. The service unwraps
`osdi:*` resources, ignores sponsor/idempotency metadata, and processes every
distinct person. A single event wrapper or these unwrapped payloads also work:

- An AN person resource with `identifiers: ["action_network:PERSON_ID"]` or its
  canonical `_links.self.href`.
- A wrapper with `person` containing that resource.
- An OSDI action resource with `_links["osdi:person"].href` pointing to that
  group's AN API person endpoint.

Canonical `osdi:person` links take priority over embedded person data (which
native action webhooks may supply without identifiers). Action identifiers are
never treated as person identifiers in event wrappers.

If your webhook omits the AN identifier/link, adapt the upstream payload to
include it; an email address alone is deliberately not accepted. The service
**always fetches the current person from AN**, rather than trusting payload
postcodes or custom fields.

Responses:

- **200** `{"accepted": true, "job_id": "...", "people": N}` — the person IDs
  were validated and **durably written to the queue** (flushed with `fsync` and
  atomically renamed) before responding. It does **not** mean AN was updated yet;
  the worker does that, normally within a minute or two.
- 400 malformed JSON or unsupported identity (nothing is queued; invalid identities
  reject the whole payload); 401 bad token; 405 wrong method; 413 oversized body;
  415 wrong content type.
- **503** configuration error or **queue storage failure** — nothing was
  acknowledged; the sender should retry. The sweep reconciles regardless of the
  sender's retry policy.

The acceptance path makes **no remote API calls**: it authenticates, checks the
content type and size, parses JSON, extracts person IDs locally, writes one queue
file, and responds. Only the opaque person IDs and scheduling metadata are stored
— never the request body, headers, token, postcodes or emails. Rejections are
logged with a reason code only (`webhook_rejected`); accepted deliveries log
`webhook_queued` with the job ID.

## Processing and recovery

Postcodes are uppercased, whitespace-normalized, and checked for UK syntax.
Syntax validation does not prove that a postcode exists. Parliament's constituency
search receives `searchText`, `skip=0`, and `take=20`; only a single constituency
with `endDate: null` is accepted. Historical, ambiguous, or truncated results
are never guessed.

The published schema describes constituency `searchText` as a **name** search.
If it yields no current match, the service uses the explicitly documented
postcode query `Members/Search?Location=POSTCODE&House=1&IsCurrentMember=true`
and reads `latestHouseMembership.membershipFrom` from a current Commons member.
This fallback also requires one unambiguous constituency and shares the cache.
If the constituency search cannot resolve a postcode and the seat is vacant
(or Parliament is dissolved), the fallback may return `no_match`; the existing
value is preserved and cron retries later.

AN person updates use **PUT**, not PATCH, with only the destination
`custom_fields` entry. Other fields are not sent or replaced. An exact existing
match skips the write, including when the enrichment write triggers another
webhook. Each process caches normalized postcode results and no-matches in
memory; transient lookup failures are not cached.

Network failures, 429, and 5xx receive bounded exponential retries (1s, 2s by
default). `Retry-After` is respected up to a 5-second delay cap; persistent
throttling is left for the queue's retry schedule and the next sweep. Other
non-2xx responses fail immediately. Invalid/no-match results leave any existing
value untouched and are rechecked by the sweep.

**No database or per-person status custom fields are required.** Besides the
small webhook queue, AN is the durable source of truth: every sweep follows all `osdi:people` pages visible
to the token, then re-reads and processes each person. This catches stale as well
as missing constituency values, and retries failures even if a webhook never
arrived. JSON logs record UTC timestamp, opaque person ID, source, and outcome or
sanitized error; no postcode, email, token, or API response body is logged.

Tradeoffs: each run scans the entire group, with one fresh AN GET per person plus
page requests, Parliament lookups per distinct postcode, and writes only for
changes. There is no checkpoint: pagination failure stops that run, logs an
error, and the next run restarts from page one. Monitor failures and runtime,
especially for large groups; a permanently failing early page requires operator
attention. Invalid and no-match postcodes are retried every sweep. Recovery may
take up to 12 hours, longer during an ongoing outage.

Filesystem locks prevent overlapping sweeps and workers, and serialize a person's
processing between the queue worker and the sweep on **one host/shared locking filesystem**. Keep lock
files; deleting a locked file defeats locking. Multi-host deployments need a
reliable shared lock mechanism or a single service host. Locks cannot prevent
external AN edits during a lookup; the next webhook/sweep repairs those races.

## Durable webhook queue and worker

Queue layout under `QUEUE_DIRECTORY` (server-generated job IDs such as
`20261015105055123456-0123456789abcdef`; request data never chooses a path):

| Directory | Meaning |
| --- | --- |
| `tmp/` | Files being written. Never acknowledged; abandoned ones are deleted after an hour. |
| `pending/` | Accepted jobs waiting (or scheduled for a retry). |
| `processing/` | Claimed by the running worker. Left here only if a worker crashed; the next run recovers them. |
| `failed/` | Terminal failures, **kept for investigation** until requeued or removed by an operator. |

`scripts/queue-worker.php` (CLI only):

- Takes a non-blocking **worker lock**, so overlapping cron runs exit 0 with
  `{"skipped":"worker_already_running"}`. It still also takes the existing
  **per-person lock**, so it serializes with the sweeper for the same person.
- Recovers jobs left in `processing/` by a crashed run (with a retry delay), then
  claims due jobs oldest first by **atomic rename** into `processing/`.
- Re-reads every person from AN (existing enrichment behavior). A job is
  **deleted only after every person in it succeeded**. With several people,
  successful people are removed from the job as it goes; failed people stay.
- Failure: retried after 1, 2, 4, 8, 16, 32, then 60 minutes; the attempt is
  counted before work starts, so a job that repeatedly crashes the worker also
  ends up failed. After 10 attempts (about 4½ hours) it moves to `failed/` and
  logs `queue_job_failed`. The twice-daily sweep still reconciles that person.
- Duplicates: deliveries are **at least once**, not exactly once. Within one run,
  a job for a person whose fresh read *started after the job was received* is
  skipped as covered (`duplicates_skipped`); otherwise processing is idempotent
  (an exact existing value skips the write). The worker's own AN update may
  trigger another webhook; that job finishes as `unchanged`.
- Bounded per run: `--max-jobs=N` (default 50, max 1000) and `--max-seconds=N`
  (default 45, max 3600; checked between people, so a slow API call can overrun).
  Unfinished work is returned to `pending/` without counting an attempt.
- Output: one JSON summary on stdout; sanitized JSON log lines (job ID, person
  ID, outcome or error) via PHP `error_log` (stderr for CLI unless the CLI
  `php.ini` sets `error_log`). Exit 0 = no errors, 1 = some person or the run
  failed (jobs are kept), 2 = bad arguments.

```sh
php scripts/queue-worker.php                  # process due jobs
php scripts/queue-worker.php --status         # counts and oldest pending age; read-only
php scripts/queue-worker.php --requeue-failed # move failed/ back to pending/ with fresh attempts
```

Never delete `pending/` or `processing/` files to "unstick" the queue; run
`--status`, read `last_error` in the job file, fix the cause, and let the worker
retry. Deleting a file in `failed/` is a deliberate operator decision. Queue
files contain AN person IDs; keep the directory private and back it up with the
application. Multi-host deployments need a shared filesystem for the queue and
locks or a single worker host.

## Cron: every-minute worker and twice-daily sweep

Use absolute paths, the same OS user as the web PHP process, and a pre-created
private log directory. The worker runs every minute (if your host only allows a
coarser schedule, use the smallest it allows; queued jobs simply wait longer).
The sweep stays twice daily as reconciliation (midnight and noon **in the cron
host's timezone**); do not turn it into a minute job — it scans the whole group.

```cron
* * * * * /usr/bin/php /opt/constituency-lookup/scripts/queue-worker.php >> /var/log/constituency-lookup/queue-worker.log 2>&1
0 0,12 * * * /bin/sh -c 'set -a; . /opt/constituency-lookup/.env; set +a; exec /usr/bin/php /opt/constituency-lookup/scripts/sweeper.php' >> /var/log/constituency-lookup/sweeper.log 2>&1
```

The worker needs no shell sourcing because the bootstrap reads `.env`; the
existing sourced sweeper line keeps working (sourced values take precedence and
are identical). The worker writes about one short JSON line per minute: rotate
or truncate its log periodically and alert on repeated non-zero exits, a growing
`--status` `oldest_pending_age_seconds`, or any `failed` count.

Sweeper:

`--dry-run` performs real reads/lookups and reports `would_update`, but **never
writes to AN**. Final stdout is a JSON counter summary. Exit code 0 means the scan
completed without API/processing errors (invalid/no-match inputs have separate
counters); 1 means failure or an overlapping sweep; 2 means invalid CLI arguments.
Per-person failures do not stop the scan. Rotate/restrict logs and alert on
nonzero exit codes, invalid/no-match counts, and runs that fail to complete.

## Local checks

No Composer packages or test framework are needed:

```sh
php /absolute/path/to/GO-constituency_lookup/tests/run.php
find /absolute/path/to/GO-constituency_lookup/src /absolute/path/to/GO-constituency_lookup/public /absolute/path/to/GO-constituency_lookup/scripts /absolute/path/to/GO-constituency_lookup/tests -name '*.php' -exec php -l {} \;
```

Tests use synthetic responses and no live credentials/API writes. They cover
postcode validation, authentication, cache behavior, current vs historical
results, idempotence, dry-run, recovery, pagination safety, locks, `.env`
parsing, and the queue: the real `webhook.php` served by PHP's built-in server
on localhost (auth/content-type/size/JSON/identity rejections, 503 on an
unwritable queue, token never stored) followed by worker enrichment, partial
multi-person failure, retry backoff, terminal failures and requeue, duplicates,
crash recovery, corrupt files, and the worker lock. Tests need permission to
bind a random `127.0.0.1` port; permission tests are skipped when run as root.

### Acceptance benchmark (local only)

```sh
php tests/benchmark-acceptance.php 200
```

This sends real authenticated requests to the real `webhook.php` and queue on
localhost, without mocks or remote calls, and reports min/p50/p95/max. On the
development container (PHP 8.3, ext4, built-in server) 200 requests measured
about **0.6 ms p50, 0.7 ms p95, 5 ms max**. That is **local acceptance time
only**. What Action Network observes also includes DNS, TLS, the internet path,
the CDN/proxy, the host web server and PHP start-up, and host disk/CPU
contention, none of which this measures. Measure end to end from outside with
`curl -w '%{time_total}'` (see deployment). Queuing removes the AN and
Parliament API calls from the response path, but **no implementation can
guarantee every request meets a 500 ms deadline** on shared hosting.

After exporting a **test group's** environment, run the local server:

```sh
php -S 127.0.0.1:8080 -t /absolute/path/to/GO-constituency_lookup/public
```

From another shell with the same webhook secret exported:

```sh
curl -i http://127.0.0.1:8080/webhook.php \
  -H 'Content-Type: application/json' \
  -H "X-Webhook-Token: $WEBHOOK_SECRET" \
  --data '{"person":{"identifiers":["action_network:REPLACE_WITH_TEST_PERSON_ID"]}}'
```

This returns `{"accepted":true,...}` and queues a job; run
`php scripts/queue-worker.php` to process it. The worker **writes to AN** when
the field differs; a second delivery should finish as `unchanged`. Use cron
`--dry-run` first for read-only verification. The development server's HTTP
address is for localhost only; deploy the real webhook with HTTPS.

## Deploying the queue to the 20i host

Paths below are for the current deployment; nothing here needs a secret to be
pasted into chat, tickets or logs. Run server steps over SSH as the hosting
account user.

```sh
APP=/home/virtual/vps-16f9df/1/13bd028939/apps/GO-constituency_lookup
PHP=/usr/php83/usr/bin/php
```

### 0. Rotate the exposed webhook secret

The previous `WEBHOOK_SECRET` was exposed and must not be reused. On the server:

```sh
$PHP -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'   # copy the output; do not paste it anywhere else
nano "$APP/.env"                                     # replace the WEBHOOK_SECRET value
chmod 600 "$APP/.env"
```

Then set the same new value as the `X-Webhook-Token` header in the AN webhook
settings. The old token now returns 401.

### 1. Back up before changing anything

```sh
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP="$HOME/backups/constituency-$STAMP"
mkdir -p "$BACKUP" && chmod 700 "$BACKUP"
cd "$APP"
git status --short                                   # expect: M src/bootstrap.php (+ any bootstrap.php.bak.* file)
git rev-parse HEAD > "$BACKUP/previous-commit.txt"
git diff > "$BACKUP/server-local-changes.patch"
cp -p src/bootstrap.php "$BACKUP/bootstrap.php.server-edit"
cp -p .env "$BACKUP/env.backup" && chmod 600 "$BACKUP/env.backup"
[ -d var ] && cp -pR var "$BACKUP/var"
crontab -l > "$BACKUP/crontab.txt" 2>/dev/null || echo "Copy the cron entries from the 20i panel instead"
```

Keep `$BACKUP` private. It contains `.env`; delete the backup copy of `.env`
once the deployment is confirmed.

### 2. Reconcile the manual `src/bootstrap.php` edit (no reset/reclone)

The server's uncommitted edit loads the root `.env` with `putenv`. This release
contains an equivalent, stricter loader in `src/bootstrap.php`/`src/Env.php`
(same precedence: existing environment variables win), so the manual edit is
**superseded** rather than merged. Set it aside safely, then update:

```sh
cd "$APP"
git stash push -m "server dotenv edit $STAMP" -- src/bootstrap.php
git stash list                                       # keep this entry until the deployment is confirmed
mv src/bootstrap.php.bak.* "$BACKUP/" 2>/dev/null    # stray backup copies from earlier edits, if any
git fetch origin                                     # after this change is merged into main
git log --oneline HEAD..origin/main                  # review what will be applied
git merge --ff-only origin/main
```

If `--ff-only` refuses, stop and investigate (`git status`, `git log`); do not
force it. Do **not** `git stash pop` afterwards (it would duplicate the loader);
compare with `git stash show -p stash@{0}` if needed. Then confirm `.env` parses
with the new loader (prints line numbers only, never values):

```sh
$PHP -r 'require "src/Env.php"; foreach (file(".env") as $n => $l) { if (Constituency\Env::parseLine($l) === false) echo "Line ", $n + 1, " would be ignored\n"; }'
```

Fix any reported line by single-quoting its value. The old manual loader
accepted some forms (e.g. unquoted spaces) that a shell would not.

### 3. Lint and test with PHP 8.3

```sh
cd "$APP"
find src public scripts tests -name '*.php' -exec $PHP -l {} \;
$PHP tests/run.php                                   # expect "0 failures"; makes no live API calls
$PHP tests/benchmark-acceptance.php 50               # local-only timing; see the benchmark notes above
```

The tests use a temporary directory and a localhost built-in server; if the host
forbids binding a local port, the HTTP test fails while the rest still run —
rely on step 6 instead.

### 4. Create the queue and check ownership

```sh
cd "$APP"
mkdir -p var/queue var/locks var/logs
chmod 700 var var/queue var/locks var/logs
$PHP scripts/queue-worker.php --status              # creates tmp/pending/processing/failed; prints counts
id -u; ls -ln var var/locks | head                   # existing lock files were created by the web process
```

The UID owning lock files written by web requests must match `id -u` (the cron
user). Step 6 confirms this for the queue too. If they differ, stop and ask 20i
to run PHP and cron as the same user; do not `chmod 777`.

### 5. Check the endpoint is serving the new code (no update performed)

From your Mac:

```sh
curl -i https://wsoautomations-com.lhr.stackstaging.com/webhook.php \
  -H 'Content-Type: application/json' -H 'X-Webhook-Token: deliberately-invalid-token' --data '{}'
```

Expect **401**. A 503 means the web process cannot read `.env` or the secret is
under 32 characters (check the PHP error log for `env_file_error`).

### 6. End-to-end test with one designated test person

1. In AN, choose a test person with a valid UK postcode and set their
   `parliamentary_constituency` field to a placeholder such as `queue-test`, so
   that a real update is observable.
2. From your Mac (the secret is typed at a hidden prompt, not saved in history):
   ```sh
   read -rs WEBHOOK_TOKEN && export WEBHOOK_TOKEN
   curl -sS -i -w '\ntime_total=%{time_total}s\n' https://wsoautomations-com.lhr.stackstaging.com/webhook.php \
     -H 'Content-Type: application/json' -H "X-Webhook-Token: $WEBHOOK_TOKEN" \
     --data '{"person":{"identifiers":["action_network:TEST_PERSON_ID"]}}'
   unset WEBHOOK_TOKEN
   ```
   Expect `HTTP 200` and `{"accepted":true,"job_id":"…","people":1}`. Note the
   job ID. `time_total` is **end to end from your Mac** (network, TLS, CDN,
   host); repeat a few times to see its spread. **HTTP 200 alone proves only that
   the job was stored, not that AN was updated.**
3. On the server, confirm the job was persisted by the web user, without the token:
   ```sh
   cd "$APP"
   ls -ln var/queue/pending/                         # JOB_ID.json, owner UID = id -u
   cat var/queue/pending/JOB_ID.json                 # only IDs, attempts, timestamps; no token
   $PHP scripts/queue-worker.php --status
   ```
4. Run the worker once by hand (safe: lock-protected, bounded, never deletes a
   job before it succeeds):
   ```sh
   $PHP scripts/queue-worker.php --max-jobs=5
   ```
   Expect `"completed":1` and `"outcomes":{"updated":1}`, plus an `enrichment`
   log line for the test person. Any other jobs already queued by live AN
   deliveries are processed too.
5. **Verify in AN** that the test person's field now shows the correct
   constituency (not `queue-test`). Only this confirms the eventual update.
   `--status` should show the job is gone.

### 7. Schedule the worker

Add a cron job in the 20i panel (or `crontab -e`), keeping the existing sweeper
entry unchanged:

```cron
* * * * * /usr/php83/usr/bin/php /home/virtual/vps-16f9df/1/13bd028939/apps/GO-constituency_lookup/scripts/queue-worker.php >> /home/virtual/vps-16f9df/1/13bd028939/apps/GO-constituency_lookup/var/logs/queue-worker.log 2>&1
```

If the panel cannot run every minute, use its smallest interval. After a few
minutes, `tail -n 20 "$APP/var/logs/queue-worker.log"` should show one JSON
summary per run. If log lines go elsewhere, check `$PHP -i | grep error_log`.
Repeat step 6 (with the placeholder reset) **without** the manual run, and
confirm AN updates within about two minutes.

### 8. Verify real Action Network deliveries

1. Run AN's webhook test. If it still fails, check the host PHP error log for
   `webhook_rejected` (with a `reason`) or `webhook_queue_error` at that time,
   and measure `time_total` as in step 6. If the server logs `webhook_queued`
   but AN still reports a failure, the remaining latency is outside the
   application (network, CDN/firewall, host start-up). A rejection with
   `no_person_identity` means that payload contained no person of this group.
2. Trigger a real event for the test person (e.g. submit a test form as them)
   after resetting their field to the placeholder. Then confirm, in order:
   `webhook_queued` in the PHP error log; `queue_job_completed` and an
   `enrichment` line for the person in `queue-worker.log`; the corrected value
   in AN.

### Retry diagnostics

```sh
$PHP scripts/queue-worker.php --status                 # pending, due, processing, failed, oldest age
grep -E 'queue_job_(retry_scheduled|failed|recovered|corrupt)|enrichment_failed' "$APP/var/logs/queue-worker.log" | tail
ls -l "$APP/var/queue/failed/"; cat "$APP/var/queue/failed/JOB_ID.json"   # last_error, attempts, remaining person_ids
$PHP scripts/queue-worker.php --requeue-failed         # after fixing the cause
```

Repeated `worker_already_running` means a run is stuck: check
`ps -u "$(id -un)" -f | grep queue-worker`. `processing/` should be empty
between runs; leftovers are recovered automatically by the next run.

### Rollback (keeps pending jobs)

`var/` is not tracked by git, so changing code never touches the queue.

1. If possible, drain first: `$PHP scripts/queue-worker.php --max-jobs=1000`
   until `--status` shows `pending: 0`. Then copy the queue:
   `cp -pR "$APP/var/queue" "$BACKUP/queue-at-rollback"`.
2. Remove the worker cron entry (the old code has no worker script).
3. Return to the previous commit without discarding anything:
   ```sh
   cd "$APP"
   git switch -c "rollback-$STAMP" "$(cat "$BACKUP/previous-commit.txt")"
   git stash apply "stash@{0}"   # re-applies the server's dotenv edit (check `git stash list` for the right entry)
   $PHP -l src/bootstrap.php
   ```
   The old synchronous webhook is then live again (and slower than AN's test).
4. Any jobs left in `var/queue/pending` stay on disk untouched; the twice-daily
   sweep still reconciles those people. Redeploying the queue code and its cron
   later processes them.

## Troubleshooting

- **401 / 503 before processing:** check webhook token, secret length, and that
  the web process can read the root `.env` (or receives the environment). Look
  for `env_file_error` / `env_file_lines_ignored` in the PHP error log. Never
  weaken authentication to debug.
- **503 `Webhook could not be queued`:** `webhook_queue_error` in the PHP error
  log; check `QUEUE_DIRECTORY` ownership/permissions/free space for the web user.
- **Accepted but AN not updated:** `php scripts/queue-worker.php --status`; is
  cron running the worker (check its log)? Read `last_error` in the job JSON
  under `pending/` or `failed/`; check `queue_job_retry_scheduled` /
  `queue_job_failed` / `enrichment_failed` log lines for that job or person.
- **AN HTTP 401/403:** check group token/API permissions and configured base URL.
- **400 payload identity:** inspect a redacted sample; provide the AN identifier
  or canonical person link, not an email, action ID, or arbitrary URL.
- **`invalid_postcode`:** check primary address or `AN_POSTCODE_FIELD` mapping;
  correct data in AN. No constituency is cleared on invalid input.
- **`no_match`:** Parliament returned no usable current result. Check postcode
  existence; unsupported/non-parliamentary postcodes may never match.
- **429/5xx/timeouts:** inspect sanitized server logs, retry with cron, and check
  connectivity/rate limits. Repeated throttling may require a slower scheduled
  run for a large group. Queue jobs that hit throttling retry on their backoff
  schedule.
- **Lock failure:** verify directory ownership and consistent configuration for
  cron and PHP. A running process releases locks on exit; files are not stale
  work queues and should not be removed during processing.

API references:
[AN people and PUT updates](https://actionnetwork.org/docs/v2/people),
[AN webhooks](https://actionnetwork.org/docs/webhooks),
[Parliament Members API](https://members-api.parliament.uk/index.html).
