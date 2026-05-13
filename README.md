# ldapと独自データベースを併用したOpenVPNの認証スクリプト
- 独自データベースに登録されているユーザーのみ接続できるスクリプト
- ただしユーザー認証はLDAP（主にActive Directory）で行う

## インストール方法
1. 任意のパスに以下を格納する
  - openvpn-ldap-plus-auth.php
  - auth.db
  - openvpn-ldap-plus-auth.conf
2. openvpn-ldap-plus-auth.confに以下の設定を追加する
   - LDAPサーバのURL
   - ログイン対象となるDN
   - CA証明書のパス
   - auth.dbのパス
   - ユーザー認証を実行する場合のフィルター
   - ドメインマッピング（FQDNドメイン名と単一ラベルの名前空間をマッピングする必要がある場合）
3. 対象のOpenVPNサーバの設定ファイルに以下を追記
```conf
# Custom Authenticate Script
script-security 2
username-as-common-name
auth-user-pass-verify /etc/openvpn/auth/openvpn-ldap-plus-auth.php via-file
```

4. auth.dbにログインユーザーを追加する
- SQLiteのテーブル構造
```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
```
- ユーザー追加方法
```sh
sqlite3 auth.db "INSERT INTO users (username, is_active) VALUES ('username', 1)"
```
- ユーザーの有効化
```sh
sqlite3 auth.db "UPDATE users SET is_active = 1 WHERE username = 'username'"
```
- ユーザーの無効化
```sh
sqlite3 auth.db "UPDATE users SET is_active = 0 WHERE username = 'username'"
```
- ユーザーの削除
```sh
sqlite3 auth.db "DELETE FROM users WHERE username = 'username'"
```
- ユーザーの一覧表示
```sh
sqlite3 auth.db "SELECT * FROM users"
```


## インストール補足
### server.confの記述
- 認証スクリプトを`/etc/openvpn/auth/openvpn-ldap-plus-auth.php`に配置した場合に以下を追記する
```conf
# Custom Authenticate Script
script-security 2
username-as-common-name
auth-user-pass-verify /etc/openvpn/auth/openvpn-ldap-plus-auth.php via-file
```
- 上の例ではdebian13の標準パッケージにある`php-cli` `php-ldap`をインストールしている
- `script-security 2`は必須


