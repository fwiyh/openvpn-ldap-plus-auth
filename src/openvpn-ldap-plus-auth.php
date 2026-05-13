#!/usr/bin/php
<?php
/**
 * OpenVPN LDAP + RDBMS Authentication Script
 * 
 * 1. Read credentials via-file (OpenVPN auth-user-pass-verify via-file)
 * 2. Verify user in local RDBMS (auth.db)
 * 3. Bind to LDAP if local verification passes
 * 4. Return exit(0) on success, exit(1) on failure.
 */

if ($argc < 2) {
    error_log("Error: One parameter (temp file path) is required for via-file authentication.\n");
    exit(1);
}

/**
 * 同じディレクトリにあるopenvpn-ldap-plus-auth.confから設定を取得する
 * 内部形式はini
 */
$confFile = __DIR__ . "/openvpn-ldap-plus-auth.conf";
if (!file_exists($confFile)) {
    error_log("Error: Configuration file '{$confFile}' not found.\n");
    exit(1);
}

$config = parse_ini_file($confFile, true);
if ($config === false) {
    error_log("Error: Failed to parse configuration file '{$confFile}'.\n");
    exit(1);
}

/**
 * 各種設定の取得
 */
// LDAP settings
$ldapServerUrl = $config['ldap']['server'] ?? '';
$baseDn = $config['ldap']['base_dn'] ?? '';
$tlsCertPath = $config['ldap']['ca_cert'] ?? '';
// 相対パスの場合は絶対パスに置き換える
if (strpos($tlsCertPath, "./") === 0 || strpos($tlsCertPath, "../") === 0) {
    $tlsCertPath = __DIR__ . "/" . $tlsCertPath;
}
if (strpos($tlsCertPath, ".\\") === 0 || strpos($tlsCertPath, "..\\") === 0) {
    $tlsCertPath = __DIR__ . "\\" . $tlsCertPath;
}

// Database settings
$dbPath = $config['db']['path'] ?? '';
// 相対パスの場合は絶対パスに置き換える
if (strpos($dbPath, "./") === 0 || strpos($dbPath, "../") === 0) {
    $dbPath = __DIR__ . "/" . $dbPath;
}
if (strpos($dbPath, ".\\") === 0 || strpos($dbPath, "..\\") === 0) {
    $dbPath = __DIR__ . "\\" . $dbPath;
}

// LDAP search settings
$filterPattern = $config['ldap_search']['filter'] ?? '';
$attributes = explode(',', $config['ldap_search']['attributes'] ?? '');

/**
 * via-fileの認証情報のパース
 * 認証情報は一時ファイルに書き込まれ、そのファイルパスが引数として渡される
 */
$tempFilePath = $argv[1];
if (!file_exists($tempFilePath)) {
    error_log("Error: Authentication file not found.\n");
    exit(1);
}

