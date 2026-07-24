<?php

/**
 * Tina4 v3 Carbon Benchmarks (PHP) - 9 workload categories.
 *
 * Run all:      php benchmarks/carbon_benchmarks.php
 * Run one:      php benchmarks/carbon_benchmarks.php json
 * Startup cost: php benchmarks/carbon_benchmarks.php --startup
 * Carbon (SCI): php benchmarks/carbon_benchmarks.php --carbon
 * Categories:   json, db_single, db_multi, template, json_large,
 *               plaintext, crud, paginated, startup
 *
 * By default this reports WALL-CLOCK time and throughput. `--carbon` shells out
 * to the real Carbonah CLI for Software Carbon Intensity; `--startup` spawns
 * fresh PHP processes to measure per-process boot cost, which no in-process loop
 * can see (the autoloader only reads each file once).
 *
 * Parity: this is the PHP half of the cross-framework suite. The workloads,
 * iteration counts, SQL, JSON payloads and the Twig template are deliberately
 * IDENTICAL to tina4-python/benchmarks/carbon_benchmarks.py so the numbers are
 * comparable between languages rather than merely coexisting. Verified: PHP
 * Frond renders the same template with the same filters (upper, number_format,
 * truncate) and the same loop.even/loop.index semantics.
 */

require __DIR__ . '/../vendor/autoload.php';

use Tina4\Database\Database;
use Tina4\Frond;
use Tina4\Response;

/**
 * Nominal iteration count, still used for --single (the carbonah-wrapped form,
 * which needs a fixed amount of work rather than a fixed duration).
 */
const ITERATIONS = 1000;

/** Timed runs keep going until this much wall-clock has elapsed. */
const MIN_SECONDS = 0.25;

/** ...but never fewer than this many iterations, however fast the operation. */
const MIN_ITERATIONS = 200;

/** A throwaway sqlite file, cleaned up by the caller. */
function benchTempDb(): string
{
    $dir = sys_get_temp_dir() . '/tina4-bench-' . getmypid() . '-' . random_int(1000, 9999);
    @mkdir($dir, 0777, true);
    return $dir;
}

function benchCleanup(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}

// ── 1. JSON serialization - raw overhead ───────────────────────

function benchJson(): array
{
    $payload = ['message' => 'Hello, World!', 'status' => 'ok'];
    return [static function () use ($payload): void {
        (new Response(true))->json($payload);
    }, null];
}

// ── 2. Single database query ───────────────────────────────────

function benchDbSingle(): array
{
    $dir = benchTempDb();
    $db = Database::create("sqlite:///{$dir}/bench.db");
    $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
    $db->execute("INSERT INTO users VALUES (1, 'Alice', 'alice@test.com')");
    $db->commit();
    return [
        static function () use ($db): void {
            $db->fetchOne('SELECT * FROM users WHERE id = ?', [1]);
        },
        static function () use ($db, $dir): void {
            $db->close();
            benchCleanup($dir);
        },
    ];
}

// ── 3. Multiple database queries ───────────────────────────────

function benchDbMulti(): array
{
    $dir = benchTempDb();
    $db = Database::create("sqlite:///{$dir}/bench.db");
    $db->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT, price REAL)');
    for ($i = 0; $i < 100; $i++) {
        $db->execute('INSERT INTO items VALUES (?, ?, ?)', [$i, "Item {$i}", $i * 1.5]);
    }
    $db->commit();
    return [
        static function () use ($db): void {
            $db->fetch('SELECT * FROM items WHERE price > ?', [50.0], 20);
            $db->fetchOne('SELECT COUNT(*) as cnt FROM items');
            $db->fetch('SELECT * FROM items ORDER BY price DESC', [], 5);
        },
        static function () use ($db, $dir): void {
            $db->close();
            benchCleanup($dir);
        },
    ];
}

// ── 4. Template rendering ──────────────────────────────────────

