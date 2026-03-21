<?php

/**
 * Tina4 - This is not a 4ramework.
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

        $this->assertTrue($result);
        $this->assertNotNull($user->getPrimaryKeyValue());
        $this->assertTrue($user->exists());
    }

    public function testLoad(): void
    {
        $this->db->exec("INSERT INTO users (name, email) VALUES ('Alice', 'alice@test.com')");

        $user = new TestUser($this->db);
        $user->load(1);

        $this->assertTrue($user->exists());
        $this->assertSame('Alice', $user->name);
        $this->assertSame('alice@test.com', $user->email);
    }

    public function testLoadNonexistent(): void
    {
        $user = new TestUser($this->db);
        $user->load(999);

        $this->assertFalse($user->exists());
    }

    public function testUpdate(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Alice';
        $user->email = 'alice@test.com';
        $user->save();

        $id = $user->getPrimaryKeyValue();

        $user2 = new TestUser($this->db);
        $user2->load($id);
        $user2->name = 'Alice Updated';
        $user2->save();

        $user3 = new TestUser($this->db);
        $user3->load($id);
        $this->assertSame('Alice Updated', $user3->name);
    }

    public function testDelete(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Alice';
        $user->save();
        $id = $user->getPrimaryKeyValue();

        $result = $user->delete();
        $this->assertTrue($result);

        $user2 = new TestUser($this->db);
        $user2->load($id);
        $this->assertFalse($user2->exists());
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
        $post2->load($id);
        $this->assertFalse($post2->exists());
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

    // --- All ---

    public function testAll(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->db->exec("INSERT INTO users (name) VALUES (:name)", [':name' => "User{$i}"]);
        }

        $user = new TestUser($this->db);
        $result = $user->all(10, 0);

        $this->assertCount(10, $result['data']);
        $this->assertSame(15, $result['total']);
        $this->assertSame(10, $result['limit']);
        $this->assertSame(0, $result['offset']);
    }

    public function testAllSecondPage(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->db->exec("INSERT INTO users (name) VALUES (:name)", [':name' => "User{$i}"]);
        }

        $user = new TestUser($this->db);
        $result = $user->all(10, 10);

        $this->assertCount(5, $result['data']);
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
        $product->load(1);

        // Reverse mapping: DB columns map back to PHP properties
        $this->assertTrue($product->exists());
    }

    // --- Serialization ---

    public function testToArray(): void
    {
        $user = new TestUser($this->db);
        $user->name = 'Alice';
        $user->email = 'alice@test.com';

        $arr = $user->toArray();
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

        $user = new TestUser();
        $user->name = 'Alice';
        $user->save();
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
        $loaded->load($id);
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
}
