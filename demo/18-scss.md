# SCSS Compiler

The `ScssCompiler` class compiles SCSS to CSS with zero external dependencies. It supports variables, nesting with `&` parent selectors, `@import` with file resolution, `@mixin`/`@include` with parameters, basic math operations, `@media` queries, and multi-line comments.

## Basic Compilation

```php
use Tina4\ScssCompiler;

$compiler = new ScssCompiler();

// Compile from string
$css = $compiler->compile('
    $primary: #3498db;
    $padding: 16px;

    .container {
        max-width: 1200px;
        padding: $padding;

        .header {
            background: $primary;
            color: white;
        }
    }
');

// Output:
// .container {
//   max-width: 1200px;
//   padding: 16px;
// }
// .container .header {
//   background: #3498db;
//   color: white;
// }
```

## Compile from File

```php
$css = $compiler->compileFile('src/scss/main.scss');
```

The file's directory is automatically added as an import path, so relative `@import` directives resolve correctly.

## Variables

```scss
// src/scss/main.scss
$font-stack: 'Helvetica Neue', Arial, sans-serif;
$primary: #2c3e50;
$secondary: #3498db;
$border-radius: 4px;
$spacing: 8px;

body {
    font-family: $font-stack;
    color: $primary;
}

.button {
    background: $secondary;
    border-radius: $border-radius;
    padding: $spacing $spacing * 2;
}
```

### Preset Variables

Inject variables before compilation:

```php
$compiler = new ScssCompiler([
    'variables' => [
        'primary' => '#e74c3c',
        'font-size' => '16px',
    ],
]);

// Or set individually
$compiler->setVariable('primary', '#e74c3c');
$compiler->setVariable('$primary', '#e74c3c'); // Leading $ is stripped
```

## Nesting

```scss
.nav {
    display: flex;
    gap: 16px;

    .nav-item {
        padding: 8px 16px;

        &:hover {
            background: #f0f0f0;
        }

        &.active {
            font-weight: bold;
            border-bottom: 2px solid #3498db;
        }
    }

    .nav-brand {
        font-size: 1.5em;
    }
}
```

Output:
```css
.nav {
  display: flex;
  gap: 16px;
}
.nav .nav-item {
  padding: 8px 16px;
}
.nav .nav-item:hover {
  background: #f0f0f0;
}
.nav .nav-item.active {
  font-weight: bold;
  border-bottom: 2px solid #3498db;
}
.nav .nav-brand {
  font-size: 1.5em;
}
```

## Mixins

```scss
@mixin flex-center {
    display: flex;
    align-items: center;
    justify-content: center;
}

@mixin button($bg, $color: white) {
    background: $bg;
    color: $color;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
}

.hero {
    @include flex-center;
    height: 100vh;
}

.btn-primary {
    @include button(#3498db);
}

.btn-danger {
    @include button(#e74c3c, #fff);
}
```

## Imports

```scss
// src/scss/main.scss
@import 'variables';      // Resolves: _variables.scss or variables.scss
@import 'components/card'; // Resolves: components/_card.scss or components/card.scss
@import 'layout';
```

The compiler searches for files in this order:
1. `{dir}/{name}.scss`
2. `{dir}/_{name}.scss`
3. `{dir}/{name}` (exact match)
4. Each import path in the same order

Circular imports are detected and skipped.

### Custom Import Paths

```php
$compiler = new ScssCompiler([
    'import_paths' => [
        'src/scss',
        'vendor/theme/scss',
    ],
]);

// Or add individually
$compiler->addImportPath('src/scss/vendor');
```

## Math Operations

```scss
$base: 16px;
$columns: 12;

.col-6 {
    width: 6 / $columns * 100%;  // 50%
}

.spacing-lg {
    padding: $base * 2;  // 32px
    margin: $base + 8px; // 24px
}
```

Supported operators: `+`, `-`, `*`, `/`

## Media Queries

```scss
.container {
    max-width: 1200px;
    padding: 16px;

    @media (max-width: 768px) {
        padding: 8px;

        .sidebar {
            display: none;
        }
    }
}
```

Output:
```css
.container {
  max-width: 1200px;
  padding: 16px;
}
@media (max-width: 768px) {
  .container {
    padding: 8px;
  }
  .container .sidebar {
    display: none;
  }
}
```

## Comments

```scss
// Single-line comments are stripped from output

/* Multi-line comments
   are preserved in output */

.element {
    color: red; // This comment is removed
    /* This comment stays */
    padding: 8px;
}
```

## Integration with Routes

Serve compiled CSS on-the-fly:

```php
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;
use Tina4\ScssCompiler;

Router::get('/css/style.css', function (Request $request, Response $response) {
    $compiler = new ScssCompiler(['import_paths' => ['src/scss']]);
    $css = $compiler->compileFile('src/scss/main.scss');

    return $response
        ->header('Content-Type', 'text/css')
        ->status(200)
        ->html($css);
})->cache();
```

## Project Structure

```
src/scss/
  _variables.scss      # Variables (partial — prefixed with _)
  _mixins.scss         # Shared mixins
  _reset.scss          # CSS reset
  components/
    _card.scss
    _button.scss
    _form.scss
  layout/
    _header.scss
    _footer.scss
    _grid.scss
  main.scss            # Entry point — imports everything
```

```scss
// src/scss/main.scss
@import 'variables';
@import 'mixins';
@import 'reset';
@import 'components/card';
@import 'components/button';
@import 'components/form';
@import 'layout/header';
@import 'layout/footer';
@import 'layout/grid';
```

## Tips

- Prefix partial files with `_` (e.g., `_variables.scss`) — the compiler resolves both `_name.scss` and `name.scss`.
- Use preset variables for theme customization without modifying source files.
- The math evaluator handles units (`px`, `%`, `em`) and preserves the unit from the first operand.
- Empty rulesets are automatically removed from the output.
- Single-line comments (`//`) are stripped; multi-line comments (`/* */`) are preserved.
- Cache compiled CSS in production to avoid recompilation on every request.
