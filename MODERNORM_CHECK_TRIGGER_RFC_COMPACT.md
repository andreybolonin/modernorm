# RFC: ModernORM — PostgreSQL `#[Check]` и `#[Trigger]` (MVP)

**Статус:** ready for Codex · **Область:** PostgreSQL metadata, schema, diff, migrations.

## 1. Цель и публичный API

```php
#[Check(name: 'chk_order_dates', expression: 'end_date >= start_date')]
#[Trigger(
    name: 'trg_order_audit',
    timing: 'AFTER',
    events: ['INSERT', 'UPDATE', 'DELETE'],
    functionSql: 'database/functions/fn_order_audit.sql'
)]
class ProductionOrder {}
```

Атрибуты повторяемые, применяются к Entity с физической таблицей. Использовать существующие namespace и компоненты ModernORM. Только PostgreSQL; на других платформах — явная ошибка.

```php
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Check
{
    public function __construct(public string $name, public string $expression) {}
}

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Trigger
{
    public function __construct(
        public string $name,
        public string $timing,
        public array $events,
        public ?string $function = null,
        public ?string $functionSql = null,
    ) {}
}
```

## 2. `Check`: контракт

- `name` — непустой PostgreSQL identifier, не более 63 байт; имя уникально среди CHECK на таблице. Идентификаторы экранировать существующим механизмом.
- `expression` — непустой доверенный SQL-предикат; не принимать пользовательский ввод и не пытаться парсить/переписывать выражение. PostgreSQL проверяет синтаксис и корректность при применении DDL.
- Генерация: `ALTER TABLE ... ADD CONSTRAINT ... CHECK (...)`. При создании новой таблицы допустим эквивалентный inline DDL.

## 3. `Trigger`: контракт

- `name` — уникальное имя триггера на таблице, до 63 байт.
- `timing` — `BEFORE` или `AFTER`.
- `events` — непустой набор без повторов из `INSERT`, `UPDATE`, `DELETE`. Нормализовать регистр и порядок для стабильного diff. `TRUNCATE` не поддерживается: MVP только `FOR EACH ROW`.
- `function` необязателен. По умолчанию **`trg_order_audit` → `fn_order_audit`**: заменить обязательный префикс `trg_` на `fn_`. Без `trg_` и без явного `function` — ошибка. Явный `function` имеет приоритет, в том числе для общей функции нескольких триггеров.
- Имя функции — один идентификатор либо `schema.identifier`. Неквалифицированное имя привязывать к схеме Entity, а не к `search_path`; проверять длину и экранировать части независимо.
- `functionSql` необязателен: если отсутствует, функция управляется внешней миграцией и должна существовать к моменту создания триггера.
- Генерация: `CREATE TRIGGER ... BEFORE|AFTER ... ON ... FOR EACH ROW EXECUTE FUNCTION schema.fn_name()`.

## 4. SQL-файл функции

Пример `database/functions/fn_order_audit.sql`:

```sql
CREATE OR REPLACE FUNCTION public.fn_order_audit()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        INSERT INTO public.order_audit(order_id, action)
        VALUES (OLD.id, TG_OP);
        RETURN OLD;
    END IF;
    INSERT INTO public.order_audit(order_id, action)
    VALUES (NEW.id, TG_OP);
    RETURN NEW;
END;
$$;
```

- **Контракт файла:** одно определение `CREATE OR REPLACE FUNCTION` с разрешённым именем, без аргументов, `RETURNS trigger`, `LANGUAGE plpgsql`. SQL — доверенный код разработчика.
- **Не писать PL/pgSQL-парсер.** Не разбивать SQL по `;`: они есть внутри `$$...$$`. В MVP допускается ограниченная проверка заголовка/имени с последующей проверкой PostgreSQL при транзакционном применении. 
- SQL-файл должен объявлять **ровно ту функцию**, которая получена из `name` или явно указана в `function`; имя файла на выбор имени не влияет. При невозможности проверки — ошибка генерации.

## 5. Простая политика обновления функций

**Нормативное решение:** ModernORM управляет определениями функций через версионируемые SQL-файлы и `CREATE OR REPLACE FUNCTION`, **не анализирует тело PL/pgSQL и не удаляет функции автоматически**.

