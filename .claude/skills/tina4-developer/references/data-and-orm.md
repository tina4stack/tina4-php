# Data, ORM & Database

## Defining Models

Drop a model file in `src/orm/` and it's auto-registered.

### Python
```python
from tina4 import ORM, IntegerField, StringField, TextField, BooleanField

class User(ORM):
    id = IntegerField(primary_key=True, auto_increment=True)
    name = StringField()
    email = StringField()
    bio = TextField(default="")
    is_active = BooleanField(default=True)
```

### PHP
```php
class User extends \Tina4\ORM {
    public $id;
    public $name;
    public $email;
    public $bio = "";
    public $isActive = true;
    public $primaryKey = "id";
}
```

### Ruby
```ruby
class User < Tina4::ORM
  field :id, type: :integer, primary_key: true, auto_increment: true
  field :name, type: :string
  field :email, type: :string
  field :bio, type: :text, default: ""
  field :is_active, type: :boolean, default: true
end
```

### Node.js
```typescript
import { ORM, IntegerField, StringField } from 'tina4';

export class User extends ORM {
  id = IntegerField({ primaryKey: true, autoIncrement: true });
  name = StringField();
  email = StringField();
}
```

## CRUD Operations

### Create
```python
user = User({"name": "Alice", "email": "alice@example.com"})
user.save()
```

### Read
```python
user = User().find(1)                          # By ID
users = User().select("*").fetch()             # All
users = User().select("*").where("is_active = ?", [True]).fetch()  # Filtered
user = User().fetch_one("email = ?", ["alice@example.com"])        # Single
```

### Update
```python
user = User().find(1)
user.name = "Alice Smith"
user.save()
```

### Delete
```python
user = User().find(1)
user.delete()
```

## Relationships

```python
class Post(ORM):
    id = IntegerField(primary_key=True, auto_increment=True)
    user_id = IntegerField()
    title = StringField()
    has_one = [{"User": "user_id"}]

class User(ORM):
    id = IntegerField(primary_key=True, auto_increment=True)
    name = StringField()
    has_many = [{"Post": "user_id"}]

# Access:
user = User().find(1)
posts = user.posts        # All posts by this user
post = Post().find(1)
author = post.user        # The post's author
```

## Soft Delete

```python
class Article(ORM):
    soft_delete = True  # Adds deleted_at column

article = Article().find(1)
article.delete()              # Sets deleted_at (doesn't remove)
article.restore()             # Clears deleted_at
article.force_delete()        # Actually removes from DB

# Query includes only non-deleted by default
articles = Article().select("*").fetch()
# Include deleted:
articles = Article().with_trashed().fetch()
```

## Pagination

All queries return a standardized paginated result:
```python
result = User().select("*").page(1).per_page(20).fetch()
```

Use `offset` (not `skip`) to set the starting row in all database/ORM operations:
```python
result = User().select("*").offset(40).per_page(20).fetch()
```
```json
{
    "data": [...],
    "total": 100,
    "page": 1,
    "per_page": 20,
    "total_pages": 5,
    "has_next": true,
    "has_prev": false
}
```

## Raw SQL

For complex queries, use SQL directly:
```python
from tina4 import Database

db = Database("sqlite3:data/app.db")
result = db.fetch("SELECT u.*, COUNT(p.id) as post_count FROM users u LEFT JOIN posts p ON p.user_id = u.id GROUP BY u.id HAVING post_count > ?", [5])
```

The `fetch()` method returns a `DatabaseResult` object with lazy column metadata:
```python
result = db.fetch("SELECT * FROM users WHERE is_active = ?", [True])
result.data          # The rows
result.total         # Total count
result.columnInfo()  # Column metadata (lazy-loaded on first call)
```

**Note:** The class is `Database`, not `DatabaseFactory`. All raw SQL goes through `Database`.

## Database Connection Strings

Same format in every language:
```
sqlite3:data/app.db
pgsql://user:password@localhost:5432/mydb
mysql://user:password@localhost:3306/mydb
mssql://user:password@localhost:1433/mydb
```

Set in `.env`:
```env
DATABASE_NAME=sqlite3:data/app.db
```

## Migrations

```bash
tina4 migrate:create "create users table"   # Creates SQL file
tina4 migrate                                # Runs pending migrations
```

Migration files are versioned SQL in `src/migrations/`. Write standard SQL:
```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

## Seeding

```bash
tina4 seed:create "initial users"   # Creates seed file
tina4 seed                           # Runs all seeds
```

Quick seeding with fake data:
```python
from tina4 import FakeData, seed_orm

fake = FakeData()
fake.name()     # "Alice Johnson"
fake.email()    # "alice.johnson@example.com"

seed_orm(User, count=50)  # Bulk seed from field types
```

## Auto-CRUD

Generate a full admin CRUD interface with one call:
```python
@get("/api/users/crud")
async def user_crud(request, response):
    return CRUD.to_crud(request, {
        "sql": "SELECT * FROM users",
        "title": "User Management",
        "primary_key": "id"
    })
```
