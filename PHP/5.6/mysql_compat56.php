<?php

 //require_once __DIR__ . '/mysql_compat56.php';

if (function_exists('mysql_query')) {
    return;
}

/* --------------------------- internal state --------------------------- */

class _MysqlCompatLink {
    /** @var PDO */
    public $pdo;
    /** @var string */
    public $hostInfo = '';
    /** @var string */
    public $db = '';

    public function __construct($pdo, $hostInfo, $db) {
        $this->pdo = $pdo;
        $this->hostInfo = (string)$hostInfo;
        $this->db = (string)$db;
    }
}

class _MysqlCompatResult {
    /** @var array */
    public $rows = array();
    /** @var int */
    public $pos = 0;
    /** @var array */
    public $fields = array();
    /** @var int */
    public $affected = 0;

    public function __construct($rows, $fields, $affected) {
        $this->rows = $rows;
        $this->fields = $fields;
        $this->affected = (int)$affected;
    }
}

class _MysqlCompat {
    /** @var _MysqlCompatLink|null */
    public static $link = null;
    /** @var int */
    public static $errno = 0;
    /** @var string */
    public static $error = '';
    /** @var string|int */
    public static $insertId = 0;
    /** @var int */
    public static $affected = 0;

    public static function setErr($no, $msg) {
        self::$errno = (int)$no;
        self::$error = (string)$msg;
    }

    public static function clrErr() {
        self::$errno = 0;
        self::$error = '';
    }
}

/* --------------------------- config discovery -------------------------- */

function _mysql_compat_guess_config() {
    $candidates = array(
        'host' => array('dbhost', 'db_host', 'mysql_host', 'host'),
        'user' => array('dbuser', 'db_user', 'mysql_user', 'user', 'username'),
        'pass' => array('dbpass', 'db_pass', 'mysql_pass', 'pass', 'password'),
        'name' => array('dbname', 'db_name', 'mysql_db', 'database', 'db'),
        'port' => array('dbport', 'db_port', 'mysql_port', 'port'),
    );

    $out = array('host' => '127.0.0.1', 'user' => '', 'pass' => '', 'name' => '', 'port' => 3306);

    foreach ($candidates as $k => $vars) {
        foreach ($vars as $v) {
            if (isset($GLOBALS[$v]) && $GLOBALS[$v] !== '') {
                $out[$k] = $GLOBALS[$v];
                break;
            }
        }
    }

    // host may be "host:port"
    if (is_string($out['host']) && strpos($out['host'], ':') !== false) {
        $parts = explode(':', $out['host'], 2);
        if ($parts[0] !== '') $out['host'] = $parts[0];
        if (isset($parts[1]) && ctype_digit((string)$parts[1])) $out['port'] = (int)$parts[1];
    }

    $out['port'] = (int)($out['port'] ? $out['port'] : 3306);
    return $out;
}

function _mysql_compat_connect($host, $user, $pass, $db) {
    $cfg = _mysql_compat_guess_config();

    $host = ($host !== null && $host !== '') ? (string)$host : (string)$cfg['host'];
    $user = ($user !== null) ? (string)$user : (string)$cfg['user'];
    $pass = ($pass !== null) ? (string)$pass : (string)$cfg['pass'];
    $db   = ($db !== null && $db !== '') ? (string)$db : (string)$cfg['name'];
    $port = (int)$cfg['port'];

    // Support "host:port"
    if (strpos($host, ':') !== false) {
        $parts = explode(':', $host, 2);
        if ($parts[0] !== '') $host = $parts[0];
        if (isset($parts[1]) && ctype_digit((string)$parts[1])) $port = (int)$parts[1];
    }

    $dsn = "mysql:host={$host};port={$port}";
    if ($db !== '') $dsn .= ";dbname={$db}";
    $dsn .= ";charset=utf8";

    $opts = array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => true,
    );

    $pdo = new PDO($dsn, $user, $pass, $opts);
    $pdo->exec("SET NAMES utf8");

    return new _MysqlCompatLink($pdo, "{$host}:{$port}", $db);
}

/* ------------------------------ mysql_* -------------------------------- */

function mysql_connect($server = null, $username = null, $password = null, $new_link = false, $client_flags = 0) {
    try {
        _MysqlCompat::$link = _mysql_compat_connect($server, $username, $password, null);
        _MysqlCompat::clrErr();
        return _MysqlCompat::$link;
    } catch (Exception $e) {
        _MysqlCompat::setErr(2002, $e->getMessage());
        return false;
    }
}

function mysql_pconnect($server = null, $username = null, $password = null, $client_flags = 0) {
    return mysql_connect($server, $username, $password, false, $client_flags);
}

function mysql_select_db($database_name, $link_identifier = null) {
    $link = $link_identifier ? $link_identifier : _MysqlCompat::$link;
    if (!($link instanceof _MysqlCompatLink)) {
        _MysqlCompat::setErr(2006, 'No MySQL-Link resource');
        return false;
    }
    try {
        $link->pdo->exec('USE `' . str_replace('`', '``', (string)$database_name) . '`');
        $link->db = (string)$database_name;
        _MysqlCompat::clrErr();
        return true;
    } catch (Exception $e) {
        _MysqlCompat::setErr(1049, $e->getMessage());
        return false;
    }
}

