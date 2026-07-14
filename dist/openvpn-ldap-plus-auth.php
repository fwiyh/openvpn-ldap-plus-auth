#!/usr/bin/php
<?php
declare(strict_types=1);

namespace OpenVpnLdapPlusAuth {
    use Exception;
    use PDO;
    use PDOException;
    use LDAP\Connection;
    use DateTime;

    // ---- Begin openvpn-ldap-plus-auth\src\Config.php ----
    /**
     * Config クラス
     *
     * 設定ファイルの解析およびパスの解決を担当するクラス
     *
     * @package OpenVpnLdapPlusAuth
     */
    class Config
    {
        /** 
         * @var array<string, array<string, mixed>> 設定配列
         */
        private array $settings = [];

        /** 
         * @var string 設定ファイルが存在するディレクトリのパス
         */
        private string $configDir;

        /**
         * コンストラクタ
         *
         * @param string $filePath 設定ファイルのパス
         * @throws Exception 設定ファイルが存在しない、または解析に失敗した場合
         */
        public function __construct(string $filePath)
        {
            /**
             * 設定ファイルのパスから、設定ファイルが存在するディレクトリのパスを取得
             * @var string $configDir 設定ファイルが存在するディレクトリのパス
             */
            $this->configDir = dirname($filePath);

            /**
             * 設定ファイルが存在しない場合、例外をスロー
             */
            if (!file_exists($filePath)) {
                throw new Exception("Configuration file '{$filePath}' not found.");
            }

            /**
             * 設定ファイルをパースする
             * @var mixed $parsed 設定ファイルのパース結果
             */
            $parsed = parse_ini_file($filePath, true);

            /**
             * 設定ファイルのパースに失敗した場合、例外をスロー
             */
            if ($parsed === false) {
                throw new Exception("Failed to parse configuration file '{$filePath}'.");
            }

            $this->settings = $parsed;
        }

        /**
         * 設定値を取得する
         *
         * @param string $section セクション名
         * @param string $key キー名
         * @param mixed $default デフォルト値
         * @return mixed
         */
        public function get(string $section, string $key, $default = null)
        {
            return $this->settings[$section][$key] ?? $default;
        }

        /**
         * 相対パス・絶対パスを判定し、絶対パスとして解決します。
         * 設定ファイルが存在するディレクトリを基準とします。
         *
         * @param string $path 解決対象のパス
         * @return string 解決された絶対パス
         */
        public function resolvePath(string $path): string
        {
            /**
             * パスが空の場合、空文字列を返す
             */
            if (empty($path)) {
                return '';
            }

            /**
             * 絶対パスの判定
             * Windows: ドライブレターから始まる場合 (e.g. C:\ や C:/) または バックスラッシュ/スラッシュから始まる場合
             * Linux/Unix: スラッシュから始まる場合
             */
            if (preg_match('/^[a-zA-Z]:[\\\\\/]/', $path) || strpos($path, '/') === 0 || strpos($path, '\\') === 0) {
                /**
                 * 絶対パスの場合はそのまま返す
                 */
                return $path;
            }

            /**
             * 相対パスの場合、設定ファイルが存在するディレクトリを基準に解決して返す
             * @var string $absolutePath 解決された絶対パス
             */
            $absolutePath = $this->configDir . DIRECTORY_SEPARATOR . $path;

            return $absolutePath;
        }
    }
    // ---- End openvpn-ldap-plus-auth\src\Config.php ----

    // ---- Begin openvpn-ldap-plus-auth\src\Database.php ----
    /**
     * Class Database
     *
     * データベースへの接続管理およびテーブルの初期化・更新（マイグレーション）を担当します。
     *
     * @package OpenVpnLdapPlusAuth
     */
    class Database
    {
        /** 
         * @var PDO データベース接続インスタンス
         */
        private PDO $pdo;

        /** 
         * @var string PDO ドライバ名
         */
        private string $pdo_driver_name = "";

