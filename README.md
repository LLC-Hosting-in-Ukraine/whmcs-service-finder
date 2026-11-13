# Service Finder for WHMCS

One search box for the WHMCS admin area that looks at **every field of a service at once**. [Українською](README.uk.md)

WHMCS's own *Services* list can filter by domain, product, status and server, but not by IP, panel login, notes or a client's phone. In real installs the same IP lives in different fields depending on the product (the *Domain* field for some modules, *Dedicated IP* for others, *Assigned IPs*, a custom field, an add-on note). Service Finder searches all of them together.

- Free-text search over: domain, dedicated IP, assigned IPs, username, admin notes, subscription ID, suspend reason, **product custom fields**, **add-ons**, client name / company / e-mail / phone, product, group, server name / hostname / IP
- Several words = all must match (any field each); `"quoted phrase"` for exact phrases
- Operators: `ip:` `status:` `server:` `product:` `client:` `user:` `id:` `old:N` (next due date was N or more days ago)
- Filters: status, product group, server
- Shows why a row matched, days overdue, the suspend reason, and links to the service and the client
- Also lists matching domain registrations and clients
- **Read-only**: it never writes to WHMCS tables
- Optional Elasticsearch / OpenSearch backend (off by default, falls back to SQL on any error)
- English and Ukrainian UI (add a language by copying `lang/english.php`)

## Install

1. Copy `modules/addons/servicefinder` into your WHMCS root (`<whmcs>/modules/addons/servicefinder`).
2. Admin → **System Settings → Addon Modules → Service Finder → Activate**, then **Configure** and give the admin roles that should use it access.
3. Open **Addons → Service Finder**.

Tested on WHMCS 9.0 with PHP 8.3 and MariaDB. It uses only the standard addon API and the bundled Eloquent/Capsule, so older 8.x releases should work too, but they have not been tested.

## Search examples

```
198.51.100.66                    # any service whose domain, IP, assigned IPs or notes contain it
203.0.113                         # IP prefix
ftpuser1                        # panel login
john smith                      # client name (both words), across all of the client's services
status:suspended old:60         # suspended, due date 60+ days ago
server:vm6 product:"FTP storage"
user:ftp +380501112233
```

`%` and `_` are matched literally; user input is always escaped and parameterised.

## Elasticsearch (optional)

Not needed for a few thousand services (plain SQL is instant). If you want typo-tolerant, ranked search across much larger data:

1. Run Elasticsearch/OpenSearch **somewhere other than the billing host**.
2. In the addon settings choose *Elasticsearch / OpenSearch* and fill the URL (and user/password if needed).
3. The index (`whmcs_services` by default) is rebuilt nightly by the `DailyCronJob` hook. Search returns service IDs and the table is always loaded from WHMCS, so the data you see is current. Queries with operators use SQL.

The Elastic backend is covered by tests with a stubbed transport (document building, bulk reindex, query body, escaping) but **has not been run against a live cluster yet** — treat it as experimental.

## Tests

On the WHMCS host:

```
php tests/run.php /path/to/whmcs
```

The tests create **temporary tables** with the WHMCS table names (visible only to the test's DB session), so no real data is read or changed. 80 checks: query parsing, every search path, operators, filters, SQL-injection and wildcard handling, the Elastic document/query logic, translations and a smoke test of the page.

## Security

Anyone who can open the addon sees client names, e-mails and phone numbers. Limit it to the admin roles that already have access to client data (Addon Modules → Configure → Access Control).

## License

MIT, see [LICENSE](LICENSE).
