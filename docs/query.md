# OQL: querying objects with SQL

> **Experimental.** OQL is new in 1.0 and marked `@experimental`: its syntax, its semantics and the API of `Oql`, `Result`, `Reference` and `QueryException` may change in a minor release of 1.x, not only in a major one. Such changes are listed in the [Changelog](../Changelog.md) under **Breaking (experimental)**. Feedback on the language is welcome before it is declared stable.

OQL is a small query language like SQL for the objects of a storage: `SELECT` with joins, `WHERE`, `GROUP BY` with aggregates, `HAVING`, `ORDER BY`, `LIMIT` and `OFFSET`, and `INSERT`, `UPDATE` and `DELETE`. It works on the objects as stored, so the classes aren't needed to read (the [viewer](maintenance.md#the-web-viewer) queries any storage), and paths lead into referenced objects.

```php
use melia\ObjectStorage\Query\Oql;

$result = (new Oql($storage))->query(
    'SELECT c.title AS category, COUNT(*) AS products, AVG(p.price) AS price
     FROM Product p JOIN Category c ON c = p.category
     WHERE p.active = :active AND p.price < :max
     GROUP BY c.title
     HAVING COUNT(*) > 1
     ORDER BY products DESC, category
     LIMIT 10',
    ['active' => true, 'max' => 100],
);

$result->columns;     // ['category', 'products', 'price']
$result->rows;        // [['Fruit', 12, 3.5], ...], values in the order of the columns
foreach ($result as $row) {
    echo $row['category'], ': ', $row['products'], "\n";   // rows by the names of the columns
}
count($result);       // the number of rows
$result->column('category');
```

```php
$oql->query("INSERT INTO Category (title) VALUES ('Tools'), (:title)", ['title' => 'Toys'])->column(0); // the new UUIDs
$oql->query('UPDATE Product SET price = price * 1.1, category = REF(:c) WHERE category.title = :old', ['c' => $uuid, 'old' => 'Misc'])->affected();
$oql->query('DELETE FROM Product WHERE UUID = ?', [$uuid]);
$oql->query('DELETE FROM Product WHERE active = FALSE', dryRun: true)->column(0);       // what would be deleted
```

Planned: using [search indexes](search.md) to find the objects instead of reading all objects of a class.

## Syntax

```
SELECT [DISTINCT] expression [[AS] name], … | * | alias.*
FROM Class [[AS] alias]
[[INNER] JOIN | LEFT [OUTER] JOIN Class [[AS] alias] ON condition] …
[WHERE condition]
[GROUP BY expression, …]
[HAVING condition]
[ORDER BY expression [ASC | DESC], …]
[LIMIT count] [OFFSET count]

INSERT INTO Class (property, …) VALUES (value, …) [, (value, …)] …
UPDATE Class [[AS] alias] SET property = value [, property = value] … [WHERE condition]
DELETE FROM Class [[AS] alias] [WHERE condition]
```

Keywords ignore case; `--` starts a comment up to the end of the line, `/* … */` is one too.

### Classes and aliases

