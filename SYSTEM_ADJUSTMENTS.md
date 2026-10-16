# System adjustments

## Corrected issues

- The database setup launcher now checks MySQL, starts the local XAMPP MySQL server when it is stopped, waits for it to accept connections, and only then imports the schema.
- Removed redundant `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` statements. The columns already belong to the table definitions, and the statements can fail on older XAMPP MySQL versions.
- Database connection errors now identify the missing service and give the exact recovery action instead of exposing a raw PDO error.

## Run order

1. Run `setup-barangay-balagan-database.bat`.
2. Run `run-barangay-balagan-health-center.bat`.
3. Open `http://127.0.0.1:8000/`.

