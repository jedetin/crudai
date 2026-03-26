# CRUDAI API

> Point it at a MySQL database. Get a fully working REST API.

CRUDAI API is a PHP code generator that reads your database schema — either a live MySQL connection or a `.sql` DDL file — and writes a complete, flat, dependency-free REST API for every table. No framework, no Composer, no runtime magic. The output is plain PHP you can read, understand, and deploy anywhere.

Inspired by [Cruddiy](https://github.com/jan-vandenberg/cruddiy), which does the same for Bootstrap CRUD UIs. This project targets API-first workflows.

---

## What it generates

Given this schema:

```sql
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE bookings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','confirmed','cancelled') DEFAULT 'pending',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

You get a working API with these routes — automatically:

```
GET    /users                          list, paginated
GET    /users?name=Alice               filtered by column value
GET    /users/912                      single record
GET    /users/912/bookings             nested: all bookings for user 912
GET    /users/912/bookings?status=confirmed   nested + filtered
POST   /users                          create
PUT    /users/912                      full update
DELETE /users/912                      delete
```

Every table gets its own handler. Foreign key relationships are detected automatically and drive the nested route generation.

---

## Requirements

- PHP 8.2+ (uses `readonly` classes)
- MySQL 5.7+ or MariaDB 10.3+
- A web server with `mod_rewrite` (Apache) or equivalent rewrite support (Nginx)

No Composer. No external dependencies.

---

## Quickstart

**1. Clone the repository**

```bash
git clone https://github.com/yourusername/CRUDAI-api.git
cd CRUDAI-api
```

**2. Configure `run.php`**

Open `run.php` and set your database credentials and source mode:

```php
$config = [
    'host' => 'localhost',
    'name' => 'your_database',
    'user' => 'root',
    'pass' => 'secret',
];

// 'ddl' to parse a local .sql file, 'pdo' to introspect a live database
$mode    = 'ddl';
$ddlFile = __DIR__ . '/schema.sql';
```

**3. Add your schema**

Either drop your `CREATE TABLE` statements into `schema.sql`, or set `$mode = 'pdo'` to read the live database directly.

**4. Run the generator**

```bash
php run.php
```

Output:

```
CRUDAI API Generator
----------------------------------------
Mode      : ddl
Database  : hotel
Output    : /your/path/api

Tables found: users, properties, rooms, bookings, payments

Generating files...
  [ok] users.php
  [ok] properties.php
  [ok] rooms.php
  [ok] bookings.php
  [ok] payments.php
  [ok] index.php

Done. Output: /your/path/api
```

**5. Copy the runtime core**

The `api/core/` directory contains three small files (`DB.php`, `Request.php`, `Response.php`) that are **not** generated — copy them into your `api/core/` folder once and leave them there.

**6. Add the rewrite rule**

Create `api/.htaccess`:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
```

For Nginx:

```nginx
location /api/ {
    try_files $uri $uri/ /api/index.php?$query_string;
}
```

**7. Hit the API**

```bash
curl http://localhost/api/users
curl http://localhost/api/users/1
curl http://localhost/api/users/1/bookings?status=confirmed

curl -X POST http://localhost/api/users \
  -H "Content-Type: application/json" \
  -d '{"name":"Alice","email":"alice@example.com"}'

curl -X PUT http://localhost/api/users/1 \
  -H "Content-Type: application/json" \
  -d '{"name":"Alice Smith","email":"alice@example.com"}'

curl -X DELETE http://localhost/api/users/1
```

---

## Route reference

| Method | Pattern | Description |
|--------|---------|-------------|
| `GET` | `/{table}` | List all records. Supports `?page=`, `?per_page=`, and any column as a filter (`?status=active`). |
| `GET` | `/{table}/{id}` | Single record by primary key. Returns 404 if not found. |
| `GET` | `/{table}/{id}/{related}` | All records in `{related}` that have a foreign key pointing to `{table}.id`. Supports the same filters and pagination as a normal list. |
| `POST` | `/{table}` | Create a record. Body: JSON object. Auto-managed columns (`id`, `created_at`, `updated_at`) are excluded. Returns 201 with the created record. |
| `PUT` | `/{table}/{id}` | Full update. Body: JSON object with all updatable fields. Returns the updated record. |
| `DELETE` | `/{table}/{id}` | Delete by primary key. Returns 204 on success. |

### Pagination

All list endpoints are paginated:

```
GET /bookings?page=2&per_page=10
```

Response envelope:

```json
{
  "data": [ ... ],
  "meta": {
    "page": 2,
    "per_page": 10,
    "total": 83
  }
}
```

### Filtering

Pass any column name as a query parameter for exact-match filtering:

```
GET /bookings?status=confirmed
GET /properties?location=goa
GET /rooms?capacity=2
```

Multiple filters are combined with `AND`.

---

## Validation

The generator inspects each column and emits validation rules automatically:

| Column property | Rule emitted |
|----------------|-------------|
| `NOT NULL` with no default | field is required |
| `ENUM('a','b','c')` | value must be one of the allowed values |
| `INT` / `BIGINT` | value must be a valid integer |

Validation errors return HTTP 422:

```json
{
  "errors": {
    "email": "required",
    "status": "must be one of: 'pending','confirmed','cancelled'"
  }
}
```

---

## Response format

All responses are JSON with appropriate HTTP status codes.

| Situation | Status |
|-----------|--------|
| Successful read | 200 |
| Record created | 201 |
| Successful delete | 204 |
| Validation failed | 422 |
| Record not found | 404 |
| Wrong method | 405 |

---

## Project structure

```
CRUDAI-api/
│
├── run.php                          ← Entry point: php run.php
├── schema.sql                       ← Your DDL input (DDL mode)
│
├── generator/                       ← The generator (not deployed)
│   ├── Schema.php                   ← Readonly data structs (IR)
│   ├── SchemaParser.php             ← DDL parser + PDO introspection
│   ├── CrudGenerator.php            ← Template renderer + file writer
│   └── templates/
│       ├── handler.tpl.php          ← Per-table handler template
│       └── index.tpl.php           ← Router template
│
└── api/                             ← Generated output (deploy this)
    ├── index.php                    ← Router (generated)
    ├── users.php                    ← Handler: all verbs for users (generated)
    ├── bookings.php                 ← Handler: all verbs for bookings (generated)
    ├── ...
    └── core/                        ← Runtime core (written once, not generated)
        ├── DB.php                   ← PDO singleton
        ├── Request.php             ← HTTP request wrapper
        └── Response.php           ← JSON response helpers
```

The `generator/` directory is never deployed. Only the `api/` directory needs to be served.

---

## Regenerating

Run `php run.php` again at any time. All files in `api/` (except `core/`) are overwritten on each run. Do not manually edit generated files — any customisation will be lost on the next generation.

If you need to extend a handler, the recommended approach is to place your custom logic in a separate file and call it from a hook — see [Contributing / Roadmap](#roadmap) below.

---

## Schema source modes

**DDL mode** (default) — no database connection needed at generation time:

```php
$mode    = 'ddl';
$ddlFile = __DIR__ . '/schema.sql';
```

Parses `CREATE TABLE` statements directly. Supports backtick-quoted identifiers, multi-line `FOREIGN KEY` constraints, `ENUM` types, inline and table-level `PRIMARY KEY`, and `ON DELETE` rules.

**PDO mode** — introspects a live database:

```php
$mode = 'pdo';
```

Queries `information_schema.COLUMNS`, `information_schema.KEY_COLUMN_USAGE`, and `information_schema.REFERENTIAL_CONSTRAINTS` to build the same internal representation as DDL mode. Useful when your schema is managed by migrations rather than a maintained DDL file.

---

## Roadmap

The following are known improvements in rough priority order. Contributions welcome.

- **FK integrity validation** — check that FK values exist in the referenced table before INSERT/UPDATE, returning 422 rather than a raw DB constraint error
- **PATCH support** — partial updates (only send the fields you want to change)
- **Sorting** — `?sort=price&order=desc`
- **Full-text search** — `?q=keyword` across text columns
- **Eager loading** — `?expand=user,room` to inline FK-related records
- **Auth layer** — optional Bearer token / API key check in the router
- **OpenAPI export** — generate an `openapi.json` spec alongside the PHP files
- **Middleware hooks** — `before_read` / `after_write` callbacks for custom logic without editing generated files
- **Self-referencing table support** — `categories.parent_id → categories.id` (generates `GET /categories/{id}/children`)
- **Composite FK support** — multi-column foreign keys

---

## Contributing

Pull requests are welcome. Please open an issue first for anything beyond a small fix.

When touching the generator, keep the `generator/` and `api/` separation clean — nothing in `api/core/` should depend on anything in `generator/`, and the generator should have no runtime footprint.

---

## License

MIT
