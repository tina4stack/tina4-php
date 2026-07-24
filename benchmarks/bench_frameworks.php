<?php

declare(strict_types=1);

/**
 * Framework Comparison: tina4-php vs raw PDO vs Eloquent vs Doctrine DBAL.
 *
 * The PHP counterpart of tina4-ruby/benchmarks/bench_frameworks.rb. It measures
 * database CRUD performance against real competitor libraries on the SAME
 * SQLite engine, under the SAME rules, so the numbers are comparable:
 *
 *   - Equal work: every framework materialises the SAME number of rows for
 *     "Select ALL" (Tina4's fetch defaults to LIMIT 100 -- pass ALL_ROWS), and
 *     an equal-work gate below WITHHOLDS the performance table if the row counts
 *     disagree. A rigged row is worse than no row.
 *   - Equal SQLite settings: WAL + foreign_keys ON on every connection, so the
 *     write rows compare frameworks, not journal modes.
 *   - Median of ITERATIONS after an untimed warm-up (not a mean, not a cold
 *     first sample) -- one flush stall must not move the number.
 *
 * The competitor libraries (illuminate/database, doctrine/dbal) live in this
 * directory's own composer.json, NOT the framework's zero-dependency
 * composer.json. Run:
 *
 *   cd benchmarks && composer install && php bench_frameworks.php
 *
 * A framework whose library is not installed is skipped cleanly (its row simply
 * does not appear) -- raw PDO and Tina4 always run.
 */

// ---- Autoloaders: Tina4 (parent) + competitors (this dir) -------------------
require __DIR__ . '/../vendor/autoload.php';           // Tina4 framework under test
$competitorAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($competitorAutoload)) {
    require $competitorAutoload;
}

const NUM_ROWS   = 5000;
const ITERATIONS = 20;
const LIMIT      = 20;
// Explicit "give me everything" bound for read APIs that apply a default cap
// (Tina4's fetch defaults to 100). Every "select all" must return the same rows.
const ALL_ROWS   = NUM_ROWS * 2;

const CITIES = ['NewYork', 'London', 'Tokyo', 'Paris', 'Berlin',
                'Sydney', 'Toronto', 'Mumbai', 'SaoPaulo', 'Cairo'];

/** Deterministic seed data so every framework inserts identical rows. */
function generateUsers(int $n): array
{
    mt_srand(42);
    $rows = [];
    for ($i = 1; $i <= $n; $i++) {
        $rows[] = [
            'name'   => substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyz', 2)), 0, 10),
            'email'  => 'user' . $i . '@example.com',
            'age'    => mt_rand(18, 80),
            'city'   => CITIES[array_rand(CITIES)],
            'active' => mt_rand(0, 1),
        ];
    }
    return $rows;
}

/** Comma-format an integer (PHP has number_format but this keeps intent clear). */
function commafy(float $n): string
{
    return number_format($n);
}

abstract class FrameworkBench
{
    public string $name;
    protected string $dbPath;
    protected array $users;

    // The SQLite settings every framework must share, so writes compare the
    // framework and not the journal mode. Tina4 sets these itself on connect;
    // the raw/competitor connections apply them via applyEqualPragmas().
    protected const EQUAL_PRAGMAS = [
        'PRAGMA journal_mode=WAL',
        'PRAGMA foreign_keys=ON',
    ];

    public function __construct(string $name)
    {
        $this->name   = $name;
        $this->dbPath = sys_get_temp_dir() . '/tina4_bench_' . str_replace([' ', '/'], '_', strtolower($name)) . '_' . getmypid() . '.db';
        @unlink($this->dbPath);
        $this->users = generateUsers(NUM_ROWS);
    }

    /** Median of ITERATIONS timings (ms) after one untimed warm-up. */
    protected function bench(callable $op): float
    {
        $op(); // warm-up, untimed
        $times = [];
        for ($i = 0; $i < ITERATIONS; $i++) {
            $t0 = hrtime(true);
            $op();
            $times[] = (hrtime(true) - $t0) / 1e6; // ns -> ms
        }
        sort($times);
        return $times[intdiv(count($times), 2)];
    }

    /** How many rows this framework's "Select all" actually materialises. */
    abstract public function selectAllRowCount(): int;

    abstract public function setup(): void;
    abstract public function cleanup(): void;

    abstract public function benchInsertSingle(): float;
    abstract public function benchInsertBulk(): float;
    abstract public function benchSelectAll(): float;
    abstract public function benchSelectFiltered(): float;
    abstract public function benchSelectPaginated(): float;
    abstract public function benchUpdate(): float;
    abstract public function benchDelete(): float;

