# Tina4 v3.0 — Development → Staging → Production Workflow

## The Golden Path

```
LOCAL DEV ──→ STAGING ──→ PRODUCTION
   ↑              │
   └──── fix ─────┘
```

Every code change follows this path. No exceptions. No shortcuts to production.

## 1. Local Development

### First Time Setup
```bash
# Clone and go
git clone git@github.com:org/myapp.git
cd myapp
tina4py init                          # Creates missing folders, installs deps
cp .env.example .env                  # Configure local environment
tina4py serve                         # Running on http://localhost:7145
```

That's it. SQLite by default — no Docker, no database server, no configuration.

### Daily Development Loop
```bash
tina4py serve                         # Start dev server with hot reload
```

**What happens automatically:**
- File watcher detects changes → restart
- Debug overlay injected into HTML pages
- Stack traces shown in browser on errors
- SQL queries logged to `logs/query.log`
- frond.js served unminified for debugging
- Swagger UI at `/swagger`
- Console at `/tina4/console`

### Writing Code

```
1. Create/edit route          → src/routes/api/feature/get.py
2. Create/edit model          → src/orm/Feature.py
3. Create migration           → tina4py migrate:create "add features table"
4. Edit migration SQL         → src/migrations/20260319_add_features_table.sql
5. Run migration              → tina4py migrate
6. Seed test data             → tina4py seed
7. Test in browser/Postman    → http://localhost:7145/api/feature
8. Write tests                → tests/test_feature.py
9. Run tests                  → tina4py test
```

### Branching Strategy
```
main ─────────────────────────────────────→ (production releases)
  │
  ├── develop ────────────────────────────→ (staging deploys)
  │     │
  │     ├── feature/user-auth ──→ PR → develop
  │     ├── feature/queue-worker ──→ PR → develop
  │     └── fix/login-bug ──→ PR → develop
  │
  └── hotfix/critical-fix ──→ PR → main + develop
```

### Pre-Commit Checklist (automated)
```bash
tina4py test                          # All tests pass
tina4py routes                        # Verify route list looks right
tina4py migrate --dry-run             # Check pending migrations
```

## 2. Staging

### Purpose
Staging is production's mirror. Same database engine, same OS, same config — just different data and URL. If it works on staging, it works in production.

### Deploy to Staging
```bash
# One command
tina4py stage

# Or with watch mode for rapid iteration
tina4py stage --watch
```

**What `tina4py stage` does:**
```
Step 1: Run tests locally                                    ~10s
Step 2: Build Docker image (cached layers)                    ~5s
Step 3: Push changed layer to registry                        ~3s
Step 4: Deploy to staging (kubectl rollout or compose up)    ~10s
Step 5: Wait for health check                                 ~5s
Step 6: Print staging URL                                     ~0s
────────────────────────────────────────────────────────────
Total:                                                       ~33s
```

### Staging Environment
```env
TINA4_ENV=staging
DATABASE_URL=postgresql://user:pass@staging-db:5432/myapp_staging
TINA4_DEBUG=false                     # No debug overlay
TINA4_CONSOLE=true                    # Console available for inspection
TINA4_CONSOLE_TOKEN=staging-secret
TINA4_LOG_LEVEL=debug                 # Verbose logging for troubleshooting
```

### Staging Checklist
```
□ All API endpoints return expected responses
□ Frond templates render correctly
□ Migrations ran without errors
□ WebSocket connections work
□ Queue workers process messages
□ Auth flow works (login, token, secure routes)
□ Email sending works (use staging SMTP / mailpit)
□ Performance acceptable (no obvious regressions)
□ No .broken files generated
□ Health check returns 200
□ Console shows clean state
```

### Staging Testing
```bash
# Run integration tests against staging
tina4py test --target https://staging.myapp.com

# Run smoke tests (quick sanity check)
tina4py test --smoke --target https://staging.myapp.com

# Check health
curl https://staging.myapp.com/health
```

### Fix Loop
```
Found a bug on staging?
  ↓
Fix locally (tina4py serve, debug, fix)
  ↓
tina4py stage                         # Redeploy in ~30 seconds
  ↓
Verify fix on staging
  ↓
Repeat until clean
```

## 3. Production

### Deploy to Production
```bash
# Promote the exact staging image (no rebuild)
tina4py deploy promote staging production

# What this does:
# 1. Gets the Docker image digest running in staging
# 2. Tags it for production
# 3. Deploys to production namespace
# 4. Waits for healthy rollout
# 5. Reports status
```