$lines = file($tempFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false || count($lines) < 2) {
    error_log("Error: Invalid authentication file format.\n");
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

/**
 * account名とドメイン名の分離
 * @var string $accountName アカウント名
 * @var string $domainPart ドメイン名
 */
$accountName = $rawUserName;
$domainPart = "";

/**
 * ドメイン名\アカウント名の形式の場合
 * @var array $parts ドメイン名とアカウント名に分割した配列
 */
if (strpos($rawUserName, "\\") !== false) {
    $parts = explode("\\", $rawUserName, 2);
    $domainPart = $parts[0];
    $accountName = $parts[1];
}
/**
 * user@DOMAIN or user@FQDN 形式の場合
 * @var array $parts ドメイン名とアカウント名に分割した配列
 */
elseif (strpos($rawUserName, "@") !== false) {
    $parts = explode("@", $rawUserName, 2);
    $accountName = $parts[0];
    $domainPart = $parts[1];
}

/** 
 * .\user 形式やドメイン指定がない場合は認証拒否
 */
if (strpos($rawUserName, ".\\") === 0 || $domainPart === "") {
    error_log("Auth Denied: User '{$rawUserName}' has no domain specified.\n");
    $rawPassword = '';
    exit(1);
}

/**
 * データベースから有効性をチェックする
 * @var PDO $pdo データベース接続
 * @var PDOStatement $stmt ユーザーの存在と有効フラグ(is_active=1)の確認
 * @var array $user ユーザー情報
 */
try {
    /**
     * @return PDO $pdo データベース接続
     */
    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    /**
     * ユーザーの存在と有効フラグ(is_active=1)の確認
     * @return PDOStatement $stmt
     */
    $stmt = $pdo->prepare("SELECT is_active FROM users WHERE username = :username");
    $stmt->bindValue(':username', $accountName, PDO::PARAM_STR);
    $stmt->execute();

    /**
     * @return array<string, string>|false $user ユーザー情報
     */
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    /**
     * ユーザーが存在しない場合
     */
    if (!$user) {
        error_log("Auth Denied: User '{$accountName}' not found in local database.\n");
        $rawPassword = '';
        exit(1);
    }

    /**
     * 有効フラグ(is_active=1)の確認
     */
    if ((int)$user['is_active'] !== 1) {
        error_log("Auth Denied: User '{$accountName}' is disabled in local database.\n");
        $rawPassword = '';
        exit(1);
    }

    /**
     * 認証成功時のメッセージ
     */
    echo "Local DB Verification Passed for user '{$rawUserName}'.\n";
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage() . "\n");
    $rawPassword = '';
    exit(1);
}

/**
 * 証明書の指定
 */
if (strlen($tlsCertPath) > 0 && file_exists($tlsCertPath)) {
    putenv("LDAPTLS_CACERT=" . $tlsCertPath);
}

/**
 * LDAPサーバにアクセスして認証を行う
 */
try {
    /**
     * @return LDAP\Connection|false $ldapConnect
     */
    $ldapConnect = @ldap_connect($ldapServerUrl);
    if ($ldapConnect === false) {
        error_log("LDAP Error: Fail to connect to {$ldapServerUrl}.\n");
        $rawPassword = '';
        exit(1);
    }

    /**
     * @todo 任意のldapオプションを外部設定ファイルから取得するように変更する
     */
    ldap_set_option($ldapConnect, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldapConnect, LDAP_OPT_REFERRALS, 0);

    /**
     * バインドの実行 (正規化されたFQDN形式でバインド)
     * @return bool
     */
    $ret = @ldap_bind($ldapConnect, $rawUserName, $rawPassword);

    // セキュリティのためバインド直後にパスワード変数をクリア
    $rawPassword = '';
    // bindできない場合にエラーを出して異常終了
    if (!$ret) {
        error_log("Auth Denied: Fail to bind LDAP for user '{$rawUserName}'.\n");
        exit(1);
    }

    /** 
     * LDAPフィルタの取得
     * @return string $filter LDAPフィルタ
     */
    $filter = sprintf($filterPattern, $accountName);

    /**
     * LDAPサーバからユーザー情報を取得する
     * @return LDAP\Result|array<LDAP\Result>|false $sr LDAP検索結果
     */
    $sr = @ldap_search($ldapConnect, $baseDn, $filter, $attributes);
    if ($sr === false) {
        error_log("LDAP Error: ldap_search failed.\n");
        exit(1);
    }

    /**
     * LDAPサーバからユーザー情報を取得する
     * @var array|false $info ユーザー情報
     */
    $info = @ldap_get_entries($ldapConnect, $sr);

    if ($info !== false && $info["count"] > 0) {
        echo "Auth Success: User '{$rawUserName}' authenticated successfully.\n";
        exit(0);
    } else {
        error_log("Auth Denied: User not found in LDAP base DN search.\n");
        exit(1);
    }
} catch (Exception $e) {
    error_log("System Error: Exception raised during LDAP auth: " . $e->getMessage() . "\n");
    $rawPassword = '';
    exit(1);
}