    public function benchmarks(): array
    {
        return [
            'Insert (single)'   => fn() => $this->benchInsertSingle(),
            'Insert (100 bulk)' => fn() => $this->benchInsertBulk(),
            'Select ALL rows'   => fn() => $this->benchSelectAll(),
            'Select filtered'   => fn() => $this->benchSelectFiltered(),
            'Select paginated'  => fn() => $this->benchSelectPaginated(),
            'Update (by PK)'    => fn() => $this->benchUpdate(),
            'Delete (by PK)'    => fn() => $this->benchDelete(),
        ];
    }
}

// ---------------------------------------------------------------------------
// 1. Raw PDO -- the floor
// ---------------------------------------------------------------------------
class RawPdoBench extends FrameworkBench
{
    private \PDO $db;

    public function __construct()
    {
        parent::__construct('Raw PDO');
    }

    public function setup(): void
    {
        $this->db = new \PDO('sqlite:' . $this->dbPath);
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        foreach (self::EQUAL_PRAGMAS as $p) {
            $this->db->exec($p);
        }
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, age INTEGER, city TEXT, active INTEGER)');
        $this->db->beginTransaction();
        $stmt = $this->db->prepare('INSERT INTO users (name,email,age,city,active) VALUES (?,?,?,?,?)');
        foreach ($this->users as $u) {
            $stmt->execute([$u['name'], $u['email'], $u['age'], $u['city'], $u['active']]);
        }
        $this->db->commit();
    }

    public function cleanup(): void
    {
        unset($this->db);
        @unlink($this->dbPath);
    }

    public function selectAllRowCount(): int
    {
        return count($this->db->query('SELECT * FROM users')->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function benchInsertSingle(): float
    {
        $ins = $this->db->prepare('INSERT INTO users (name,email,age,city,active) VALUES (?,?,?,?,?)');
        return $this->bench(function () use ($ins) {
            $ins->execute(['x', 'x@x.com', 25, 'Test', 1]);
            $this->db->exec('DELETE FROM users WHERE id > ' . NUM_ROWS);
        });
    }

    public function benchInsertBulk(): float
    {
        $ins = $this->db->prepare('INSERT INTO users (name,email,age,city,active) VALUES (?,?,?,?,?)');
        return $this->bench(function () use ($ins) {
            $this->db->beginTransaction();
            for ($i = 0; $i < 100; $i++) {
                $ins->execute(['x', 'x@x.com', 25, 'Test', 1]);
            }
            $this->db->commit();
            $this->db->exec('DELETE FROM users WHERE id > ' . NUM_ROWS);
        });
    }

    public function benchSelectAll(): float
    {
        return $this->bench(fn() => $this->db->query('SELECT * FROM users')->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function benchSelectFiltered(): float
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE age > ? AND city = ?');
        return $this->bench(function () use ($stmt) {
            $stmt->execute([30, 'London']);
            $stmt->fetchAll(\PDO::FETCH_ASSOC);
        });
    }

    public function benchSelectPaginated(): float
    {
        return $this->bench(fn() => $this->db->query('SELECT * FROM users LIMIT ' . LIMIT . ' OFFSET 100')->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function benchUpdate(): float
    {
        $upd = $this->db->prepare('UPDATE users SET age = ? WHERE id = ?');
        return $this->bench(fn() => $upd->execute([99, mt_rand(1, NUM_ROWS)]));
    }

    public function benchDelete(): float
    {
        $ins = $this->db->prepare('INSERT INTO users (name,email,age,city,active) VALUES (?,?,?,?,?)');
        return $this->bench(function () use ($ins) {
            $ins->execute(['del', 'del@x.com', 20, 'Test', 1]);
            $this->db->exec('DELETE FROM users WHERE id > ' . NUM_ROWS);
        });
    }
}

// ---------------------------------------------------------------------------
// 2. Tina4 -- the framework under test (uses its own Database adapter)
// ---------------------------------------------------------------------------
class Tina4Bench extends FrameworkBench
{
    private \Tina4\Database\Database $db;

    public function __construct()
    {
        parent::__construct('tina4_php');
    }

    public function setup(): void
    {
        // Tina4 sets journal_mode=WAL + foreign_keys=ON itself on connect.
        $this->db = \Tina4\Database\Database::create('sqlite:///' . $this->dbPath);
        $this->db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, age INTEGER, city TEXT, active INTEGER)');
        $this->db->startTransaction();
        foreach ($this->users as $u) {
            $this->db->insert('users', $u);
        }
        $this->db->commit();
    }

    public function cleanup(): void
    {
        $this->db->close();
        @unlink($this->dbPath);
    }

    private function rows($result): array
    {
        return $result instanceof \Tina4\Database\DatabaseResult ? $result->records : (array) $result;
    }

    public function selectAllRowCount(): int
    {
        // Explicit ALL_ROWS: without it Tina4's fetch returns only 100 of 5000,
        // which would silently make this a 50x-less-work "win".
        return count($this->rows($this->db->fetch('SELECT * FROM users', [], ALL_ROWS)));
    }

    public function benchInsertSingle(): float
    {
        return $this->bench(function () {
            $this->db->insert('users', ['name' => 'x', 'email' => 'x@x.com', 'age' => 25, 'city' => 'Test', 'active' => 1]);
            $this->db->execute('DELETE FROM users WHERE id > ?', [NUM_ROWS]);
        });
    }

    public function benchInsertBulk(): float
    {
        return $this->bench(function () {
            $this->db->startTransaction();
            for ($i = 0; $i < 100; $i++) {
                $this->db->insert('users', ['name' => 'x', 'email' => 'x@x.com', 'age' => 25, 'city' => 'Test', 'active' => 1]);
            }
            $this->db->commit();
            $this->db->execute('DELETE FROM users WHERE id > ?', [NUM_ROWS]);
        });
    }

    public function benchSelectAll(): float
    {
        return $this->bench(fn() => $this->rows($this->db->fetch('SELECT * FROM users', [], ALL_ROWS)));
    }

    public function benchSelectFiltered(): float
    {
        return $this->bench(fn() => $this->rows($this->db->fetch('SELECT * FROM users WHERE age > ? AND city = ?', [30, 'London'], ALL_ROWS)));
    }

    public function benchSelectPaginated(): float
    {
        return $this->bench(fn() => $this->rows($this->db->fetch('SELECT * FROM users', [], LIMIT, 100)));
    }

    public function benchUpdate(): float
    {
        return $this->bench(fn() => $this->db->update('users', ['age' => 99], 'id = ?', [mt_rand(1, NUM_ROWS)]));
    }

    public function benchDelete(): float
    {
        return $this->bench(function () {
            $this->db->insert('users', ['name' => 'del', 'email' => 'del@x.com', 'age' => 20, 'city' => 'Test', 'active' => 1]);
            $this->db->execute('DELETE FROM users WHERE id > ?', [NUM_ROWS]);
        });
    }
}

// ---------------------------------------------------------------------------
// 3. Eloquent (illuminate/database) -- real ORM models, like Ruby's ActiveRecord
// ---------------------------------------------------------------------------
class EloquentBench extends FrameworkBench
{
    private $capsule;

    public function __construct()
    {
        parent::__construct('Eloquent');
    }

    public function setup(): void
    {
        if (!class_exists(\Illuminate\Database\Capsule\Manager::class)) {
            throw new \RuntimeException('illuminate/database not installed');
        }
        // Laravel 11's SQLite connector calls the app helper base_path() to
        // resolve a database file that does not yet exist -- undefined when
        // Eloquent runs standalone. Pre-create the (absolute) file so the
        // connector uses it directly instead of trying to resolve it.
        touch($this->dbPath);
        $this->capsule = new \Illuminate\Database\Capsule\Manager();
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => $this->dbPath, 'prefix' => '']);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $conn = $this->capsule->getConnection();
        foreach (self::EQUAL_PRAGMAS as $p) {
            $conn->statement($p);
        }
        $conn->statement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, age INTEGER, city TEXT, active INTEGER)');
        $conn->transaction(function () use ($conn) {
            foreach ($this->users as $u) {
                $conn->table('users')->insert($u);
            }
        });
    }

    public function cleanup(): void
    {
        @unlink($this->dbPath);
    }

    public function selectAllRowCount(): int
    {
        return \BenchUser::all()->count();
    }

    public function benchInsertSingle(): float
    {
        $conn = $this->capsule->getConnection();
        return $this->bench(function () use ($conn) {
            \BenchUser::create(['name' => 'x', 'email' => 'x@x.com', 'age' => 25, 'city' => 'Test', 'active' => 1]);
            $conn->statement('DELETE FROM users WHERE id > ' . NUM_ROWS);
        });
    }

    public function benchInsertBulk(): float
    {
        $conn = $this->capsule->getConnection();
        return $this->bench(function () use ($conn) {
            $conn->transaction(function () {
                for ($i = 0; $i < 100; $i++) {
                    \BenchUser::create(['name' => 'x', 'email' => 'x@x.com', 'age' => 25, 'city' => 'Test', 'active' => 1]);
                }
            });
            $conn->statement('DELETE FROM users WHERE id > ' . NUM_ROWS);
        });
    }

    public function benchSelectAll(): float
    {
        return $this->bench(fn() => \BenchUser::all()->all());
    }

    public function benchSelectFiltered(): float
    {
        return $this->bench(fn() => \BenchUser::where('age', '>', 30)->where('city', 'London')->get()->all());
    }

    public function benchSelectPaginated(): float
    {
        return $this->bench(fn() => \BenchUser::query()->limit(LIMIT)->offset(100)->get()->all());
    }

    public function benchUpdate(): float
    {
        return $this->bench(fn() => \BenchUser::where('id', mt_rand(1, NUM_ROWS))->update(['age' => 99]));
    }

    public function benchDelete(): float
    {
        $conn = $this->capsule->getConnection();
        return $this->bench(function () use ($conn) {
            \BenchUser::create(['name' => 'del', 'email' => 'del@x.com', 'age' => 20, 'city' => 'Test', 'active' => 1]);
            $conn->statement('DELETE FROM users WHERE id > ' . NUM_ROWS);
        });
    }
}

