<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * GraphQL — Zero-dependency GraphQL engine.
 * Recursive-descent parser, schema builder, and query executor.
 * Matches the Python tina4_python.graphql implementation.
 */

namespace Tina4;

class GraphQL
{
    /** @var array<string, array> Registered types: name => [field => type] */
    private array $types = [];

    /** @var array<string, array> Registered queries: name => [args, returnType, resolver] */
    private array $queries = [];

    /** @var array<string, array> Registered mutations: name => [args, returnType, resolver] */
    private array $mutations = [];

    public function __construct()
    {
    }

    /**
     * Register a type definition.
     *
     * @param string $name   Type name
     * @param array  $fields Field definitions: [fieldName => typeName]
     * @return self
     */
    public function addType(string $name, array $fields): self
    {
        $this->types[$name] = $fields;
        return $this;
    }

    /**
     * Register a query resolver.
     *
     * @param string   $name       Query name
     * @param array    $args       Argument definitions: [argName => typeName]
     * @param string   $returnType Return type name
     * @param callable $resolver   Resolver function(root, args, context)
     * @return self
     */
    public function addQuery(string $name, array $args, string $returnType, callable $resolver): self
    {
        $this->queries[$name] = [
            'args' => $args,
            'type' => $returnType,
            'resolve' => $resolver,
        ];
        return $this;
    }

    /**
     * Register a mutation resolver.
     *
     * @param string   $name       Mutation name
     * @param array    $args       Argument definitions: [argName => typeName]
     * @param string   $returnType Return type name
     * @param callable $resolver   Resolver function(root, args, context)
     * @return self
     */
    public function addMutation(string $name, array $args, string $returnType, callable $resolver): self
    {
        $this->mutations[$name] = [
            'args' => $args,
            'type' => $returnType,
            'resolve' => $resolver,
        ];
        return $this;
    }

    /**
     * Execute a GraphQL query string.
     *
     * @param string     $query     GraphQL query/mutation string
     * @param array|null $variables Variable values
     * @return array {data: mixed, errors?: array}
     */
    public function execute(string $query, ?array $variables = null): array
    {
        $variables = $variables ?? [];
        $errors = [];

        try {
            $tokens = $this->tokenize($query);
            $parser = new GraphQLParser($tokens);
            $doc = $parser->parse();
        } catch (\Throwable $e) {
            return ['data' => null, 'errors' => [['message' => $e->getMessage()]]];
        }

        // Separate fragments and operations
        $fragments = [];
        $operations = [];
        foreach ($doc['definitions'] as $defn) {
            if ($defn['kind'] === 'fragment') {
                $fragments[$defn['name']] = $defn;
            } else {
                $operations[] = $defn;
            }
        }

        if (empty($operations)) {
            return ['data' => null, 'errors' => [['message' => 'No operation found']]];
        }

        $op = $operations[0];
        $resolvers = ($op['operation'] === 'query') ? $this->queries : $this->mutations;

        // Apply variable defaults
        foreach ($op['variables'] ?? [] as $vdef) {
            if (!isset($variables[$vdef['name']]) && $vdef['default'] !== null) {
                $variables[$vdef['name']] = $vdef['default'];
            }
        }

        $data = [];
        $errs = $this->resolveSelectionsInto($op['selections'], $resolvers, null, $variables, $fragments, $data);
        $errors = array_merge($errors, $errs);

        $response = ['data' => $data];
        if (!empty($errors)) {
            $response['errors'] = $errors;
        }
        return $response;
    }

    /**
     * Return the schema as SDL string.
     *
     * @return string
     */
    public function schemaSdl(): string
    {
        $sdl = '';

        foreach ($this->types as $name => $fields) {
            $sdl .= "type {$name} {\n";
            foreach ($fields as $field => $type) {
                $sdl .= "  {$field}: {$type}\n";
            }
            $sdl .= "}\n\n";
        }

        if (!empty($this->queries)) {
            $sdl .= "type Query {\n";
            foreach ($this->queries as $name => $config) {
                $argStr = $this->formatArgs($config['args']);
                $sdl .= "  {$name}{$argStr}: {$config['type']}\n";
            }
            $sdl .= "}\n\n";
        }

        if (!empty($this->mutations)) {
            $sdl .= "type Mutation {\n";
            foreach ($this->mutations as $name => $config) {
                $argStr = $this->formatArgs($config['args']);
                $sdl .= "  {$name}{$argStr}: {$config['type']}\n";
            }
            $sdl .= "}\n\n";
        }

        return $sdl;
    }

