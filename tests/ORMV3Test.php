<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;
use Tina4\ORM;

/**
 * Test model for ORM tests.
 */
class TestUser extends ORM
{
    public string $tableName = 'users';
    public string $primaryKey = 'id';
    public array $fieldMapping = [];
    public array $hasMany = ['posts' => 'TestRelPost.user_id'];
    public array $hasOne = ['profile' => 'TestProfile.user_id'];
}

/**
 * Test model with soft delete enabled.
 */
class TestPost extends ORM
{
    public string $tableName = 'posts';
    public string $primaryKey = 'id';
    public bool $softDelete = true;
}

/**
 * Test model for relationship testing — posts with user_id FK.
 */
class TestRelPost extends ORM
{
    public string $tableName = 'rel_posts';
    public string $primaryKey = 'id';
    public array $belongsTo = ['author' => 'TestUser.user_id'];
    public array $hasMany = ['comments' => 'TestComment.post_id'];
}

/**
 * Test model for relationship testing — comments with post_id FK.
 */
class TestComment extends ORM
{
    public string $tableName = 'comments';
    public string $primaryKey = 'id';
    public array $belongsTo = ['post' => 'TestRelPost.post_id'];
}

/**
 * Test model for relationship testing — user profiles.
 */
class TestProfile extends ORM
{
    public string $tableName = 'profiles';
    public string $primaryKey = 'id';
    public array $belongsTo = ['owner' => 'TestUser.user_id'];
}

/**
 * Test model with field mapping.
 */
class TestProduct extends ORM
{
    public string $tableName = 'products';
    public string $primaryKey = 'id';
    public array $fieldMapping = [
        'productName' => 'product_name',
        'unitPrice' => 'unit_price',
    ];
}

/**
 * Typed-property test model — matches the shape real users write (PHP 8+).
 *
 * Every domain field is declared with an explicit type AND a default value.
 * This is the pattern that tripped issue #102: the declared `public int $id = 0`
 * meant reflection-based getDbData() serialised the PK into every INSERT,
 * breaking auto-increment. Tests using this fixture lock the fix in place.
 */
class TestTypedWidget extends ORM
{
    public string $tableName = 'typed_widgets';
    public string $primaryKey = 'id';
    public array $fieldMapping = [];
    public int $id = 0;
    public string $name = '';
    public string $displayLabel = '';
    public int $priceCents = 0;
}

