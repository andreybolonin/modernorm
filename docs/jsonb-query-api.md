# JSONB query API

`QueryBuilder::jsonb($alias, $field)` creates expressions for a mapped JSONB property on an alias registered with `fromEntity()` or `addJoinedEntity()`. Property names resolve to physical column names with the existing quote strategy. Values, keys and path segments are bound automatically.

```php
use ModernORM\Mapping as ORM;

#[ORM\Column(type: 'jsonb', nullable: true)]
public mixed $attributes = null;
```

```php
$builder = $em->createQueryBuilder()->fromEntity(Product::class, 'p');
$data = $builder->jsonb('p', 'attributes');
$builder->andWhere($data->contains(['status' => 'active']))
    ->andWhere($data->path(['price'])->compareNumber('>=', '100.00'))
    ->andWhere($data->path(['profile'])->exists('city'));
$products = $builder->getQuery()->getResult();
```

Expressions are SQL strings that compose with the existing `where`, `andWhere`, `orWhere`, DBAL expression builder and scalar projections. No query language is parsed. Tenant and SoftDelete visibility are preserved when predicates are appended with `andWhere()`; ordinary `where()` still replaces previous predicates.

## Containment and existence

- `contains($value)` emits native JSONB containment (`@>`). PHP arrays, objects and scalar values use standard JSON encoding. Strings are JSON strings, not pre-encoded JSON. PHP `null` encodes as JSON null, not SQL NULL. Use `(object) []` for `{}` and `[]` for a JSON array. Invalid JSON values such as non-finite floats or invalid UTF-8 raise `JsonException`.
- `exists($key)` tests native top-level key/string-element existence, including keys with a JSON-null value. On arrays it tests string-element membership; it does not test an array position. Use `path()` first to test a nested object. Its implementation uses `pg_catalog.jsonb_exists()` to avoid treating PostgreSQL's `?` operator as a PDO placeholder.

Both methods retain PostgreSQL's SQL NULL behavior: a SQL NULL document yields SQL NULL rather than a matching predicate. JSON null and empty containers follow native JSONB semantics.

## Paths and projections

Paths are lists of literal keys and integer array indexes, not dotted strings or JSONPath expressions:

```php
$data->path(['profile', 'city']);
$data->path(['items', 0, 'sku']);
$data->path(['items', -1]);          // Last array member.
$data->path(['literal.dot', 'key']); // The dot is part of a key.
```

Each `path()` returns a new expression; the original is unchanged, and further calls append segments. Keys containing quotes, commas, braces or SQL punctuation remain parameter values. Integer indexes are converted to text path segments, following PostgreSQL's `#>` / `#>>` semantics. The current JSON node determines whether a segment is an object key or an array index.

`json()` returns JSONB-valued SQL, preserving JSON types. `text()` returns extracted text: strings lose their JSON quotes; numbers/booleans become text; objects/arrays become their PostgreSQL JSON text representation. An empty path selects the whole document. Missing or structurally incompatible paths yield SQL NULL.

```php
$rows = $builder
    ->addScalar($data->path(['profile'])->json(), 'profile_json', 'profile', 'jsonb')
    ->addScalar($data->path(['price'])->text(), 'price_text', 'priceText')
    ->getQuery()->getResult();
// Object hydration with scalars: $rows[0][0] is Product; the other keys are scalar results.
```

Choose `jsonb_object` as the scalar mapping type when JSON objects should decode to PHP objects. Normal DBAL JSON decoding uses PHP numeric types; use `text()` when reading a large JSON number without losing decimal precision is required.

## Comparisons

| Method | Meaning |
| --- | --- |
| `compare('=', $value)` / `compare('<>', $value)` | Native JSONB equality/inequality; number `10`, string `"10"`, boolean `false` and JSON null retain their types. Arrays and objects are supported. |
| `compareText($operator, $value)` | Compare JSON **strings** only, using PostgreSQL text collation. JSON numbers and booleans do not get coerced to strings. |
| `compareNumber($operator, $value)` | Compare JSON **numbers** as PostgreSQL numeric. JSON numeric strings such as `"10"` do not match. |

Typed comparisons allow `=`, `<>`, `!=`, `<`, `<=`, `>`, `>=`; `!=` is normalized to `<>`. General JSONB comparison allows equality/inequality only; choose a typed method for ordering. Operators are allow-listed, never taken as arbitrary SQL fragments.

Numeric comparison values accept PHP ints, finite floats, or decimal/exponent strings such as `'9007199254740993.000001'`. Decimal strings are bound directly and do not pass through a PHP float. Values outside PostgreSQL's numeric range remain database errors. Text comparison is lexical; a JSON string `"10"` sorts before `"2"`, while a JSON number `10` compares numerically.

A CASE/type guard prevents casting heterogeneous document values to an incompatible type. Missing keys, JSON null, SQL NULL and values of another JSON type produce SQL NULL in typed comparisons and do not match WHERE. The guards also apply to inequality; non-number values do not match `compareNumber('<>', 10)`.

## Missing values and nulls

For `$value = $data->path(['x'])`:

| Stored document | `$value->isJsonNull()` | `$value->isSqlNull()` | `$value->isMissing()` |
| --- | --- | --- | --- |
| `{"x":null}` | true | false | false |
| `{}` | SQL NULL | true | true |
| SQL NULL | SQL NULL | true | false |
| `{"x":"null"}` | false | false | false |

`isJsonNull()` is JSONB equality with JSON null. `isSqlNull()` checks the extracted JSONB expression, so it includes absent paths and SQL NULL documents. `isMissing()` requires a path and excludes SQL NULL documents; it also covers paths that cannot resolve because the JSON structure differs. At the root, use `$data->isSqlNull()` to select SQL NULL documents and `$data->isJsonNull()` to select the JSON literal null.

Text extraction collapses explicit JSON null to SQL NULL as well. DBAL JSON hydration decodes both SQL NULL and JSON null to PHP `null`; project a predicate such as `isJsonNull()` alongside a value when the distinction must be retained in results. SQL NULL predicates are not true under WHERE, including when negated; use explicit null predicates when composing such cases.

## Scope, cloning and indexes

Use native `jsonb` / `jsonb_object` mappings, or connection-local type aliases that resolve to these DBAL types. Legacy `json` / `json_object` with `options: ['jsonb' => true]` is accepted. Ordinary JSON/text mappings, associations and custom `columnDefinition` are rejected; no implicit column cast hides a mapping mismatch. Filtering values use JSON encoding directly, rather than a custom mapped domain-value conversion.

An expression belongs to its originating builder. After cloning a builder, obtain expressions from that clone before compiling new filters. Already compiled filters/bindings are copied normally, and `getQuery()` produces independent snapshots. Internal `__orm_jsonb_*` parameter names are reserved; existing bindings with those names are skipped during allocation. As with other generated bindings, `setParameters()` replaces them too; append application values with `setParameter()` when filters are already compiled. Names are not recycled after a reset.

Root containment retains the `@>` operator and can use a suitable PostgreSQL GIN index. `exists()` uses a function rather than the GIN-supported `?` operator; do not assume the same index plan. Path/typed comparisons may need their own expression indexes. This API does not create indexes or promise a particular plan; inspect actual queries for performance-sensitive workloads.

JSONPath, JSONB writes and automatic index generation are outside this API. See PostgreSQL's [JSON functions, operators and null semantics](https://www.postgresql.org/docs/17/functions-json.html).