        /**
         * Database コンストラクタ。
         *
         * @param Config $config
         * @throws Exception データベース接続設定が不足している、または接続に失敗した場合
         */
        public function __construct(Config $config)
        {
            /**
             * 設定ファイルからデータベースの接続情報を取得
             * @var string $dsn データベース接続文字列
             * @var string|null $user データベースユーザー名
             * @var string|null $password データベースパスワード
             */
            $dsn = $config->get('db', 'dsn', '');
            $user = $config->get('db', 'user', null);
            $password = $config->get('db', 'password', null);

            if (empty($dsn)) {
                /**
                 * 後方互換性: 旧 [db].path 設定値から sqlite DSN を構築
                 * @var string $path データベースパス
                 * @var string $resolvedPath 解決されたデータベースパス
                 */
                $path = $config->get('db', 'path', '');
                if (!empty($path)) {
                    $resolvedPath = $config->resolvePath($path);
                    $dsn = "sqlite:" . $resolvedPath;
                } else {
                    throw new Exception("Database configuration (dsn or path) is missing.");
                }
            } else {
                /**
                 * sqlite:./auth.db のように DSN の中に相対パスが含まれる場合の解決
                 * @var string $dbPath データベースパス
                 * @var string $resolvedPath 解決されたデータベースパス
                 */
                if (strpos($dsn, 'sqlite:') === 0) {
                    $dbPath = substr($dsn, 7);
                    $resolvedPath = $config->resolvePath($dbPath);
                    $dsn = "sqlite:" . $resolvedPath;
                }
            }

            try {
                /**
                 * PDO インスタンスの作成
                 * @var PDO $pdo データベース接続インスタンス
                 * @throws PDOException データベース接続に失敗した場合
                 */
                $this->pdo = new PDO($dsn, $user, $password);
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (PDOException $e) {
                throw new Exception("Database connection failed: " . $e->getMessage());
            }
        }

        /**
         * PDO 接続インスタンスを取得します。
         *
         * @return PDO
         */
        public function getConnection(): PDO
        {
            return $this->pdo;
        }

        /**
         * データベースの初期化およびアップデートを実行します。
         * 既存の users テーブルがあれば is_domain カラムの追加と user_passwords テーブルの作成を行います。
         *
         * @return void
         */
        public function init(): void
        {
            $this->pdo_driver_name = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

            if ($this->pdo_driver_name === 'sqlite') {
                $this->initSqlite();
            } else {
                $this->initGeneral();
            }
        }

        /**
         * SQLite向けの初期化およびアップデートを実行します。
         *
         * @return void
         */
        private function initSqlite(): void
        {
            // users テーブルの作成
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT NOT NULL,
                    is_active INTEGER NOT NULL,
                    is_domain INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    UNIQUE (username)
                )
            ");

            // カラム is_domain が存在するかチェックし、なければ追加
            $hasIsDomain = false;
            $stmt = $this->pdo->query("PRAGMA table_info(users)");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($columns as $column) {
                if ($column['name'] === 'is_domain') {
                    $hasIsDomain = true;
                    break;
                }
            }

            if (!$hasIsDomain) {
                $this->pdo->exec("ALTER TABLE users ADD COLUMN is_domain INTEGER NOT NULL DEFAULT 0");
            }

            // user_passwords テーブルの作成
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS user_passwords (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    password_hash TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
                )
            ");
        }

        /**
         * MySQLなどの一般的なRDBMS向けの初期化およびアップデートを実行します。
         *
         * @return void
         */
        private function initGeneral(): void
        {
            // users テーブルの作成
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(255) NOT NULL,
                    is_active TINYINT NOT NULL,
                    is_domain TINYINT NOT NULL DEFAULT 0,
                    created_at VARCHAR(64) NOT NULL,
                    updated_at VARCHAR(64) NOT NULL,
                    UNIQUE KEY (username)
                )
            ");

            // カラム is_domain が存在するかチェックし、なければ追加
            $this->addIsDomainColumnIfMissing('users');