function benchTemplate(): array
{
    $dir = benchTempDb();
    $engine = new Frond($dir);
    $tpl = <<<'TPL'
<!DOCTYPE html>
<html>
<head><title>{{ title }}</title></head>
<body>
<h1>{{ heading }}</h1>
<ul>
{% for item in items %}
<li class="{{ loop.even ? 'even' : 'odd' }}">{{ loop.index }}. {{ item.name | upper }} - ${{ item.price | number_format(2) }}</li>
{% endfor %}
</ul>
{% if show_footer %}
<footer>{{ footer_text | truncate(50) }}</footer>
{% endif %}
</body>
</html>
TPL;
    $items = [];
    for ($i = 0; $i < 20; $i++) {
        $items[] = ['name' => "Product {$i}", 'price' => $i * 9.99];
    }
    $data = [
        'title' => 'Benchmark Page',
        'heading' => 'Product List',
        'items' => $items,
        'show_footer' => true,
        'footer_text' => 'This is a footer with some text that may be truncated for display purposes.',
    ];
    // render() from a FILE rather than renderString(): it is the per-request call a
    // real app makes, and the honest counterpart to a compiled-template comparison.
    //
    // Corrects an earlier comment here that justified this as "renderString
    // recompiles on every call (Frond has no compiled-template cache)". Frond DOES
    // cache compiled tokens on both paths, and measured in the Python twin
    // tokenizing is only 1.9% of a full render. The reason to pick render(file) is
    // fidelity to real usage, not compile overhead.
    file_put_contents($dir . '/bench.twig', $tpl);
    return [
        static function () use ($engine, $data): void {
            $engine->render('bench.twig', $data);
        },
        static function () use ($dir): void {
            benchCleanup($dir);
        },
    ];
}

// ── 5. Large JSON payload ──────────────────────────────────────

function benchJsonLarge(): array
{
    $users = [];
    for ($i = 0; $i < 100; $i++) {
        $users[] = [
            'id' => $i,
            'name' => "User {$i}",
            'email' => "user{$i}@test.com",
            'active' => $i % 2 === 0,
            'score' => $i * 1.5,
            'tags' => ['tag1', 'tag2', 'tag3'],
            'address' => ['street' => "{$i} Main St", 'city' => 'TestCity', 'zip' => (string)(10000 + $i)],
        ];
    }
    $payload = ['users' => $users, 'meta' => ['total' => 100, 'page' => 1, 'per_page' => 100]];
    return [static function () use ($payload): void {
        (new Response(true))->json($payload);
    }, null];
}

// ── 6. Plaintext response ──────────────────────────────────────

function benchPlaintext(): array
{
    return [static function (): void {
        (new Response(true))->html('Hello, World!');
    }, null];
}

// ── 7. Full CRUD cycle ─────────────────────────────────────────

function benchCrud(): array
{
    $dir = benchTempDb();
    $db = Database::create("sqlite:///{$dir}/bench.db");
    $db->execute('CREATE TABLE tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, done INTEGER DEFAULT 0)');
    $db->commit();
    // One measured op is ONE full create/read/update/delete cycle. The old code
    // did ITERATIONS/10 cycles inside a single timed call, so the reported
    // "ops/sec" counted tenths of a cycle.
    return [
        static function () use ($db): void {
            $db->insert('tasks', ['title' => 'Benchmark task', 'done' => 0]);
            $taskId = $db->getLastId();
            $db->fetchOne('SELECT * FROM tasks WHERE id = ?', [$taskId]);
            $db->update('tasks', ['done' => 1], 'id = ?', [$taskId]);
            $db->delete('tasks', 'id = ?', [$taskId]);
            $db->commit();
        },
        static function () use ($db, $dir): void {
            $db->close();
            benchCleanup($dir);
        },
    ];
}

// ── 8. Paginated query with count ──────────────────────────────

