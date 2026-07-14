#!/usr/bin/php
<?php
declare(strict_types=1);

namespace OpenVpnLdapPlusAuth {
    use Exception;
    use PDO;
    use PDOException;
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
    use OpenVpnLdapPlusAuth\Logger;

    // ---- Begin openvpn-ldap-plus-auth\src\ovpn-user.php ----
    /**
     * 独自DBにユーザーの登録・更新・削除を行う
     * 
     * command:
     *  php ovpn-user.php init
     *  php ovpn-user.php add <username> [--domain]
     *  php ovpn-user.php add <username> --local -p <password>
     *  php ovpn-user.php add <username> --local --password=<password>
     *  php ovpn-user.php update <username> [<new-username>] [--domain]
     *  php ovpn-user.php update <username> [<new-username>] --local -p <password>
     *  php ovpn-user.php update <username> [<new-username>] --local --password=<password>
     *  php ovpn-user.php delete <username>
     *  php ovpn-user.php list
     *  php ovpn-user.php disable <username>
     *  php ovpn-user.php enable <username>
     */


    // require_once __DIR__ . '/bootstrap.php';

    if ($argc < 2) {
        echo "Usage:\n";
        echo "  php ovpn-user.php init\n";
        echo "  php ovpn-user.php add <username> [--domain]\n";
        echo "  php ovpn-user.php add <username> --local -p <password>\n";
        echo "  php ovpn-user.php update <username> [<new-username>] [--domain]\n";
        echo "  php ovpn-user.php update <username> [<new-username>] --local -p <password>\n";
        echo "  php ovpn-user.php delete <username>\n";
        echo "  php ovpn-user.php list\n";
        echo "  php ovpn-user.php disable <username>\n";
        echo "  php ovpn-user.php enable <username>\n";
        exit(1);
    }

    $operation = $argv[1];

    // 引数とオプションのパース
    $positionalArgs = [];
    $options = [];

    for ($i = 2; $i < $argc; $i++) {
        $arg = $argv[$i];
        if (strpos($arg, '--') === 0) {
            if (strpos($arg, '=') !== false) {
                list($key, $val) = explode('=', substr($arg, 2), 2);
                $options[$key] = $val;
            } else {
                $key = substr($arg, 2);
                $options[$key] = true;
            }
        } elseif (strpos($arg, '-') === 0) {
            $key = substr($arg, 1);
            if ($key === 'p') {
                if ($i + 1 < $argc && strpos($argv[$i + 1], '-') !== 0) {
                    $options['password'] = $argv[++$i];
                } else {
                    echo "Error: -p option requires a value.\n";
                    exit(1);
                }
            } else {
                $options[$key] = true;
            }
        } else {
            $positionalArgs[] = $arg;
        }
    }

    // 設定ファイルの読み込み
    $confFile = __DIR__ . "/openvpn-ldap-plus-auth.conf";
    if (!file_exists($confFile)) {
        Logger::error("Configuration file '{$confFile}' not found.");
        exit(1);
    }

    try {
        $config = new Config($confFile);
        $db = new Database($config);
    } catch (Exception $e) {
        Logger::error("Initialization failed: " . $e->getMessage());
        exit(1);
    }

    $pdo = $db->getConnection();

