# OQL by example

[OQL](../query.md) is the SQL-like query language of the object storage. This page teaches it with examples on one small shop — products, customers, purchases — from the first `SELECT` to joins, lists, dates, writing and changing what is stored. The [reference](../query.md) has every rule in detail.

Every query on this page is tested: [`OqlByExampleTest`](../../tests/melia/ObjectStorage/Examples/OqlByExampleTest.php) runs the SQL blocks in the order of the page, on the data below, and compares the results shown. Writing statements change the data for the queries after them, as they would for you.

> OQL is **experimental** in 1.x: its syntax may still change in a minor release (see the [Changelog](../../Changelog.md)).

**Contents:** [The example data](#the-example-data) · [Running a query](#running-a-query) · [First queries](#first-queries) · [Conditions](#conditions) · [Computed values](#computed-values) · [Math](#math) · [Regular expressions](#regular-expressions) · [Paths into referenced objects](#paths-into-referenced-objects) · [Joins](#joins) · [Lists and keys](#lists-and-keys) · [Grouping](#grouping-and-aggregates) · [Dates and NOW()](#dates-and-now) · [Writing](#writing-insert-update-delete) · [Created and modified](#when-objects-were-created-and-modified) · [Adding a property, e.g. a date](#adding-a-property-eg-a-date) · [Renaming and removing properties](#renaming-and-removing-properties) · [Indexes and EXPLAIN](#indexes-and-explain) · [Command line and viewer](#command-line-and-viewer) · [Coming from SQL](#coming-from-sql)

## The example data

Plain PHP classes — there is no schema: the properties of the objects are the "columns", the classes the "tables".

```php
use DateTimeImmutable;

final class Category
{
    public function __construct(
        public string    $title,
        public ?Category $parent = null,
    ) {}
}

final class Product
{
    public function __construct(
        public string   $name,
        public float    $price,
        public Category $category,
        public bool     $active = true,
        public array    $labels = [],   // list of strings
    ) {}
}

final class Customer
{
    public function __construct(
        public string  $name,
        public string  $email,
        public ?string $city = null,
        public array   $tags = [],      // list of strings
        public array   $phones = [],    // map: kind => number
    ) {}
}

final class PurchaseLine
{
    public function __construct(
        public Product $product,
        public int     $quantity,
        public float   $price,
    ) {}
}

final class Purchase
{
    // added later, see "Adding a property": not promoted, so objects stored before load with null
    public ?DateTimeImmutable $paidAt = null;

    public function __construct(
        public string             $number,
        public Customer           $customer,
        public DateTimeImmutable  $createdAt,
        public array              $lines = [],      // list of PurchaseLine
        public string             $status = 'open',
        public ?DateTimeImmutable $shippedAt = null,
    ) {}
}
```

The objects, stored as usual. Referenced objects (a category, the customer of a purchase, its lines) are stored along and become references.

```php
$food = new Category('Food');
$fruit = new Category('Fruit', $food);
$furniture = new Category('Furniture');

$apple = new Product('Apple', 1.5, $fruit, labels: ['bio', 'regional']);
$banana = new Product('Banana', 2.0, $fruit, labels: ['bio']);
$bread = new Product('Bread', 3.2, $food);
$chair = new Product('Chair', 49.0, $furniture);
$lamp = new Product('Lamp', 25.0, $furniture, active: false);
foreach ([$apple, $banana, $bread, $chair, $lamp] as $product) {
    $storage->store($product);
}

$homa = new Customer('Homa', 'homa@example.com', 'Berlin', ['vip'], ['mobile' => '+49 170 1111111', 'work' => '+49 30 2222222']);
$alex = new Customer('Alex', 'alex@example.com', 'Köln', [], ['mobile' => '+49 171 3333333']);
$kim = new Customer('Kim', 'kim@example.com');
foreach ([$homa, $alex, $kim] as $customer) {
    $storage->store($customer);
}

$storage->store(new Purchase('A-1', $homa, new DateTimeImmutable('2026-10-03T09:15:00Z'),
    [new PurchaseLine($apple, 4, 1.5), new PurchaseLine($bread, 1, 3.2)],
    status: 'shipped', shippedAt: new DateTimeImmutable('2026-10-04T14:00:00Z')));
$storage->store(new Purchase('A-2', $homa, new DateTimeImmutable('2026-11-02T18:40:00Z'),
    [new PurchaseLine($chair, 2, 49.0)]));
$storage->store(new Purchase('A-3', $alex, new DateTimeImmutable('2026-11-05T08:00:00Z'),
    [new PurchaseLine($banana, 6, 2.0), new PurchaseLine($apple, 2, 1.5)]));
```

## Running a query

In PHP, `Oql::query()` takes the query and its parameters. **Pass values as parameters** (`:name` or `?`), never by concatenating strings: parameters are never read as part of the query.

```php
use melia\ObjectStorage\Query\Oql;

$oql = new Oql($storage);

$result = $oql->query(
    'SELECT name, price FROM Product WHERE price <= :max AND category.title = :category ORDER BY price',
    ['max' => 10, 'category' => 'Fruit'],
);

$result->columns;            // ['name', 'price']
$result->rows;               // [['Apple', 1.5], ['Banana', 2]] (2.0 is stored as 2 in JSON)
foreach ($result as $row) {  // rows by the names of the columns
    echo $row['name'], ': ', $row['price'], "\n";
}
$result->column('name');     // ['Apple', 'Banana']
count($result);              // 2

$oql->query('SELECT name FROM Product WHERE price BETWEEN ? AND ?', [3, 4]);   // ? by position

// at most 2 rows; $result->truncated tells whether there were more
$result = $oql->query('SELECT name FROM Product ORDER BY name', maxRows: 2);
```

The same queries run on the [command line](#command-line-and-viewer) and in the query console of the viewer. The results below are shown as `$result->rows` returns them; `NULL` is `null`.

## First queries

All products, cheapest first. With one class after `FROM`, properties are named directly:

```sql
SELECT name, price FROM Product ORDER BY price
```
<!-- result -->
| name | price |
|---|---|
| Apple | 1.5 |
| Banana | 2 |
| Bread | 3.2 |
| Lamp | 25 |
| Chair | 49 |

A condition, and sorting by several columns (`DESC` for descending):

```sql
SELECT name, price, active FROM Product WHERE price < 30 ORDER BY active DESC, name
```
<!-- result -->
| name | price | active |
|---|---|---|
| Apple | 1.5 | TRUE |
| Banana | 2 | TRUE |
| Bread | 3.2 | TRUE |
| Lamp | 25 | FALSE |

Paging with `LIMIT` and `OFFSET`; the second and third most expensive product:

```sql
SELECT name, price FROM Product ORDER BY price DESC LIMIT 2 OFFSET 1
```
<!-- result -->
| name | price |
|---|---|
| Lamp | 25 |
| Bread | 3.2 |

`DISTINCT` and an alias (`AS`) for a column. `NULL` sorts first:

```sql
SELECT DISTINCT city AS town FROM Customer ORDER BY town
```
<!-- result -->
| town |
|---|
| NULL |
| Berlin |
| Köln |

An alias for the class works as in SQL; with several classes (joins) it is needed:

```sql
SELECT p.name, p.price FROM Product AS p WHERE p.price > 20 ORDER BY p.name
```
<!-- result -->
| p.name | p.price |
|---|---|
| Chair | 49 |
| Lamp | 25 |

## Conditions

`LIKE` matches the whole value and ignores case (`%` any characters, `_` one):

```sql
SELECT name FROM Product WHERE name LIKE 'b%' ORDER BY name
```
<!-- result -->
| name |
|---|
| Banana |
| Bread |

`IN`, `BETWEEN` (both ends included), `AND`, `OR`, `NOT`:

```sql
SELECT name, price FROM Product
WHERE price BETWEEN 2 AND 30 AND NOT name IN ('Lamp', 'Bread')
   OR name = 'Chair'
ORDER BY price
```
<!-- result -->
| name | price |
|---|---|
| Banana | 2 |
| Chair | 49 |

Booleans are `TRUE` and `FALSE` (the numbers 1 and 0):

```sql
SELECT name FROM Product WHERE active = FALSE
```
<!-- result -->
| name |
|---|
| Lamp |

**`NULL` is unknown**, as in SQL: test it with `IS NULL` / `IS NOT NULL`, never with `= NULL`.

```sql
SELECT name FROM Customer WHERE city IS NULL
```
<!-- result -->
| name |
|---|
| Kim |

A pitfall known from SQL: `city NOT IN ('Berlin')` is unknown for Kim, whose city is `NULL`, so Kim isn't found:

```sql
SELECT name FROM Customer WHERE city NOT IN ('Berlin') ORDER BY name
```
<!-- result -->
| name |
|---|
| Alex |

`COALESCE` (or `IFNULL`) replaces `NULL` with a value:

```sql
SELECT name FROM Customer WHERE COALESCE(city, '') NOT IN ('Berlin') ORDER BY name
```
<!-- result -->
| name |
|---|
| Alex |
| Kim |

## Computed values

Arithmetic, functions, and `||` to join texts. A `NULL` in a calculation gives `NULL`; `IFNULL` gives a default:

```sql
SELECT name,
       ROUND(price * 1.19, 2) AS gross,
       UPPER(name) AS shouting,
       LENGTH(name) AS letters,
       name || ' (' || IFNULL(category.title, '-') || ')' AS label
FROM Product
WHERE active = TRUE
ORDER BY name
```
<!-- result -->
| name | gross | shouting | letters | label |
|---|---|---|---|---|
| Apple | 1.79 | APPLE | 5 | Apple (Fruit) |
| Banana | 2.38 | BANANA | 6 | Banana (Fruit) |
| Bread | 3.81 | BREAD | 5 | Bread (Food) |
| Chair | 58.31 | CHAIR | 5 | Chair (Furniture) |

```sql
SELECT name, city || ', DE' AS address, IFNULL(city, 'unknown') AS town FROM Customer ORDER BY name
```
<!-- result -->
| name | address | town |
|---|---|---|
| Alex | Köln, DE | Köln |
| Homa | Berlin, DE | Berlin |
| Kim | NULL | unknown |

**`CONCAT` joins texts and leaves `NULL` out** (unlike `||`); `CONCAT_WS` puts a separator between the values that aren't `NULL` — handy for addresses or labels:

```sql
SELECT name,
       name || ' – ' || city AS withPipes,
       CONCAT(name, ' – ', city) AS withConcat,
       CONCAT_WS(', ', name, city, phones['work']) AS contact
FROM Customer
ORDER BY name
```
<!-- result -->
| name | withPipes | withConcat | contact |
|---|---|---|---|
| Alex | Alex – Köln | Alex – Köln | Alex, Köln |
| Homa | Homa – Berlin | Homa – Berlin | Homa, Berlin, +49 30 2222222 |
| Kim | NULL | Kim –  | Kim |

Functions: `LOWER`, `UPPER`, `LENGTH`, `TRIM`, `SUBSTR(text, start [, length])`, `ABS`, `ROUND(number [, digits])`, `COALESCE`, `IFNULL`, `CONCAT`, `CONCAT_WS`, `REGEXP_SUBSTR`, `REGEXP_REPLACE`, and those of [dates](#dates-and-now).

### Math

`+ - * /`, `%` (remainder) and `DIV` (integer quotient) are operators; `CEIL`, `FLOOR`, `TRUNCATE`, `POW`, `SQRT`, `MOD` and more are functions, as in MySQL:

```sql
SELECT l.product.name AS product,
       l.quantity * l.price AS total,
       l.quantity DIV 4 AS packs,
       l.quantity % 4 AS single,
       CEIL(l.price) AS up,
       FLOOR(l.price) AS down,
       TRUNCATE(l.price * 1.19, 1) AS gross,
       POW(l.quantity, 2) AS squared
FROM Purchase p
JOIN PurchaseLine l ON l = p.lines.*
WHERE p.number = 'A-3'
ORDER BY product
```
<!-- result -->
| product | total | packs | single | up | down | gross | squared |
|---|---|---|---|---|---|---|---|
| Apple | 3.0 | 0 | 2 | 2 | 1 | 1.7 | 4 |
| Banana | 12 | 1 | 2 | 2 | 2 | 2.3 | 36 |

### Regular expressions

`REGEXP` matches a regular expression **anywhere** in the value; `^` and `$` anchor it. It minds case — `(?i)` ignores it:

```sql
SELECT name, email FROM Customer WHERE email REGEXP '^[a-z]+@example\.com$' ORDER BY name
```
<!-- result -->
| name | email |
|---|---|
| Alex | alex@example.com |
| Homa | homa@example.com |
| Kim | kim@example.com |

```sql
SELECT name FROM Product WHERE name REGEXP '(?i)^b|r$' ORDER BY name
```
<!-- result -->
| name |
|---|
| Banana |
| Bread |
| Chair |

`NOT REGEXP`, and on lists: true if any element matches (negated: none):

```sql
SELECT number FROM Purchase WHERE number NOT REGEXP '[13]$'
```
<!-- result -->
| number |
|---|
| A-2 |

```sql
SELECT name FROM Customer WHERE phones.* REGEXP '^\+49 30 '
```
<!-- result -->
| name |
|---|
| Homa |

`REGEXP_SUBSTR` returns the first match, `REGEXP_REPLACE` replaces all of them (`$1` for a group):

```sql
SELECT name,
       REGEXP_SUBSTR(email, '[^@]+$') AS domain,
       REGEXP_REPLACE(phones['mobile'], '[^0-9]', '') AS digits,
       REGEXP_REPLACE(email, '^(.)[^@]*', '$1***') AS masked
FROM Customer
ORDER BY name
```
<!-- result -->
| name | domain | digits | masked |
|---|---|---|---|
| Alex | example.com | 491713333333 | a***@example.com |
| Homa | example.com | 491701111111 | h***@example.com |
| Kim | example.com | NULL | k***@example.com |

## Paths into referenced objects

A property holding an object is a reference. **A path with dots follows it** into the referenced object — no join needed. Up to three references per path:

```sql
SELECT name, category.title AS category, category.parent.title AS parent
FROM Product
ORDER BY name
```
<!-- result -->
| name | category | parent |
|---|---|---|
| Apple | Fruit | Food |
| Banana | Fruit | Food |
| Bread | Food | NULL |
| Chair | Furniture | NULL |
| Lamp | Furniture | NULL |

Paths work in every part of a query, here in `WHERE`: all products of the category Food, also those of its subcategories.

```sql
SELECT name FROM Product
WHERE category.title = 'Food' OR category.parent.title = 'Food'
ORDER BY name
```
<!-- result -->
| name |
|---|
| Apple |
| Banana |
| Bread |

`UUID` is the UUID of an object, at the end of a path the UUID of the referenced object (`category.UUID`). It is a keyword; it varies with every storage, so it isn't shown here:

```php
$oql->query('SELECT UUID, name FROM Product WHERE UUID = ?', [$uuid]);
$oql->query('SELECT name FROM Product WHERE category.UUID = ?', [$categoryUuid]);
```

## Joins

`JOIN … ON` combines objects of two classes. **The alias alone is the object** (its UUID), and a reference is the UUID of its object, so `ON c = p.customer` joins the customer of each purchase:

```sql
SELECT p.number, c.name AS customer, c.city
FROM Purchase p
JOIN Customer c ON c = p.customer
ORDER BY p.number
```
<!-- result -->
| p.number | customer | c.city |
|---|---|---|
| A-1 | Homa | Berlin |
| A-2 | Homa | Berlin |
| A-3 | Alex | Köln |

For one value a path is shorter: `p.customer.name`. A join is needed to start from the other side — all customers with their purchases. `LEFT JOIN` keeps the customers without any (their purchase is `NULL`):

```sql
SELECT c.name, p.number
FROM Customer c
LEFT JOIN Purchase p ON p.customer = c
ORDER BY c.name, p.number
```
<!-- result -->
| c.name | p.number |
|---|---|
| Alex | A-3 |
| Homa | A-1 |
| Homa | A-2 |
| Kim | NULL |

Customers who never bought anything — `LEFT JOIN` and `IS NULL`:

```sql
SELECT c.name
FROM Customer c
LEFT JOIN Purchase p ON p.customer = c
WHERE p IS NULL
```
<!-- result -->
| c.name |
|---|
| Kim |

**Joining the elements of a list**: `p.lines.*` are all lines of a purchase, so each line becomes a row; a second join adds its product:

```sql
SELECT p.number, pr.name AS product, l.quantity, l.price
FROM Purchase p
JOIN PurchaseLine l ON l = p.lines.*
JOIN Product pr ON pr = l.product
ORDER BY p.number, pr.name
```
<!-- result -->
| p.number | product | l.quantity | l.price |
|---|---|---|---|
| A-1 | Apple | 4 | 1.5 |
| A-1 | Bread | 1 | 3.2 |
| A-2 | Chair | 2 | 49 |
| A-3 | Apple | 2 | 1.5 |
| A-3 | Banana | 6 | 2 |

## Lists and keys

A list (an array) can be read as a whole, element by element (`*`), by position (`[0]`, `[-1]` for the last) and by key (`['mobile']`) — also through references:

```sql
SELECT number,
       lines.*.product.name AS products,
       lines[0].product.name AS first,
       lines[-1].quantity AS lastQuantity
FROM Purchase
ORDER BY number
```
<!-- result -->
| number | products | first | lastQuantity |
|---|---|---|---|
| A-1 | ["Apple","Bread"] | Apple | 1 |
| A-2 | ["Chair"] | Chair | 2 |
| A-3 | ["Banana","Apple"] | Banana | 2 |

```sql
SELECT name, tags, phones['mobile'] AS mobile, phones['work'] AS work FROM Customer ORDER BY name
```
<!-- result -->
| name | tags | mobile | work |
|---|---|---|---|
| Alex | [] | +49 171 3333333 | NULL |
| Homa | ["vip"] | +49 170 1111111 | +49 30 2222222 |
| Kim | [] | NULL | NULL |

**A condition on a path with `*` is true if any element matches** — purchases with apples, customers tagged `vip`:

```sql
SELECT number FROM Purchase WHERE lines.*.product.name = 'Apple' ORDER BY number
```
<!-- result -->
| number |
|---|
| A-1 |
| A-3 |

```sql
SELECT name FROM Customer WHERE tags.* = 'vip'
```
<!-- result -->
| name |
|---|
| Homa |

Also with `LIKE`, `IN`, `<`, `BETWEEN` — products with a label starting with "reg", purchases with a line of 5 or more pieces:

```sql
SELECT name FROM Product WHERE labels.* LIKE 'reg%'
```
<!-- result -->
| name |
|---|
| Apple |

```sql
SELECT number FROM Purchase WHERE lines.*.quantity >= 5
```
<!-- result -->
| number |
|---|
| A-3 |

**Negated, it is true if no element matches** (also for an empty list) — purchases without apples:

```sql
SELECT number FROM Purchase WHERE lines.*.product.name != 'Apple'
```
<!-- result -->
| number |
|---|
| A-2 |

```sql
SELECT name FROM Product WHERE labels.* NOT IN ('bio') ORDER BY name
```
<!-- result -->
| name |
|---|
| Bread |
| Chair |
| Lamp |

Across several objects: customers who bought something of the category Fruit (purchase → customer is the other direction, so a join; purchase → line → product → category is a path):

```sql
SELECT DISTINCT c.name
FROM Customer c
JOIN Purchase p ON p.customer = c
WHERE p.lines.*.product.category.title = 'Fruit'
ORDER BY c.name
```
<!-- result -->
| c.name |
|---|
| Alex |
| Homa |

## Grouping and aggregates

`COUNT`, `SUM`, `AVG`, `MIN`, `MAX` with `GROUP BY`; `HAVING` filters the groups. `ORDER BY` may name a column of the selection:

```sql
SELECT category.title AS category,
       COUNT(*) AS products,
       MIN(price) AS cheapest,
       ROUND(AVG(price), 2) AS average
FROM Product
GROUP BY category.title
ORDER BY products DESC, category
```
<!-- result -->
| category | products | cheapest | average |
|---|---|---|---|
| Fruit | 2 | 1.5 | 1.75 |
| Furniture | 2 | 25 | 37.0 |
| Food | 1 | 3.2 | 3.2 |

```sql
SELECT category.title AS category, COUNT(*) AS products
FROM Product
GROUP BY category.title
HAVING COUNT(*) > 1
ORDER BY category
```
<!-- result -->
| category | products |
|---|---|
| Fruit | 2 |
| Furniture | 2 |

Without `GROUP BY`, aggregates make one row. `COUNT(x)` counts the values that aren't `NULL`, `COUNT(DISTINCT x)` the different ones:

```sql
SELECT COUNT(*) AS customers, COUNT(city) AS withCity, COUNT(DISTINCT city) AS cities FROM Customer
```
<!-- result -->
| customers | withCity | cities |
|---|---|---|
| 3 | 2 | 2 |

Revenue per customer, from the lines of their purchases:

```sql
SELECT c.name, COUNT(DISTINCT p.number) AS purchases, SUM(l.quantity * l.price) AS revenue
FROM Purchase p
JOIN Customer c ON c = p.customer
JOIN PurchaseLine l ON l = p.lines.*
GROUP BY c.name
ORDER BY revenue DESC
```
<!-- result -->
| c.name | purchases | revenue |
|---|---|---|
| Homa | 2 | 107.2 |
| Alex | 1 | 15.0 |

Best-selling products by pieces:

```sql
SELECT l.product.name AS product, SUM(l.quantity) AS pieces
FROM Purchase p
JOIN PurchaseLine l ON l = p.lines.*
GROUP BY l.product.name
ORDER BY pieces DESC, product
LIMIT 3
```
<!-- result -->
| product | pieces |
|---|---|
| Apple | 6 |
| Banana | 6 |
| Chair | 2 |

## Dates and NOW()

Dates (`DateTimeInterface` properties) are compared by their point in time. In results they are their time in UTC as stored. **A text compared with a date is read as a date**; without an offset it is UTC:

```sql
SELECT number, createdAt FROM Purchase WHERE createdAt >= '2026-11-01' ORDER BY createdAt
```
<!-- result -->
| number | createdAt |
|---|---|
| A-2 | 2026-11-02T18:40:00.000000Z |
| A-3 | 2026-11-05T08:00:00.000000Z |

With a time zone (an offset or a name), e.g. from midnight in Berlin, and `BETWEEN` for a period:

```sql
SELECT number FROM Purchase
WHERE createdAt BETWEEN '2026-11-01T00:00+01:00' AND '2026-11-03 00:00'
```
<!-- result -->
| number |
|---|
| A-2 |

The parts of a date, in UTC or in a time zone given as the last argument (A-2 was bought at 18:40 UTC, 19:40 in Berlin):

```sql
SELECT number,
       DATE(createdAt) AS dayUtc,
       DATE(createdAt, 'Europe/Berlin') AS dayBerlin,
       HOUR(createdAt, 'Europe/Berlin') AS hourBerlin,
       YEAR(createdAt) AS year,
       MONTH(createdAt) AS month
FROM Purchase
ORDER BY number
```
<!-- result -->
| number | dayUtc | dayBerlin | hourBerlin | year | month |
|---|---|---|---|---|---|
| A-1 | 2026-10-03 | 2026-10-03 | 11 | 2026 | 10 |
| A-2 | 2026-11-02 | 2026-11-02 | 19 | 2026 | 11 |
| A-3 | 2026-11-05 | 2026-11-05 | 9 | 2026 | 11 |

Purchases per month:

```sql
SELECT YEAR(createdAt) AS y, MONTH(createdAt) AS m, COUNT(*) AS purchases
FROM Purchase
GROUP BY y, m
ORDER BY y, m
```
<!-- result -->
| y | m | purchases |
|---|---|---|
| 2026 | 10 | 1 |
| 2026 | 11 | 2 |

`DATE_ADD(date, amount, unit)` adds `SECOND`, `MINUTE`, `HOUR`, `DAY`, `WEEK`, `MONTH` or `YEAR`, `DATE_SUB` subtracts them. In arithmetic a date is its seconds since 1970, so a difference is a duration in seconds:

```sql
SELECT number,
       DATE_ADD(createdAt, 14, 'DAY') AS due,
       DATE_SUB(createdAt, 1, 'MONTH') AS monthBefore,
       ROUND((shippedAt - createdAt) / 3600, 1) AS hoursToShip
FROM Purchase
ORDER BY number
```
<!-- result -->
| number | due | monthBefore | hoursToShip |
|---|---|---|---|
| A-1 | 2026-10-17T09:15:00.000000Z | 2026-09-03T09:15:00.000000Z | 28.8 |
| A-2 | 2026-11-16T18:40:00.000000Z | 2026-10-02T18:40:00.000000Z | NULL |
| A-3 | 2026-11-19T08:00:00.000000Z | 2026-10-05T08:00:00.000000Z | NULL |

`DATETIME(text, zone)` makes a date of a text in a time zone — 08:30 in Berlin is 07:30 UTC, before A-3:

```sql
SELECT number FROM Purchase WHERE createdAt < DATETIME('2026-11-05 08:30', 'Europe/Berlin') ORDER BY number
```
<!-- result -->
| number |
|---|
| A-1 |
| A-2 |

**`NOW()` is the current time**, the same for the whole statement. The results depend on today, so none are shown:

```sql
-- purchases of the last 30 days
SELECT number, createdAt FROM Purchase WHERE createdAt >= DATE_SUB(NOW(), 30, 'DAY');

-- open purchases older than a week
SELECT number FROM Purchase WHERE status = 'open' AND createdAt < DATE_SUB(NOW(), 1, 'WEEK');

-- the age of each purchase in days
SELECT number, ROUND((NOW() - createdAt) / 86400) AS days FROM Purchase ORDER BY number;

-- today in Berlin
SELECT number FROM Purchase WHERE DATE(createdAt, 'Europe/Berlin') = DATE(NOW(), 'Europe/Berlin')
```

Parameters may be dates, in any time zone:

```php
$oql->query('SELECT number FROM Purchase WHERE createdAt >= :since', [
    'since' => new DateTimeImmutable('2026-11-01 00:00', new DateTimeZone('Europe/Berlin')),
]);
```

## Writing: INSERT, UPDATE, DELETE

Writing statements are checked against the classes (types, nullability, unknown properties) **before anything is written**; an error writes nothing. Search and unique indexes follow. The result lists the UUIDs of the objects written, `$result->affected()` their number.

**INSERT** creates objects of a class, several at once. Properties not given get their defaults (here `parent`):

```sql
INSERT INTO Category (title) VALUES ('Garden'), ('Toys');
SELECT title, parent FROM Category WHERE title IN ('Garden', 'Toys') ORDER BY title
```
<!-- result -->
| title | parent |
|---|---|
| Garden | NULL |
| Toys | NULL |

**A reference is written with `REF(uuid)`**, the UUID best passed as a parameter. Properties of a class with a default (`active`, `labels`, `status`, `lines`) can be left out; `NOW()` is the current time:

```php
$garden = $oql->query("SELECT UUID FROM Category WHERE title = 'Garden'")->rows[0][0];
$oql->query('INSERT INTO Product (name, price, category) VALUES (:name, :price, REF(:category))', [
    'name' => 'Shovel', 'price' => 12.5, 'category' => $garden,
]);

// a purchase of today
$oql->query('INSERT INTO Purchase (number, customer, createdAt) VALUES (:number, REF(:customer), NOW())', [
    'number' => 'A-4', 'customer' => $kimUuid,
]);
```

**UPDATE** computes the new values from the old ones of each object; the condition may use paths:

```sql
UPDATE Product SET price = ROUND(price * 1.1, 2) WHERE category.title = 'Fruit';
SELECT name, price FROM Product WHERE category.title = 'Fruit' ORDER BY name
```
<!-- result -->
| name | price |
|---|---|
| Apple | 1.65 |
| Banana | 2.2 |

Dates, with `NOW()` or a text that is a date (into a property declared as a date):

```sql
UPDATE Purchase SET status = 'shipped', shippedAt = NOW() WHERE number = 'A-2';
UPDATE Purchase SET status = 'shipped', shippedAt = '2026-11-06T10:00:00+01:00' WHERE number = 'A-3';
SELECT number, status, shippedAt IS NOT NULL AS shipped FROM Purchase ORDER BY number
```
<!-- result -->
| number | status | shipped |
|---|---|---|
| A-1 | shipped | TRUE |
| A-2 | shipped | TRUE |
| A-3 | shipped | TRUE |

`REF()` also takes a UUID from a path: Banana moves from Fruit to the parent category of Fruit, Food:

```sql
UPDATE Product SET category = REF(category.parent.UUID) WHERE name = 'Banana';
SELECT name, category.title AS category FROM Product WHERE name IN ('Apple', 'Banana') ORDER BY name
```
<!-- result -->
| name | category |
|---|---|
| Apple | Fruit |
| Banana | Food |

**Lists and maps are changed with paths**: `SET` a key (`phones['fax']`), an element by position (`tags[0]`, `tags[-1]`) or a new element at the end (`tags[]`); `REMOVE` takes keys and elements out (the rest of a list moves up):

```sql
UPDATE Customer SET tags[] = 'newsletter', phones['fax'] = '+49 171 4444444' WHERE name = 'Alex';
SELECT name, tags, phones['fax'] AS fax FROM Customer WHERE name = 'Alex'
```
<!-- result -->
| name | tags | fax |
|---|---|---|
| Alex | ["newsletter"] | +49 171 4444444 |

```sql
UPDATE Customer REMOVE tags[-1], phones['fax'] WHERE name = 'Alex';
SELECT name, tags, phones['fax'] AS fax FROM Customer WHERE name = 'Alex'
```
<!-- result -->
| name | tags | fax |
|---|---|---|
| Alex | [] | NULL |

**DELETE** deletes objects (and referenced objects, as the child deletion policy of the storage says):

```sql
DELETE FROM Product WHERE active = FALSE;
SELECT COUNT(*) AS products FROM Product
```
<!-- result -->
| products |
|---|
| 4 |

**Dry run**: computes and checks everything, returns the objects that would be written, writes nothing:

```php
$result = $oql->query("UPDATE Product SET price = price * 0.9 WHERE category.title = 'Furniture'", dryRun: true);
$result->dryRun;      // true
$result->affected();  // the number of objects that would be changed
```

Errors name the problem, before anything is written:

<!-- not run -->
```sql
UPDATE Product SET price = 'cheap'
-- price of Product is float, not the text 'cheap'

UPDATE Product SET color = 'red'
-- Product has no property color

UPDATE Product SET category = NULL
-- category of Product is Category: NULL isn't possible

UPDATE Purchase SET lines[0].quantity = 3
-- lines[0].quantity leads into a referenced object: UPDATE writes only the object itself, …
```

## When objects were created and modified

Every object knows when it was first stored and when its data was written last — `CREATED()` and `MODIFIED()` read it, e.g. to find stale objects. `touch($uuid)` sets "modified" without changing the data (like touch on a file). The values depend on when the example ran, so no results are shown:

```sql
-- products not changed for 30 days
SELECT name, MODIFIED() AS modified FROM Product WHERE MODIFIED() < DATE_SUB(NOW(), 30, 'DAY');

-- customers by their latest change, with the alias in joins
SELECT c.name, CREATED(c) AS created, MODIFIED(c) AS modified FROM Customer c ORDER BY MODIFIED(c) DESC;

-- how many objects were changed per day
SELECT DATE(MODIFIED()) AS day, COUNT(*) AS changed FROM Purchase GROUP BY day ORDER BY day
```

```php
$storage->touch($uuid);            // modified: now, the data unchanged
$storage->getModifiedAt($uuid);    // DateTimeImmutable in UTC
```

## Adding a property, e.g. a date

**Objects have no schema, so there is no `ALTER … ADD`: a new property is a new property of the class.** OQL then fills it for the objects stored before. Say purchases get a payment date:

```php
final class Purchase
{
    public ?DateTimeImmutable $paidAt = null;   // new
    // …
}
```

- Declare it **with a default** (`= null`), outside of the constructor: objects stored before don't have it, and loading sets the defaults of properties, not those of constructor parameters (a new promoted parameter would stay uninitialized for them).
- In OQL, a property an object doesn't have is `NULL` — before and after it is filled.

Then fill it with `UPDATE`, e.g. from other values or with `NOW()`:

```sql
SELECT number, status, paidAt FROM Purchase ORDER BY number
```
<!-- result -->
| number | status | paidAt |
|---|---|---|
| A-1 | shipped | NULL |
| A-2 | shipped | NULL |
| A-3 | shipped | NULL |

```sql
-- shipped purchases were paid when they were bought
UPDATE Purchase SET paidAt = createdAt WHERE status = 'shipped' AND paidAt IS NULL;
SELECT number, paidAt FROM Purchase ORDER BY number
```
<!-- result -->
| number | paidAt |
|---|---|
| A-1 | 2026-10-03T09:15:00.000000Z |
| A-2 | 2026-11-02T18:40:00.000000Z |
| A-3 | 2026-11-05T08:00:00.000000Z |

```sql
-- any others: now (here there are none left)
UPDATE Purchase SET paidAt = NOW() WHERE paidAt IS NULL;
SELECT COUNT(*) AS unpaid FROM Purchase WHERE paidAt IS NULL
```
<!-- result -->
| unpaid |
|---|
| 0 |

New objects get the date with `INSERT … VALUES (…, NOW())`, or from PHP as usual. For `stdClass` and classes with `#[AllowDynamicProperties]`, `UPDATE` sets a new property without changing a class: `UPDATE Note SET reviewedAt = NOW()`.

## Renaming and removing properties

Renaming a property, step by step — the class first, then the data:

1. Add the new property to the class (`public ?string $town = null;`).
2. Copy the values: `UPDATE Customer SET town = city`.
3. Remove the old property from the class.
4. Remove it from the stored objects:

<!-- not run -->
```sql
ALTER CLASS Customer DROP ATTRIBUTE city
```

**`ALTER CLASS … DROP ATTRIBUTE`** removes properties from all stored objects of a class. For those coming from SQL, `ALTER TABLE … DROP COLUMN` is the same:

<!-- not run -->
```sql
ALTER CLASS Customer DROP ATTRIBUTE city, DROP ATTRIBUTE legacyId;
ALTER TABLE Customer DROP COLUMN city
```

A property the class still declares is refused (loading would give it its default, so it would come back): remove it from the class first. Dropping works for any class, also for those that can't be loaded anymore. With a dry run it lists the objects that have the property. (The class of this page still has `city`, so the two blocks above aren't run.)

## Indexes and EXPLAIN

Without an index, a query reads all objects of its class. `EXPLAIN` tells which way a query takes:

```php
use melia\ObjectStorage\Unique\Definition;
use melia\ObjectStorage\Unique\Manager;

$unique = new Manager($storage);
$unique->create(new Definition('customer-email', Customer::class, 'email'));

$oql = new Oql($storage, unique: $unique);
$oql->query("EXPLAIN SELECT name FROM Customer WHERE email = 'kim@example.com'")->rows;
// [['Customer', 'Customer', 'unique index', 'customer-email: email IN (1 value(s)), 1 object(s)']]
$oql->query("EXPLAIN SELECT name FROM Customer WHERE city = 'Berlin'")->rows[0][2];
// 'all objects of the class'
```

`=` and `IN` with texts use a [unique index](../unique.md) on the path. A Loupe [search index](../search.md) (`new Oql($storage, $searchManager)`) answers more — `=`, `IN`, `<`, `>`, `BETWEEN` on number, date and boolean fields, `=`/`IN` on text fields:

```php
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\Manager;
use melia\ObjectStorage\Search\Loupe\LoupeEngine;

$search = new Manager($storage, [new LoupeEngine()]);
$search->create(new Definition('products', Product::class, [
    new Field('name', 'name', searchable: true, filterable: true),
    new Field('price', 'price', FieldType::NUMBER, searchable: false, filterable: true),
    new Field('active', 'active', FieldType::BOOLEAN, searchable: false, filterable: true),
    new Field('category', 'category.title', searchable: false, filterable: true),
]))->build();
$search->attach();   // keeps it up to date with every write

$oql = new Oql($storage, $search);
$oql->query("EXPLAIN SELECT name FROM Product WHERE price BETWEEN 2 AND 30 AND active = TRUE")->rows[0];
// ['Product', 'Product', 'search index', 'products: ((price >= 2 AND price <= 30) OR price IS NULL) AND (active = true OR active IS NULL), 2 object(s)']
$oql->query("EXPLAIN SELECT name FROM Product WHERE category.title = 'Fruit'")->rows[0][2];   // 'search index'
$oql->query("EXPLAIN SELECT name FROM Product WHERE price > 10 OR active = FALSE")->rows[0][2];   // 'all objects of the class': OR
```

Results are the same with or without indexes: the index only names the objects to read, each is checked against the whole condition. See [Indexes](../query.md#performance-and-memory) for what is looked up.

## Command line and viewer

```bash
object-storage query --dir var/storage "SELECT name, price FROM Product WHERE price < :max ORDER BY price" --param max=10
object-storage query --dir var/storage --json "SELECT category.title, COUNT(*) FROM Product GROUP BY category.title"
object-storage query --dir var/storage --dry-run "UPDATE Product SET price = price * 1.1 WHERE category.title = 'Fruit'"
object-storage query --dir var/storage --file report.oql
```

In the [viewer](../maintenance.md#the-web-viewer), **Query** opens the console: highlighting, Ctrl+Enter to run, links to the records, errors marked in the query, a history. Writing statements ask before running; **Dry run** lists the objects instead.

## Coming from SQL

| SQL | OQL |
|---|---|
| table, row, column | class, object, property |
| `CREATE TABLE`, `ALTER TABLE … ADD COLUMN` | a class, a property of the class; fill it with `UPDATE` ([above](#adding-a-property-eg-a-date)) |
| `ALTER TABLE … DROP COLUMN` | the same, or `ALTER CLASS … DROP ATTRIBUTE` |
| primary key | `UUID` |
| foreign key, `JOIN` on it | a reference: a path `p.category.title`, or `JOIN Category c ON c = p.category` |
| a table between two tables (n:m) | a list of references: `p.lines.*`, `JOIN … ON l = p.lines.*` |
| `INSERT … VALUES (…, 42)` for a foreign key | `REF(:uuid)` |
| `CURRENT_TIMESTAMP`, `NOW()` | `NOW()` |
| `DATE_ADD(d, INTERVAL 1 DAY)`, `DATE_SUB(d, INTERVAL 1 DAY)` | `DATE_ADD(d, 1, 'DAY')`, `DATE_SUB(d, 1, 'DAY')` |
| `CONCAT(a, b)`, `CONCAT_WS(', ', a, b)` | the same; `NULL` is left out (as in PostgreSQL, not MySQL) |
| `EXTRACT(YEAR FROM d)` | `YEAR(d)`, also `YEAR(d, 'Europe/Berlin')` |
| subqueries, `UNION`, `WITH`, window functions | not supported (yet); use joins, or several queries |
| `col = ANY(array)` | `list.* = value` |
| `col REGEXP 'p'` (MySQL), `col ~ 'p'` (PostgreSQL) | `col REGEXP 'p'`; `(?i)` to ignore case |
| `REGEXP_SUBSTR`, `REGEXP_REPLACE` | the same |
| `a % b`, `MOD`, `a DIV b`, `CEIL`, `FLOOR`, `TRUNCATE`, `POW`, `SQRT`, `LOG`, … | the same as in MySQL |
| `updated_at` columns | `MODIFIED(p)` from the metadata, and `touch($uuid)` |
| `TRUE`/`FALSE` | `TRUE`/`FALSE` (the numbers 1 and 0) |
| `"quoted identifier"` | `` `quoted` `` or `"quoted"`; texts in `'single quotes'` |
| a keyword as a name (`order`, `uuid`) | in backticks: `` `order` ``, `` p.`uuid` `` |