function benchPaginated(): array
{
    $dir = benchTempDb();
    $db = Database::create("sqlite:///{$dir}/bench.db");
    $db->execute('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, category TEXT, price REAL)');
    for ($i = 0; $i < 500; $i++) {
        $db->execute('INSERT INTO products VALUES (?, ?, ?, ?)',
            [$i, "Product {$i}", 'Cat ' . ($i % 10), $i * 2.5]);
    }
    $db->commit();
    return [
        static function () use ($db): void {
            $result = $db->fetch('SELECT * FROM products WHERE category = ?', ['Cat 3'], 20, 0);
            if ($result instanceof \Tina4\Database\DatabaseResult) {
                $result->toArray();
            }
        },
        static function () use ($db, $dir): void {
            $db->close();
            benchCleanup($dir);
        },
    ];
}

// ── 9. Framework startup ───────────────────────────────────────

/**
 * Runs the work ONCE in this process.
 *
 * Boot cost cannot be measured by looping: the autoloader reads each file once,
 * so a second pass is a already-declared check, not a load. The Python suite had
 * exactly this bug (it looped 100 imports and reported ~400us per "startup"
 * while the real import cost was 79ms). `--startup` measures the real thing by
 * spawning fresh processes.
 */
function benchStartup(): array
{
    // Returned op is the one-shot boot work; the runner special-cases "startup"
    // and calls it exactly once (looping it would measure already-loaded
    // class_exists lookups, which is the bug the Python suite had).
    $op = static function (): void {
        // Touch the surface a real app boot touches. PSR-4 is lazy, so naming these
        // is what actually pulls them off disk.
        class_exists(\Tina4\Router::class);
        class_exists(\Tina4\Request::class);
        class_exists(\Tina4\Response::class);
        class_exists(\Tina4\Frond::class);
        class_exists(\Tina4\Auth::class);
        class_exists(\Tina4\Session::class);
        class_exists(\Tina4\Swagger::class);
        class_exists(\Tina4\Queue::class);
        class_exists(\Tina4\Api::class);
        class_exists(\Tina4\FakeData::class);
        class_exists(\Tina4\I18n::class);
        class_exists(\Tina4\GraphQL::class);
        class_exists(\Tina4\WSDL::class);
        class_exists(\Tina4\Messenger::class);
        class_exists(\Tina4\HtmlElement::class);
        class_exists(\Tina4\Middleware\CorsMiddleware::class);
        class_exists(\Tina4\Middleware\RateLimiter::class);
        class_exists(\Tina4\Middleware\ResponseCache::class);

        new \Tina4\Middleware\CorsMiddleware();
        new \Tina4\Auth();
        new \Tina4\Swagger();
        new \Tina4\GraphQL();
    };
    return [$op, null];
}

// ── Runner ─────────────────────────────────────────────────────

const BENCHMARKS = [
    'json'       => ['JSON Hello World',    'benchJson'],
    'db_single'  => ['Single DB Query',     'benchDbSingle'],
    'db_multi'   => ['Multiple DB Queries', 'benchDbMulti'],
    'template'   => ['Template Rendering',  'benchTemplate'],
    'json_large' => ['Large JSON Payload',  'benchJsonLarge'],
    'plaintext'  => ['Plaintext Response',  'benchPlaintext'],
    'crud'       => ['CRUD Cycle',          'benchCrud'],
    'paginated'  => ['Paginated Query',     'benchPaginated'],
    'startup'    => ['Framework Startup',   'benchStartup'],
];

/**
 * Run one benchmark: setup and teardown OUTSIDE the clock, op timed in a loop
 * that runs until MIN_SECONDS has elapsed.
 *
 * The previous version timed the whole bench function, so per-benchmark setup
 * sat inside the measurement. Measured on this machine, benchDbSingle's setup
 * (temp dir + sqlite file + CREATE TABLE + INSERT + commit) cost 11.20ms against
 * 4.26ms for the 1,000 reads it was supposedly measuring -- 72% of the reported
 * time was setup, understating read throughput by 3.6x (64,683 vs a true
 * 234,686 ops/sec). Same class of bug as the Python compare harness timing its
 * own imports.
 *
 * Duration-based rather than a fixed count because the categories span five
 * orders of magnitude: 1,000 iterations is ~4ms of noise for plaintext and ~2.4s
 * for template rendering. A fixed wall-clock target gives every row a usable
 * sample without hand-tuning nine separate counts.
 */
