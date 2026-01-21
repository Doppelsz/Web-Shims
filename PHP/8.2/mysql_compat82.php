<?php

// INCLUDE with minimal issues
//require_once __DIR__ . '/inc/mysql_compat.php';
//require_once __DIR__ . '/config.php'; // if your config sets $dbhost/$dbuser/$dbpass/$dbname

if (function_exists('mysql_query')) {
    // If some polyfill already exists, do nothing.
    return;
}

/* --------------------------- internal state --------------------------- */

final class _MysqlCompatLink {
    public PDO $pdo;
    public string $hostInfo = '';
    public string $db = '';
    public function __construct(PDO $pdo, string $hostInfo, string $db) {
        $this->pdo = $pdo;
        $this->hostInfo = $hostInfo;
        $this->db = $db;
    }
}

final class _MysqlCompatResult {
    /** @var list<array<string,mixed>> */
    public array $rows;
    public int $pos = 0;
    /** @var list<string> */
    public array $fields;
    public int $affected = 0;

    public function __construct(array $rows, array $fields, int $affected = 0) {
        $this->rows = $rows;
        $this->fields = $fields;
        $this->affected = $affected;
    }
}

final class _MysqlCompat {
    public static ?_MysqlCompatLink $link = null;
    public static int $errno = 0;
    public static string $error = '';
    public static int|string $insertId = 0;
    public static int $affected = 0;

    public static function setErr(int $no, string $msg): void { self::$errno = $no; self::$error = $msg; }
    public static function clrErr(): void { self::$errno = 0; self::$error = ''; }
}

/* --------------------------- config discovery -------------------------- */

function _mysql_compat_guess_config(): array {
    // Common 2000s config variable names
    $candidates = [
        'host' => ['dbhost', 'db_host', 'mysql_host', 'host'],
        'user' => ['dbuser', 'db_user', 'mysql_user', 'user', 'username'],
        'pass' => ['dbpass', 'db_pass', 'mysql_pass', 'pass', 'password'],
        'name' => ['dbname', 'db_name', 'mysql_db', 'database', 'db'],
        'port' => ['dbport', 'db_port', 'mysql_port', 'port'],
    ];

    $out = ['host' => '127.0.0.1', 'user' => '', 'pass' => '', 'name' => '', 'port' => 3306];

    foreach ($candidates as $k => $vars) {
        foreach ($vars as $v) {
            if (isset($GLOBALS[$v]) && $GLOBALS[$v] !== '') {
                $out[$k] = $GLOBALS[$v];
                break;
            }
        }
    }

    // If host is "host:port"
    if (is_string($out['host']) && str_contains($out['host'], ':')) {
        [$h, $p] = explode(':', $out['host'], 2);
        if ($h !== '') $out['host'] = $h;
        if (ctype_digit((string)$p)) $out['port'] = (int)$p;
    }

    $out['port'] = (int)($out['port'] ?: 3306);
    return $out;
}

function _mysql_compat_connect(?string $host, ?string $user, ?string $pass, ?string $db = null): _MysqlCompatLink {
    $cfg = _mysql_compat_guess_config();

    $host = ($host !== null && $host !== '') ? $host : (string)$cfg['host'];
    $user = ($user !== null) ? $user : (string)$cfg['user'];
    $pass = ($pass !== null) ? $pass : (string)$cfg['pass'];
    $db   = ($db !== null && $db !== '') ? $db : (string)$cfg['name'];
    $port = (int)$cfg['port'];

    // Support "host:port" passed to mysql_connect
    if (str_contains($host, ':')) {
        [$h, $p] = explode(':', $host, 2);
        if ($h !== '') $host = $h;
        if (ctype_digit((string)$p)) $port = (int)$p;
    }

    $dsn = "mysql:host={$host};port={$port}";
    if ($db !== '') $dsn .= ";dbname={$db}";
    $dsn .= ";charset=utf8";

    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Old apps often rely on “it just works” stringy behavior:
        PDO::ATTR_EMULATE_PREPARES   => true,
    ];

    $pdo = new PDO($dsn, (string)$user, (string)$pass, $opts);
    // Old mysql_* usually ended up in latin1 or server default; utf8 here is generally safest.
    // If your app breaks, you can change this line:
    $pdo->exec("SET NAMES utf8");

    return new _MysqlCompatLink($pdo, "{$host}:{$port}", $db);
}