1. При генерации миграции читать файл, вычислять `SHA-256` **сырых байтов** и сравнивать с состоянием **последней применённой** версии управляемой функции.
2. При новом определении или изменившемся хеше генерировать миграцию с полным `CREATE OR REPLACE FUNCTION` из файла. SQL и хеш фиксировать **в тексте миграции**; во время её исполнения файл повторно не читать.
3. После успешного применения обновлять хеш в учёте применённых версий функций **в той же транзакции**, что и DDL. Нельзя обновлять учёт при одной лишь генерации миграции. Использовать существующий механизм состояния миграций, если он подходит; иначе добавить минимальный реестр `(schema, function_name, source_hash)`.
4. При неизменном хеше не генерировать обновление функции. При изменении только тела **не пересоздавать триггер**: PostgreSQL сохранит привязку при `CREATE OR REPLACE FUNCTION`.
5. Сигнатура функции в MVP неизменна: `schema.name() RETURNS trigger`. Если существующая функция несовместима, конфликтует с внешней или принадлежит другому управлению — ошибка, не `DROP ... CASCADE`.

## 6. Schema introspection и diff

- Встроиться в существующие `SchemaGenerator`/`SchemaIntrospector`/`SchemaComparator`/migration generator, не создавать альтернативный стек.
- `CHECK`: читать из `pg_constraint` (`contype = 'c'`, `pg_get_constraintdef`). Учитывать нормализацию PostgreSQL: сравнение исходной строки с выводом БД **не является** надёжным семантическим diff. MVP: создание, чтение, стабильный no-op diff для эквивалентного состояния; неоднозначные изменения выражений отмечать как drift и требовать явной миграции.
- `Trigger`: читать пользовательские триггеры из `pg_trigger` (`NOT tgisinternal`) и связанные `pg_proc`/`pg_namespace`. Сравнивать таблицу, имя, timing, набор events, `FOR EACH ROW`, квалифицированную функцию; не сравнивать строку `pg_get_triggerdef` целиком.

## 7. Миграции, порядок, безопасность

- Использовать существующий migration API/CLI. Порядок: **создание таблицы → `CREATE OR REPLACE FUNCTION` → CHECK → `CREATE TRIGGER`**.
- В PostgreSQL DDL и обновление реестра хешей выполнять транзакционно, насколько это допускает существующий runner. Ошибка откатывает всё.
- Нельзя генерировать `DROP FUNCTION` автоматически. Удаление CHECK/trigger — только через явную миграцию или существующий проверенный механизм управления удалениями.

## 8. Критерии приёмки

1. Два `#[Check]` на одной Entity создаются, introspect-ятся, запрещают некорректные INSERT/UPDATE; `NULL` обрабатывается по семантике PostgreSQL.
2. `#[Trigger]` с `functionSql` создаёт функцию перед триггером, а реальный INSERT/UPDATE/DELETE вызывает функцию.
3. Имя `trg_*` корректно превращается в `fn_*`; явный `function` перекрывает правило. Неверный префикс без override отклоняется.
4. Изменение SQL-файла создаёт **одну** миграцию `CREATE OR REPLACE FUNCTION`, обновляет сохранённый SHA-256 после применения и не пересоздаёт триггер.
5. Неизменённые метаданные и SQL-файлы дают пустой повторный diff. Несовместимые функции и конфликтующие общие определения блокируются.

## 9. Задание Codex

> Изучи текущий репозиторий ModernORM и найди реальные точки расширения атрибутов, metadata, PostgreSQL schema generation, introspection, comparator и migration generator. Реализуй этот RFC без параллельных подсистем. Начни с CHECK end-to-end, затем Trigger + `functionSql`, затем SHA-256 и обновление через `CREATE OR REPLACE FUNCTION`. Не создавай SQL/PLpgSQL-парсер; соблюдай ограниченный контракт SQL-файла и проверяй функцию при применении в PostgreSQL. Не удаляй функции автоматически и не пересоздавай триггеры при изменении только тела функции. Добавь PostgreSQL integration tests, запусти их и перечисли изменённые файлы, результаты и оставшиеся ограничения. Не считай задачу выполненной без introspection, стабильного diff и реального применения миграций.

**Вне scope:** `data:transfer`, `schema:rekey`, MySQL, перенос данных, ORM lifecycle callbacks.