    /**
     * Return schema metadata for debugging.
     */
    public function introspect(): array
    {
        $queries = [];
        foreach ($this->queries as $name => $config) {
            $queries[$name] = ['type' => $config['type'], 'args' => $config['args'] ?? []];
        }
        $mutations = [];
        foreach ($this->mutations as $name => $config) {
            $mutations[$name] = ['type' => $config['type'], 'args' => $config['args'] ?? []];
        }
        return ['types' => $this->types, 'queries' => $queries, 'mutations' => $mutations];
    }

    /**
     * Get registered types.
     */
    public function getTypes(): array
    {
        return $this->types;
    }

    /**
     * Get registered queries.
     */
    public function getQueries(): array
    {
        return $this->queries;
    }

    /**
     * Get registered mutations.
     */
    public function getMutations(): array
    {
        return $this->mutations;
    }

    // ── Tokenizer ────────────────────────────────────────────────

    /**
     * Tokenize a GraphQL query string.
     *
     * @param string $source
     * @return array List of [type, value, pos]
     */
    public function tokenize(string $source): array
    {
        $patterns = [
            'SPREAD'   => '\.\.\.',
            'LBRACE'   => '\{',
            'RBRACE'   => '\}',
            'LPAREN'   => '\(',
            'RPAREN'   => '\)',
            'LBRACKET' => '\[',
            'RBRACKET' => '\]',
            'COLON'    => ':',
            'BANG'     => '!',
            'EQUALS'   => '=',
            'AT'       => '@',
            'DOLLAR'   => '\$',
            'COMMA'    => ',',
            'STRING'   => '"(?:[^"\\\\]|\\\\.)*"',
            'NUMBER'   => '-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?',
            'BOOL'     => '\b(?:true|false)\b',
            'NULL_VAL' => '\bnull\b',
            'NAME'     => '[_a-zA-Z]\w*',
            'SKIP'     => '[\s,]+',
            'COMMENT'  => '#[^\n]*',
        ];

        $combined = '';
        foreach ($patterns as $name => $pattern) {
            if ($combined !== '') {
                $combined .= '|';
            }
            $combined .= "(?P<{$name}>{$pattern})";
        }

        $tokens = [];
        if (preg_match_all("/{$combined}/", $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                foreach ($patterns as $name => $_) {
                    if (isset($match[$name]) && $match[$name][1] !== -1 && $match[$name][0] !== '') {
                        if ($name === 'SKIP' || $name === 'COMMENT') {
                            break;
                        }
                        // Normalize NULL_VAL to NULL for parser
                        $type = ($name === 'NULL_VAL') ? 'NULL' : $name;
                        $tokens[] = ['type' => $type, 'value' => $match[$name][0], 'pos' => $match[$name][1]];
                        break;
                    }
                }
            }
        }

        return $tokens;
    }

    // ── Resolver engine ─────────────────────────────────────────

    /**
     * Resolve selections and merge results into target.
     */
    private function resolveSelectionsInto(array $selections, array $resolvers, mixed $parent,
                                           array $variables, array $fragments, array &$target): array
    {
        $errors = [];

        foreach ($selections as $sel) {
            if (!$this->checkDirectives($sel['directives'] ?? [], $variables)) {
                continue;
            }

            if ($sel['kind'] === 'fragment_spread') {
                $frag = $fragments[$sel['name']] ?? null;
                if (!$frag) {
                    $errors[] = ['message' => "Fragment not found: {$sel['name']}"];
                    continue;
                }
                $errs = $this->resolveSelectionsInto(
                    $frag['selections'], $resolvers, $parent, $variables, $fragments, $target
                );
                $errors = array_merge($errors, $errs);
                continue;
            }

            if ($sel['kind'] === 'inline_fragment') {
                $errs = $this->resolveSelectionsInto(
                    $sel['selections'], $resolvers, $parent, $variables, $fragments, $target
                );
                $errors = array_merge($errors, $errs);
                continue;
            }

            // Field resolution
            [$val, $errs] = $this->resolveField($sel, $resolvers, $parent, $variables, $fragments);
            $errors = array_merge($errors, $errs);
            $key = $sel['alias'] ?? $sel['name'];
            $target[$key] = $val;
        }

        return $errors;
    }

