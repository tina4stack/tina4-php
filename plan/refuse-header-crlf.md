# Task: Refuse CR/LF/NUL in response headers; ADR-0068 request framing (PHP)

**Outcome:** a response header name or value, a redirect location, a content
type, a download name, or a cookie name/value/attribute carrying CR, LF or NUL
(and `;` for cookies) is refused at the call site with `InvalidArgumentException`
and the exact ADR-0068 message; header and cookie names must be RFC 9110 tokens.
`Tina4\Server` refuses to write an unsafe header (500 `{"error":"Invalid response header"}`),
frames requests per ADR-0068 section 3 (431 / 400 / 413 / 408, chunked decoding) and
answers every transport rejection with the section 4 shape.

Governing decision: `tina4-documentation/plan/v3/decisions/ADR-0068.md`
Fixture: `tina4-documentation/plan/v3/fixtures/http_hardening_contract.json`

## Scope
- [x] Read ADR-0068 + fixture; wording, env vars and status codes taken verbatim
- [x] Real tests first, confirmed red on v3 HEAD 94e493ca (43 of 45 contract cases red)
- [x] `Response::header()` / `add_header()` / `withHeaders()` validate name + value
- [x] `Response::redirect()` validates the location before touching the status
- [x] `Response::cookie()` validates the name, value and every attribute
- [x] Content-type arguments (`__invoke`, `send`, `stream`, `file`) and the download name
- [x] `Server` refuses to write an unsafe header (defence in depth), logs it by name
- [x] Request framing: 431 on the head (complete or not), 400 malformed head / Content-Length / Transfer-Encoding, 413 declared + chunked running count, chunked decoding, 408
- [x] Transport rejection shape: JSON body, Content-Type, Content-Length, Connection: close, security headers, no HSTS; half-close + 2s drain
- [x] Contract runner `tests/HttpHardeningContractTest.php`
- [x] Mutation proofs (call-site, writer, declared-length, reaper double close)
- [x] Full suite on the lab (Linux, PHP 8.3, TINA4_REQUIRE_SERVICES=1): 5779 tests at fdca0975, 0 failed, 41 skipped - 37 live graph-DB cases (Ultipa/Neo4j/Memgraph/Arango not provisioned on the lab) + 4 Swoole cases that skip in the main pass by design and pass 4/4 in the openswoole pass

## Parity
| ADR-0068 part            | Python | PHP | Ruby | Node |
|--------------------------|--------|-----|------|------|
| 1 call-site refusal      | py worker | ✅ | ❌ owed | ✅ (test/refuse-header-crlf) |
| 2 writer refusal         | py worker | ✅ | ❌ owed | ✅ native (500 is the error page, not the JSON body) |
| 3 limits before read     | py worker | ✅ | ❌ owed | ⚠️ 413 ok; 431/408 env vars not read; RSS grows after 413 |
| 4 rejection shape        | py worker | ✅ | ❌ owed | ❌ owed |

## Tests (real - no mocks, positive + negative)
- [x] 45 contract cases in HttpHardeningContractTest (response object + real `tina4 serve` over raw sockets, RSS from ps)
- [x] ServerRequestLimitsTest: the server survives timing out a stalled request

## Bugs
- [x] Response header()/redirect()/cookie() accepted CR/LF/NUL; Server wrote header lines verbatim
- [x] Server closed a rejected/timed-out socket twice: PHP 8 TypeError stopped `tina4 serve` after one 413, 431 or 408
- [x] A complete head past TINA4_MAX_REQUEST_HEADER was served (only an unterminated one was capped)
- [x] Non-numeric Content-Length read as 0; Transfer-Encoding: chunked ignored (body empty, chunk bytes parsed as the next request)
- [x] Content-Length matched anywhere in the head (`X-Content-Length:` counted)
- [x] An agreeing pair of Content-Length headers was accepted (ruling: 400 in all four)
- [x] Firebird migration test depended on fewer than 100 user tables in the shared lab DB

## Commits
- 3fd8b023  fix(server): refuse CR/LF in response headers; ADR-0068 request framing
- abf2404d  fix(server): refuse a second Content-Length even when it agrees (maintainer ruling)
- ba443a58  Merge origin/v3
- fdca0975  test(migration): look the Firebird quoted table up by name, not by listing

## Status: Complete (PR open; fixture suites entry for PHP handed to the ADR-0068 worker)