    switch ($operation) {
        case 'init':
            try {
                $db->init();
                echo "Database initialized / updated successfully.\n";
            } catch (Exception $e) {
                echo "Database Init Error: " . $e->getMessage() . "\n";
                exit(1);
            }
            break;

        case 'add':
            $username = $positionalArgs[0] ?? '';
            if (empty($username)) {
                echo "Error: Username is required.\n";
                exit(1);
            }

            $isDomain = true;
            if (isset($options['local'])) {
                $isDomain = false;
            }

            $password = $options['password'] ?? null;
            if (!$isDomain && empty($password)) {
                echo "Error: Password is required for local user (--local -p <password>).\n";
                exit(1);
            }

            try {
                $pdo->beginTransaction();
                $now = getCurrentDateTime();

                $stmt = $pdo->prepare("INSERT INTO users (username, is_active, is_domain, created_at, updated_at) VALUES (:username, :is_active, :is_domain, :created_at, :updated_at)");
                $stmt->execute([
                    ':username' => $username,
                    ':is_active' => 1,
                    ':is_domain' => $isDomain ? 1 : 0,
                    ':created_at' => $now,
                    ':updated_at' => $now
                ]);

                $userId = $pdo->lastInsertId();

                if (!$isDomain && !empty($password)) {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO user_passwords (user_id, password_hash, created_at, updated_at) VALUES (:user_id, :password_hash, :created_at, :updated_at)");
                    $stmt->execute([
                        ':user_id' => $userId,
                        ':password_hash' => $passwordHash,
                        ':created_at' => $now,
                        ':updated_at' => $now
                    ]);
                }

                $pdo->commit();
                echo "User '{$username}' added successfully.\n";
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "Database Error: " . $e->getMessage() . "\n";
                exit(1);
            }
            break;

        case 'update':
            $username = $positionalArgs[0] ?? '';
            if (empty($username)) {
                echo "Error: Username is required.\n";
                exit(1);
            }

            $newUsername = $positionalArgs[1] ?? null;

            // 既存ユーザーの取得
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                echo "Error: User '{$username}' not found.\n";
                exit(1);
            }

            $userId = $user['id'];
            $currentIsDomain = (int) $user['is_domain'] === 1;

            $changeToDomain = isset($options['domain']);
            $changeToLocal = isset($options['local']);
            $password = $options['password'] ?? null;

            if ($changeToDomain && $changeToLocal) {
                echo "Error: Cannot specify both --domain and --local.\n";
                exit(1);
            }

            if ($changeToLocal && empty($password)) {
                echo "Error: Password is required when converting to local user.\n";
                exit(1);
            }

