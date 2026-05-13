<?php

/**
 * 独自DBにユーザーの登録・更新・削除を行う
 * 
 * command:
 *  php ovpn-user.php init
 *  php ovpn-user.php add <username>
 *  php ovpn-user.php update <username> <new-username>
 *  php ovpn-user.php delete <username>
 *  php ovpn-user.php list
 *  php ovpn-user.php disable <username>
 *  php ovpn-user.php enable <username>
 *  
 */

# params
if ($argc < 2) {
    echo "argument error.\n";
    exit(1);
}

# get params
$operation = $argv[1];
$params = array_slice($argv, 2);
$qurtyTemplate = "";
$queryParams = [];

/**
 * 操作別クエリとパラメータの取得
 */
switch ($operation) {
    case "init":
        $qurtyTemplate = "CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            is_active INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE (username)
        )";
        $queryParams = [];
        break;
    case "add":
        $userName = $params[0];
        $qurtyTemplate = "INSERT INTO users (username, is_active, created_at, updated_at) VALUES (:username, :is_active, :created_at, :updated_at)";
        $queryParams = [
            ":username" => $userName,
            ":is_active" => 1,
            ":created_at" => getCurrentDateTime(),
            ":updated_at" => getCurrentDateTime()
        ];
        break;
    case "update":
        $userName = $params[0];
        $newName = $params[1];
        if (empty($newName)) {
            echo "new name is required.\n";
            exit(1);
        }
        $qurtyTemplate = "UPDATE users SET username = :newname, is_active = :is_active, updated_at = :updated_at WHERE username = :username";
        $queryParams = [
            ":username" => $userName,
            ":newname" => $newName,
            ":is_active" => 1,
            ":updated_at" => getCurrentDateTime()
        ];
        break;
    case "delete":
        $userName = $params[0];
        $qurtyTemplate = "DELETE FROM users WHERE username = :username";
        $queryParams = [
            ":username" => $userName
        ];
        break;
    case "list":
        $qurtyTemplate = "SELECT * FROM users order by id";
        break;
    case "disable":
        $userName = $params[0];
        $qurtyTemplate = "UPDATE users SET is_active = :is_active, updated_at = :updated_at WHERE username = :username";
        $queryParams = [
            ":username" => $userName,
            ":is_active" => 0,
            ":updated_at" => getCurrentDateTime()
        ];
        break;
    case "enable":
        $userName = $params[0];
        $qurtyTemplate = "UPDATE users SET is_active = :is_active, updated_at = :updated_at WHERE username = :username";
        $queryParams = [
            ":username" => $userName,
            ":is_active" => 1,
            ":updated_at" => getCurrentDateTime()
        ];
        break;
    default:
        echo "unknown operation.\n";
        exit(1);
}

# get conf 同じディレクトリにある前提
$config = parse_ini_file(__DIR__ . "/openvpn-ldap-plus-auth.conf", true);
if ($config === false) {
    error_log("Error: Failed to parse configuration file $config.\n");
    exit(1);
}

// sqliteファイルのフルパスを取得
$dbPath = $config['db']['path'] ?? '';
// 相対パスの場合は絶対パスに置き換える
if (strpos($dbPath, "./") === 0 || strpos($dbPath, "../") === 0) {
    $dbPath = __DIR__ . "/" . $dbPath;
}
if (strpos($dbPath, ".\\") === 0 || strpos($dbPath, "..\\") === 0) {
    $dbPath = __DIR__ . "\\" . $dbPath;
}

/**
 * データベース操作
 * @var PDO $pdo データベース接続
 * @var PDOStatement $stmt プリペアドステートメント
 */
try {
    /**
     * @return PDO $pdo データベース接続
     */
    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    /**
     * ユーザーの存在と有効フラグ(is_active=1)の確認
     * @return PDOStatement
     */
    $stmt = $pdo->prepare($qurtyTemplate);

    /**
     * クエリパラメータのバインド
     */
    foreach ($queryParams as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    /**
     * クエリの実行
     */
    $stmt->execute();

    /**
     * 結果の取得
     * @return array|false $user ユーザー情報
     */
    if ($operation !== "list") {
        $userCount = $stmt->rowCount();
        echo "$userCount user(s) have been processed.\n";
    } else {
        $user = $stmt->fetchAll(PDO::FETCH_ASSOC);
        var_dump(json_encode($user, JSON_PRETTY_PRINT));
    }
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    $pdo = null;
}

/**
 * ISO8601形式で現在日時を取得
 * 各種RDBMSが解釈できる形式で出力
 * @return string 現在日時(RFC3339 Y-m-dTH:i:s±hh:mm)
 */
function getCurrentDateTime()
{
    $currentDateTime = (new DateTime())->format(DateTime::RFC3339_EXTENDED);
    return preg_replace("/\+00:00/", "Z", $currentDateTime);
}