/* ------------------------------ mysql_* -------------------------------- */

function mysql_connect(string $server = null, string $username = null, string $password = null, bool $new_link = false, int $client_flags = 0) {
    try {
        _MysqlCompat::$link = _mysql_compat_connect($server, $username, $password, null);
        _MysqlCompat::clrErr();
        return _MysqlCompat::$link; // resource-like object
    } catch (Throwable $e) {
        _MysqlCompat::setErr(2002, $e->getMessage());
        return false;
    }
}

function mysql_pconnect(string $server = null, string $username = null, string $password = null, int $client_flags = 0) {
    // PDO persistent connections are often problematic across old codebases; emulate normal connect.
    return mysql_connect($server ?? '', $username ?? '', $password ?? '', false, $client_flags);
}

function mysql_select_db(string $database_name, $link_identifier = null): bool {
    $link = $link_identifier ?: _MysqlCompat::$link;
    if (!$link instanceof _MysqlCompatLink) {
        _MysqlCompat::setErr(2006, 'No MySQL-Link resource');
        return false;
    }
    try {
        $link->pdo->exec('USE `' . str_replace('`', '``', $database_name) . '`');
        $link->db = $database_name;
        _MysqlCompat::clrErr();
        return true;
    } catch (Throwable $e) {
        _MysqlCompat::setErr(1049, $e->getMessage());
        return false;
    }
}

function mysql_query(string $query, $link_identifier = null) {
    $link = $link_identifier ?: _MysqlCompat::$link;
    if (!$link instanceof _MysqlCompatLink) {
        // Auto-connect using guessed globals if possible
        try {
            _MysqlCompat::$link = _mysql_compat_connect(null, null, null, null);
            $link = _MysqlCompat::$link;
        } catch (Throwable $e) {
            _MysqlCompat::setErr(2006, 'No MySQL-Link resource');
            return false;
        }
    }

    _MysqlCompat::$insertId = 0;
    _MysqlCompat::$affected = 0;

    try {
        $stmt = $link->pdo->query($query);
        if ($stmt === false) {
            _MysqlCompat::setErr(1064, 'Query failed');
            return false;
        }

        $colCount = (int)$stmt->columnCount();
        if ($colCount > 0) {
            // Buffer rows so mysql_num_rows() behaves like old mysql_*
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $fields = [];
            if (isset($rows[0]) && is_array($rows[0])) $fields = array_keys($rows[0]);
            _MysqlCompat::clrErr();
            return new _MysqlCompatResult($rows, $fields, 0);
        }

        _MysqlCompat::$affected = (int)$stmt->rowCount();
        _MysqlCompat::$insertId = $link->pdo->lastInsertId();
        _MysqlCompat::clrErr();
        return true;
    } catch (PDOException $e) {
        $errno = 2000;
        if (is_array($e->errorInfo ?? null) && isset($e->errorInfo[1]) && is_numeric($e->errorInfo[1])) {
            $errno = (int)$e->errorInfo[1];
        }
        _MysqlCompat::setErr($errno, $e->getMessage());
        return false;
    } catch (Throwable $e) {
        _MysqlCompat::setErr(2000, $e->getMessage());
        return false;
    }
}

function mysql_unbuffered_query(string $query, $link_identifier = null) {
    // Compatibility: behaves same as mysql_query in this shim (buffered).
    return mysql_query($query, $link_identifier);
}

function mysql_db_query(string $database, string $query, $link_identifier = null) {
    $link = $link_identifier ?: _MysqlCompat::$link;
    if (!$link instanceof _MysqlCompatLink) {
        $link = mysql_connect();
        if ($link === false) return false;
    }
    if (!mysql_select_db($database, $link)) return false;
    return mysql_query($query, $link);
}

function mysql_num_rows($result): int {
    return ($result instanceof _MysqlCompatResult) ? count($result->rows) : 0;
}