    /**
     * Resolve a single field.
     */
    private function resolveField(array $sel, array $resolvers, mixed $parent,
                                  array $variables, array $fragments): array
    {
        $errors = [];
        $name = $sel['name'];
        $args = $this->resolveArgs($sel['args'] ?? [], $variables);

        $value = null;
        if ($parent !== null) {
            if (is_array($parent)) {
                $value = $parent[$name] ?? null;
            } elseif (is_object($parent)) {
                $value = $parent->$name ?? null;
            }
        } elseif (isset($resolvers[$name])) {
            $config = $resolvers[$name];
            $resolver = $config['resolve'] ?? null;
            if ($resolver) {
                try {
                    $value = $resolver(null, $args, []);
                } catch (\Throwable $e) {
                    $errors[] = ['message' => $e->getMessage(), 'path' => [$name]];
                    return [null, $errors];
                }
            }
        }

        if (empty($sel['selections'])) {
            return [$value, $errors];
        }

        if (is_array($value) && !$this->isAssoc($value)) {
            $result = [];
            foreach ($value as $item) {
                $obj = [];
                $errs = $this->resolveSelectionsInto(
                    $sel['selections'], [], $item, $variables, $fragments, $obj
                );
                $errors = array_merge($errors, $errs);
                $result[] = $obj;
            }
            return [$result, $errors];
        }

        if ($value !== null) {
            $obj = [];
            $errs = $this->resolveSelectionsInto(
                $sel['selections'], [], $value, $variables, $fragments, $obj
            );
            $errors = array_merge($errors, $errs);
            return [$obj, $errors];
        }

        return [null, $errors];
    }

    /**
     * Resolve argument values, substituting variables.
     */
    private function resolveArgs(array $args, array $variables): array
    {
        $resolved = [];
        foreach ($args as $k => $v) {
            if (is_array($v) && isset($v['$var'])) {
                $resolved[$k] = $variables[$v['$var']] ?? null;
            } elseif (is_array($v) && !$this->isAssoc($v)) {
                $resolved[$k] = array_map(function ($i) use ($variables) {
                    if (is_array($i) && isset($i['$var'])) {
                        return $variables[$i['$var']] ?? null;
                    }
                    return $i;
                }, $v);
            } else {
                $resolved[$k] = $v;
            }
        }
        return $resolved;
    }