- A class is named by its short name (`Product`), if only one stored class has it, or by its full name (`App\Entity\Product`, or in backticks or double quotes: `` `App\Entity\Product` ``). Class names ignore case, as in PHP. A class without stored objects gives no rows.
- Without an alias, a source is named by the short name of its class: `SELECT Product.name FROM Product`.
- A column is a name or a path of names: `name`, `p.name`, `p.category.title`. With one source, the alias may be left out; with several sources (joins) it is required.
- **Paths lead into referenced objects**: `p.category.parent.title` follows the references to the category and its parent, at most three references per path (as the [search indexes](search.md#joins-paths-into-referenced-objects)). A referenced object that doesn't exist (anymore) or has expired gives `NULL`; so does a property that doesn't exist.
- **`UUID` is the UUID of an object**, a reserved keyword: `SELECT UUID, name FROM Product WHERE UUID IN (?, ?)`, with several sources `p.UUID`, and at the end of a path the UUID of the referenced object there: `p.category.UUID` (`NULL` if there is no reference). It can be used wherever a value can: in the selection, conditions, `ON`, `GROUP BY`, `ORDER BY`, `UPDATE` and `DELETE`. As a keyword it ignores case; a property named uuid is written in backticks: `` p.`uuid` ``.
- **The alias alone is the object** too, as its UUID: `SELECT p FROM Product p` lists the UUIDs; a reference is its UUID as well, so `JOIN Category c ON c = p.category` (or `ON c.UUID = p.category`) joins the referenced category.
- Lists give arrays, also through references: `p.labels.title` gives the titles of all labels (`NULL` for an empty list). Lists can be selected, but not compared.
- `*` selects the properties of the objects (with joins: as `alias.property`), `alias.*` those of one source; references as UUIDs.

### Values and operators

| | |
|---|---|
| values | `'text'` (`''` for a quote), `12`, `-1.5`, `1e3`, `TRUE`, `FALSE`, `NULL` |
| parameters | `?` (by position, from 0) and `:name`: texts, numbers, booleans, `NULL` and dates (`DateTimeInterface`); the values are never read as part of the query |
| arithmetic | `+ - * /`; `7 / 2` is `3.5`, a division by 0 is `NULL` |
| text | `a \|\| b` joins texts |
| comparison | `= != <> < <= > >=` |
| conditions | `IS [NOT] NULL`, `[NOT] IN (a, b, …)`, `[NOT] BETWEEN low AND high`, `[NOT] LIKE pattern`, `AND`, `OR`, `NOT` |
| functions | `LOWER`, `UPPER`, `LENGTH` (characters), `TRIM`, `SUBSTR(text, start [, length])` (from 1), `ABS`, `ROUND(number [, digits])`, `COALESCE(a, b, …)`, `IFNULL(a, b)` |
| dates | `NOW()`, `DATETIME(x [, zone])`, `DATE(x [, zone])`, `YEAR`, `MONTH`, `DAY`, `HOUR`, `MINUTE`, `SECOND(x [, zone])`, `DATE_ADD(x, amount, unit)`, see [Dates](#dates) |
| aggregates | `COUNT(*)`, `COUNT([DISTINCT] x)`, `SUM([DISTINCT] x)`, `AVG`, `MIN`, `MAX` |

The precedence of the operators, from the lowest, is that of SQLite: `OR`, `AND`, `NOT`, `= != IS IN LIKE BETWEEN`, `< <= > >=`, `+ -`, `* /`, `||`, unary `-`.

`LIKE` matches the whole value: `%` any characters, `_` one character, a backslash takes the next character as it is (`'50\%'`); it ignores case, of all letters (`'äp%'` matches `ÄPFEL`).

### NULL and types

As in SQL:

- `NULL` is unknown: comparisons with it are unknown, and the logic is three-valued (`NULL AND FALSE` is false, `NULL OR TRUE` true, `NOT NULL` unknown). `WHERE`, `ON` and `HAVING` keep the rows whose condition is true. `x IN (1, NULL)` is true for 1 and unknown otherwise, so `NOT IN` with `NULL` matches nothing.
- Booleans are the numbers 1 and 0 (`active = 1` is `active = TRUE`).
- Numbers are compared as numbers (`1 = 1.0`), texts by their bytes (with case: `'a' != 'A'`, `'B' < 'a'`); numbers come before texts.
- A number and a number written as text are compared as numbers, as in MySQL: objects have no schema, and a price may be stored as `"97.65"` in some objects. `GROUP BY` and `DISTINCT` keep them apart (`'1.0'` and `1` are two groups).
- Dates are compared by their point in time, see [Dates](#dates); they come after numbers and before texts.
- Aggregates skip `NULL`; `COUNT` of no values is 0, `SUM`, `AVG`, `MIN`, `MAX` are `NULL`. `SUM` of integers is an integer, `AVG` a float. Without `GROUP BY`, aggregates make one row, also without any objects.

### Dates

Dates (`DateTimeInterface`, [stored inline](serialization.md#dates)) are values of their own:

- **Compared by their point in time**, whatever their time zone: `ORDER BY createdAt`, `MIN(createdAt)`, `createdAt BETWEEN :from AND :to`.
- **A text that is a date** is read as one when compared with a date: `'2026-01-01'`, `'2026-01-01 10:00'`, `'2026-01-01T10:00:00.5Z'`, `'2026-01-01T10:00:00+01:00'`. **Without `Z` or offset it is UTC**: `createdAt >= '2026-01-01'` means from midnight UTC; write the offset for a local time (`'2026-01-01T00:00+01:00'`). A text that isn't a date (also `'2026-02-30'`) is compared with the date as text, as in SQLite. Parameters may be dates (`DateTimeInterface`) or texts.
- **In results**, a date is its point in time in UTC as stored: `2026-11-09T09:00:00.123456Z`. As text (`||`, `LIKE`) too, so `LIKE '2026-11%'` matches November in UTC; in arithmetic it is the seconds since 1970-01-01 UTC (`DATE_ADD(a, 1, 'DAY') - a` is 86400).
- **Functions** take a date, a text that is one or a number (seconds since 1970); the time zone is UTC unless given as the last argument (an identifier like `'Europe/Berlin'` or an offset like `'+01:00'`). A value that isn't a date gives `NULL`.

| function | |
|---|---|
| `NOW()` | the current time, the same for the whole statement |
| `DATETIME(x [, zone])` | `x` as a date; a text without offset in the zone: `DATETIME('2026-01-01 10:00', 'Europe/Berlin')` |
| `DATE(x [, zone])` | the day as text, `'2026-11-09'` |
| `YEAR`, `MONTH`, `DAY`, `HOUR`, `MINUTE`, `SECOND(x [, zone])` | the part as a number: `GROUP BY YEAR(createdAt, 'Europe/Berlin'), MONTH(createdAt, 'Europe/Berlin')` |
| `DATE_ADD(x, amount, unit)` | plus a whole number of `SECOND`, `MINUTE`, `HOUR`, `DAY`, `WEEK`, `MONTH` or `YEAR` (also plural, negative to subtract), in the time zone of the date: a day is a day of its calendar, also across daylight saving time. A month that doesn't have the day goes on into the next (`DATE_ADD('2026-01-31', 1, 'MONTH')` is March 3), as in PHP and SQLite. |

```sql
SELECT title, createdAt FROM Post WHERE createdAt >= DATE_ADD(NOW(), -7, 'DAY') ORDER BY createdAt DESC
SELECT YEAR(createdAt) AS y, MONTH(createdAt) AS m, COUNT(*) FROM Post GROUP BY y, m ORDER BY y, m
```

`INSERT` and `UPDATE` write dates as the storage does: a property declared as a date (`DateTimeImmutable`, `DateTime`, `DateTimeInterface`, nullable) takes a date or a text that is one (`SET publishedAt = '2026-11-09T10:00+01:00'`, written with the offset as its time zone), in the declared class; a property without type takes a date (`DATETIME(…)`, `NOW()`), a text stays a text. A date for a property declared as `string` is refused. Indexes aren't used for a text that is a date (`createdAt = '2026-01-01'`): they don't have dates as text.

### Joins

`[INNER] JOIN` keeps the pairs whose condition is true, `LEFT JOIN` also the rows without a match, with `NULL` for the joined source. `ON` sees the sources joined before it. A join comparing with `=` (e.g. `ON c = p.category`, also with further conditions by `AND`) is done by hashing, any other condition by trying every pair.

### Grouping and sorting

- With `GROUP BY` or aggregates, every column outside of an aggregate must be an expression of `GROUP BY`: `SELECT category.title, COUNT(*) FROM Product GROUP BY category.title`.
- `ORDER BY`, `GROUP BY` and `HAVING` may name a column of the selection by its name (`ORDER BY products DESC`), `ORDER BY` and `GROUP BY` also by its position (`ORDER BY 2`). A name of the selection wins over a property of the same name.
- `NULL` sorts first ascending and last descending. Rows with equal values keep the order in which the objects were read.
- `LIMIT` and `OFFSET` take whole numbers (or parameters); `OFFSET` works without `LIMIT`.

## INSERT, UPDATE and DELETE

The statements write the data of the objects as stored, like [copying](transfer.md) does (`writeRaw()`): objects aren't loaded and stored again, which would write their referenced objects along. The result lists the UUIDs of the objects written (`$result->column(0)`), `$result->affected()` their number.

- **Values** are those of `SELECT` (expressions, parameters, functions); `UPDATE` computes them from the values the object had before the statement (`SET price = 10, note = 'was ' || price` uses the old price). A reference is written with `REF(uuid)`: `REF(:uuid)`, `REF(other.category.UUID)`; it must name an existing object. Only properties of the object itself are written (no paths), and no lists or objects other than references yet.
- **Checked against the class**, if it can be loaded: the types of the properties (`string`, `int`, `float`, `bool`, nullable, unions; a class for `REF()`), properties the class doesn't declare unless it allows dynamic ones (`stdClass`, `#[AllowDynamicProperties]`). A number without a fraction is an `int` (`36.0` for an `int` is 36); `7.2` for an `int` is an error, not cut off. `INSERT` needs the class; it sets the properties not given to their defaults (also those of promoted parameters of the constructor, which loading doesn't call), or `NULL` if the type allows it — a property without either needs a value. `UPDATE` and `DELETE` of objects whose class isn't loadable write without checks.
- **All or nothing before writing**: every value of every object is computed and checked first; an error writes nothing. Then each object is written under its exclusive lock: read again, its condition checked again (another process may have changed it in the meantime), written with a new checksum and revision. If writing one fails — e.g. a [unique](unique.md) value — the objects written before stay written, and the error says how many (`Stopped after 1 of 2 objects were updated: …`).
- **Indexes follow**: unique indexes are checked, [search indexes](search.md) updated (`METADATA_SAVED`, `AFTER_STORE`, `AFTER_DELETE`), as for any write; events of loaded objects (`BEFORE_UPDATE`, `OBJECT_SAVED`) aren't dispatched, as no objects are loaded.
- `UPDATE` counts the objects that changed (an object already having the new values isn't written), `DELETE` deletes with the [child deletion policy](configuration.md) of the storage.
- **Dry run**: `query($query, dryRun: true)` computes and checks everything and returns the objects that would be written (for `INSERT` without UUIDs), writing nothing.

## Command line and viewer

```bash
object-storage query --dir ./data "SELECT name, price FROM Product WHERE price < :max ORDER BY price" --param max=10
object-storage query --dir ./data --json --file report.oql
echo "SELECT COUNT(*) FROM Product" | object-storage query --dir ./data
```

`--param name=value` sets `:name`, `--param value` the next `?`; numbers, `true`, `false` and `null` are taken as such, `"quoted"` as text. `INSERT`, `UPDATE` and `DELETE` print the UUIDs written and their number (`--json`: `{"statement", "dryRun", "affected", "uuids"}`); `--dry-run` writes nothing.

In the viewer, **Query** on the list of classes (or of the objects of a class) opens the console: the query is highlighted as it is typed (keywords, `UUID`, functions, texts, numbers, parameters, comments), Ctrl+Enter runs it, the result links UUIDs to the records, errors name their line and column and are underlined in the query until it is changed, and the last queries are kept in the browser (**History**). `INSERT`, `UPDATE` and `DELETE` ask before running and run from the form only (POST), so a link or the history never writes; **Dry run** lists the objects without writing.

## Performance and memory

**Streaming.** Objects are read one after another and dropped once they've been checked: memory depends on the result, not on the number of objects. `WHERE`, `COUNT(*)` and other aggregates over 100,000 objects run within PHP's default `memory_limit` of 128 MB.
- `LIMIT` without `ORDER BY` stops reading once it has enough rows.
- `ORDER BY … LIMIT n` keeps only the first `offset + n` rows while reading (a heap).
- `GROUP BY` keeps, per group, one row and the running values of its aggregates.
- Of joined objects and rows, only the properties the query uses are kept.
- Referenced objects that paths lead to are cached per query, at most 10,000 of them.

What has to stay in memory is the result itself: sorting without `LIMIT`, `DISTINCT`, and the objects on the right side of a `JOIN` (only their used properties). `query(…, maxRows: 1000)` keeps at most 1,000 rows; `$result->truncated` tells whether there were more (the viewer does this).

The caches of the storage are bounded: `ObjectStorage` and configurations of type `memory` keep at most `InMemoryCache::DEFAULT_MAX_ENTRIES` (10,000) objects and metadata, dropping the least recently used (`new InMemoryCache(maxEntries: …)`; without `maxEntries`, unbounded as before).

**Indexes.** Without an index, a query reads all objects of its class (the condition is checked on each). Indexes are used for conditions joined by `AND` at the top level of `WHERE`, so that only the objects they name are read:

| Condition | Index |
|---|---|
| `UUID = ?`, `UUID IN (…)` | none needed |
| `path = 'text'`, `path IN ('text', …)` | a [unique index](unique.md) on exactly this path |
| the same, on a filterable `string` field | a Loupe [search index](search.md) of the class that is built and has no pending changes, passed as `new Oql($storage, $searchManager)` |

```php
$oql = new Oql($storage, $searchManager);          // the unique indexes of the storage are found by themselves
$oql->query("SELECT * FROM User WHERE name = ?", ['Homa']);
$oql->query("EXPLAIN SELECT * FROM User WHERE name = ?", ['Homa']);
// source | class | access       | details
// User   | User  | search index | users: name IN ('Homa'), 1 object(s)
```

An index is only used when it names *every* object the condition can be true for; the whole condition is still checked on each object it names, so results are the same with or without indexes (`new Oql($storage, useIndexes: false)` to compare). That's why these aren't looked up and read all objects:
- `OR`, `NOT`, `!=`, `LIKE`, `<`, `>`;
- number fields;
- texts that look like numbers (`'12'` equals the number 12 in OQL, the index keeps them apart);
- search indexes with pending changes, or naming more than 999 objects.

A search index only knows the writes made while its manager was attached (the viewer attaches it). `EXPLAIN SELECT …` returns, per source, how its objects are found. In the viewer, the console uses the search and unique indexes of the storage.

## Tests

OQL is tested against SQLite: random objects with references are stored in the object storage and in SQLite, and random queries (joins, paths, `WHERE` with `NULL`, grouping, aggregates, `HAVING`, `DISTINCT`, sorting, `LIMIT`) are run in both languages and their results compared (`OqlSqliteTest`, 750 queries; `OQL_SEEDS=20 OQL_QUERIES=1000` for more). So are random sequences of `INSERT`, `UPDATE` and `DELETE`, comparing all objects after every statement (180 statements; `OQL_WRITES=150` for more per seed). `UPDATE` there reads no other objects of the class it writes: SQLite evaluates those while it updates the table, row by row, OQL — as the SQL standard — on the state before the statement.
