
# Action Network constituency enrichment

Vanilla PHP service that enriches Action Network (AN) people with their current UK
parliamentary constituency. Webhooks provide the fast path; a **twice-daily full
sweep** repairs missed webhooks, failed writes, and changed postcodes.

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
3. **Export the variables into the process environment.** There is no automatic
   dotenv loader. For a local shell, the template is shell-compatible:
   ```sh
   set -a
   . /absolute/path/to/GO-constituency_lookup/.env
   set +a
   php /absolute/path/to/GO-constituency_lookup/scripts/sweeper.php --dry-run
   ```
   Quote shell-special characters in values; source only a trusted, locally
   managed file. For production, use your deployment's secret/environment
   mechanism. PHP-FPM commonly clears environment variables: explicitly pass
   them in its pool configuration. Cron needs the same configuration.
4. Serve **only `public/`** through HTTPS, with PHP execution enabled.
   Never expose the repository root, `.env`, `src/`, `scripts/`, or lock files.
   Disable PHP `display_errors`; send error logs to your monitoring system.
5. Set `LOCK_DIRECTORY` to a writable private absolute directory shared by
   webhook and cron. Use the same service user for both. Defaults to `var/locks`
   in the repository; created with directory mode 0700 and file mode 0600.
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
| `WEBHOOK_SECRET` | Required for webhook; at least 32 characters. Cron does not require it. |
| `LOCK_DIRECTORY` | Repository `var/locks`; private absolute directory outside the public root. |
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
happens before parsing or API requests.

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

Responses: 200 with `updated`, `unchanged`, `invalid_postcode`, or `no_match`;
400 malformed JSON or unsupported identity; 401 bad token; 405 wrong method;
413 oversized body; 415 wrong content type; 503 configuration/API/lock failure.
Batch responses contain outcome counters; one failed person does not prevent
attempting other people, but the entire request returns 503 so it can be retried.
Replayed successful items skip writes. Invalid identities reject the payload
before any enrichment. 503 signals retry where the sender supports it; cron repairs failures regardless
of the sender's retry policy. Invalid/no-match results leave any existing value
untouched and are rechecked by cron.

## Processing and recovery

Postcodes are uppercased, whitespace-normalized, and checked for UK syntax.
Syntax validation does not prove that a postcode exists. Parliament's constituency
search receives `searchText`, `skip=0`, and `take=20`; only a single constituency
with `endDate: null` is accepted. Historical, ambiguous, or truncated results
are never guessed.

AN person updates use **PUT**, not PATCH, with only the destination
`custom_fields` entry. Other fields are not sent or replaced. An exact existing
match skips the write, including when the enrichment write triggers another
webhook. Each process caches normalized postcode results and no-matches in
memory; transient lookup failures are not cached.

Network failures, 429, and 5xx receive bounded exponential retries (1s, 2s by
default). `Retry-After` is respected up to a 5-second delay cap; persistent
throttling is left for the next sweep. Other non-2xx responses fail immediately.
The endpoint is synchronous: allow sufficient PHP/proxy execution time for
up to three API operations and their retries. If your webhook sender times out
sooner, cron still repairs the record. There is no durable webhook queue.

**No database or per-person status custom fields are required.** AN is the
durable source of truth: every cron run follows all `osdi:people` pages visible
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

Filesystem locks prevent overlapping sweeps and serialize a person's processing
between webhook and cron on **one host/shared locking filesystem**. Keep lock
files; deleting a locked file defeats locking. Multi-host deployments need a
reliable shared lock mechanism or a single service host. Locks cannot prevent
external AN edits during a lookup; the next webhook/sweep repairs those races.

## Twice-daily cron

Example at midnight and noon **in the cron host's timezone**. Use absolute paths,
the same service user as PHP-FPM, and a pre-created private log directory:

```cron
0 0,12 * * * /bin/sh -c 'set -a; . /opt/constituency-lookup/.env; set +a; exec /usr/bin/php /opt/constituency-lookup/scripts/sweeper.php' >> /var/log/constituency-lookup/sweeper.log 2>&1
```

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
results, idempotence, dry-run, recovery, pagination safety, and locks.

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

This authenticated sample **writes to AN** when the field differs. Send it twice:
the second request should return `unchanged`. Use cron `--dry-run` first for
read-only verification. The development server's HTTP address is for localhost
only; deploy the real webhook with HTTPS.

## Troubleshooting

- **401 / 503 before processing:** check webhook token, secret length, and that
  PHP-FPM receives the environment. Never weaken authentication to debug.
- **AN HTTP 401/403:** check group token/API permissions and configured base URL.
- **400 payload identity:** inspect a redacted sample; provide the AN identifier
  or canonical person link, not an email, action ID, or arbitrary URL.
- **`invalid_postcode`:** check primary address or `AN_POSTCODE_FIELD` mapping;
  correct data in AN. No constituency is cleared on invalid input.
- **`no_match`:** Parliament returned no usable current result. Check postcode
  existence; unsupported/non-parliamentary postcodes may never match.
- **429/5xx/timeouts:** inspect sanitized server logs, retry with cron, and check
  connectivity/rate limits. Repeated throttling may require a slower scheduled
  run or a queued implementation for a large group.
- **Lock failure:** verify directory ownership and consistent configuration for
  cron and PHP. A running process releases locks on exit; files are not stale
  work queues and should not be removed during processing.

API references:
[AN people and PUT updates](https://actionnetwork.org/docs/v2/people),
[AN webhooks](https://actionnetwork.org/docs/webhooks),
[Parliament Members API](https://members-api.parliament.uk/index.html).
