# DBAL type providers

ModernORM requires DBAL 4.6 or later within 4.x. Mapped types are resolved through the DBAL connection configuration for SQL generation, writes, hydration, schema validation and L2 row conversion.

Configure the provider before creating the connection and EntityManager:

```php
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\TypeRegistry;

$dbal = new Configuration();
$dbal->setTypeProvider(new TypeRegistry([
    'money' => new MoneyType(),
]));
$connection = DriverManager::getConnection($params, $dbal);
$em = new ModernORM\EntityManager($connection, $ormConfiguration);
```

`TypeRegistry` includes built-in types. Custom implementations of DBAL's `TypeProvider` interface must supply every type their connection needs. An entity can map a local type with `#[Column(type: 'money')]`; another connection may register its own implementation under the same name. ORM query parameters accept type names or `Type` instances, including instances passed through the query builder.

Connections without an explicit provider retain DBAL's global registry fallback. Existing global registrations continue working, but DBAL 4.6 deprecates static `Type` registry methods. Explicit providers do not inherit globally registered custom types.

Configure providers once before using an EntityManager. Use separate cache namespaces for applications or tenants whose data or type semantics differ; hydration caches contain PHP values. SQL projection cache keys include type SQL conversions, and L2 raw rows are converted with the receiving connection's provider.

DBAL 4.6 introspection maps date/time columns to immutable types. ORM entity conversion continues to follow the mapped field type, and equivalent mutable/immutable types produce the same DDL.