// ---------------------------------------------------------------------------
// 4. Doctrine DBAL -- query-builder / DBAL level, like Ruby's Sequel
// ---------------------------------------------------------------------------
class DoctrineDbalBench extends FrameworkBench
{
    private $conn;

    public function __construct()
    {
        parent::__construct('Doctrine DBAL');
    }

    public function setup(): void
    {
        if (!class_exists(\Doctrine\DBAL\DriverManager::class)) {
            throw new \RuntimeException('doctrine/dbal not installed');
        }
        $this->conn = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbPath]);
        foreach (self::EQUAL_PRAGMAS as $p) {
            $this->conn->executeStatement($p);
        }
        $this->conn->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, age INTEGER, city TEXT, active INTEGER)');
        $this->conn->transactional(function ($conn) {
            foreach ($this->users as $u) {
                $conn->insert('users', $u);
            }
        });
    }

    public function cleanup(): void
    {
        @unlink($this->dbPath);
    }

    public function selectAllRowCount(): int
    {
        return count($this->conn->executeQuery('SELECT * FROM users')->fetchAllAssociative());
    }

    public function benchInsertSingle(): float
    {
        return $this->bench(function () {
            $this->conn->insert('users', ['name' => 'x', 'email' => 'x@x.com', 'age' => 25, 'city' => 'Test', 'active' => 1]);
            $this->conn->executeStatement('DELETE FROM users WHERE id > ' . NUM_ROWS);
        });
    }

    public function benchInsertBulk(): float
    {
        return $this->bench(function () {
            $this->conn->transactional(function ($conn) {
                for ($i = 0; $i < 100; $i++) {
                    $conn->insert('users', ['name' => 'x', 'email' => 'x@x.com', 'age' => 25, 'city' => 'Test', 'active' => 1]);
                }
            });
            $this->conn->executeStatement('DELETE FROM users WHERE id > ' . NUM_ROWS);
        });
    }

    public function benchSelectAll(): float
    {
        return $this->bench(fn() => $this->conn->executeQuery('SELECT * FROM users')->fetchAllAssociative());
    }

    public function benchSelectFiltered(): float
    {
        return $this->bench(fn() => $this->conn->executeQuery('SELECT * FROM users WHERE age > ? AND city = ?', [30, 'London'])->fetchAllAssociative());
    }

    public function benchSelectPaginated(): float
    {
        return $this->bench(fn() => $this->conn->executeQuery('SELECT * FROM users LIMIT ' . LIMIT . ' OFFSET 100')->fetchAllAssociative());
    }

    public function benchUpdate(): float
    {
        return $this->bench(fn() => $this->conn->update('users', ['age' => 99], ['id' => mt_rand(1, NUM_ROWS)]));
    }

    public function benchDelete(): float
    {
        return $this->bench(function () {
            $this->conn->insert('users', ['name' => 'del', 'email' => 'del@x.com', 'age' => 20, 'city' => 'Test', 'active' => 1]);
            $this->conn->executeStatement('DELETE FROM users WHERE id > ' . NUM_ROWS);
        });
    }
}

