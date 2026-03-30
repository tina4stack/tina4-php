# Data, ORM & Database

## Defining Models

Drop a model file in `src/orm/` and it's auto-registered.

### Python
```python
from tina4_python.orm import ORM
from tina4_python.orm.fields import IntegerField, StringField, TextField, BooleanField

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
    public $tableName = "users";
    public $primaryKey = "id";
    public ?int $id = null;
    public string $name = '';
    public string $email = '';
    public bool $isActive = true;
}
```

### Ruby
```ruby
class User < Tina4::ORM
  field :id, type: :integer, primary_key: true, auto_increment: true
  field :name, type: :string
  field :email, type: :string
  field :is_active, type: :boolean, default: true
end
```

### Node.js
```typescript
export default class User {
  static tableName = "users";
  static fields = {
    id:       { type: "integer" as const, primaryKey: true, autoIncrement: true },
    name:     { type: "string"  as const, required: true },
    email:    { type: "string"  as const, required: true },
    isActive: { type: "boolean" as const, default: true },
  };
}
```

## Database Setup (once at boot)

The database is bound globally at application startup — it is **never** passed to individual ORM calls.

### Python
```python
from tina4_python.database import Database
from tina4_python.orm import orm_bind

db = Database("sqlite:///data/app.db")
orm_bind(db)   # binds db to ALL ORM subclasses globally
```

### PHP
```php
$db = \Tina4\Database\Database::create('sqlite:///data/app.db');
\Tina4\App::setDatabase($db);
```

### Ruby
```ruby
db = Tina4::Database.new("sqlite:///data/app.db")
Tina4.database = db
```

### Node.js
```typescript
import { initDatabase } from "@tina4/orm";
await initDatabase({ url: "sqlite:///data/app.db" });
```

## CRUD Operations

### Create
```python
# Python
user = User({"name": "Alice", "email": "alice@example.com"})
user.save()

# PHP
$user = new User();
$user->name = "Alice";
$user->email = "alice@example.com";
$user->save();

# Ruby
user = User.new(name: "Alice", email: "alice@example.com")
user.save

# Node.js — auto-CRUD route or direct SQL via adapter
```

### Read
```python
# Python
user = User()
user.load("id = ?", [1])          # load by filter

result = User().select(filter="is_active = ?", params=[True], limit=10)
rows = result.data

# PHP
$user = new User();
$user->load("id = ?", [1]);

$result = (new User())->select("*", 100);  # returns DataResult

# Ruby
user = User.find(1)
users = User.where("is_active = ?", true).all
```

### Update
```python
user = User()
user.load("id = ?", [1])
user.name = "Alice Smith"
user.save()
```

### Delete
```python
user = User()
user.load("id = ?", [1])
user.delete()
```

## Soft Delete

```python
# Python
class Article(ORM):
    soft_delete = True   # adds deleted_at column

article = Article()
article.load("id = ?", [1])
article.delete()         # sets deleted_at (keeps row)
article.restore()        # clears deleted_at
article.force_delete()   # removes row permanently
```

```php
// PHP
class Article extends \Tina4\ORM {
    public bool $softDelete = true;
}

$article = new Article();
$article->load("id = ?", [1]);
$article->delete();       // sets deleted_at
$article->restore();      // clears deleted_at
$article->forceDelete();  // hard delete
```

## Raw SQL

For complex queries use the database adapter directly:

```python
# Python
from tina4_python.database import Database

db = Database("sqlite:///data/app.db")
result = db.fetch(
    "SELECT u.*, COUNT(p.id) as post_count FROM users u "
    "LEFT JOIN posts p ON p.user_id = u.id GROUP BY u.id",
    limit=20
)
for row in result.data:
    print(row)
```

```php
// PHP
$db = \Tina4\Database\Database::create('sqlite:///data/app.db');
$result = $db->fetch("SELECT * FROM users WHERE is_active = ?", 10, 0);
foreach ($result->data as $row) { ... }
```

## Database Connection Strings

Same `scheme://host/database` format in every language:
```
sqlite:///data/app.db           # SQLite (relative path)
sqlite::memory:                 # SQLite in-memory
postgres://user:pass@host:5432/mydb
mysql://user:pass@host:3306/mydb
mssql://user:pass@host:1433/mydb
firebird://user:pass@host:3050/path/to/db.fdb
```

Set in `.env`:
```env
DATABASE_URL=sqlite:///data/app.db
```

**Not** `sqlite3:` — that is the old v2 format. **Not** `DATABASE_NAME` — the env var is `DATABASE_URL`.

## Migrations

```bash
tina4 migrate:create "create users table"   # Creates SQL file in src/migrations/
tina4 migrate                                # Runs pending migrations
```

Migration files are versioned SQL in `src/migrations/`:
```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

## Seeding

Quick seeding with fake data:
```python
from tina4_python.seeder import FakeData, seed_table

fake = FakeData()
fake.name()     # "Alice Johnson"
fake.email()    # "alice.johnson@example.com"

seed_table(db, "users", 50, {
    "name": lambda: fake.name(),
    "email": lambda: fake.email(),
})
```