**Why promote, not rebuild:**
- The image running on staging IS the tested image
- Rebuilding could produce a different binary (dependency updates, build variance)
- Promoting guarantees staging = production, byte for byte

### Production Environment
```env
TINA4_ENV=production
DATABASE_URL=postgresql://user:pass@prod-db:5432/myapp
TINA4_DEBUG=false                     # Never
TINA4_CONSOLE=false                   # Disabled in production (or behind VPN)
TINA4_COMPRESS=true                   # gzip everything
TINA4_MINIFY_HTML=true                # Minify HTML
TINA4_LOG_LEVEL=info                  # Info and above
TINA4_LOG_ROTATE=daily
TINA4_LOG_RETAIN=90                   # 90 days retention
TINA4_BROKEN_THRESHOLD=1              # Any .broken = unhealthy
```

### Production Safeguards
- **Zero-downtime deploys** — rolling update, `maxUnavailable: 0`
- **Health check gating** — new pods must pass `/health` before receiving traffic
- **.broken monitoring** — any 500 error creates .broken file → health check fails → alerts fire
- **Auto-rollback** — if new pods fail health check, K8s rolls back automatically
- **Rate limiting** — enabled by default
- **CORS** — locked to configured origins

### Production Monitoring
```bash
# Check status
tina4py deploy status

# Tail logs
tina4py deploy logs
tina4py deploy logs --errors           # Errors only

# Check health
tina4py deploy health

# View .broken files
tina4py deploy errors
```

### Rollback
```bash
# Something went wrong? Roll back to previous image
tina4py deploy rollback

# Roll back to specific version
tina4py deploy rollback --to v3.1.0
```

### Hotfix Flow
```
Production bug detected
  ↓
git checkout -b hotfix/critical-fix main
  ↓
Fix locally (tina4py serve)
  ↓
tina4py test
  ↓
tina4py stage                         # Deploy fix to staging
  ↓
Verify on staging
  ↓
tina4py deploy promote staging production
  ↓
Merge hotfix → main + develop
```

## Environment Parity Matrix

| Aspect | Local Dev | Staging | Production |
|--------|----------|---------|------------|
| **Database** | SQLite (default) | PostgreSQL/MySQL | PostgreSQL/MySQL |
| **Server** | Built-in dev server | Docker + prod server | Docker + prod server |
| **Debug overlay** | Yes | No | No |
| **Console** | Yes (auto) | Yes (token) | No (or VPN) |
| **Hot reload** | Yes | No | No |
| **Compression** | Optional | Yes | Yes |
| **HTML minify** | No | Yes | Yes |
| **Logging** | Human-readable | JSON (debug level) | JSON (info level) |
| **Log rotation** | No (small files) | Daily | Daily, 90 day retain |
| **.broken files** | Shown in console | Shown in console | Alert + auto-rollback |
| **CORS** | `*` (allow all) | Configured origins | Configured origins |
| **Rate limiting** | Disabled | Enabled (lenient) | Enabled (strict) |
| **Swagger UI** | Yes | Yes | Disabled (or auth-gated) |
| **SSL/TLS** | No (http) | Yes (https) | Yes (https) |
| **Workers** | 1 | 2-4 | 4+ (auto-scaled) |
| **Replicas** | 1 | 1-2 | 2-20 (HPA) |

## CLI Command Summary

| Phase | Command | What It Does |
|-------|---------|-------------|
| **Dev** | `tina4py serve` | Start dev server with hot reload |
| **Dev** | `tina4py test` | Run test suite |
| **Dev** | `tina4py migrate` | Run migrations |
| **Dev** | `tina4py seed` | Seed database |
| **Staging** | `tina4py stage` | Build → push → deploy to staging (~30s) |
| **Staging** | `tina4py stage --watch` | Auto-deploy on file change |
| **Staging** | `tina4py test --target URL` | Run tests against staging |
| **Production** | `tina4py deploy promote staging production` | Promote staging image to prod |
| **Production** | `tina4py deploy status` | Show running pods/containers |
| **Production** | `tina4py deploy logs` | Tail production logs |
| **Production** | `tina4py deploy rollback` | Roll back to previous image |
| **Production** | `tina4py deploy health` | Check health status |
| **Production** | `tina4py deploy errors` | View .broken files |

## The Rules

1. **Never deploy to production directly** — always promote from staging
2. **Never skip staging** — even for "small" changes
3. **Never rebuild for production** — promote the tested image
4. **Fix forward when possible** — rollback is for emergencies
5. **If staging is broken, stop and fix** — don't stack changes on top of broken
6. **Automate everything** — if you do it twice, script it
7. **Health checks are sacred** — if health fails, nothing deploys
