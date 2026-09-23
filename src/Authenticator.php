<?php

namespace OpenVpnLdapPlusAuth;

use PDO;
use PDOException;
use Exception;

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