    /**
     * Check @skip and @include directives.
     */
    private function checkDirectives(array $directives, array $variables): bool
    {
        foreach ($directives as $d) {
            $val = $d['args']['if'] ?? false;
            if (is_array($val) && isset($val['$var'])) {
                $val = $variables[$val['$var']] ?? false;
            }
            if ($d['name'] === 'skip' && $val) {
                return false;
            }
            if ($d['name'] === 'include' && !$val) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if an array is associative.
     */
    private function isAssoc(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * Auto-generate type, queries, and CRUD mutations from an ORM class.
     *
     * Creates:
     *   - A GraphQL type from the ORM's table columns
     *   - Queries: {modelName}(id: ID!): Type, {modelNames}(limit: Int, offset: Int): [Type]
     *   - Mutations: create{ModelName}, update{ModelName}, delete{ModelName}
     *
     * @param ORM $ormInstance An instance of the ORM subclass (must have setDb() called)
     * @return self
     */
    public function fromOrm(ORM $ormInstance): self
    {
        $db = $ormInstance->getDb();
        if ($db === null) {
            // Try to resolve the database the same way ORM::ensureDb() does
            $db = ORM::getGlobalDb() ?? App::getDatabase() ?? Database\Database::fromEnv();
            if ($db !== null) {
                $ormInstance->setDb($db);
            } else {
                throw new \RuntimeException('GraphQL::fromOrm() requires an ORM instance with a database adapter set.');
            }
        }

        $className = (new \ReflectionClass($ormInstance))->getShortName();
        $tableName = $ormInstance->tableName;
        $primaryKey = $ormInstance->primaryKey;

        // Get column metadata from the database
        $columns = $db->getColumns($tableName);

        // Build GraphQL fields from column definitions
        $gqlFields = [];
        foreach ($columns as $col) {
            $colName = $col['name'];
            $colType = strtoupper($col['type'] ?? 'TEXT');

            if ($col['primary'] ?? false) {
                $gqlType = 'ID';
            } elseif (str_contains($colType, 'INT')) {
                $gqlType = 'Int';
            } elseif (str_contains($colType, 'REAL') || str_contains($colType, 'FLOAT')
                || str_contains($colType, 'DOUBLE') || str_contains($colType, 'NUMERIC')
                || str_contains($colType, 'DECIMAL')) {
                $gqlType = 'Float';
            } elseif (str_contains($colType, 'BOOL')) {
                $gqlType = 'Boolean';
            } else {
                $gqlType = 'String';
            }

            $gqlFields[$colName] = $gqlType;
        }

        // If no columns found, create a minimal type with just the primary key
        if (empty($gqlFields)) {
            $gqlFields[$primaryKey] = 'ID';
        }

        $this->addType($className, $gqlFields);

        // Build singular/plural names
        $singular = lcfirst($className);
        $plural = $singular . 's';

        // Query: single record by ID
        $this->addQuery($singular, ['id' => 'ID!'], $className, function ($root, $args, $context) use ($ormInstance) {
            $model = clone $ormInstance;
            $model->load($args['id']);
            return $model->exists() ? $model->toDict() : null;
        });

        // Query: list with pagination
        $this->addQuery($plural, ['limit' => 'Int', 'offset' => 'Int'], "[{$className}]", function ($root, $args, $context) use ($ormInstance) {
            $limit = $args['limit'] ?? 10;
            $offset = $args['offset'] ?? 0;
            $models = $ormInstance->all($limit, $offset);
            $records = [];
            foreach ($models as $model) {
                $records[] = $model->toDict();
            }
            return $records;
        });

        // Build mutation args (all fields except PK)
        $mutationArgs = [];
        foreach ($gqlFields as $field => $type) {
            if ($field !== $primaryKey) {
                $mutationArgs[$field] = 'String';
            }
        }

        // Mutation: create
        $this->addMutation("create{$className}", $mutationArgs, $className, function ($root, $args, $context) use ($ormInstance) {
            $model = clone $ormInstance;
            $model->fill($args);
            $model->save();
            return $model->toDict();
        });

        // Mutation: update
        $updateArgs = array_merge(['id' => 'ID!'], $mutationArgs);
        $this->addMutation("update{$className}", $updateArgs, $className, function ($root, $args, $context) use ($ormInstance, $primaryKey) {
            $model = clone $ormInstance;
            $model->load($args['id']);
            if (!$model->exists()) {
                return null;
            }
            $data = $args;
            unset($data['id']);
            $model->fill($data);
            $model->save();
            return $model->toDict();
        });

        // Mutation: delete
        $this->addMutation("delete{$className}", ['id' => 'ID!'], 'Boolean', function ($root, $args, $context) use ($ormInstance) {
            $model = clone $ormInstance;
            $model->load($args['id']);
            if (!$model->exists()) {
                return false;
            }
            $model->delete();
            return true;
        });

        return $this;
    }

    /**
     * Map a database column type string to a GraphQL type.
     *
     * @param string $dbType The database column type (e.g. 'INTEGER', 'TEXT', 'REAL')
     * @return string The GraphQL type name
     */
    private function dbTypeToGraphQL(string $dbType): string
    {
        $dbType = strtoupper($dbType);
        if (str_contains($dbType, 'INT')) {
            return 'Int';
        }
        if (str_contains($dbType, 'REAL') || str_contains($dbType, 'FLOAT')
            || str_contains($dbType, 'DOUBLE') || str_contains($dbType, 'NUMERIC')
            || str_contains($dbType, 'DECIMAL')) {
            return 'Float';
        }
        if (str_contains($dbType, 'BOOL')) {
            return 'Boolean';
        }
        return 'String';
    }

    /**
     * Format argument definitions for SDL.
     */
    private function formatArgs(array $args): string
    {
        if (empty($args)) {
            return '';
        }
        $parts = [];
        foreach ($args as $name => $type) {
            $parts[] = "{$name}: {$type}";
        }
        return '(' . implode(', ', $parts) . ')';
    }
}

/**
 * Recursive-descent GraphQL parser.
 */
class GraphQLParser
{
    private array $tokens;
    private int $pos = 0;

    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    public function parse(): array
    {
        $doc = ['definitions' => []];
        while ($this->pos < count($this->tokens)) {
            $doc['definitions'][] = $this->parseDefinition();
        }
        return $doc;
    }

    public function peek(int $offset = 0): ?array
    {
        return $this->tokens[$this->pos + $offset] ?? null;
    }

    public function advance(): array
    {
        return $this->tokens[$this->pos++];
    }

    public function expect(string $type, ?string $value = null): array
    {
        $t = $this->peek();
        if (!$t || $t['type'] !== $type || ($value !== null && $t['value'] !== $value)) {
            $expected = $value ? "{$type}({$value})" : $type;
            $got = $t ? "{$t['type']}({$t['value']})" : 'EOF';
            throw new \RuntimeException("Expected {$expected}, got {$got}");
        }
        return $this->advance();
    }

    public function match(string $type, ?string $value = null): ?array
    {
        $t = $this->peek();
        if ($t && $t['type'] === $type && ($value === null || $t['value'] === $value)) {
            return $this->advance();
        }
        return null;
    }

    private function parseDefinition(): array
    {
        $t = $this->peek();
        if ($t && $t['type'] === 'NAME' && $t['value'] === 'fragment') {
            return $this->parseFragment();
        }
        return $this->parseOperation();
    }

    private function parseOperation(): array
    {
        $t = $this->peek();
        $opType = 'query';
        $name = null;
        $variables = [];

        if ($t && $t['type'] === 'NAME' && in_array($t['value'], ['query', 'mutation', 'subscription'])) {
            $opType = $this->advance()['value'];
            $next = $this->peek();
            if ($next && $next['type'] === 'NAME') {
                $name = $this->advance()['value'];
            }
            if ($this->match('LPAREN')) {
                $variables = $this->parseVariableDefs();
                $this->expect('RPAREN');
            }
        }

        $directives = $this->parseDirectives();
        $selections = $this->parseSelectionSet();

        return [
            'kind' => 'operation',
            'operation' => $opType,
            'name' => $name,
            'variables' => $variables,
            'directives' => $directives,
            'selections' => $selections,
        ];
    }

    private function parseFragment(): array
    {
        $this->expect('NAME', 'fragment');
        $name = $this->expect('NAME')['value'];
        $this->expect('NAME', 'on');
        $typeName = $this->expect('NAME')['value'];
        $directives = $this->parseDirectives();
        $selections = $this->parseSelectionSet();

        return [
            'kind' => 'fragment',
            'name' => $name,
            'on' => $typeName,
            'directives' => $directives,
            'selections' => $selections,
        ];
    }

    private function parseSelectionSet(): array
    {
        $this->expect('LBRACE');
        $selections = [];

        while (!$this->match('RBRACE')) {
            if ($this->match('SPREAD')) {
                $next = $this->peek();
                if ($next && $next['type'] === 'NAME' && $next['value'] === 'on') {
                    $this->advance();
                    $typeName = $this->expect('NAME')['value'];
                    $directives = $this->parseDirectives();
                    $sels = $this->parseSelectionSet();
                    $selections[] = [
                        'kind' => 'inline_fragment',
                        'on' => $typeName,
                        'directives' => $directives,
                        'selections' => $sels,
                    ];
                } elseif ($next && $next['type'] === 'LBRACE') {
                    $directives = $this->parseDirectives();
                    $sels = $this->parseSelectionSet();
                    $selections[] = [
                        'kind' => 'inline_fragment',
                        'on' => null,
                        'directives' => $directives,
                        'selections' => $sels,
                    ];
                } else {
                    $fragName = $this->expect('NAME')['value'];
                    $directives = $this->parseDirectives();
                    $selections[] = [
                        'kind' => 'fragment_spread',
                        'name' => $fragName,
                        'directives' => $directives,
                    ];
                }
            } else {
                $selections[] = $this->parseField();
            }
        }

        return $selections;
    }

    private function parseField(): array
    {
        $name = $this->expect('NAME')['value'];
        $alias = null;

        if ($this->match('COLON')) {
            $alias = $name;
            $name = $this->expect('NAME')['value'];
        }

        $args = [];
        if ($this->match('LPAREN')) {
            $args = $this->parseArguments();
            $this->expect('RPAREN');
        }

        $directives = $this->parseDirectives();

        $selections = null;
        $next = $this->peek();
        if ($next && $next['type'] === 'LBRACE') {
            $selections = $this->parseSelectionSet();
        }

        return [
            'kind' => 'field',
            'name' => $name,
            'alias' => $alias,
            'args' => $args,
            'directives' => $directives,
            'selections' => $selections,
        ];
    }

    private function parseArguments(): array
    {
        $args = [];
        while ($this->peek() && $this->peek()['type'] !== 'RPAREN') {
            $name = $this->expect('NAME')['value'];
            $this->expect('COLON');
            $args[$name] = $this->parseValue();
            $this->match('COMMA');
        }
        return $args;
    }

    private function parseValue(): mixed
    {
        $t = $this->peek();
        if (!$t) {
            throw new \RuntimeException('Unexpected EOF in value');
        }

        switch ($t['type']) {
            case 'STRING':
                $this->advance();
                $inner = substr($t['value'], 1, -1);
                return str_replace(['\\"', '\\\\'], ['"', '\\'], $inner);

            case 'NUMBER':
                $this->advance();
                if (str_contains($t['value'], '.') || stripos($t['value'], 'e') !== false) {
                    return (float)$t['value'];
                }
                return (int)$t['value'];

            case 'BOOL':
                $this->advance();
                return $t['value'] === 'true';

            case 'NULL':
                $this->advance();
                return null;

            case 'NAME':
                $this->advance();
                return $t['value'];

            case 'DOLLAR':
                $this->advance();
                $name = $this->expect('NAME')['value'];
                return ['$var' => $name];

            case 'LBRACKET':
                $this->advance();
                $items = [];
                while (!$this->match('RBRACKET')) {
                    $items[] = $this->parseValue();
                }
                return $items;

            case 'LBRACE':
                $this->advance();
                $obj = [];
                while (!$this->match('RBRACE')) {
                    $key = $this->expect('NAME')['value'];
                    $this->expect('COLON');
                    $obj[$key] = $this->parseValue();
                }
                return $obj;

            default:
                throw new \RuntimeException("Unexpected token: {$t['type']}({$t['value']})");
        }
    }

    private function parseDirectives(): array
    {
        $directives = [];
        while ($this->peek() && $this->peek()['type'] === 'AT') {
            $this->advance();
            $name = $this->expect('NAME')['value'];
            $args = [];
            if ($this->match('LPAREN')) {
                $args = $this->parseArguments();
                $this->expect('RPAREN');
            }
            $directives[] = ['name' => $name, 'args' => $args];
        }
        return $directives;
    }

    private function parseVariableDefs(): array
    {
        $defs = [];
        while ($this->peek() && $this->peek()['type'] === 'DOLLAR') {
            $this->advance();
            $name = $this->expect('NAME')['value'];
            $this->expect('COLON');
            $typeName = $this->parseTypeRef();
            $default = null;
            if ($this->match('EQUALS')) {
                $default = $this->parseValue();
            }
            $defs[] = ['name' => $name, 'type' => $typeName, 'default' => $default];
            $this->match('COMMA');
        }
        return $defs;
    }

    private function parseTypeRef(): string
    {
        if ($this->match('LBRACKET')) {
            $inner = $this->parseTypeRef();
            $this->expect('RBRACKET');
            $t = "[{$inner}]";
        } else {
            $t = $this->expect('NAME')['value'];
        }
        if ($this->match('BANG')) {
            $t .= '!';
        }
        return $t;
    }
}

/**
 * Lightweight GraphQL type wrapper matching Ruby's GraphQLType.
 */
class GraphQLType
{
    public const SCALARS = ['String', 'Int', 'Float', 'Boolean', 'ID'];

    public string $name;
    public string $kind;
    public ?GraphQLType $ofType;

    public function __construct(string $name, string $kind = 'object', ?GraphQLType $ofType = null)
    {
        $this->name = $name;
        $this->kind = $kind;
        $this->ofType = $ofType;
    }

    /**
     * Parse a GraphQL type string like "String", "String!", "[Int!]!".
     */
    public static function parse(string $typeStr): self
    {
        $s = trim($typeStr);
        if (str_ends_with($s, '!')) {
            $inner = self::parse(substr($s, 0, -1));
            return new self($s, 'non_null', $inner);
        }
        if (str_starts_with($s, '[') && str_ends_with($s, ']')) {
            $inner = self::parse(substr($s, 1, -1));
            return new self($s, 'list', $inner);
        }
        if (in_array($s, self::SCALARS, true)) {
            return new self($s, 'scalar');
        }
        return new self($s, 'object');
    }
}