function runBenchmark(string $name): float
{
    [$label, $fn] = BENCHMARKS[$name];

    /** @var array{0: callable, 1: ?callable} $pair */
    $pair = $fn();
    [$op, $teardown] = $pair;

    // Startup is a one-shot measurement: looping it would time already-loaded
    // class_exists lookups instead of the boot work.
    if ($name === 'startup') {
        $start = microtime(true);
        $op();
        $elapsed = microtime(true) - $start;
        if ($teardown !== null) {
            $teardown();
        }
        printf("  %-25s %13s %13s   1 run, in-process (%.3fs)\n", $label, '-', '-', $elapsed);
        return $elapsed;
    }

    // Warm-up doubles as batch-size calibration, and it must be a LOOP: the first
    // op runs cold, so calibrating off one call sized every batch at ~2 and
    // defeated the amortisation below.
    //
    // hrtime() not microtime(): microtime() carries only microsecond resolution,
    // so a sub-microsecond op (plaintext, JSON) samples as 0.0 and a 1/x throughput
    // printed a fabricated 1,000,000,000/s off the divide-by-zero floor. hrtime()
    // is integer nanoseconds.
    // TWO passes, keep the second: one pass still pays the cold costs. A single
    // 64-op pass read JSON Hello World at ~50us/op against a real ~375ns --
    // inflating the estimate 130x and collapsing the batch back to 1.
    $CALIBRATION_OPS = 64;
    $one = 1.0;
    for ($pass = 0; $pass < 2; $pass++) {
        $c0 = hrtime(true);
        for ($i = 0; $i < $CALIBRATION_OPS; $i++) {
            $op();
        }
        $one = max((hrtime(true) - $c0) / $CALIBRATION_OPS, 1.0);   // ns
    }

    // Sample in BATCHES sized so a batch costs >= ~50us. Two reasons:
    //  1. A mean alone hides a fat tail. Measured in the Python twin, the CRUD
    //     cycle has a ~108us median but ONE op per run costs ~711ms (a SQLite
    //     flush), dragging mean throughput from ~9,300 to ~1,350 ops/sec -- a
    //     mean-only line understates CRUD 7x.
    //  2. Timing every single op distorts the fastest benchmarks, where two clock
    //     reads cost the same order as the work itself. Batching amortises them.
    $batch = (int) min(max((int) (50000 / $one), 1), 10000);

    $batches = [];
    $iterations = 0;
    $start = hrtime(true);
    do {
        $b0 = hrtime(true);
        for ($i = 0; $i < $batch; $i++) {
            $op();
        }
        $batches[] = (hrtime(true) - $b0) / $batch;          // ns per op
        $iterations += $batch;
    } while ($iterations < MIN_ITERATIONS || (hrtime(true) - $start) / 1e9 < MIN_SECONDS);
    $elapsed = (hrtime(true) - $start) / 1e9;

    if ($teardown !== null) {
        $teardown();
    }

    // p50 leads, mean is secondary: the mean absorbs scheduler/flush stalls and
    // swung 3x run-to-run in the Python twin while p50 held steady. A number that
    // moves 3x between runs cannot support a comparative claim.
    sort($batches);
    $p50 = max($batches[intdiv(count($batches), 2)], 1.0);    // ns
    printf(
        "  %-25s %13s %13s   %sx%d\n",
        $label,
        number_format(1e9 / $p50),
        number_format($iterations / max($elapsed, 1e-9)),
        number_format($iterations),
        $batch
    );
    return $elapsed;
}

