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

function smtp_read_response($socket, array $expectedCodes, string $phase): void
{
    $lastLine = '';
    do {
        $line = fgets($socket, 515);
        if ($line === false) {
            throw new RuntimeException('SMTP connection closed during ' . $phase . '.');
        }
        $lastLine = $line;
    } while (isset($line[3]) && $line[3] === '-');

    $code = (int) substr($lastLine, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP rejected ' . $phase . ' (response ' . $code . ').');
    }
}

function smtp_command($socket, string $command, array $expectedCodes, string $phase): void
{
    if (fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException('Could not send SMTP ' . $phase . ' command.');
    }
    smtp_read_response($socket, $expectedCodes, $phase);
}

function send_app_mail(string $to, string $subject, string $body, array $config): bool
{
    $smtpHost = trim((string) ($config['smtp_host'] ?? ''));
    $smtpUser = trim((string) ($config['smtp_username'] ?? ''));
    $smtpPass = (string) ($config['smtp_password'] ?? '');

    if ($smtpHost === '' || $smtpUser === '' || $smtpPass === '') {
        error_log('Password reset email is not configured: add SMTP settings to the private config.local.php.');
        return false;
    }

    $port = (int) ($config['smtp_port'] ?? 465);
    $encryption = strtolower(trim((string) ($config['smtp_encryption'] ?? 'ssl')));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($smtpUser, FILTER_VALIDATE_EMAIL)
        || !in_array($encryption, ['ssl', 'tls'], true) || $port < 1 || $port > 65535) {
        error_log('Password reset SMTP configuration is invalid.');
        return false;
    }

    $from = (string) ($config['mail_from'] ?? $smtpUser);
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $from = $smtpUser;
    }

    $socket = false;
    try {
        $endpoint = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $smtpHost . ':' . $port;
        $socket = stream_socket_client($endpoint, $errorCode, $errorMessage, 15, STREAM_CLIENT_CONNECT);
        if ($socket === false) {
            throw new RuntimeException('Could not connect to configured SMTP server (error ' . $errorCode . ').');
        }
        stream_set_timeout($socket, 15);
        smtp_read_response($socket, [220], 'connection');

        smtp_command($socket, 'EHLO barangaybalangan.site', [250], 'EHLO');
        if ($encryption === 'tls') {
            smtp_command($socket, 'STARTTLS', [220], 'STARTTLS');
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Could not enable TLS encryption.');
            }
            smtp_command($socket, 'EHLO barangaybalangan.site', [250], 'EHLO after TLS');
        }

        smtp_command($socket, 'AUTH LOGIN', [334], 'authentication');
        smtp_command($socket, base64_encode($smtpUser), [334], 'username authentication');
        smtp_command($socket, base64_encode($smtpPass), [235], 'password authentication');
        smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250], 'sender');
        smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251], 'recipient');
        smtp_command($socket, 'DATA', [354], 'message data');

        $safeSubject = str_replace(["\r", "\n"], '', $subject);
        $message = "From: Barangay Balagan Health Center <{$from}>\r\n";
        $message .= "To: <{$to}>\r\n";
        $message .= "Subject: {$safeSubject}\r\n";
        $message .= 'Date: ' . date(DATE_RFC2822) . "\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $normalizedBody = preg_replace("/\r\n|\r|\n/", "\r\n", $body) ?? $body;
        $message .= preg_replace('/(^|\r\n)\./', '$1..', $normalizedBody) . "\r\n.\r\n";
        if (fwrite($socket, $message) === false) {
            throw new RuntimeException('Could not send SMTP message data.');
        }
        smtp_read_response($socket, [250], 'message acceptance');
        smtp_command($socket, 'QUIT', [221], 'disconnect');
        fclose($socket);
        return true;
    } catch (Throwable $exception) {
        error_log('Password reset SMTP delivery failed: ' . $exception->getMessage());
        if (is_resource($socket)) {
            fclose($socket);
        }
        return false;
    }
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
        'role' => (string) ($user['role'] ?? 'user'),
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
        $pdo->exec("ALTER TABLE users ADD COLUMN role ENUM('user', 'admin') NOT NULL DEFAULT 'user' AFTER password_hash");
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

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            used_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_password_reset_user_created (user_id, created_at),
            KEY idx_password_reset_expiry (expires_at),
            CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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

