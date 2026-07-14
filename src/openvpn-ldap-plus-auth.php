#!/usr/bin/php
<?php
/**
 * OpenVPN LDAP + RDBMS 認証
 * 
 * 1. via-file で認証情報を読み込む (OpenVPN auth-user-pass-verify via-file)
 * 2. データベースの認証情報を検証
 * 3. LDAPにバインド / ローカル認証情報検証
 * 4. 成功した場合は exit(0) を返す。失敗した場合は exit(1) を返す。
 */

use Exception;
use OpenVpnLdapPlusAuth\Config;
use OpenVpnLdapPlusAuth\Database;
use OpenVpnLdapPlusAuth\LdapAuthenticator;
use OpenVpnLdapPlusAuth\Authenticator;
use OpenVpnLdapPlusAuth\Logger;

require_once __DIR__ . '/bootstrap.php';

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