/**
 * Boot cost is per-PROCESS, so it can only be measured by spawning fresh ones.
 * This is where PSR-4's laziness shows up; per-request throughput is unaffected.
 */
/**
 * @param int $runs Best-of count. Higher than the Python suite's 10 on purpose:
 *                  a bare `php -r ';'` costs ~190ms on a stock macOS build with
 *                  70 loaded extensions and varies by 150ms run to run, so the
 *                  interpreter's own boot dwarfs the framework's contribution.
 *                  Best-of-20 pushes the floor down far enough for the framework
 *                  deltas to stop being noise. Read the deltas, not the
 *                  absolutes, and compare only within one machine.
 */
function measureStartup(int $runs = 20): void
{
    $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
    $snippets = [
        'bare php' => ';',
        'composer autoload' => "require '{$autoload}';",
        'core surface used' => "require '{$autoload}'; class_exists(Tina4\\Router::class); class_exists(Tina4\\Response::class);",
        '+ one lazy feature' => "require '{$autoload}'; class_exists(Tina4\\Router::class); class_exists(Tina4\\Queue::class);",
        '+ every lazy feature' => "require '{$autoload}'; foreach (['Queue','Messenger','GraphQL','WSDL','Mqtt','Swagger','AutoCrud','FakeData','I18n','Api'] as \$c) { class_exists('Tina4\\\\'.\$c); }",
    ];

    printf("\n  Startup cost - fresh process, best of %d\n\n", $runs);
    printf("  %-24s %9s %9s\n", 'Scenario', 'Best', 'Files');
    echo '  ' . str_repeat('-', 45) . "\n";

    $baseline = null;
    foreach ($snippets as $label => $snippet) {
        // One untimed warm-up. Without it the FIRST row pays the cold file
        // cache for every file it touches and can read HIGHER than a
        // strictly-larger scenario measured after it - a nonsense ordering that
        // makes the whole table untrustworthy.
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($snippet) . ' 2>&1', $o, $s);

        $best = null;
        $failed = false;
        for ($i = 0; $i < $runs; $i++) {
            $start = microtime(true);
            exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($snippet) . ' 2>&1', $out, $status);
            $elapsed = microtime(true) - $start;
            if ($status !== 0) {
                printf("  %-24s  FAILED: %s\n", $label, substr(implode(' ', $out), 0, 60));
                $failed = true;
                break;
            }
            $best = $best === null ? $elapsed : min($best, $elapsed);
        }
        if ($failed || $best === null) {
            continue;
        }

        // Included-file count is PHP's analogue of Python's sys.modules size.
        $fo = [];
        exec(escapeshellarg(PHP_BINARY) . ' -r ' .
             escapeshellarg($snippet . ' echo count(get_included_files());') . ' 2>&1', $fo, $fs);
        // NOT `end($fo) ?: '?'` -- the string "0" is FALSY in PHP, so a genuine
        // count of zero (bare php includes no files) would render as "?".
        $last = $fo === [] ? '' : trim((string)end($fo));
        $files = ($fs === 0 && $last !== '') ? $last : '?';

        $delta = '';
        if ($baseline === null) {
            $baseline = $best;
        } else {
            $delta = sprintf('  (+%.1fms over bare)', ($best - $baseline) * 1000);
        }
        printf("  %-24s %7.1fms %9s%s\n", $label, $best * 1000, $files, $delta);
        unset($out, $fo);
    }
    echo "\n";
}

/**
 * Measure each benchmark's SCI with the real Carbonah CLI.
 *
 * Carbonah is an external tool, so its absence is reported rather than faked,
 * and a run with no hardware energy counter is labelled "(modelled)" instead of
 * being presented as measured.
 */
