# Tina4 v3.0 — Examples & Documentation Requirements

## README Requirements
Each repo's README must contain a **full language feature reference** with short, concise examples. Not a tutorial — a reference. Every feature, one example each, copy-pasteable.

The README structure:
1. Quick Start (5 lines to get running)
2. Routing (every method, params, middleware, auth)
3. ORM (define, CRUD, relationships, soft delete, scopes, pagination)
4. Database (connect, query, transactions)
5. Frond Templates (variables, filters, control flow, inheritance, macros)
6. Auth / JWT (generate, validate, secure routes)
7. Sessions (configure, get/set)
8. Migrations (create, run, rollback)
9. Seeders (create, run)
10. Queue (push, pop, complete, fail)
11. GraphQL (schema, queries, mutations)
12. WebSocket (connect, send, receive)
13. WSDL/SOAP (define service, generate WSDL)
14. API Client (GET, POST, auth, headers)
15. Localization (configure, translate)
16. Email (send, templates, attachments)
17. SCSS (compile, variables, nesting)
18. CLI Commands (all commands with flags)
19. Configuration (all env vars)
20. Testing (write tests, run tests)

## Real-World Examples

Each repo contains an `examples/` folder with **identical functionality** across all four frameworks. Same business logic, same endpoints, same templates — only the language differs.

### Example Applications

#### 1. `examples/todo-app/` — Todo List API + UI
A complete CRUD application demonstrating:
- Project scaffolding
- ORM model (Todo with title, description, completed, due_date)
- Auto-CRUD endpoints
- Frond templates for the UI
- frond.js for AJAX
- Form validation
- Pagination
- Search/filter

#### 2. `examples/blog/` — Blog with Auth
A multi-model application demonstrating:
- User model with JWT auth
- Post model with soft delete
- Comment model with relationships (Post hasMany Comments)
- Secure routes (create/edit/delete require auth)
- Swagger API docs
- Frond templates with inheritance (base layout)
- Sessions for login state
- Seeder for demo data
- Migrations for schema

#### 3. `examples/chat/` — Real-Time Chat
A WebSocket application demonstrating:
- WebSocket connection
- Message broadcasting
- Room/channel support
- frond.js WebSocket helper
- Frond templates
- Queue for message persistence

#### 4. `examples/api-gateway/` — Microservice Client
A service-to-service application demonstrating:
- API client usage
- Rate limiting
- Health check
- Structured logging
- Request ID propagation
- CORS configuration
- Environment validation

#### 5. `examples/multidb/` — Multi-Database
Demonstrates connecting to multiple databases:
- SQLite for local cache
- PostgreSQL for main data
- DATABASE_URL configuration
- Migration per database

### Example Structure (per example)
```
examples/todo-app/
├── .env.example
├── src/
│   ├── routes/
│   │   └── api/
│   │       └── todos/
│   ├── orm/
│   │   └── Todo.{ext}
│   ├── migrations/
│   │   ├── 20260101000000_create_todos.sql
│   │   └── 20260101000000_create_todos.down.sql
│   ├── seeds/
│   │   └── todos.{ext}
│   ├── templates/
│   │   ├── base.html
│   │   └── todos/
│   │       ├── index.html
│   │       └── form.html
│   └── public/
│       └── js/
│           └── frond.js
└── tests/
    └── test_todos.{ext}
```

### Cross-Framework Verification
A CI script should:
1. Start each framework's todo-app example
2. Run the same HTTP test suite against each
3. Verify identical JSON responses
4. Verify identical HTML rendering (modulo whitespace)
5. Report any divergence

## Documentation Generation
Each framework should support:
```bash
tina4 docs                    # Generate API documentation
```
This outputs a static site from the Swagger spec + route annotations, suitable for hosting on GitHub Pages.
