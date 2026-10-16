# Barangay Balagan Health Center Online Appointment Scheduling System

This is a focused PHP and MySQL website for online appointment scheduling.

## Main Logic

The health center posts available schedules first. Patients can only request appointments from those posted schedules, so the appointment date and time are controlled by the Barangay Balagan Health Center schedule.

## Features

- Resident portal for registration, login, email verification-code password recovery, profile updates, and online appointment requests
- Separate admin dashboard for appointment management, schedule posting, and clinic staff management
- Service list for consultation, prenatal, immunization, newborn/child injection, child growth monitoring, family planning, and follow-up checkup
- Automatic appointment code
- Slot limit per schedule
- Appointment list with patient profile
- Status tracking: pending, confirmed, completed, cancelled, and no show
- Remarks field for health center staff
- Animated glass-style UI with dashboard counters and reveal effects

## Files

- `index.php` resident portal
- `admin.php` separate admin dashboard
- `db.php` database connection and helpers
- `schema.sql` MySQL database and starter data

## Run

1. Start MySQL in XAMPP, or run `setup-barangay-balagan-database.bat` to initialize the database.
2. Double-click `run-barangay-balagan-health-center.bat` in this folder.
3. Open `http://127.0.0.1:8000/`.

If your local MySQL root account has a password, set `BBHC_DB_PASS` in your environment before running either batch file. Do not store the database password in project files.

## Admin Login

The app does not create a default administrator account. Create an admin account for an authorized clinic staff member in the `users` table before opening the admin dashboard at `admin.php`. Do not use a shared or default password. Resident users should use `index.php`.

## cPanel deployment

- Create a MySQL database and a dedicated database user in cPanel. Grant that user access to the app database only.
- Save the connection settings in `/home/<cpanel-user>/config.local.php`, one level above `public_html`. Use `config.local.example.php` as a template, and replace every placeholder before use.
- Import `schema.sql` into the new database. Add clinic staff and appointment schedules only after confirming that the names, contact details, availability, and services are accurate.
- Create a cPanel email mailbox on the domain and add the SMTP settings from `config.local.example.php` to the private `/home/<cpanel-user>/config.local.php`. Use the cPanel **Connect Devices** values for the SMTP hostname if it differs from the example; keep the mailbox password only in that private file. Password recovery requires authenticated SMTP delivery.
- Point the domain to the hosting account and enable SSL before collecting resident information.
- Keep `config.local.php`, database exports, and uploaded documents outside version control and outside the public web root.