            try {
                $pdo->beginTransaction();
                $now = getCurrentDateTime();
                $updateFields = [];
                $updateParams = [];

                if ($newUsername !== null && $newUsername !== $username) {
                    $updateFields[] = "username = :new_username";
                    $updateParams[':new_username'] = $newUsername;
                }

                if ($changeToDomain) {
                    $updateFields[] = "is_domain = :is_domain";
                    $updateParams[':is_domain'] = 1;

                    // ドメインユーザー化するのでパスワードハッシュを削除
                    $stmt = $pdo->prepare("DELETE FROM user_passwords WHERE user_id = :user_id");
                    $stmt->execute([':user_id' => $userId]);
                } elseif ($changeToLocal) {
                    $updateFields[] = "is_domain = :is_domain";
                    $updateParams[':is_domain'] = 0;

                    // パスワードの設定・更新
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("SELECT id FROM user_passwords WHERE user_id = :user_id");
                    $stmt->execute([':user_id' => $userId]);
                    if ($stmt->fetch()) {
                        $stmt = $pdo->prepare("UPDATE user_passwords SET password_hash = :password_hash, updated_at = :updated_at WHERE user_id = :user_id");
                        $stmt->execute([
                            ':password_hash' => $passwordHash,
                            ':updated_at' => $now,
                            ':user_id' => $userId
                        ]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO user_passwords (user_id, password_hash, created_at, updated_at) VALUES (:user_id, :password_hash, :created_at, :updated_at)");
                        $stmt->execute([
                            ':user_id' => $userId,
                            ':password_hash' => $passwordHash,
                            ':created_at' => $now,
                            ':updated_at' => $now
                        ]);
                    }
                } elseif (!$currentIsDomain && !empty($password)) {
                    // すでにローカルで、単なるパスワード更新の場合
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("SELECT id FROM user_passwords WHERE user_id = :user_id");
                    $stmt->execute([':user_id' => $userId]);
                    if ($stmt->fetch()) {
                        $stmt = $pdo->prepare("UPDATE user_passwords SET password_hash = :password_hash, updated_at = :updated_at WHERE user_id = :user_id");
                        $stmt->execute([
                            ':password_hash' => $passwordHash,
                            ':updated_at' => $now,
                            ':user_id' => $userId
                        ]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO user_passwords (user_id, password_hash, created_at, updated_at) VALUES (:user_id, :password_hash, :created_at, :updated_at)");
                        $stmt->execute([
                            ':user_id' => $userId,
                            ':password_hash' => $passwordHash,
                            ':created_at' => $now,
                            ':updated_at' => $now
                        ]);
                    }
                }

                if (!empty($updateFields)) {
                    $updateFields[] = "updated_at = :updated_at";
                    $updateParams[':updated_at'] = $now;
                    $updateParams[':username'] = $username;

                    $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE username = :username";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($updateParams);
                }

                $pdo->commit();
                echo "User '{$username}' updated successfully.\n";
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "Database Error: " . $e->getMessage() . "\n";
                exit(1);
            }
            break;

        case 'delete':
            $username = $positionalArgs[0] ?? '';
            if (empty($username)) {
                echo "Error: Username is required.\n";
                exit(1);
            }

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username");
                $stmt->execute([':username' => $username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    $userId = $user['id'];

                    $stmt = $pdo->prepare("DELETE FROM user_passwords WHERE user_id = :user_id");
                    $stmt->execute([':user_id' => $userId]);

                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
                    $stmt->execute([':id' => $userId]);

                    $pdo->commit();
                    echo "User '{$username}' and associated passwords deleted.\n";
                } else {
                    $pdo->rollBack();
                    echo "Error: User '{$username}' not found.\n";
                    exit(1);
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "Database Error: " . $e->getMessage() . "\n";
                exit(1);
            }
            break;

        case 'list':
            try {
                $stmt = $pdo->prepare("
                    SELECT u.id, u.username, u.is_active, u.is_domain, u.created_at, u.updated_at,
                           (CASE WHEN up.password_hash IS NOT NULL THEN 1 ELSE 0 END) as has_password
                    FROM users u
                    LEFT JOIN user_passwords up ON u.id = up.user_id
                    ORDER BY u.id
                ");
                $stmt->execute();
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($users, JSON_PRETTY_PRINT) . "\n";
            } catch (Exception $e) {
                echo "Database Error: " . $e->getMessage() . "\n";
                exit(1);
            }
            break;

        case 'disable':
            $username = $positionalArgs[0] ?? '';
            if (empty($username)) {
                echo "Error: Username is required.\n";
                exit(1);
            }

            try {
                $now = getCurrentDateTime();
                $stmt = $pdo->prepare("UPDATE users SET is_active = 0, updated_at = :updated_at WHERE username = :username");
                $stmt->execute([
                    ':username' => $username,
                    ':updated_at' => $now
                ]);
                echo "User '{$username}' disabled successfully.\n";
            } catch (Exception $e) {
                echo "Database Error: " . $e->getMessage() . "\n";
                exit(1);
            }
            break;

        case 'enable':
            $username = $positionalArgs[0] ?? '';
            if (empty($username)) {
                echo "Error: Username is required.\n";
                exit(1);
            }

            try {
                $now = getCurrentDateTime();
                $stmt = $pdo->prepare("UPDATE users SET is_active = 1, updated_at = :updated_at WHERE username = :username");
                $stmt->execute([
                    ':username' => $username,
                    ':updated_at' => $now
                ]);
                echo "User '{$username}' enabled successfully.\n";
            } catch (Exception $e) {
                echo "Database Error: " . $e->getMessage() . "\n";
                exit(1);
            }
            break;

        default:
            echo "Error: Unknown operation '{$operation}'.\n";
            exit(1);
    }

    /**
     * ISO8601形式で現在日時を取得
     * 各種RDBMSが解釈できる形式で出力
     *
     * @return string 現在日時 (YYYY-MM-DDTHH:MM:SS+HH:MM)
     */
    function getCurrentDateTime(): string
    {
        $currentDateTime = (new DateTime())->format(DateTime::RFC3339_EXTENDED);
        return preg_replace("/\+00:00/", "Z", $currentDateTime);
    }
    // ---- End openvpn-ldap-plus-auth\src\ovpn-user.php ----
}