// Eloquent model (defined only if Eloquent is available).
if (class_exists(\Illuminate\Database\Eloquent\Model::class)) {
    class BenchUser extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'users';
        public $timestamps = false;
        protected $guarded = [];
    }
}

// ---------------------------------------------------------------------------
// main
// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 100) . "\n";
echo "  PHP FRAMEWORK COMPARISON: tina4_php vs Raw PDO vs Eloquent vs Doctrine DBAL\n";
echo str_repeat('=', 100) . "\n";
printf("  DB Benchmark: %d users | %d iterations | SQLite (WAL, same for all)\n\n", NUM_ROWS, ITERATIONS);
echo "  PART 1: DATABASE PERFORMANCE (ms per operation, median, lower is better)\n";
echo str_repeat('=', 100) . "\n\n";

$classes = [RawPdoBench::class, Tina4Bench::class, EloquentBench::class, DoctrineDbalBench::class];
$order = [];
$results = [];
$rowCounts = [];
$benchNames = [];

foreach ($classes as $class) {
    try {
        $fw = new $class();
    } catch (\Throwable $e) {
        echo "  [{$class}] FAILED to init: {$e->getMessage()}\n";
        continue;
    }
    echo "  [{$fw->name}] Setting up...\n";
    try {
        $fw->setup();
    } catch (\Throwable $e) {
        echo "  [{$fw->name}] SKIP (setup failed: {$e->getMessage()})\n";
        continue;
    }

    try {
        $rowCounts[$fw->name] = $fw->selectAllRowCount();
        printf("  [%s] Select-all materialises %d rows\n", $fw->name, $rowCounts[$fw->name]);
    } catch (\Throwable $e) {
        $rowCounts[$fw->name] = null;
        echo "  [{$fw->name}] could not report Select-all row count: {$e->getMessage()}\n";
    }

    $order[] = $fw->name;
    $results[$fw->name] = [];
    foreach ($fw->benchmarks() as $label => $op) {
        try {
            $results[$fw->name][$label] = $op();
        } catch (\Throwable $e) {
            $results[$fw->name][$label] = null;
            printf("    %-20s FAILED: %s\n", $label, $e->getMessage());
        }
    }
    if (empty($benchNames)) {
        $benchNames = array_keys($fw->benchmarks());
    }
    $fw->cleanup();
}

