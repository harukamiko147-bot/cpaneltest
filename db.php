<?php
declare(strict_types=1);

$sessionDir = dirname(__DIR__) . '/sessions';
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0775, true);
}
session_save_path($sessionDir);
session_start();

function app_config(): array
{
    // On cPanel, keep database credentials one directory above public_html.
    foreach ([dirname(__DIR__) . '/config.local.php', __DIR__ . '/config.local.php'] as $deploymentConfigPath) {
        if (is_file($deploymentConfigPath)) {
            $deploymentConfig = require $deploymentConfigPath;
            if (is_array($deploymentConfig)) {
                return $deploymentConfig;
            }
        }
    }

    return [
        'host' => getenv('BBHC_DB_HOST') ?: '127.0.0.1',
        'database' => getenv('BBHC_DB_NAME') ?: 'bbhc_online_appointments',
        'username' => getenv('BBHC_DB_USER') ?: 'root',
        'password' => getenv('BBHC_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ];
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config();
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['database'],
        $config['charset']
    );

    try {
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $exception) {
        throw new RuntimeException('Database connection failed. Check the private deployment configuration.', 0, $exception);
    }

    return $pdo;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function build_url(string $path, array $params = []): string
{
    if (!$params) {
        return $path;
    }

    return $path . '?' . http_build_query($params);
}

function redirect_to(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function redirect_with_message(string $url, string $message, string $type = 'success'): void
{
    $separator = str_contains($url, '?') ? '&' : '?';
    redirect_to($url . $separator . http_build_query([
        'message' => $message,
        'type' => $type,
    ]));
}

function redirect_to_page(string $path, string $page, string $message, string $type = 'success'): void
{
    redirect_to(build_url($path, [
        'page' => $page,
        'message' => $message,
        'type' => $type,
    ]));
}

function post_string(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function post_int(string $key, int $default = 0): int
{
    return (int) ($_POST[$key] ?? $default);
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function current_user_role(): ?string
{
    return current_user()['role'] ?? null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    return current_user_role() === 'admin';
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect_to('index.php?page=login');
    }
}

function require_admin(): void
{
    if (!is_admin()) {
        redirect_to('admin.php?page=login');
    }
}

function login_user(array $user): void
{
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'first_name' => (string) $user['first_name'],
        'last_name' => (string) $user['last_name'],
        'username' => (string) $user['username'],
        'email' => (string) $user['email'],
        'role' => (string) ($user['role'] ?? 'patient'),
        'photo_path' => (string) ($user['photo_path'] ?? ''),
    ];
}

function user_display_name(array $user): string
{
    $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
    return $name ?: trim((string) ($user['full_name'] ?? '')) ?: 'Guest';
}

function logout_user(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function refresh_logged_in_user(PDO $pdo): void
{
    if (!is_logged_in()) {
        return;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => current_user()['id']]);
    $user = $stmt->fetch();

    if ($user) {
        login_user($user);
    }
}

function paginate_rows(array $rows, int $page, int $perPage): array
{
    $total = count($rows);
    $pages = max(1, (int) ceil($total / max(1, $perPage)));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;

    return [
        'items' => array_slice($rows, $offset, $perPage),
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'pages' => $pages,
        'start' => $total === 0 ? 0 : $offset + 1,
        'end' => min($offset + $perPage, $total),
    ];
}

function render_pagination_links(string $basePath, array $baseParams, string $pageParam, array $meta, string $fragment = ''): void
{
    if ((int) $meta['pages'] <= 1) {
        return;
    }

    $page = (int) $meta['page'];
    $pages = (int) $meta['pages'];
    $start = max(1, $page - 1);
    $end = min($pages, $page + 1);

    $renderLink = static function (int $targetPage, string $label, string $class = '') use ($basePath, $baseParams, $pageParam, $fragment): void {
        $params = $baseParams;
        $params[$pageParam] = $targetPage;
        echo '<a class="page-link' . $class . '" href="' . e(build_url($basePath, $params) . $fragment) . '">' . e($label) . '</a>';
    };

    $renderLink(max(1, $page - 1), 'Prev', $page <= 1 ? ' disabled' : '');

    if ($start > 1) {
        $renderLink(1, '1');
        if ($start > 2) {
            echo '<span class="page-ellipsis">...</span>';
        }
    }

    for ($currentPage = $start; $currentPage <= $end; $currentPage++) {
        $renderLink($currentPage, (string) $currentPage, $currentPage === $page ? ' active' : '');
    }

    if ($end < $pages) {
        if ($end < $pages - 1) {
            echo '<span class="page-ellipsis">...</span>';
        }
        $renderLink($pages, (string) $pages);
    }

    $renderLink(min($pages, $page + 1), 'Next', $page >= $pages ? ' disabled' : '');
}

function ensure_schema_compatibility(PDO $pdo): void
{
    $hasUserRole = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    if (!$hasUserRole) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role ENUM('patient', 'admin') NOT NULL DEFAULT 'patient' AFTER password_hash");
    } else {
        $roleType = strtolower(str_replace(' ', '', (string) ($hasUserRole['Type'] ?? '')));
        $roleDefault = (string) ($hasUserRole['Default'] ?? '');

        if (str_contains($roleType, "'user'")) {
            // Allow both old and new values during the data conversion.
            $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('user', 'patient', 'admin') NOT NULL DEFAULT 'patient'");
            $pdo->exec("UPDATE users SET role = 'patient' WHERE role = 'user'");
            $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('patient', 'admin') NOT NULL DEFAULT 'patient'");
        } elseif ($roleType !== "enum('patient','admin')" || $roleDefault !== 'patient') {
            $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('patient', 'admin') NOT NULL DEFAULT 'patient'");
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notifications (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            message VARCHAR(255) NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_notifications_user (user_id, is_read),
            CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $hasFirstName = $pdo->query("SHOW COLUMNS FROM users LIKE 'first_name'")->fetch();
    if (!$hasFirstName) {
        $pdo->exec("ALTER TABLE users ADD COLUMN first_name VARCHAR(60) NOT NULL DEFAULT '' AFTER id");
        $pdo->exec("ALTER TABLE users ADD COLUMN last_name VARCHAR(60) NOT NULL DEFAULT '' AFTER first_name");
        $pdo->exec("UPDATE users SET first_name = TRIM(SUBSTRING_INDEX(full_name, ' ', 1)), last_name = TRIM(SUBSTRING(full_name, CHAR_LENGTH(SUBSTRING_INDEX(full_name, ' ', 1)) + 1))");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS clinic_staff (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            full_name VARCHAR(120) NOT NULL,
            role ENUM('Doctor', 'Nurse', 'Midwife') NOT NULL,
            specialty VARCHAR(160) NOT NULL,
            contact_number VARCHAR(30) NOT NULL,
            availability VARCHAR(120) NOT NULL DEFAULT 'Available',
            status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            photo_path VARCHAR(255) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_clinic_staff_role (role),
            KEY idx_clinic_staff_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $hasScheduleStaff = $pdo->query("SHOW COLUMNS FROM schedules LIKE 'staff_id'")->fetch();
    if (!$hasScheduleStaff) {
        $pdo->exec("ALTER TABLE schedules ADD COLUMN staff_id INT UNSIGNED NULL AFTER service_id");
        try {
            $pdo->exec(
                "ALTER TABLE schedules
                    ADD CONSTRAINT fk_schedules_staff
                    FOREIGN KEY (staff_id) REFERENCES clinic_staff(id)
                    ON UPDATE CASCADE
                    ON DELETE SET NULL"
            );
        } catch (Throwable) {
            // If the foreign key already exists or the table is in a transient state, continue safely.
        }
    }
}

function ensure_seed_data(PDO $pdo): void
{
    ensure_schema_compatibility($pdo);
}

function format_date(?string $date): string
{
    if (!$date) {
        return 'N/A';
    }

    try {
        return (new DateTimeImmutable($date))->format('M d, Y');
    } catch (Throwable) {
        return $date;
    }
}

function format_time(?string $time): string
{
    if (!$time) {
        return 'N/A';
    }

    try {
        return (new DateTimeImmutable($time))->format('g:i A');
    } catch (Throwable) {
        return $time;
    }
}

function age_from_birthdate(string $birthdate): int
{
    try {
        return (int) (new DateTimeImmutable($birthdate))->diff(new DateTimeImmutable('today'))->y;
    } catch (Throwable) {
        return 0;
    }
}

function appointment_count(PDO $pdo, int $scheduleId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM appointments WHERE schedule_id = :schedule_id AND status NOT IN ('cancelled', 'no_show')");
    $stmt->execute([':schedule_id' => $scheduleId]);
    return (int) $stmt->fetch()['total'];
}

function create_notification(PDO $pdo, int $userId, string $message): void
{
    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, message) VALUES (:user_id, :message)');
    $stmt->execute([':user_id' => $userId, ':message' => $message]);
}

function save_uploaded_image(string $fieldName, string $prefix, ?string $oldPath = null): ?string
{
    if (empty($_FILES[$fieldName]['name'])) {
        return $oldPath;
    }

    if (($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return $oldPath;
    }

    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $extension = strtolower(pathinfo((string) $_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed, true)) {
        return $oldPath;
    }

    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $fileName = $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $target = $uploadDir . '/' . $fileName;

    if (!move_uploaded_file((string) $_FILES[$fieldName]['tmp_name'], $target)) {
        return $oldPath;
    }

    return 'uploads/' . $fileName;
}

