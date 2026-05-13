# LDAPと独自データベースを併用したOpenVPNの認証スクリプト
- 独自データベースに登録されているユーザーのみ接続できるスクリプト
- ただしユーザー認証はLDAP（主にActive Directory）で行う

## 処理概要
1. ユーザーパスワード認証として`ユーザー名@ドメインのFQDN` `ユーザー名@単一ラベルのDNS名` `単一ラベルのDNS名\ユーザー名`でユーザー名が渡された場合のみ処理を続行する
  - `.\ユーザー名`やドメイン情報を一切持たないユーザー名のみのログインは拒否する
2. sqliteファイルからユーザー名と一致するデータが有り、かつ、有効になっている場合に処理を続行する
3. 入力されたユーザー名とパスワードを元に単純にLDAP認証を行い、正常に認証ができた場合のみOpenVPN接続を許可する。

## インストール方法
1. 任意のパスに以下を格納する
  - openvpn-ldap-plus-auth.php
  - openvpn-ldap-plus-auth.conf
  - ovpn-user.php
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

4. ユーザー登録
- コマンドラインツールとして`ovpn-user.php`を用意している
```sh
# データベース作成: openvpn-ldap-plus-auth.confに設定されているDBのパスからsqliteファイルを作成
php ovpn-user.php init
# ユーザーを登録する
php ovpn-user.php add <username>
# ユーザー名を変更する
php ovpn-user.php update <username> <new-username>
# ユーザーを削除する
php ovpn-user.php delete <username>
# ユーザー一覧をjson形式で返す
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


