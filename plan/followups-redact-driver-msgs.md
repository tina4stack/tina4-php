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
- [x] Item 6: WSDL refuses BOM / non-UTF-8 / NUL / non-UTF-8-declared bodies with the Malformed XML Client fault, before any parse
- [x] Item 6 (found): operation + params matched by local name in any namespace (Python parity)
- [x] Item 7: SOAP parity table (10 payloads) - matches Python after the fixes
- [x] Item 8: ADR-0071 SMTP transport table (ssl = implicit TLS any port; 465 always implicit; tls/starttls STARTTLS required before AUTH; none never upgrades)
- [x] Item 8: unknown SMTP / IMAP encryption raises at construction (exact ADR messages)
- [x] Item 8: SMTP certificates verified with host name (verify_peer + verify_peer_name + peer_name); no opt-out
- [x] Item 8: IMAP starttls -> /imap/tls-sslv23 (c-client /tls is TLSv1-only and fails on modern servers); none -> /imap/notls; ssl accepted as implicit TLS
- [x] Item 8: example/.env.example drops TINA4_MAIL_TLS_INSECURE and states the ADR-0071 semantics
- [x] Extra (a): DB connect errors + log with passwords containing ':' / '@' and ODBC PWD= - no leak in PHP (one primitive, userinfo to the LAST '@'); pinned by a real connect-failure test, mutation-proved
- [x] Extra (b): SMTP and IMAP unknown / empty-after-trim values raise with the value as given (tests pin '', '   ')
- [x] Extra (c): no fake backplane stand-in in PHP tests (RedisBackplane is always real); WebSocketHardeningTest's Redis gate now FAILS under TINA4_REQUIRE_SERVICES instead of skipping
- [x] Extra (d): Kafka push to a closed port raises (reproduced for real); found + fixed: an explicit `brokers` was overwritten by TINA4_KAFKA_BROKERS / TINA4_QUEUE_URL (ADR-0041), and an amqp:// TINA4_QUEUE_URL became the kafka broker list
- [ ] OWED: NATS backplane - no NATS server on the lab, so NATSBackplane has no live test (needs basis-company/nats + a nats container)
- [ ] OWED: RedisBackplane ext-redis path (AUTH/SELECT via phpredis) - the lab PHP has no ext-redis, only the raw RESP path ran live

## Parity
| Item | PHP before | PHP after |
|------|-----------|-----------|
| 1 backplane AUTH | ❌ never AUTHs | ✅ |
| 1 URL redaction sweep | ⚠️ Mqtt/Api leak | ✅ |
| 2 driver messages | ⚠️ S3 no command; db-cache swallowed | ✅ |
| 6 WSDL UTF-16 / UTF-7 | ❌ entity expanded | ✅ |
| 7 SOAP parity | ❌ 5/10 differ (namespace) | ✅ 10/10 |
| 8 ADR-0071 | ❌ ssl clear off 465; tls opportunistic; certs unverified; typos = clear | ✅ |

## Tests (written first, real - no mocks, positive + negative)
- [x] WebSocketBackplaneAuthTest (real password Redis, real child-process log output)
- [x] ConnectionUrlRedactionSweepTest (Mqtt pure logic; Api in a child with no https wrapper)
- [x] WsdlEncodingSecurityTest (real UTF-16 BOM payload, UTF-16 no BOM, UTF-7, UTF-8 BOM, invalid UTF-8; namespace resolution)
- [x] MessengerTlsTransportTest (lab GreenMail / Mailpit / Dovecot TLS servers; trusted vs untrusted child processes)
- [x] DriverMissingMessageTest (child processes: composer autoloader without aws; `php -n` without pgsql; negative control with pgsql loaded + unreachable server)

## Bugs
- [x] RedisBackplane never sent AUTH/SELECT: against requirepass Redis it degraded to local-only
- [x] Mqtt::parseUrl error messages echoed the raw URL with its password
- [x] Api HTTPS-unavailable error echoed the requested URL with its userinfo password
- [x] Database::makePostgres/makeSqlite referenced \PDO unguarded: without ext-pdo a fatal Error replaced the install message

- [x] WSDL: UTF-16 (BOM or not) and UTF-7 bodies hid the DOCTYPE from the byte regex; libxml expanded the entity
- [x] WSDL: an operation in a client namespace (not urn:<ServiceName>) got "Empty SOAP Body"
- [x] IMAP STARTTLS (/imap/tls) never worked against a modern server: c-client negotiates TLSv1 only
- [x] IMAP 'none' (/imap) silently upgraded with STARTTLS whenever the server offered it

## Commits
- a90e456d  fix(backplane): AUTH + SELECT against a password Redis; redact URLs in Mqtt and Api errors
- c9bf70ca  fix(drivers): S3 and database-cache messages name the package and its install command
- c9047b5b  fix(wsdl): refuse non-UTF-8 SOAP bodies before parsing; match operations by local name
- ec3f510d  test(drivers): a missing-extension error may be a RuntimeException subclass
- a855d443  fix(wsdl): accept only the exact encoding name UTF-8 in the XML declaration
- 25eac4c7  fix(messenger): ADR-0071 mail encryption

## Status: Complete (NATS + ext-redis backplane paths owed, see Scope)