function mysql_query($query, $link_identifier = null) {
    $link = $link_identifier ? $link_identifier : _MysqlCompat::$link;

    if (!($link instanceof _MysqlCompatLink)) {
        // best-effort autoconnect using guessed globals
        try {
            _MysqlCompat::$link = _mysql_compat_connect(null, null, null, null);
            $link = _MysqlCompat::$link;
        } catch (Exception $e) {
            _MysqlCompat::setErr(2006, 'No MySQL-Link resource');
            return false;
        }
    }

    _MysqlCompat::$insertId = 0;
    _MysqlCompat::$affected = 0;

    try {
        $stmt = $link->pdo->query((string)$query);
        if ($stmt === false) {
            _MysqlCompat::setErr(1064, 'Query failed');
            return false;
        }

        $colCount = (int)$stmt->columnCount();
        if ($colCount > 0) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $fields = array();
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
        if (is_array($e->errorInfo) && isset($e->errorInfo[1]) && is_numeric($e->errorInfo[1])) {
            $errno = (int)$e->errorInfo[1];
        }
        _MysqlCompat::setErr($errno, $e->getMessage());
        return false;

    } catch (Exception $e) {
        _MysqlCompat::setErr(2000, $e->getMessage());
        return false;
    }
}

function mysql_unbuffered_query($query, $link_identifier = null) {
    return mysql_query($query, $link_identifier);
}

function mysql_db_query($database, $query, $link_identifier = null) {
    $link = $link_identifier ? $link_identifier : _MysqlCompat::$link;
    if (!($link instanceof _MysqlCompatLink)) {
        $link = mysql_connect();
        if ($link === false) return false;
    }
    if (!mysql_select_db($database, $link)) return false;
    return mysql_query($query, $link);
}

function mysql_num_rows($result) {
    return ($result instanceof _MysqlCompatResult) ? count($result->rows) : 0;
}

function mysql_fetch_assoc($result) {
    if (!($result instanceof _MysqlCompatResult)) return false;
    if ($result->pos >= count($result->rows)) return false;
    $row = $result->rows[$result->pos];
    $result->pos++;
    return $row;
}

function mysql_fetch_row($result) {
    $row = mysql_fetch_assoc($result);
    if ($row === false) return false;
    return array_values($row);
}

function mysql_fetch_array($result, $result_type = 1 /*MYSQL_ASSOC*/) {
    $row = mysql_fetch_assoc($result);
    if ($row === false) return false;

    if ((int)$result_type === 2) return array_values($row); // MYSQL_NUM

    if ((int)$result_type === 3) { // MYSQL_BOTH
        $both = $row;
        $vals = array_values($row);
        foreach ($vals as $i => $v) $both[$i] = $v;
        return $both;
    }

    return $row; // MYSQL_ASSOC
}

function mysql_fetch_object($result, $class_name = 'stdClass', $params = array()) {
    $row = mysql_fetch_assoc($result);
    if ($row === false) return false;

    $class_name = (string)$class_name;
    if ($class_name === 'stdClass') return (object)$row;

    // PHP 5.6 supports argument unpacking only for arrays? (No.)
    // So we ignore $params expansion and just new $class_name.
    $obj = new $class_name();
    foreach ($row as $k => $v) {
        $obj->$k = $v; // legacy compatibility
    }
    return $obj;
}

function mysql_data_seek($result, $row_number) {
    if (!($result instanceof _MysqlCompatResult)) return false;
    $row_number = (int)$row_number;
    if ($row_number < 0 || $row_number >= count($result->rows)) return false;
    $result->pos = $row_number;
    return true;
}

function mysql_result($result, $row, $field = 0) {
    if (!($result instanceof _MysqlCompatResult)) return false;
    $row = (int)$row;
    if ($row < 0 || $row >= count($result->rows)) return false;

    $r = $result->rows[$row];

    if (is_int($field)) {
        $vals = array_values($r);
        return array_key_exists($field, $vals) ? $vals[$field] : false;
    }

    $field = (string)$field;
    return array_key_exists($field, $r) ? $r[$field] : false;
}

function mysql_num_fields($result) {
    if (!($result instanceof _MysqlCompatResult)) return 0;
    return count($result->fields);
}

function mysql_field_name($result, $field_offset) {
    if (!($result instanceof _MysqlCompatResult)) return false;
    $field_offset = (int)$field_offset;
    return isset($result->fields[$field_offset]) ? $result->fields[$field_offset] : false;
}

function mysql_free_result($result) {
    return true;
}

function mysql_insert_id($link_identifier = null) {
    $link = $link_identifier ? $link_identifier : _MysqlCompat::$link;
    if ($link instanceof _MysqlCompatLink) {
        try { return $link->pdo->lastInsertId(); } catch (Exception $e) {}
    }
    return _MysqlCompat::$insertId;
}

function mysql_affected_rows($link_identifier = null) {
    return (int)_MysqlCompat::$affected;
}

function mysql_errno($link_identifier = null) {
    return (int)_MysqlCompat::$errno;
}

function mysql_error($link_identifier = null) {
    return (string)_MysqlCompat::$error;
}

function mysql_real_escape_string($unescaped_string, $link_identifier = null) {
    $link = $link_identifier ? $link_identifier : _MysqlCompat::$link;
    $unescaped_string = (string)$unescaped_string;

    if ($link instanceof _MysqlCompatLink) {
        $q = $link->pdo->quote($unescaped_string);
        if ($q !== false && strlen($q) >= 2 && $q[0] === "'" && $q[strlen($q) - 1] === "'") {
            return substr($q, 1, -1);
        }
    }
    return addslashes($unescaped_string);
}

function mysql_escape_string($unescaped_string) {
    return addslashes((string)$unescaped_string);
}

function mysql_set_charset($charset, $link_identifier = null) {
    $link = $link_identifier ? $link_identifier : _MysqlCompat::$link;
    if (!($link instanceof _MysqlCompatLink)) return false;
    try {
        $link->pdo->exec("SET NAMES " . (string)$charset);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function mysql_close($link_identifier = null) {
    if ($link_identifier === null || $link_identifier === _MysqlCompat::$link) {
        _MysqlCompat::$link = null;
    }
    return true;
}
