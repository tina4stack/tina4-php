# Todo App — Tina4 PHP

Minimal todo API demonstrating Tina4 conventions.

## Run

```bash
cd examples/todo-app
composer install
php -S localhost:7146 index.php
```

## API

| Method | Path               | Description   |
|--------|--------------------|---------------|
| GET    | /api/todos         | List todos    |
| POST   | /api/todos         | Create todo   |
| GET    | /api/todos/{id}    | Get one todo  |
| PUT    | /api/todos/{id}    | Update todo   |
| DELETE | /api/todos/{id}    | Delete todo   |

## Structure

```
index.php                           # Entry point
src/models/Todo.php                 # Todo ORM model
src/routes/api/todos/get.php        # GET  /api/todos
src/routes/api/todos/post.php       # POST /api/todos
src/routes/api/todos/{id}/get.php   # GET  /api/todos/{id}
src/routes/api/todos/{id}/put.php   # PUT  /api/todos/{id}
src/routes/api/todos/{id}/delete.php# DELETE /api/todos/{id}
src/templates/index.html            # Frond template
```

## Cross-Framework Parity

This exact same structure, API, and patterns exist in all 4 Tina4 frameworks:

| File             | Python          | PHP             | Node.js         | Ruby            |
|------------------|-----------------|-----------------|-----------------|-----------------|
| Entry point      | `app.py`        | `index.php`     | `app.ts`        | `app.rb`        |
| Model            | `todo.py`       | `Todo.php`      | `todo.ts`       | `todo.rb`       |
| Routes           | `get.py` etc.   | `get.php` etc.  | `get.ts` etc.   | `get.rb` etc.   |
| Template         | `index.html`    | `index.html`    | `index.html`    | `index.html`    |
| Response pattern | `response()`    | `$response()`   | `response()`    | `response.json` |
| HTTP constants   | `HTTP_OK`       | `HTTP_OK`       | `HTTP_OK`       | `Tina4::HTTP_OK`|