// ---- Equal-work gate --------------------------------------------------------
$seen = array_values(array_unique(array_filter($rowCounts, fn($c) => $c !== null)));
if (count($seen) > 1) {
    echo "\n  !! EQUAL-WORK CHECK FAILED - performance table withheld.\n";
    foreach ($rowCounts as $n => $c) {
        printf("     %-16s Select-all rows: %s\n", $n, var_export($c, true));
    }
    echo "     The frameworks materialised different row counts, so these timings\n";
    echo "     are not comparable. Fix the read call that truncates, then re-run.\n\n";
    exit(1);
}

// ---- Performance table ------------------------------------------------------
echo "\n" . str_repeat('-', 100) . "\n";
printf("  %-22s", 'Operation');
foreach ($order as $name) {
    printf("%14s", $name);
}
echo "\n" . str_repeat('-', 100) . "\n";

foreach ($benchNames as $label) {
    printf("  %-22s", $label);
    // find fastest for the * marker
    $best = null;
    foreach ($order as $name) {
        $v = $results[$name][$label] ?? null;
        if ($v !== null && ($best === null || $v < $best)) {
            $best = $v;
        }
    }
    foreach ($order as $name) {
        $v = $results[$name][$label] ?? null;
        if ($v === null) {
            printf('%14s', 'FAIL');
        } else {
            $mark = ($v === $best) ? ' *' : '  ';
            printf('%12.3f%s', $v, $mark);
        }
    }
    echo "\n";
}
echo str_repeat('-', 100) . "\n";
echo "  * = fastest\n\n";

// ---- Overhead vs raw PDO ----------------------------------------------------
$baseline = 'Raw PDO';
if (isset($results[$baseline])) {
    echo "  OVERHEAD vs Raw PDO (avg across all operations):\n";
    $baseAvg = array_sum(array_filter($results[$baseline], fn($v) => $v !== null)) / max(1, count(array_filter($results[$baseline], fn($v) => $v !== null)));
    foreach ($order as $name) {
        if ($name === $baseline) {
            continue;
        }
        $vals = array_filter($results[$name], fn($v) => $v !== null);
        if (empty($vals)) {
            continue;
        }
        $avg = array_sum($vals) / count($vals);
        $pct = ($avg / $baseAvg - 1) * 100;
        printf("    %-16s %+.1f%%\n", $name, $pct);
    }
    echo "\n";
}