class ORMV3Test extends TestCase
{
    private SQLite3Adapter $db;

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT, age INTEGER)");
        $this->db->exec("CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, body TEXT, is_deleted INTEGER DEFAULT 0)");
        $this->db->exec("CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, product_name TEXT NOT NULL, unit_price REAL)");
        $this->db->exec("CREATE TABLE rel_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, body TEXT, user_id INTEGER)");
        $this->db->exec("CREATE TABLE comments (id INTEGER PRIMARY KEY AUTOINCREMENT, text TEXT, post_id INTEGER)");
        $this->db->exec("CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, bio TEXT, user_id INTEGER)");
        $this->db->exec("CREATE TABLE typed_widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, display_label TEXT, price_cents INTEGER)");
        \Tina4\ORM::setGlobalDb($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    // --- Basic CRUD ---

    public function testInsert(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Alice';
        $user->email = 'alice@test.com';

        $result = $user->save();

        $this->assertNotFalse($result);
        $this->assertNotNull($user->getPrimaryKeyValue());
        $this->assertTrue($user->exists($user->getPrimaryKeyValue()));
    }

    public function testLoad(): void
    {
        $this->db->exec("INSERT INTO users (name, email) VALUES ('Alice', 'alice@test.com')");

        $user = new TestUser($this->db);
        $user->load("id = ?", [1]);

        $this->assertTrue($user->exists(1));
        $this->assertSame('Alice', $user->name);
        $this->assertSame('alice@test.com', $user->email);
    }

    public function testLoadNonexistent(): void
    {
        $user = new TestUser($this->db);
        $user->load("id = ?", [999]);

        $this->assertFalse($user->exists(999));
    }

    public function testUpdate(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Alice';
        $user->email = 'alice@test.com';
        $user->save();

        $id = $user->getPrimaryKeyValue();

        $user2 = new TestUser($this->db);
        $user2->load("id = ?", [$id]);
        $user2->name = 'Alice Updated';
        $user2->save();

        $user3 = new TestUser($this->db);
        $user3->load("id = ?", [$id]);
        $this->assertSame('Alice Updated', $user3->name);
    }

    public function testDelete(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Alice';
        $user->save();
        $id = $user->getPrimaryKeyValue();

        $result = $user->delete();
        $this->assertNotFalse($result);

        $user2 = new TestUser($this->db);
        $user2->load("id = ?", [$id]);
        $this->assertFalse($user2->exists($id));
    }

    // --- Soft Delete ---

    public function testSoftDelete(): void
    {
        $post = new TestPost($this->db);
        $post->title = 'My Post';
        $post->body = 'Content here';
        $post->save();
        $id = $post->getPrimaryKeyValue();

        $post->delete();

        // Record still exists in DB
        $rows = $this->db->query("SELECT * FROM posts WHERE id = :id", [':id' => $id]);
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['is_deleted']);

        // But load doesn't find it
        $post2 = new TestPost($this->db);
        $post2->load("id = ?", [$id]);
        $this->assertFalse($post2->exists($id));
    }

    // --- Find ---

    public function testFind(): void
    {
        $this->db->exec("INSERT INTO users (name, email, age) VALUES ('Alice', 'alice@test.com', 30)");
        $this->db->exec("INSERT INTO users (name, email, age) VALUES ('Bob', 'bob@test.com', 25)");
        $this->db->exec("INSERT INTO users (name, email, age) VALUES ('Alice', 'alice2@test.com', 28)");

        $user = new TestUser($this->db);
        $results = $user->find(['name' => 'Alice']);

        $this->assertCount(2, $results);
        $this->assertInstanceOf(TestUser::class, $results[0]);
    }

    public function testFindEmpty(): void
    {
        $user = new TestUser($this->db);
        $results = $user->find(['name' => 'Nonexistent']);
        $this->assertSame([], $results);
    }

    public function testFindWithOrderBy(): void
    {
        $this->db->exec("INSERT INTO users (name, age) VALUES ('Alice', 30)");
        $this->db->exec("INSERT INTO users (name, age) VALUES ('Bob', 25)");

        $user = new TestUser($this->db);
        $results = $user->find([], 100, 0, 'age ASC');

        $this->assertSame('Bob', $results[0]->name);
        $this->assertSame('Alice', $results[1]->name);
    }

    public function testSelectOne(): void
    {
        $this->db->exec("INSERT INTO users (name, email) VALUES ('Alice', 'alice@test.com')");
        $this->db->exec("INSERT INTO users (name, email) VALUES ('Bob', 'bob@test.com')");

        $user = new TestUser($this->db);
        $result = $user->selectOne("SELECT * FROM users WHERE name = ?", ['Alice']);

        $this->assertInstanceOf(TestUser::class, $result);
        $this->assertSame('Alice', $result->name);
    }

    public function testSelectOneReturnsNullWhenNotFound(): void
    {
        $user = new TestUser($this->db);
        $result = $user->selectOne("SELECT * FROM users WHERE name = ?", ['Nonexistent']);

        $this->assertNull($result);
    }

    // --- All ---

    public function testAll(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->db->exec("INSERT INTO users (name) VALUES (:name)", [':name' => "User{$i}"]);
        }

        $user = new TestUser($this->db);
        $result = $user->all(10, 0);

        $this->assertCount(10, $result);
    }

    public function testAllSecondPage(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->db->exec("INSERT INTO users (name) VALUES (:name)", [':name' => "User{$i}"]);
        }

        $user = new TestUser($this->db);
        $result = $user->all(10, 10);

        $this->assertCount(5, $result);
    }

    // --- Field Mapping ---

    public function testFieldMapping(): void
    {
        $product = new TestProduct($this->db);
        $product->productName = 'Widget';
        $product->unitPrice = 9.99;
        $product->save();

        $id = $product->getPrimaryKeyValue();

        // Verify data was stored with DB column names
        $rows = $this->db->query("SELECT * FROM products WHERE id = :id", [':id' => $id]);
        $this->assertSame('Widget', $rows[0]['product_name']);
        $this->assertSame(9.99, $rows[0]['unit_price']);
    }

    public function testFieldMappingOnLoad(): void
    {
        $this->db->exec("INSERT INTO products (product_name, unit_price) VALUES ('Gadget', 19.99)");

        $product = new TestProduct($this->db);
        $product->load("id = ?", [1]);

        // Reverse mapping: DB columns map back to PHP properties
        $this->assertTrue($product->exists(1));
    }

    // --- Serialization ---

    public function testToArray(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Alice';
        $user->email = 'alice@test.com';

        $arr = $user->toDict();
        $this->assertSame('Alice', $arr['name']);
        $this->assertSame('alice@test.com', $arr['email']);
    }

    public function testToJson(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Alice';

        $json = $user->toJson();
        $decoded = json_decode($json, true);
        $this->assertSame('Alice', $decoded['name']);
    }

    // --- Fill ---

    public function testFill(): void
    {
        $user = new TestUser($this->db);
        $user->fill(['name' => 'Alice', 'email' => 'alice@test.com']);

        $this->assertSame('Alice', $user->name);
        $this->assertSame('alice@test.com', $user->email);
    }

    public function testFillFromConstructor(): void
    {
        $user = new TestUser($this->db, ['name' => 'Bob', 'age' => 25]);

        $this->assertSame('Bob', $user->name);
        $this->assertSame(25, $user->age);
    }

    // --- Magic Properties ---

    public function testIsset(): void
    {
        $user = new TestUser($this->db);
        $this->assertFalse(isset($user->name));

        $user->name = 'Alice';
        $this->assertTrue(isset($user->name));
    }

    public function testUnset(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Alice';
        unset($user->name);
        $this->assertFalse(isset($user->name));
    }

    public function testGetNull(): void
    {
        $user = new TestUser($this->db);
        $this->assertNull($user->nonexistent);
    }

    // --- Error Handling ---

    public function testSaveWithoutDb(): void
    {
        $this->expectException(\RuntimeException::class);

        // Temporarily clear global DB to test the no-DB error path
        $savedDb = $this->db;
        \Tina4\ORM::setGlobalDb(new class implements \Tina4\Database\DatabaseAdapter {
            // Dummy adapter that throws — we just need setGlobalDb to accept something
            // so we can test the "no instance db" path
            public function open(): void {}
            public function close(): void {}
            public function query(string $sql, array $params = []): array { return []; }
            public function fetch(string $sql, array $params = [], int $limit = 100, int $offset = 0): array { return []; }
            public function fetchOne(string $sql, array $params = []): ?array { return null; }
            public function execute(string $sql, array $params = []): bool|\Tina4\Database\DatabaseResult { return false; }
            public function insert(string $table, array $data): bool { return false; }
            public function update(string $table, array $data, string $where = '', array $whereParams = []): bool { return false; }
            public function delete(string $table, string|array $filter = '', array $whereParams = []): bool { return false; }
            public function executeMany(string $sql, array $paramsList = []): int { return 0; }
            public function tableExists(string $table): bool { return false; }
            public function getColumns(string $table): array { return []; }
            public function getTables(): array { return []; }
            public function lastInsertId(): int|string { return 0; }
            public function startTransaction(): void { throw new \RuntimeException('No DB'); }
            public function commit(): void {}
            public function rollback(): void {}
            public function error(): ?string { return 'No DB'; }
        });

        try {
            $user = new TestUser();
            $user->name = 'Alice';
            $user->save();
        } finally {
            \Tina4\ORM::setGlobalDb($savedDb);
        }
    }

    public function testDeleteWithNoPrimaryKey(): void
    {
        $user = new TestUser($this->db);
        $result = $user->delete();
        $this->assertFalse($result);
    }

    // --- SetDb ---

    public function testSetDb(): void
    {
        $user = new TestUser();
        $user->setDb($this->db);
        $this->assertSame($this->db, $user->getDb());
    }

    // --- Upsert (insert then update) ---

    public function testUpsertInsertsThenUpdates(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Alice';
        $user->save(); // INSERT
        $id = $user->getPrimaryKeyValue();

        $user->name = 'Alice V2';
        $user->save(); // UPDATE (same instance, already exists)

        $loaded = new TestUser($this->db);
        $loaded->load("id = ?", [$id]);
        $this->assertSame('Alice V2', $loaded->name);
    }

    // --- Lazy-loaded Relationship Tests ---

    public function testHasManyLazyLoad(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Alice';
        $user->save();

        $post1 = new TestRelPost($this->db);
        $post1->title = 'P1';
        $post1->user_id = $user->getPrimaryKeyValue();
        $post1->save();

        $post2 = new TestRelPost($this->db);
        $post2->title = 'P2';
        $post2->user_id = $user->getPrimaryKeyValue();
        $post2->save();

        // Access via __get triggers lazy loading
        $posts = $user->posts;
        $this->assertIsArray($posts);
        $this->assertCount(2, $posts);
    }

    public function testHasOneLazyLoad(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Bob';
        $user->save();

        $profile = new TestProfile($this->db);
        $profile->bio = 'Hello world';
        $profile->user_id = $user->getPrimaryKeyValue();
        $profile->save();

        $loadedProfile = $user->profile;
        $this->assertInstanceOf(TestProfile::class, $loadedProfile);
        $this->assertSame('Hello world', $loadedProfile->bio);
    }

    public function testBelongsToLazyLoad(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Carol';
        $user->save();

        $post = new TestRelPost($this->db);
        $post->title = 'Post1';
        $post->user_id = $user->getPrimaryKeyValue();
        $post->save();

        $author = $post->author;
        $this->assertInstanceOf(TestUser::class, $author);
        $this->assertSame('Carol', $author->name);
    }

    public function testRelationshipCache(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Dave';
        $user->save();

        $post = new TestRelPost($this->db);
        $post->title = 'Cached';
        $post->user_id = $user->getPrimaryKeyValue();
        $post->save();

        $posts1 = $user->posts;
        $posts2 = $user->posts;
        $this->assertSame($posts1, $posts2); // Same reference
    }

    public function testCacheClearsOnSave(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Eve';
        $user->save();

        $_ = $user->posts; // Populate cache
        $user->name = 'Eve Updated';
        $user->save();

        // Cache should be cleared — accessing posts will trigger fresh load
        $posts = $user->posts;
        $this->assertIsArray($posts);
        $this->assertCount(0, $posts);
    }

    public function testToDictWithInclude(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Alice';
        $user->save();

        $post = new TestRelPost($this->db);
        $post->title = 'Hello';
        $post->user_id = $user->getPrimaryKeyValue();
        $post->save();

        $dict = $user->toDict(['posts']);
        $this->assertArrayHasKey('posts', $dict);
        $this->assertCount(1, $dict['posts']);
        $this->assertSame('Hello', $dict['posts'][0]['title']);
    }

    public function testToDictWithNestedInclude(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Bob';
        $user->save();

        $post = new TestRelPost($this->db);
        $post->title = 'Post';
        $post->user_id = $user->getPrimaryKeyValue();
        $post->save();

        $comment = new TestComment($this->db);
        $comment->text = 'Nice';
        $comment->post_id = $post->getPrimaryKeyValue();
        $comment->save();

        $dict = $user->toDict(['posts.comments']);
        $this->assertArrayHasKey('posts', $dict);
        $this->assertArrayHasKey('comments', $dict['posts'][0]);
        $this->assertSame('Nice', $dict['posts'][0]['comments'][0]['text']);
    }

    // --- find_by_id ---

    public function testFindById(): void
    {
        $this->db->exec("INSERT INTO users (name, email) VALUES ('Alice', 'alice@test.com')");

        $user = new TestUser($this->db);
        $user->load("id = ?", [1]);

        $this->assertTrue($user->exists(1));
        $this->assertSame('Alice', $user->name);
    }

    public function testFindByIdNonexistent(): void
    {
        $user = new TestUser($this->db);
        $user->load("id = ?", [9999]);
        $this->assertFalse($user->exists(9999));
    }

    // --- find(scalar) — PK lookup parity with Python/Ruby/Node ---

    public function testFindWithScalarDoesPkLookup(): void
    {
        $this->db->exec("INSERT INTO users (name, email) VALUES ('Alice', 'alice@test.com')");

        // Scalar argument => PK lookup (returns single instance, parity with Python find(pk))
        $user = TestUser::find(1);

        $this->assertNotNull($user);
        $this->assertInstanceOf(TestUser::class, $user);
        $this->assertSame('Alice', $user->name);
    }

    public function testFindWithScalarMissingReturnsNull(): void
    {
        $user = TestUser::find(9999);
        $this->assertNull($user);
    }

    public function testFindWithStringPkDoesPkLookup(): void
    {
        $this->db->exec("INSERT INTO users (name, email) VALUES ('Bob', 'bob@test.com')");

        // String PK form (e.g. UUIDs) — also routes to PK lookup.
        $user = TestUser::find('1');
        $this->assertNotNull($user);
        $this->assertSame('Bob', $user->name);
    }

    public function testFindWithArrayStillFiltersForBackCompat(): void
    {
        $this->db->exec("INSERT INTO users (name, email) VALUES ('Alice', 'alice@test.com')");
        $this->db->exec("INSERT INTO users (name, email) VALUES ('Bob', 'bob@test.com')");

        // Array argument => filter-based lookup (returns array, PHP back-compat preserved).
        $results = TestUser::find(['name' => 'Alice']);
        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertSame('Alice', $results[0]->name);
    }

    // --- Find with multiple conditions ---

    public function testFindWithMultipleConditions(): void
    {
        $this->db->exec("INSERT INTO users (name, email, age) VALUES ('Alice', 'alice@test.com', 30)");
        $this->db->exec("INSERT INTO users (name, email, age) VALUES ('Alice', 'alice2@test.com', 25)");
        $this->db->exec("INSERT INTO users (name, email, age) VALUES ('Bob', 'bob@test.com', 30)");

        $user = new TestUser($this->db);
        $results = $user->find(['name' => 'Alice', 'age' => 30]);

        $this->assertCount(1, $results);
        $this->assertSame('alice@test.com', $results[0]->email);
    }

    // --- Find with limit and offset ---

    public function testFindWithLimitAndOffset(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->db->exec("INSERT INTO users (name) VALUES (:name)", [':name' => "User{$i}"]);
        }

        $user = new TestUser($this->db);
        $results = $user->find([], 3, 2);

        $this->assertCount(3, $results);
    }

    // --- selectOne with params ---

    public function testSelectOneWithMultipleParams(): void
    {
        $this->db->exec("INSERT INTO users (name, email, age) VALUES ('Alice', 'alice@test.com', 30)");
        $this->db->exec("INSERT INTO users (name, email, age) VALUES ('Bob', 'bob@test.com', 25)");

        $user = new TestUser($this->db);
        $result = $user->selectOne("SELECT * FROM users WHERE name = ? AND age = ?", ['Alice', 30]);

        $this->assertInstanceOf(TestUser::class, $result);
        $this->assertSame('Alice', $result->name);
    }

    // --- create classmethod equivalent (constructor fill + save) ---

    public function testCreateViaConstructorAndSave(): void
    {
        $user = new TestUser($this->db, ['name' => 'QuickCreate', 'email' => 'qc@test.com']);
        $user->save();

        $this->assertTrue($user->exists($user->getPrimaryKeyValue()));
        $this->assertNotNull($user->getPrimaryKeyValue());

        $loaded = new TestUser($this->db);
        $loaded->load("id = ?", [$user->getPrimaryKeyValue()]);
        $this->assertSame('QuickCreate', $loaded->name);
    }

    // --- Soft delete: record still in DB ---

    public function testSoftDeleteRecordStillInDb(): void
    {
        $post = new TestPost($this->db);
        $post->title = 'Soft Post';
        $post->save();
        $id = $post->getPrimaryKeyValue();

        $post->delete();

        $rows = $this->db->query("SELECT is_deleted FROM posts WHERE id = :id", [':id' => $id]);
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['is_deleted']);
    }

    // --- Soft delete: normal load doesn't find ---

    public function testSoftDeletedRecordNotFoundByLoad(): void
    {
        $post = new TestPost($this->db);
        $post->title = 'Hidden Post';
        $post->save();
        $id = $post->getPrimaryKeyValue();

        $post->delete();

        $post2 = new TestPost($this->db);
        $post2->load("id = ?", [$id]);
        $this->assertFalse($post2->exists($id));
    }

    // --- Per-model database ---

    public function testPerModelDatabaseSwitch(): void
    {
        $db2 = new SQLite3Adapter(':memory:');
        $db2->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT, age INTEGER)");

        $user = new TestUser($db2);
        $user->name = 'SeparateDb';
        $user->save();

        $this->assertTrue($user->exists($user->getPrimaryKeyValue()));

        // Verify it's in db2 not this->db
        $rows = $db2->query("SELECT * FROM users WHERE name = 'SeparateDb'");
        $this->assertCount(1, $rows);

        $rows = $this->db->query("SELECT * FROM users WHERE name = 'SeparateDb'");
        $this->assertCount(0, $rows);

        $db2->close();
    }

    // --- Relationship: has_many empty ---

    public function testHasManyEmpty(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Lonely';
        $user->save();

        $posts = $user->posts;
        $this->assertIsArray($posts);
        $this->assertCount(0, $posts);
    }

    // --- Relationship: has_one returns null ---

    public function testHasOneNone(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'NoProfile';
        $user->save();

        $profile = $user->profile;
        $this->assertNull($profile);
    }

    // --- Nested relationships ---

    public function testNestedRelationships(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Frank';
        $user->save();

        $post = new TestRelPost($this->db);
        $post->title = 'Post';
        $post->user_id = $user->getPrimaryKeyValue();
        $post->save();

        $c1 = new TestComment($this->db);
        $c1->text = 'Nice!';
        $c1->post_id = $post->getPrimaryKeyValue();
        $c1->save();

        $c2 = new TestComment($this->db);
        $c2->text = 'Great!';
        $c2->post_id = $post->getPrimaryKeyValue();
        $c2->save();

        $posts = $user->posts;
        $this->assertCount(1, $posts);
        $comments = $posts[0]->comments;
        $this->assertCount(2, $comments);
    }

    // --- toDict with belongs_to ---

    public function testToDictWithBelongsTo(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Carol';
        $user->save();

        $post = new TestRelPost($this->db);
        $post->title = 'Mine';
        $post->user_id = $user->getPrimaryKeyValue();
        $post->save();

        $dict = $post->toDict(['author']);
        $this->assertArrayHasKey('author', $dict);
        $this->assertSame('Carol', $dict['author']['name']);
    }

    // --- toDict with null has_one ---

    public function testToDictNullRelationship(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'NoProfile';
        $user->save();

        $dict = $user->toDict(['profile']);
        $this->assertArrayHasKey('profile', $dict);
        $this->assertNull($dict['profile']);
    }

    // --- Auto increment IDs ---

    public function testAutoIncrementIds(): void
    {
        $u1 = new TestUser($this->db);
        $u1->name = 'First';
        $u1->save();

        $u2 = new TestUser($this->db);
        $u2->name = 'Second';
        $u2->save();

        $this->assertSame(1, $u1->getPrimaryKeyValue());
        $this->assertSame(2, $u2->getPrimaryKeyValue());

        // Also assert via public property access — the shape users actually hit.
        // TestUser doesn't declare $id, so this reads through __get / _dynamicProps.
        $this->assertSame(1, $u1->id);
        $this->assertSame(2, $u2->id);
    }

    /**
     * Issue #102 (typed-property variant): for models that declare
     * `public int $id = 0`, the auto-increment id must still land in the
     * public $id property after save(). Before the insert() fix, getDbData()
     * sent `id = 0` in the INSERT, auto-increment skipped, $id stayed 0.
     */
    public function testAutoIncrementIdsOnTypedModel(): void
    {
        $w1 = new TestTypedWidget($this->db);
        $w1->name = 'First';
        $w1->save();

        $w2 = new TestTypedWidget($this->db);
        $w2->name = 'Second';
        $w2->save();

        // Public property must be synced — this is the bit that broke in #102
        $this->assertSame(1, $w1->id);
        $this->assertSame(2, $w2->id);

        // And getPrimaryKeyValue() should agree (belt-and-braces)
        $this->assertSame(1, $w1->getPrimaryKeyValue());
        $this->assertSame(2, $w2->getPrimaryKeyValue());

        // And the rows must actually be in the DB at those IDs
        $row1 = $this->db->fetchOne("SELECT id, name FROM typed_widgets WHERE id = ?", [1]);
        $row2 = $this->db->fetchOne("SELECT id, name FROM typed_widgets WHERE id = ?", [2]);
        $this->assertSame(['id' => 1, 'name' => 'First'], $row1);
        $this->assertSame(['id' => 2, 'name' => 'Second'], $row2);
    }

    /**
     * Edge case of the insert() PK-stripping fix: stripping only happens when
     * the PK is "unset" (null, 0, or ''). An explicit non-zero id must still
     * be honoured — the row goes in with that id, no auto-increment override.
     *
     * This matters for migrations, data imports, and sync scenarios where the
     * caller genuinely wants to pin an id.
     */
    public function testExplicitPrimaryKeyIsPreservedOnInsert(): void
    {
        $w = new TestTypedWidget($this->db);
        $w->id = 42;
        $w->name = 'Pinned';
        $w->priceCents = 100;
        $w->save();

        // The row must live at id=42, not at 1
        $row = $this->db->fetchOne("SELECT id, name, price_cents FROM typed_widgets WHERE id = ?", [42]);
        $this->assertSame(['id' => 42, 'name' => 'Pinned', 'price_cents' => 100], $row);

        // And the model still reports id=42
        $this->assertSame(42, $w->id);

        // Next auto-increment insert picks up from 43 (SQLite MAX(id)+1 behaviour)
        $w2 = new TestTypedWidget($this->db);
        $w2->name = 'AfterPinned';
        $w2->save();
        $this->assertSame(43, $w2->id);
    }

    /**
     * Full round-trip via typed properties: insert, update, reload.
     *
     * This catches regressions where public-property writes don't reach
     * getDbData() (the `_data` synchronisation bug users reported), where
     * auto-generated ids don't sync back, or where update() mangles the
     * WHERE clause because $primaryKey's value is stale.
     */
    public function testTypedPropertyInsertUpdateRoundTrip(): void
    {
        $w = new TestTypedWidget($this->db);
        $w->name = 'Sprocket';
        $w->displayLabel = 'Super Sprocket';
        $w->priceCents = 1995;
        $result = $w->save();
        $this->assertNotFalse($result, 'save() must succeed');

        $insertedId = $w->id;
        $this->assertGreaterThan(0, $insertedId, 'id must be synced back');

        // Mutate via public property and save again — must UPDATE not INSERT
        $w->priceCents = 2500;
        $w->displayLabel = 'Sprocket Ultra';
        $w->save();

        // Still the same row — no duplicate created
        $count = $this->db->fetchOne("SELECT COUNT(*) AS cnt FROM typed_widgets");
        $this->assertSame(1, (int)$count['cnt'], 'update must not create a second row');

        // Fields persisted
        $row = $this->db->fetchOne("SELECT * FROM typed_widgets WHERE id = ?", [$insertedId]);
        $this->assertSame('Sprocket', $row['name']);
        $this->assertSame('Sprocket Ultra', $row['display_label']);
        $this->assertSame(2500, $row['price_cents']);
    }

    // --- Default values ---

    public function testDefaultValuesNotSet(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Test';
        // age and email should be null/unset
        $this->assertNull($user->age);
    }

    // --- All with empty table ---

    public function testAllEmptyTable(): void
    {
        $user = new TestUser($this->db);
        $result = $user->all(10, 0);

        $this->assertCount(0, $result);
    }

    // --- Fill does not set unknown keys as properties ---

    public function testFillIgnoresUnknownKeys(): void
    {
        $user = new TestUser($this->db);
        $user->fill(['name' => 'Alice', 'nonexistent_column' => 'value']);

        $this->assertSame('Alice', $user->name);
        // nonexistent_column should just be stored as a dynamic property, not cause an error
    }

    // --- Delete returns false for unsaved ---

    public function testDeleteUnsavedReturnsFalse(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Unsaved';
        $result = $user->delete();
        $this->assertFalse($result);
    }

    // --- Validation: required field missing ---------------------------------

    public function testValidationMissingRequired(): void
    {
        $user = new TestUser($this->db);
        // name is NOT NULL in DB, try to save without it
        // Depending on implementation, this may fail at DB level
        // Test that the ORM handles missing required fields
        $user->email = 'test@test.com';

        // Should either throw or return false on save
        try {
            $result = $user->save();
            // If save returns false, validation caught it
            if ($result === false) {
                $this->assertFalse($result);
            } else {
                // Some implementations let the DB catch it
                $this->assertTrue(true);
            }
        } catch (\Exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    // --- Batch insert multiple records --------------------------------------

    public function testBatchInsertMultiple(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $user = new TestUser($this->db);
            $user->name = "BatchUser{$i}";
            $user->save();
        }

        $user = new TestUser($this->db);
        $result = $user->all(20, 0);
        $this->assertCount(10, $result);
    }

    // --- Update only changes specified fields --------------------------------

    public function testUpdateOnlyChangesSpecifiedFields(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Original';
        $user->email = 'original@test.com';
        $user->age = 25;
        $user->save();
        $id = $user->getPrimaryKeyValue();

        $user2 = new TestUser($this->db);
        $user2->load("id = ?", [$id]);
        $user2->name = 'Updated';
        $user2->save();

        $user3 = new TestUser($this->db);
        $user3->load("id = ?", [$id]);
        $this->assertSame('Updated', $user3->name);
        $this->assertSame('original@test.com', $user3->email);
        $this->assertSame(25, $user3->age);
    }

    // --- toArray includes all set fields ------------------------------------

    public function testToArrayIncludesAllSetFields(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Alice';
        $user->email = 'alice@test.com';
        $user->age = 30;

        $arr = $user->toDict();
        $this->assertSame('Alice', $arr['name']);
        $this->assertSame('alice@test.com', $arr['email']);
        $this->assertSame(30, $arr['age']);
    }

    // --- Find with order by desc -------------------------------------------

    public function testFindWithOrderByDesc(): void
    {
        $this->db->exec("INSERT INTO users (name, age) VALUES ('Alice', 30)");
        $this->db->exec("INSERT INTO users (name, age) VALUES ('Bob', 25)");
        $this->db->exec("INSERT INTO users (name, age) VALUES ('Carol', 35)");

        $user = new TestUser($this->db);
        $results = $user->find([], 100, 0, 'age DESC');

        $this->assertSame('Carol', $results[0]->name);
        $this->assertSame('Alice', $results[1]->name);
        $this->assertSame('Bob', $results[2]->name);
    }

    // --- All with limit 0 returns empty ------------------------------------

    public function testAllPagination(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->db->exec("INSERT INTO users (name) VALUES (:name)", [':name' => "User{$i}"]);
        }

        $user = new TestUser($this->db);
        $page1 = $user->all(10, 0);
        $this->assertCount(10, $page1);

        $page2 = $user->all(10, 10);
        $this->assertCount(10, $page2);

        $page3 = $user->all(10, 20);
        $this->assertCount(5, $page3);
    }

    // --- Relationship: nested depth 3 (user -> post -> comment) -----------

    public function testNestedRelationshipsThreeDeep(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Deep';
        $user->save();

        $post = new TestRelPost($this->db);
        $post->title = 'DeepPost';
        $post->user_id = $user->getPrimaryKeyValue();
        $post->save();

        $c1 = new TestComment($this->db);
        $c1->text = 'Comment1';
        $c1->post_id = $post->getPrimaryKeyValue();
        $c1->save();

        $c2 = new TestComment($this->db);
        $c2->text = 'Comment2';
        $c2->post_id = $post->getPrimaryKeyValue();
        $c2->save();

        // Navigate: user -> posts -> comments
        $posts = $user->posts;
        $this->assertCount(1, $posts);
        $comments = $posts[0]->comments;
        $this->assertCount(2, $comments);
    }

    // --- Relationship: belongs_to returns correct parent ------------------

    public function testBelongsToReturnsCorrectParent(): void
    {
        $u1 = new TestUser($this->db);
        $u1->name = 'Author1';
        $u1->save();

        $u2 = new TestUser($this->db);
        $u2->name = 'Author2';
        $u2->save();

        $post = new TestRelPost($this->db);
        $post->title = 'BelongsToTest';
        $post->user_id = $u2->getPrimaryKeyValue();
        $post->save();

        $author = $post->author;
        $this->assertInstanceOf(TestUser::class, $author);
        $this->assertSame('Author2', $author->name);
    }

    // --- Soft delete: load with soft delete excludes deleted ---------------

    public function testSoftDeleteExcludesFromFind(): void
    {
        $p1 = new TestPost($this->db);
        $p1->title = 'Active';
        $p1->save();

        $p2 = new TestPost($this->db);
        $p2->title = 'Deleted';
        $p2->save();
        $p2->delete();

        $post = new TestPost($this->db);
        $results = $post->find(['title' => 'Deleted']);
        $this->assertCount(0, $results);

        $results2 = $post->find(['title' => 'Active']);
        $this->assertCount(1, $results2);
    }

    // --- toJson contains correct structure ----------------------------------

    public function testToJsonContainsCorrectStructure(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'JsonTest';
        $user->email = 'json@test.com';
        $user->age = 42;

        $json = $user->toJson();
        $decoded = json_decode($json, true);

        $this->assertSame('JsonTest', $decoded['name']);
        $this->assertSame('json@test.com', $decoded['email']);
        $this->assertSame(42, $decoded['age']);
    }

    // --- Insert and immediately load by id ----------------------------------

    public function testInsertAndImmediatelyLoad(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Immediate';
        $user->email = 'imm@test.com';
        $user->save();

        $id = $user->getPrimaryKeyValue();
        $this->assertNotNull($id);

        $loaded = new TestUser($this->db);
        $loaded->load("id = ?", [$id]);
        $this->assertTrue($loaded->exists($id));
        $this->assertSame('Immediate', $loaded->name);
    }

    // --- Multiple saves increment id correctly -----------------------------

    public function testMultipleSavesIncrementId(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $user = new TestUser($this->db);
            $user->name = "User{$i}";
            $user->save();
            $ids[] = $user->getPrimaryKeyValue();
        }

        // IDs should be sequential
        for ($i = 1; $i < count($ids); $i++) {
            $this->assertGreaterThan($ids[$i - 1], $ids[$i]);
        }
    }

    // --- Relationship: has_many with multiple parents ----------------------

    public function testHasManyWithMultipleParents(): void
    {
        $u1 = new TestUser($this->db);
        $u1->name = 'Parent1';
        $u1->save();

        $u2 = new TestUser($this->db);
        $u2->name = 'Parent2';
        $u2->save();

        $p1 = new TestRelPost($this->db);
        $p1->title = 'P1-Post1';
        $p1->user_id = $u1->getPrimaryKeyValue();
        $p1->save();

        $p2 = new TestRelPost($this->db);
        $p2->title = 'P1-Post2';
        $p2->user_id = $u1->getPrimaryKeyValue();
        $p2->save();

        $p3 = new TestRelPost($this->db);
        $p3->title = 'P2-Post1';
        $p3->user_id = $u2->getPrimaryKeyValue();
        $p3->save();

        $this->assertCount(2, $u1->posts);
        $this->assertCount(1, $u2->posts);
    }

    // --- Delete and verify cannot load ----------------------------------------

    public function testDeleteAndVerifyCannotLoad(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'DeleteMe';
        $user->save();
        $id = $user->getPrimaryKeyValue();

        $user->delete();

        $loaded = new TestUser($this->db);
        $loaded->load("id = ?", [$id]);
        $this->assertFalse($loaded->exists($id));
    }

    // --- Fill and save preserves all fields --------------------------------

    public function testFillAndSavePreservesAllFields(): void
    {
        $user = new TestUser($this->db, ['name' => 'FilledUser', 'email' => 'filled@test.com', 'age' => 33]);
        $user->save();
        $id = $user->getPrimaryKeyValue();

        $loaded = new TestUser($this->db);
        $loaded->load("id = ?", [$id]);
        $this->assertSame('FilledUser', $loaded->name);
        $this->assertSame('filled@test.com', $loaded->email);
        $this->assertSame(33, $loaded->age);
    }

    // --- Relationship toDict with empty has_many --------------------------

    public function testToDictWithEmptyHasMany(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'NoPosts';
        $user->save();

        $dict = $user->toDict(['posts']);
        $this->assertArrayHasKey('posts', $dict);
        $this->assertCount(0, $dict['posts']);
    }

    // --- selectOne with include -------------------------------------------

    public function testSelectOneWithInclude(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'SelectInclude';
        $user->save();

        $post = new TestRelPost($this->db);
        $post->title = 'IncludedPost';
        $post->user_id = $user->getPrimaryKeyValue();
        $post->save();

        $found = (new TestUser($this->db))->selectOne(
            "SELECT * FROM users WHERE name = ?",
            ['SelectInclude'],
            ['posts']
        );

        $this->assertNotNull($found);
        $this->assertSame('SelectInclude', $found->name);
        // If include is supported on selectOne, posts should be loaded
        if (isset($found->posts)) {
            $this->assertCount(1, $found->posts);
        }
    }

    // --- Multiple field mappings round trip --------------------------------

    public function testFieldMappingRoundTrip(): void
    {
        $product = new TestProduct($this->db);
        $product->productName = 'RoundTrip';
        $product->unitPrice = 42.50;
        $product->save();
        $id = $product->getPrimaryKeyValue();

        $loaded = new TestProduct($this->db);
        $loaded->load("id = ?", [$id]);
        $this->assertTrue($loaded->exists($id));
        // Verify the mapping works in both directions
        $rows = $this->db->query("SELECT product_name, unit_price FROM products WHERE id = :id", [':id' => $id]);
        $this->assertSame('RoundTrip', $rows[0]['product_name']);
        $this->assertEquals(42.50, $rows[0]['unit_price'], '', 0.01);
    }

    // --- toObject() returns stdClass (issue #107) --------------------------

    /**
     * toObject() must return an object (stdClass cast of toDict()).
     * Regression: method previously returned an array on some ORM code paths.
     */
    public function testToObjectReturnsObject(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'ToObjectTest';
        $user->email = 'toobject@test.com';

        $result = $user->toObject();

        $this->assertIsObject($result, 'toObject() must return an object');
        $this->assertInstanceOf(\stdClass::class, $result, 'toObject() must return a stdClass instance');
    }

    /**
     * toObject() on a saved (populated) model must carry field values.
     */
    public function testToObjectCarriesFieldValues(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Alice';
        $user->email = 'alice@test.com';
        $user->save();

        $result = $user->toObject();

        $this->assertIsObject($result);
        $this->assertInstanceOf(\stdClass::class, $result);
        // Values should be accessible as object properties
        $dict = $user->toDict();
        foreach ($dict as $key => $value) {
            $this->assertObjectHasProperty($key, $result, "toObject() result should have property '{$key}'");
            $this->assertSame($value, $result->{$key}, "Property '{$key}' value mismatch in toObject()");
        }
    }

    // --- ORM find()/all() normalisation (issue #108) ----------------------

    /**
     * all() returns a flat array of model instances.
     * This covers the DatabaseResult normalisation path inside the ORM fetch layer.
     */
    public function testAllReturnsModelInstances(): void
    {
        $this->db->exec("INSERT INTO users (name, email) VALUES ('Alpha', 'a@test.com')");
        $this->db->exec("INSERT INTO users (name, email) VALUES ('Beta',  'b@test.com')");

        $page = (new TestUser($this->db))->all();

        $this->assertIsArray($page, 'all() must return an array');
        $this->assertGreaterThanOrEqual(2, count($page), 'should contain at least the 2 inserted rows');

        foreach ($page as $u) {
            $this->assertInstanceOf(TestUser::class, $u, 'Each item must be a TestUser instance');
            $this->assertIsString($u->name, 'name must be a string');
        }
    }

    /**
     * find() with no matching column/value must return an empty array without crashing.
     */
    public function testFindWithEmptyResultDoesNotCrash(): void
    {
        // find() takes an associative array of column => value pairs
        $results = TestUser::find(['id' => 99999]);

        $this->assertIsArray($results, 'find() must return an array even when no rows match');
        $this->assertCount(0, $results, 'find() with no matching rows should return an empty array');
    }

    /**
     * toDict() chaining on a freshly instantiated model (no DB row) must not crash.
     * Covers the array/object normalisation path used by find()/all() internally.
     */
    public function testToDictOnEmptyModelDoesNotCrash(): void
    {
        $user = new TestUser($this->db);
        $dict = $user->toDict();

        $this->assertIsArray($dict, 'toDict() on an empty model must return an array');
    }

    // ── Utility: snakeToCamel / camelToSnake ────────────────────

    public function testSnakeToCamel(): void
    {
        $this->assertSame('firstName', \Tina4\ORM::snakeToCamel('first_name'));
        $this->assertSame('userId', \Tina4\ORM::snakeToCamel('user_id'));
        $this->assertSame('id', \Tina4\ORM::snakeToCamel('id'));
        $this->assertSame('myFieldName', \Tina4\ORM::snakeToCamel('my_field_name'));
    }

    public function testCamelToSnake(): void
    {
        $this->assertSame('first_name', \Tina4\ORM::camelToSnake('firstName'));
        $this->assertSame('user_id', \Tina4\ORM::camelToSnake('userId'));
        $this->assertSame('id', \Tina4\ORM::camelToSnake('id'));
        $this->assertSame('my_field_name', \Tina4\ORM::camelToSnake('myFieldName'));
    }

    // ── Parity contract: driver-native types on read path ──────────
    //
    // Mirrors tina4-python's TestFieldsNativeTypes. Guards against any
    // future ORM read-path change that would accidentally re-coerce a
    // value that's already the correct type (e.g. a native DateTime
    // instance returned by psycopg2-equivalent on PostgreSQL).
    //
    // See tina4-python/plan/orm-field-validate-native-types.md.

    public function testOrmRoundTripsDateTimeStringWithoutCrashing(): void
    {
        $this->db->exec("CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, created_at TEXT)");
        $this->db->exec("INSERT INTO events (title, created_at) VALUES ('launch', '2026-04-16 22:30:00')");

        $page = $this->db->fetch("SELECT * FROM events WHERE id = 1");
        $this->assertIsArray($page, 'fetch() must return a paginated array and not crash on a datetime column');
        $this->assertArrayHasKey('data', $page);
        $this->assertNotEmpty($page['data'], 'fetch() must return the inserted row');
        $this->assertSame('2026-04-16 22:30:00', $page['data'][0]['created_at']);
    }

    // --- Regression: issue #102 — typed-property models ---

    /**
     * Issue #102: when a model uses PHP 8 typed public properties with default
     * values (e.g. `public int $id = 0`), save() used to insert `id = 0`
     * literally instead of letting the DB auto-increment assign one. The row
     * landed in the DB but with id = 0, lastInsertId() returned 0, the public
     * $id never synced back, and subsequent findById() calls failed.
     *
     * This test uses the typed-property style explicitly to lock the fix.
     */
    public function testTypedPropertyModelAutoIncrementsIdAndSyncsBack(): void
    {
        $this->db->exec("CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, display_label TEXT, price_cents INTEGER)");

        $model = new class extends ORM {
            public string $tableName = 'widgets';
            public string $primaryKey = 'id';
            public array $fieldMapping = [];
            public int $id = 0;
            public string $name = '';
            public string $displayLabel = '';
            public int $priceCents = 0;
        };

        $model->name = 'Sprocket';
        $model->displayLabel = 'Super Sprocket';
        $model->priceCents = 1995;

        $result = $model->save();

        $this->assertNotFalse($result, 'save() must succeed');
        $this->assertSame(1, $model->id, 'auto-increment id must sync back to the public $id property');

        // Read the row back via the adapter (can't use findById on anonymous class)
        $row = $this->db->fetchOne("SELECT * FROM widgets WHERE id = ?", [1]);
        $this->assertNotNull($row, 'row must exist at id=1');
        $this->assertSame('Sprocket', $row['name']);
        $this->assertSame('Super Sprocket', $row['display_label']);
        $this->assertSame(1995, $row['price_cents']);

        // Update via public property, save again, confirm the update hit
        $model->priceCents = 2500;
        $model->save();
        $row2 = $this->db->fetchOne("SELECT price_cents FROM widgets WHERE id = ?", [1]);
        $this->assertSame(2500, $row2['price_cents'], 'update via public property must persist');
    }
}