function mysql_fetch_assoc($result) {
    if (!$result instanceof _MysqlCompatResult) return false;
    if ($result->pos >= count($result->rows)) return false;
    return $result->rows[$result->pos++];
}

function mysql_fetch_row($result) {
    $row = mysql_fetch_assoc($result);
    if ($row === false) return false;
    return array_values($row);
}

function mysql_fetch_array($result, int $result_type = 1 /*MYSQL_ASSOC*/) {
    $row = mysql_fetch_assoc($result);
    if ($row === false) return false;

    // MYSQL_ASSOC=1, MYSQL_NUM=2, MYSQL_BOTH=3 (old constants)
    if ($result_type === 2) return array_values($row);

    if ($result_type === 3) {
        $both = $row;
        $vals = array_values($row);
        foreach ($vals as $i => $v) $both[$i] = $v;
        return $both;
    }
    return $row;
}

function mysql_fetch_object($result, string $class_name = 'stdClass', array $params = []) {
    $row = mysql_fetch_assoc($result);
    if ($row === false) return false;
    if ($class_name === 'stdClass') return (object)$row;

    $obj = new $class_name(...$params);
    foreach ($row as $k => $v) {
        // Dynamic properties are deprecated in 8.2; but many legacy classes rely on it.
        // This keeps compatibility.
        $obj->$k = $v;
    }
    return $obj;
}

function mysql_data_seek($result, int $row_number): bool {
    if (!$result instanceof _MysqlCompatResult) return false;
    if ($row_number < 0 || $row_number >= count($result->rows)) return false;
    $result->pos = $row_number;
    return true;
}

function mysql_result($result, int $row, $field = 0) {
    if (!$result instanceof _MysqlCompatResult) return false;
    if ($row < 0 || $row >= count($result->rows)) return false;

    $r = $result->rows[$row];
    if (is_int($field)) {
        $vals = array_values($r);
        return $vals[$field] ?? false;
    }
    return $r[$field] ?? false;
}

function mysql_num_fields($result): int {
    if (!$result instanceof _MysqlCompatResult) return 0;
    return count($result->fields);
}

function mysql_field_name($result, int $field_offset) {
    if (!$result instanceof _MysqlCompatResult) return false;
    return $result->fields[$field_offset] ?? false;
}

function mysql_free_result($result): bool {
    return true;
}

function mysql_insert_id($link_identifier = null) {
    $link = $link_identifier ?: _MysqlCompat::$link;
    if ($link instanceof _MysqlCompatLink) {
        try { return $link->pdo->lastInsertId(); } catch (Throwable) {}
    }
    return _MysqlCompat::$insertId;
}

function mysql_affected_rows($link_identifier = null): int {
    return (int)_MysqlCompat::$affected;
}

function mysql_errno($link_identifier = null): int {
    return _MysqlCompat::$errno;
}

function mysql_error($link_identifier = null): string {
    return _MysqlCompat::$error;
}

function mysql_real_escape_string(string $unescaped_string, $link_identifier = null): string {
    $link = $link_identifier ?: _MysqlCompat::$link;
    if ($link instanceof _MysqlCompatLink) {
        $q = $link->pdo->quote($unescaped_string);
        if ($q !== false && strlen($q) >= 2 && $q[0] === "'" && $q[strlen($q) - 1] === "'") {
            return substr($q, 1, -1);
        }
    }
    return addslashes($unescaped_string);
}

function mysql_escape_string(string $unescaped_string): string {
    return addslashes($unescaped_string);
}

function mysql_set_charset(string $charset, $link_identifier = null): bool {
    $link = $link_identifier ?: _MysqlCompat::$link;
    if (!$link instanceof _MysqlCompatLink) return false;
    try {
        $link->pdo->exec("SET NAMES " . $charset);
        return true;
    } catch (Throwable) {
        return false;
    }
}

function mysql_close($link_identifier = null): bool {
    // Let GC close; just drop the default link.
    if ($link_identifier === null || $link_identifier === _MysqlCompat::$link) {
        _MysqlCompat::$link = null;
    }
    return true;
}
