<?php
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

use DateTime;
use PDO;
use Exception;
use OpenVpnLdapPlusAuth\Config;
use OpenVpnLdapPlusAuth\Database;
use OpenVpnLdapPlusAuth\Logger;

require_once __DIR__ . '/bootstrap.php';

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