function measureCarbon(array $selected): void
{
    exec('command -v carbonah 2>/dev/null', $which, $status);
    if ($status !== 0 || empty($which)) {
        echo "\n  carbonah not on PATH - skipping SCI measurement.\n";
        echo "  Install it (https://carbonah.dev) and re-run with --carbon.\n\n";
        return;
    }

    $region = getenv('CARBONAH_REGION') ?: 'ZA';
    printf("\n  Software Carbon Intensity via Carbonah (region %s)\n\n", $region);
    printf("  %-25s %11s %7s %13s\n", 'Benchmark', 'gCO2e/run', 'Grade', 'Energy kWh');
    echo '  ' . str_repeat('-', 60) . "\n";

    $script = realpath(__FILE__);
    foreach ($selected as $name) {
        if (!isset(BENCHMARKS[$name])) {
            continue;
        }
        $label = BENCHMARKS[$name][0];
        $cmd = 'carbonah measure --format json --region ' . escapeshellarg($region)
             . ' -- ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
             . ' --single ' . escapeshellarg($name) . ' 2>/dev/null';
        $raw = shell_exec($cmd) ?? '';
        // carbonah prints a progress line before the JSON body.
        $brace = strpos($raw, '{');
        if ($brace === false) {
            printf("  %-25s  no JSON from carbonah\n", $label);
            continue;
        }
        $d = json_decode(substr($raw, $brace), true);
        if (!is_array($d) || !isset($d['value'])) {
            printf("  %-25s  unparseable carbonah output\n", $label);
            continue;
        }
        $modelled = empty($d['energy_measured']) ? '  (modelled)' : '';
        printf("  %-25s %11.6f %7s %13.3e%s\n",
            $label, $d['value'], $d['grade'], $d['energy_kwh'], $modelled);
    }
    echo "\n  'modelled' means Carbonah had no hardware energy counter on this\n";
    echo "  platform and derived energy from duration x grid intensity. Treat\n";
    echo "  those as comparative, not absolute.\n\n";
}

// ── Entry point ────────────────────────────────────────────────

$args = array_slice($argv, 1);

// --single runs ONE benchmark bare, with no reporting: this is the form
// `carbonah measure` wraps, so the SCI reflects the benchmark and not the
// printing around it.
$singleIdx = array_search('--single', $args, true);
if ($singleIdx !== false) {
    $only = $args[$singleIdx + 1] ?? '';
    if (!isset(BENCHMARKS[$only])) {
        fwrite(STDERR, "unknown benchmark: {$only}\n");
        exit(1);
    }
    // The benchmark functions return [op, teardown]; carbonah needs a FIXED
    // amount of work (not a fixed duration), so run the op ITERATIONS times.
    [$op, $teardown] = BENCHMARKS[$only][1]();
    if ($only === 'startup') {
        $op();
    } else {
        for ($i = 0; $i < ITERATIONS; $i++) {
            $op();
        }
    }
    if ($teardown !== null) {
        $teardown();
    }
    exit(0);
}

$wantCarbon = in_array('--carbon', $args, true);
$wantStartup = in_array('--startup', $args, true);
$selected = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
if (empty($selected)) {
    $selected = array_keys(BENCHMARKS);
}

printf(
    "\nTina4 v3 Carbon Benchmarks (PHP) - >=%ss / >=%d iterations per test\n\n",
    MIN_SECONDS,
    MIN_ITERATIONS
);
printf("  %-25s %13s %13s   %s\n", 'Benchmark', 'p50 ops/sec', 'mean ops/sec', 'samples');
echo '  ' . str_repeat('-', 72) . "\n";

$total = 0.0;
foreach ($selected as $name) {
    if (isset(BENCHMARKS[$name])) {
        $total += runBenchmark($name);
    } else {
        echo "  Unknown benchmark: {$name}\n";
    }
}

printf("\n  Total: %.3fs\n", $total);

if ($wantStartup) {
    measureStartup();
}
if ($wantCarbon) {
    measureCarbon($selected);
}
if (!$wantStartup && !$wantCarbon) {
    echo "\n  --startup  measure real per-process boot cost (fresh PHP processes)\n";
    echo "  --carbon   measure Software Carbon Intensity via the Carbonah CLI\n";
}
echo "\n";
