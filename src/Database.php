<?php

namespace OpenVpnLdapPlusAuth;

use PDO;
use PDOException;
use Exception;

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

