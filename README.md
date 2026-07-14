# LDAPと独自データベースを併用したOpenVPNの認証スクリプト
- OpenVPNサーバの認証スクリプトとして動作する
- 独自データベースに登録されているユーザーのみ接続できる
- ユーザー認証はLDAP（主にActive Directory）で行う

## ソフトウェア要件
- PHP8.0以降
  - windows版php8.4.11(nts)で動作確認を行っている
- php標準機能として以下のDynamic Extensionが必要
  - ldap
  - pdo
  - 使用するデータベースに対応したPDOドライバ(pdo_sqliteなど)

## 処理概要
1. ユーザーパスワード認証として`ユーザー名@ドメインのFQDN` `ユーザー名@単一ラベルのDNS名` `単一ラベルのDNS名\ユーザー名`でユーザー名が渡された場合のみ処理を続行する
  - `.\ユーザー名`やドメイン情報を一切持たないユーザー名はローカルアカウントとして独自DB内にある情報で認証する
2. 独自DBからユーザー名と一致するデータが有り、かつ、有効になっている場合に処理を続行する
3. ユーザー名とパスワードを元に単純にLDAP認証を行い、正常に認証ができた場合のみOpenVPN接続を許可する。ドメインユーザーである場合はLDAPサーバに対してパスワード検証を行う。ローカルユーザーである場合は独自DB内のパスワードを元にLDAP認証を行う。

## インストール方法
1. 任意のパスに以下を格納する
  - openvpn-ldap-plus-auth.php
  - openvpn-ldap-plus-auth.conf
    - openvpn-ldap-plus-auth.conf.sampleをリネームしたもの
  - ovpn-user.php
2. openvpn-ldap-plus-auth.confに以下の設定を追加する
   - LDAPサーバのURL
   - ログイン対象となるDN
   - CA証明書のパス
   - auth.dbのパス
   - ユーザー認証を実行する場合のフィルター
   - ドメインマッピング（FQDNドメイン名と単一ラベルの名前空間をマッピングする必要がある場合）
3. 対象のOpenVPNサーバの設定ファイルに以下を追記
- 以下の例では`/etc/openvpn/auth`ディレクトリを作成して配置した場合の記述
```conf
# Custom Authenticate Script
script-security 2
username-as-common-name
auth-user-pass-verify /etc/openvpn/auth/openvpn-ldap-plus-auth.php via-file
```

4. ユーザー登録
- コマンドラインツールとして`ovpn-user.php`を用意している
- データベースを作成していない場合は`php /path/to/ovpn-user.php init`を実行してデータベースを作成する

- 以下、データベース操作を行うコマンドラインツールのオプション一覧
```sh
# データベース作成: openvpn-ldap-plus-auth.confに設定されているDBのパスからデータベースを構築
php ovpn-user.php init
# ユーザーを追加する（ドメインユーザーとして登録）
php ovpn-user.php add <username> [--domain]
# ユーザーを追加する（ローカルユーザーとして登録）
php ovpn-user.php add <username> --local -p <password>
# ユーザー名を変更する
php ovpn-user.php update <username> <new-username> [--domain]
# ユーザー名を変更する（ドメインユーザーからローカルユーザーに変更する場合）
php ovpn-user.php update <username> <new-username> --local -p <password>
# ユーザーを削除する
php ovpn-user.php delete <username>
# 登録ユーザー一覧をjson形式で返す
php ovpn-user.php list
# 登録ユーザーを無効にする(is_active=0)
php ovpn-user.php disable <username>
# 登録ユーザーを有効にする(is_active=1)
php ovpn-user.php enable <username>
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

## 配布ソースの作成方法
- `composer build`を実行して、`dist/`ディレクトリにファイルが生成される
- `src/openvpn-ldap-plus-auth.conf.sample`を含めた3ファイルが配布対象となる

## 特殊運用
### 手動でテーブルを作成する場合のテーブル定義
- sqliteでは以下のテーブル定義で作成されている
  - is_domain, is_activeは数値で表現しており0:false, 1:trueとなっているが内部処理として1:true、それ以外はfalseとみなされる
```sql
-- ユーザーテーブル
CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL,
  is_domain INTEGER NOT NULL DEFAULT 1,
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  UNIQUE(username)
);

-- パスワードテーブル (ローカルユーザー用)
CREATE TABLE user_passwords (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  password_hash TEXT NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  FOREIGN KEY(user_id) REFERENCES users(id)
);
```

### パスワードの保存形式
- パスワードは`password_hash()`で生成したハッシュ値を `user_passwords`テーブルに登録する
- `password_hash()`で生成できるハッシュ値であればどのようなものでも検証できる（`PASSWORD_ARGON2I`など）
- `password_hash()`では `PASSWORD_DEFAULT`が使用されているため、特別な指定がなければPHP8.0以降ではbcryptが使用される

