# Tina4 PHP — Test Coverage Plan

Version: 3.10.37 | Last updated: 2026-03-31

## Summary

- **Test files**: 54
- **Test methods**: 1,551
- **Test runner**: PHPUnit
- **Run all**: `vendor/bin/phpunit tests/`
- **Run one**: `vendor/bin/phpunit tests/FrondTest.php --filter test_method_name`

## Test Inventory

| # | Test File | Tests | Feature | Status |
|---|-----------|-------|---------|--------|
| 1 | AITest.php | 34 | AI assistant detection | Done |
| 2 | AuthV3Test.php | 33 | Auth (JWT, API key, session) | Done |
| 3 | AutoCrudV3Test.php | 13 | AutoCrud REST generation | Done |
| 4 | ContainerTest.php | 10 | DI Container | Done |
| 5 | CorsTest.php | 15 | CORS middleware | Done |
| 6 | CsrfMiddlewareTest.php | 12 | CSRF middleware | Done |
| 7 | DatabaseDriversTest.php | 31 | Multi-driver database adapters | Done |
| 8 | DatabaseResultTest.php | 28 | DatabaseResult iteration/access | Done |
| 9 | DatabaseSessionHandlerTest.php | 17 | Database session handler | Done |
| 10 | DatabaseTest.php | 30 | Core Database class | Done |
| 11 | DatabaseUrlTest.php | 42 | DSN/URL parsing | Done |
| 12 | DevAdminTest.php | 38 | Dev admin panel | Done |
| 13 | DotEnvTest.php | 18 | .env parser | Done |
| 14 | ErrorOverlayTest.php | 20 | Error overlay | Done |
| 15 | EventsTest.php | 21 | Events system | Done |
| 16 | FormTokenTest.php | 11 | Form token protection | Done |
| 17 | FrondTest.php | 187 | Template engine | Done |
| 18 | GraphQLV3Test.php | 27 | GraphQL | Done |
| 19 | HealthTest.php | 13 | Health check endpoint | Done |
| 20 | HtmlElementTest.php | 19 | HTML element builder | Done |
| 21 | I18nV3Test.php | 15 | Internationalization | Done |
| 22 | LogTest.php | 14 | Logging | Done |
| 23 | MCPTest.php | 38 | MCP server | Done |
| 24 | MessengerTest.php | 37 | Email (SMTP/IMAP) | Done |
| 25 | MiddlewareTest.php | 19 | Middleware pipeline | Done |
| 26 | MigrationV3Test.php | 17 | Database migrations | Done |
| 27 | ORMV3Test.php | 31 | ORM | Done |
| 28 | PortConfigTest.php | 11 | Port configuration | Done |
| 29 | PostProtectionTest.php | 15 | POST protection | Done |
| 30 | QueryBuilderTest.php | 54 | Query builder | Done |
| 31 | QueueBackendTest.php | 19 | Queue backends | Done |
| 32 | QueueV3Test.php | 31 | Queue/Job system | Done |
| 33 | RateLimiterTest.php | 11 | Rate limiter middleware | Done |
| 34 | RequestV3Test.php | 20 | Request parsing | Done |
| 35 | ResponseCacheTest.php | 23 | Response cache middleware | Done |
| 36 | ResponseV3Test.php | 53 | Response builder | Done |
| 37 | RouteDiscoveryV3Test.php | 16 | Route discovery | Done |
| 38 | RouterV3Test.php | 32 | Router | Done |
| 39 | SQLite3AdapterTest.php | 21 | SQLite3 adapter | Done |
| 40 | ScssV3Test.php | 19 | SCSS compiler | Done |
| 41 | SecureByDefaultTest.php | 6 | Secure defaults | Done |
| 42 | SeederV3Test.php | 29 | FakeData / Seeder | Done |
| 43 | ServiceRunnerV3Test.php | 27 | Service runner | Done |
| 44 | SessionHandlerTest.php | 25 | Session handlers | Done |
| 45 | SessionV3Test.php | 44 | Session management | Done |
| 46 | SmokeTest.php | 80 | End-to-end smoke tests | Done |
| 47 | SqlTranslationTest.php | 41 | SQL translation | Done |
| 48 | SwaggerTest.php | 47 | Swagger/OpenAPI generation | Done |
| 49 | TemplateMethodTest.php | 5 | Template method calls | Done |
| 50 | TestingTest.php | 0 | Testing class | Not started |
| 51 | WebSocketTest.php | 35 | WebSocket | Done |
| 52 | WebSocketV3Test.php | 64 | WebSocket (v3 extended) | Done |
| 53 | WsdlTest.php | 38 | WSDL/SOAP | Done |
| 54 | test_v3_smoke.php | 0 | Smoke test script (non-PHPUnit) | Not started |

## Missing Test Coverage

| Feature | Has Test? | Recommended | Priority |
|---------|-----------|-------------|----------|
| App.php (bootstrap) | No | 10+ tests | Medium |
| StaticFiles.php | No | 10+ tests | Medium |
| Api.php (HTTP client) | No | 15+ tests | High |
| Validator.php | No | 15+ tests | High |
| DevMailbox.php | No | 10+ tests | Medium |
| Server.php | No | 5+ tests | Low |
| RequestLogger.php | No | 5+ tests | Low |
| SecurityHeaders.php | No | 5+ tests | Low |
| MongoSessionHandler.php | No | 10+ tests | Medium |
| ValkeySessionHandler.php | No | 10+ tests | Medium |
| CachedDatabase.php | No | 10+ tests | Medium |
| Metrics (code analysis) | No | N/A | Blocked — not implemented |
| TestingTest.php | Empty (0 tests) | 10+ tests | Medium |

## Summary

- **54 test files**, **1,551 test methods**
- **~22 modules** lack dedicated tests (most are trivial, interfaces, or require external services)
- Largest test: FrondTest.php (187 methods)
- Key gaps: Api client, Validator, DevMailbox need dedicated test files
