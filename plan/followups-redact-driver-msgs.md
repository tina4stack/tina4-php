# Task: Follow-ups - redaction, driver messages, WSDL UTF-16, SOAP parity, mail encryption (PHP)

**Outcome:** no connection password reaches a PHP log line or exception; the Redis backplane works
against a password Redis; driver-missing messages name the package and the install command; WSDL
refuses a UTF-16/BOM body before parsing; the Messenger implements ADR-0071 sections 1-3.

## Scope
- [x] Item 1: RedisBackplane sends AUTH (password or ACL user+password) and SELECT db, raw + ext-redis paths
- [x] Item 1: backplane errors name host:port only; "active" log line carries no URL (confirmed, pinned)
- [x] Item 1 sweep: Mqtt::parseUrl errors and Api HTTPS-unavailable errors redact the URL
- [x] Item 2: S3Storage missing-SDK message names package + `composer require aws/aws-sdk-php`
- [x] Item 2: Mongo/Redis/Valkey/Memcached caches are zero-dep wire clients (no driver case); the database cache backend CAN miss a driver - its fallback warning now appends the driver + install command (DatabaseDriverMissing)
- [ ] Item 6: WSDL refuses non-UTF-8 / BOM bodies with the Malformed XML Client fault, before any parse
- [ ] Item 7: SOAP parity table (10 payloads)
- [ ] Item 8: ADR-0071 SMTP transport table, STARTTLS required, unknown value raises, certs verified (SMTP + IMAP)

## Parity
| Item | PHP before | PHP after |
|------|-----------|-----------|
| 1 backplane AUTH | ❌ never AUTHs | ✅ |
| 1 URL redaction sweep | ⚠️ Mqtt/Api leak | ✅ |
| 2 driver messages | ⚠️ S3 no command; db-cache swallowed | ✅ |
| 6 WSDL UTF-16 | ❌ | |
| 8 ADR-0071 | ❌ | |

## Tests (written first, real - no mocks, positive + negative)
- [x] WebSocketBackplaneAuthTest (real password Redis, real child-process log output)
- [x] ConnectionUrlRedactionSweepTest (Mqtt pure logic; Api in a child with no https wrapper)
- [x] DriverMissingMessageTest (child processes: composer autoloader without aws; `php -n` without pgsql; negative control with pgsql loaded + unreachable server)

## Bugs
- [x] RedisBackplane never sent AUTH/SELECT: against requirepass Redis it degraded to local-only
- [x] Mqtt::parseUrl error messages echoed the raw URL with its password
- [x] Api HTTPS-unavailable error echoed the requested URL with its userinfo password
- [x] Database::makePostgres/makeSqlite referenced \PDO unguarded: without ext-pdo a fatal Error replaced the install message

## Commits
- (filled per commit)

## Status: In Progress