            // user_passwords テーブルの作成
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS user_passwords (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    password_hash VARCHAR(255) NOT NULL,
                    created_at VARCHAR(64) NOT NULL,
                    updated_at VARCHAR(64) NOT NULL,
                    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
                )
            ");
        }
        /**
         * 指定テーブルに is_domain カラムが存在しない場合追加します。
         * 各DBMSの構文差分をハンドリングし、エラーは汎用的に無視します。
         */
        private function addIsDomainColumnIfMissing(string $table): void
        {
            $sql = '';
            $ignoreCodes = [];
            switch ($this->pdo_driver_name) {
                case 'mysql':
                    $sql = "ALTER TABLE $table ADD COLUMN IF NOT EXISTS is_domain TINYINT NOT NULL DEFAULT 0";
                    break;
                case 'pgsql':
                    $sql = "ALTER TABLE $table ADD COLUMN IF NOT EXISTS is_domain SMALLINT NOT NULL DEFAULT 0";
                    break;
                case 'sqlsrv': // MSSQL
                    $sql = "IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='$table' AND COLUMN_NAME='is_domain') ALTER TABLE $table ADD is_domain BIT NOT NULL CONSTRAINT DF_{$table}_is_domain DEFAULT 0";
                    break;
                case 'oci': // Oracle
                    $sql = "BEGIN EXECUTE IMMEDIATE 'ALTER TABLE $table ADD (is_domain NUMBER(1) DEFAULT 0 NOT NULL)'; EXCEPTION WHEN OTHERS THEN IF SQLCODE = -01430 THEN NULL; ELSE RAISE; END IF; END;";
                    break;
                case 'ibm': // DB2
                    $sql = "ALTER TABLE $table ADD COLUMN is_domain SMALLINT NOT NULL DEFAULT 0";
                    $ignoreCodes = ['42710']; // duplicate column
                    break;
                default:
                    // 汎用クエリ
                    $sql = "ALTER TABLE $table ADD COLUMN is_domain INTEGER NOT NULL DEFAULT 0";
                    break;
            }
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) {
                $code = $e->getCode();
                // MySQL duplicate column error code 1060, PostgreSQL 42701, MSSQL 2705, Oracle 01430 handled in sql block, DB2 42710
                $duplicateCodes = ['1060', '42701', '2705', '01430'];
                if (in_array($code, $duplicateCodes, true) || in_array($code, $ignoreCodes, true)) {
                    // column already exists - ignore
                } else {
                    // Log unexpected errors
                    error_log('Failed to add is_domain column [driver Name: ' . $this->pdo_driver_name . ']: ' . $e->getMessage());
                    throw $e;
                }
            }
        }
    }
    // ---- End openvpn-ldap-plus-auth\src\Database.php ----

    // ---- Begin openvpn-ldap-plus-auth\src\LdapAuthenticator.php ----
    /**
     * LDAP サーバーとの通信、バインド（認証）、および検索処理を担当するクラス
     *
     * @package OpenVpnLdapPlusAuth
     */
    class LdapAuthenticator
    {
        /** 
         * @var Config 設定管理インスタンス 
         */
        private Config $config;

        /**
         * @var Connection|null 
         * LDAP 接続リソース (PHP 8.1 未満では resource, 8.1 以降では LDAP\Connection)
         */
        private $connection = null;

        /**
         * Ldap コンストラクタ。
         *
         * @param Config $config
         */
        public function __construct(Config $config)
        {
            $this->config = $config;
        }

        /**
         * LDAP サーバーに接続する
         *
         * @return void
         * @throws Exception 接続に失敗した場合
         */
        public function connect(): void
        {
            /**
             * @var string $serverUrl LDAP サーバー URL
             * @var string $caCert CA 証明書のパス
             */
            $serverUrl = $this->config->get('ldap', 'server', '');
            $caCert = $this->config->get('ldap', 'ca_cert', '');

            /**
             * CA 証明書のパスが設定されている場合
             * @var string $resolvedCaCert 解決された CA 証明書のパス
             */
            if (!empty($caCert)) {
                $resolvedCaCert = $this->config->resolvePath($caCert);
                if (file_exists($resolvedCaCert)) {
                    putenv("LDAPTLS_CACERT=" . $resolvedCaCert);
                }
            }

            /**
             * LDAP サーバーに接続
             */
            $this->connection = @ldap_connect($serverUrl);
            if ($this->connection === false) {
                throw new Exception("Failed to connect to LDAP server: {$serverUrl}");
            }

            /**
             * オプションの設定
             */
            ldap_set_option($this->connection, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($this->connection, LDAP_OPT_REFERRALS, 0);
        }

        /**
         * 指定されたユーザー名とパスワードで LDAP サーバーにバインド（認証）する
         *
         * @param string $username バインドに使用するユーザー名 (e.g. user@domain.internal)
         * @param string $password パスワード
         * @return bool バインドに成功した場合は true、失敗した場合は false
         * @throws Exception 接続されていない場合
         */
        public function bind(string $username, string $password): bool
        {
            if (!$this->connection) {
                $this->connect();
            }

            return @ldap_bind($this->connection, $username, $password);
        }

        /**
         * LDAP サーバーからユーザー属性を検索して取得する
         *
         * @param string $username 検索対象のアカウント名 (sAMAccountName 等)
         * @return array 検索結果のエントリ配列
         * @throws Exception 検索に失敗した場合、または接続されていない場合
         */
        public function search(string $username): array
        {
            /**
             * 接続されていない場合は接続する
             */
            if (!$this->connection) {
                $this->connect();
            }

            /**
             * 設定値を取得
             * @var string $baseDn
             * @var string $filterPattern
             * @var string $attributesStr
             */
            $baseDn = $this->config->get('ldap', 'base_dn', '');
            $filterPattern = $this->config->get('ldap_search', 'filter', '');
            $attributesStr = $this->config->get('ldap_search', 'attributes', '');

            /**
             * 検索条件を設定
             * @var array $attributes
             */
            $attributes = array_filter(array_map('trim', explode(',', $attributesStr)));

            /**
             * 検索を実行
             * @var bool|resource $sr
             */
            $filter = sprintf($filterPattern, $username);
            $sr = @ldap_search($this->connection, $baseDn, $filter, $attributes);
            if ($sr === false) {
                throw new Exception("LDAP search failed for filter '{$filter}'.");
            }

            /**
             * 検索結果を取得
             * @var array|bool $entries
             */
            $entries = @ldap_get_entries($this->connection, $sr);
            if ($entries === false) {
                throw new Exception("Failed to get LDAP entries.");
            }

            return $entries;
        }
    }
    // ---- End openvpn-ldap-plus-auth\src\LdapAuthenticator.php ----

    // ---- Begin openvpn-ldap-plus-auth\src\Authenticator.php ----
    /**
     * Class Authenticator
     *
     * ローカルDBおよびLDAPを用いた統合認証ロジックを制御する
     *
     * @package OpenVpnLdapPlusAuth
     */
    class Authenticator
    {
        /**
         * @var Database データベース管理インスタンス
         */
        private Database $db;

        /**
         * @var LdapAuthenticator LDAP通信インスタンス
         */
        private LdapAuthenticator $ldap;

        /**
         * コンストラクタ
         *
         * @param Database $db
         * @param LdapAuthenticator $ldap
         */
        public function __construct(Database $db, LdapAuthenticator $ldap)
        {
            $this->db = $db;
            $this->ldap = $ldap;
        }

        /**
         * ユーザー名とパスワードを検証して認証を行う
         *
         * @param string $rawUserName クライアントから送信された未加工のユーザー名
         * @param string $rawPassword パスワード
         * @return bool 認証成功時に true、失敗時に false
         */
        public function authenticate(string $rawUserName, string $rawPassword): bool
        {
            // 1. ユーザー名の解析とドメイン部分の分離
            $accountName = $rawUserName;
            $domainPart = "";

            if (strpos($rawUserName, "\\") !== false) {
                $parts = explode("\\", $rawUserName, 2);
                $domainPart = $parts[0];
                $accountName = $parts[1];
            } elseif (strpos($rawUserName, "@") !== false) {
                $parts = explode("@", $rawUserName, 2);
                $accountName = $parts[0];
                $domainPart = $parts[1];
            }

            // ローカルユーザーかどうかの判定
            // 先頭が「.\」で始まるか、またはドメイン指定がない場合（かつ「.」単体でもない場合など）をローカルとみなす
            $isLocal = false;
            if (strpos($rawUserName, ".\\") === 0 || $domainPart === "" || $domainPart === ".") {
                $isLocal = true;
                // 「.\username」形式の場合は先頭の2文字を取り除く
                if (strpos($rawUserName, ".\\") === 0) {
                    $accountName = substr($rawUserName, 2);
                }
            }

            // 2. データベースからユーザー情報の取得
            $pdo = $this->db->getConnection();
            try {
                $stmt = $pdo->prepare("
                    SELECT u.id, u.username, u.is_active, u.is_domain, up.password_hash 
                    FROM users u
                    LEFT JOIN user_passwords up ON u.id = up.user_id
                    WHERE u.username = :username
                ");
                $stmt->bindValue(':username', $accountName, PDO::PARAM_STR);
                $stmt->execute();
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                Logger::error("Database query failed: " . $e->getMessage());
                return false;
            }

            // ユーザーが存在しない場合
            if (!$user) {
                Logger::warn("Auth Denied: User '{$accountName}' not found in local database.");
                return false;
            }

            // 無効化されている場合
            if ((int) $user['is_active'] !== 1) {
                Logger::warn("Auth Denied: User '{$accountName}' is disabled in local database.");
                return false;
            }

            $dbIsDomain = (int) $user['is_domain'] === 1;

            // 3. 認証方式の切り替えと検証
            if ($isLocal) {
                // クライアントはローカル認証を要求しているが、DB上でドメインユーザーとして登録されている場合
                if ($dbIsDomain) {
                    Logger::warn("Auth Denied: User '{$accountName}' is registered as domain user, but tried to log in as local user.");
                    return false;
                }

                // ローカルパスワードの検証
                $passwordHash = $user['password_hash'] ?? '';
                if (empty($passwordHash) || !password_verify($rawPassword, $passwordHash)) {
                    Logger::warn("Auth Denied: Invalid password for local user '{$accountName}'.");
                    return false;
                }

                Logger::info("Local DB Verification Passed for user '{$rawUserName}'.");
                Logger::info("Auth Success: Local user '{$accountName}' authenticated successfully.");
                return true;

            } else {
                // クライアントはドメイン認証を要求しているが、DB上でローカルユーザーとして登録されている場合
                if (!$dbIsDomain) {
                    Logger::warn("Auth Denied: User '{$accountName}' is registered as local user, but tried to log in as domain user.");
                    return false;
                }

                // LDAP 認証の実行
                try {
                    // ローカルDBでの検証通過ログ (現行互換)
                    Logger::info("Local DB Verification Passed for user '{$rawUserName}'.");

                    // LDAPサーバーにバインド
                    $bindSuccess = $this->ldap->bind($rawUserName, $rawPassword);
                    if (!$bindSuccess) {
                        Logger::warn("Auth Denied: Fail to bind LDAP for user '{$rawUserName}'.");
                        return false;
                    }

                    // 検索による属性検証
                    $entries = $this->ldap->search($accountName);
                    if ($entries['count'] > 0) {
                        Logger::info("Auth Success: User '{$rawUserName}' authenticated successfully.");
                        return true;
                    } else {
                        Logger::warn("Auth Denied: User not found in LDAP base DN search.");
                        return false;
                    }
                } catch (Exception $e) {
                    Logger::error("System Error: Exception raised during LDAP auth: " . $e->getMessage());
                    return false;
                }
            }
        }
    }
    // ---- End openvpn-ldap-plus-auth\src\Authenticator.php ----

    // ---- Begin openvpn-ldap-plus-auth\src\Logger.php ----
    /**
     * Class Logger
     *
     * ログメッセージを標準エラー出力に規定のフォーマットで出力するクラスです。
     * ログフォーマット: [#OVLPA#] [TIMESTAMP] [LEVEL] [MESSAGE]
     *
     * @package OpenVpnLdapPlusAuth
     */
    class Logger
    {
        /**
         * ログメッセージを出力します。
         *
         * @param string $level ログレベル (ERROR, WARN, INFO, DEBUG)
         * @param string $message ログに記録するメッセージ
         * @return void
         */
        public static function log(string $level, string $message): void
        {
            $timestamp = (new DateTime())->format('Y-m-d\TH:i:sP');
            $logMessage = sprintf("[#OVLPA#] [%s] [%s] %s\n", $timestamp, strtoupper($level), $message);
            error_log($logMessage);
        }

        /**
         * ERROR レベルのログを出力します。
         *
         * @param string $message ログメッセージ
         * @return void
         */
        public static function error(string $message): void
        {
            self::log('ERROR', $message);
        }

        /**
         * WARN レベルのログを出力します。
         *
         * @param string $message ログメッセージ
         * @return void
         */
        public static function warn(string $message): void
        {
            self::log('WARN', $message);
        }

        /**
         * INFO レベルのログを出力します。
         *
         * @param string $message ログメッセージ
         * @return void
         */
        public static function info(string $message): void
        {
            self::log('INFO', $message);
        }

        /**
         * DEBUG レベルのログを出力します。
         *
         * @param string $message ログメッセージ
         * @return void
         */
        public static function debug(string $message): void
        {
            self::log('DEBUG', $message);
        }
    }
    // ---- End openvpn-ldap-plus-auth\src\Logger.php ----

}

namespace {
    use OpenVpnLdapPlusAuth\Config;
    use OpenVpnLdapPlusAuth\Database;
    use OpenVpnLdapPlusAuth\LdapAuthenticator;
    use OpenVpnLdapPlusAuth\Authenticator;
    use OpenVpnLdapPlusAuth\Logger;

    // ---- Begin openvpn-ldap-plus-auth\src\openvpn-ldap-plus-auth.php ----
    /**
     * OpenVPN LDAP + RDBMS 認証
     * 
     * 1. via-file で認証情報を読み込む (OpenVPN auth-user-pass-verify via-file)
     * 2. データベースの認証情報を検証
     * 3. LDAPにバインド / ローカル認証情報検証
     * 4. 成功した場合は exit(0) を返す。失敗した場合は exit(1) を返す。
     */


    // require_once __DIR__ . '/bootstrap.php';

    if ($argc < 2) {
        Logger::error("One parameter (temp file path) is required for via-file authentication.");
        exit(1);
    }

    $confFile = __DIR__ . "/openvpn-ldap-plus-auth.conf";
    if (!file_exists($confFile)) {
        Logger::error("Configuration file '{$confFile}' not found.");
        exit(1);
    }

    try {
        $config = new Config($confFile);
    } catch (Exception $e) {
        Logger::error("Failed to parse configuration file: " . $e->getMessage());
        exit(1);
    }

    /**
     * via-fileの認証情報のパース
     * 認証情報は一時ファイルに書き込まれ、そのファイルパスが引数として渡される
     * @var string $tempFilePath 一時ファイルのパス
     */
    $tempFilePath = $argv[1];

    /**
     * 一時ファイルが存在しない場合、例外をスロー
     */
    if (!file_exists($tempFilePath)) {
        Logger::error("Authentication file not found.");
        exit(1);
    }

    /**
     * 認証情報を一時ファイルから読み込む
     * @var array $lines 認証情報の配列
     */
    $lines = file($tempFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false || count($lines) < 2) {
        Logger::error("Invalid authentication file format.");
        exit(1);
    }

    /**
     * OpenVPNから送られてきた認証情報をパースする
     * @var string $rawUserName OpenVPNから送られてきた認証情報のユーザ名
     * @var string $rawPassword OpenVPNから送られてきた認証情報のパスワード
     */
    $rawUserName = $lines[0];
    $rawPassword = $lines[1];

    // パスワードが配列に残らないようクリア
    $lines = [];

    try {
        /**
         * データベース接続クラスの初期化
         * @var Database $db データベース接続クラスのインスタンス
         */
        $db = new Database($config);

        /**
         * LDAP接続クラスの初期化
         * @var LdapAuthenticator $ldap LDAP接続クラスのインスタンス
         */
        $ldap = new LdapAuthenticator($config);

        /**
         * 認証クラスの初期化
         * @var Authenticator $authenticator 認証クラスのインスタンス
         */
        $authenticator = new Authenticator($db, $ldap);

        /**
         * 認証クラスの実行
         * @var bool $success 認証結果
         */
        $success = $authenticator->authenticate($rawUserName, $rawPassword);

        // セキュリティのためパスワード変数をクリア
        $rawPassword = '';

        /**
         * 認証結果によって終了コードを返す
         */
        if ($success) {
            exit(0);
        } else {
            exit(1);
        }
    } catch (Exception $e) {
        Logger::error("System Error: Exception raised: " . $e->getMessage());
        $rawPassword = '';
        exit(1);
    }
    // ---- End openvpn-ldap-plus-auth\src\openvpn-ldap-plus-auth.php ----
}
