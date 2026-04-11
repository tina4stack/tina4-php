# Data, ORM & Database

## Defining Models

Drop a model file in `src/orm/` and it's auto-registered. In v3, **declare public properties directly** — no field descriptors needed.

### Python
```python
from tina4_python import ORM

class User(ORM):
    table_name = "users"
    primary_key = "id"

    id: int = None
    name: str = None
    email: str = None
    bio: str = ""
    is_active: bool = True
```

### PHP
```php
class User extends \Tina4\ORM {
    public $tableName = "users";
    public $primaryKey = "id";

    public $id;
    public $name;
    public $email;
    public $bio = "";
    public $isActive = true;
}
```

### Ruby
```ruby
class User < Tina4::ORM
  self.table_name = "users"
  self.primary_key = "id"

  attr_accessor :id, :name, :email, :bio, :is_active
end
```

### Node.js
```typescript
import { BaseModel } from 'tina4-nodejs';

export class User extends BaseModel {
  static tableName = "users";
  static primaryKey = "id";

  id?: number;
  name?: string;
  email?: string;
  isActive?: boolean;
}
```

## CRUD Operations

> **v3 API** — `find_by_id(id)` for ID lookup, `find(filter)` for filtered lists.
> Do NOT use the v2 query builder chain (`select("*").fetch()`) — it no longer exists.

> **v3.10.91** — QueryBuilder's `from()` method has been renamed to avoid language keyword conflicts:
> - Python / Ruby: `from_table()`
> - PHP / Node.js: `fromTable()`
>
> The old `from()` method is removed.

### Create

```python
# Python
user = User({"name": "Alice", "email": "alice@example.com"})
saved = user.save()   # returns self (fluent) on success, False on failure
```
```php
// PHP
$user = new User(["name" => "Alice", "email" => "alice@example.com"]);
$saved = $user->save();   // returns $this on success, false on failure

// Static factory
$user = User::create(["name" => "Alice", "email" => "alice@example.com"]);
```
```ruby
# Ruby
user = User.new(name: "Alice", email: "alice@example.com")
user.save   # returns self or false
```
```typescript
// Node.js
const user = new User({ name: "Alice", email: "alice@example.com" });
await user.save();
```

### Read — by ID

```python
# Python
user = User().find_by_id(1)         # returns instance or None
```
```php
// PHP
$user = (new User())->findById(1);    // returns instance or null
$user = (new User())->findOrFail(1);  // throws if not found
```
```ruby
# Ruby
user = User.new.find_by_id(1)
```
```typescript
// Node.js
const user = await User.findById(1);
```

### Read — filtered list

```python
# Python — find() takes a filter dict or SQL string
users = User().find({"is_active": True})
users = User().find("is_active = 1", limit=20, offset=0)
users = User().all(limit=50)                         # all records
user  = User().select_one("email = ?", ["alice@example.com"])  # single
```
```php
// PHP
$users = (new User())->find(["is_active" => true]);
$users = (new User())->find("is_active = 1", limit: 20, offset: 0);
$users = (new User())->all(limit: 50);
$users = (new User())->where("email = ?", ["alice@example.com"]);
$user  = (new User())->selectOne("email = ?", ["alice@example.com"]);
```
```ruby
# Ruby
users = User.new.find(is_active: true)
users = User.new.all(limit: 50)
user  = User.new.find_one("email = ?", ["alice@example.com"])
```
```typescript
// Node.js
const users = await User.find({ isActive: true });
const users = await User.all({ limit: 50 });
```

### Update

```python
# Python
user = User().find_by_id(1)
user.name = "Alice Smith"
user.save()
```
```php
// PHP
$user = (new User())->findById(1);
$user->name = "Alice Smith";
$user->save();
```

### Delete

```python
# Python
user = User().find_by_id(1)
user.delete()
```
```php
// PHP
$user = (new User())->findById(1);
$user->delete();
```

### Serialisation

```python
# Python
user.to_dict()    # {"id": 1, "name": "Alice", ...}
user.to_json()    # '{"id": 1, "name": "Alice", ...}'
user.to_array()   # [1, "Alice", ...]
```
```php
// PHP
$user->toDict();   // same as toAssoc()
$user->toJson();
$user->toArray();  // flat indexed list
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
user = User().find_by_id(1)
posts = user.posts        # All posts by this user
post  = Post().find_by_id(1)
author = post.user        # The post's author
```

## Soft Delete

```python
class Article(ORM):
    soft_delete = True  # Adds deleted_at column

article = Article().find_by_id(1)
article.delete()              # Sets deleted_at (soft)
article.restore()             # Clears deleted_at
article.force_delete()        # Actually removes from DB

# Default queries exclude deleted records
articles = Article().all()
# Include deleted:
articles = Article().with_trashed()
```

## Pagination

`find()`, `all()`, and `where()` accept `limit` and `offset`:
```python
users = User().all(limit=20, offset=40)   # page 3 at 20/page
```

For paginated JSON responses the result includes metadata:
```json
{
    "data": [...],
    "total": 100,
    "page": 3,
    "per_page": 20,
    "total_pages": 5
}
```

## DatabaseResult Methods

> **v3.10.92** — `DatabaseResult` gained convenience methods (Python now matches the other frameworks):

| Method | Description | Frameworks |
|--------|-------------|------------|
| `size()` | Returns record count | All |
| `to_array()` | Convert to list/array | All (Python added in v3.10.92) |
| `to_json()` | Convert to JSON string | All (Python added in v3.10.92) |
| `to_csv()` | Convert to CSV string | All (Python added in v3.10.92) |

```python
# Python
results = db.fetch("SELECT * FROM users")
results.size()       # 42
results.to_array()   # [{"id": 1, "name": "Alice"}, ...]
results.to_json()    # '[{"id": 1, "name": "Alice"}, ...]'
results.to_csv()     # 'id,name\n1,Alice\n...'
```

## Raw SQL

For complex queries, bypass the ORM:
```python
from tina4 import Database

db = Database.from_env()
results = db.fetch(
    "SELECT u.*, COUNT(p.id) as post_count FROM users u "
    "LEFT JOIN posts p ON p.user_id = u.id GROUP BY u.id HAVING post_count > ?",
    [5]
)
```

## Database Connection Strings

Same format in every language (set in `.env` as `DATABASE_URL`):
```
sqlite://data/app.db
postgres://user:password@localhost:5432/mydb
mysql://user:password@localhost:3306/mydb
mssql://user:password@localhost:1433/mydb
firebird://user:password@localhost:3050/mydb
```

```python
# Python
from tina4 import Database
db = Database.from_env()  # reads DATABASE_URL
```
```php
// PHP
$db = \Tina4\Database\Database::fromEnv();  // reads DATABASE_URL
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
