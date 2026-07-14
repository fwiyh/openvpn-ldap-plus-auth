<?php

namespace OpenVpnLdapPlusAuth;

use Exception;
use LDAP\Connection;

/**
 * LDAP サーバーとの通信、バインド（認証）、および検索処理を担当するクラス
 *
 * @package OpenVpnLdapPlusAuth
 */
class LdapAuthenticator
{
    /** 
     * @var Config 設定管理インスタンス 
     */
    private Config $config;

    /**
     * @var Connection|null 
     * LDAP 接続リソース (PHP 8.1 未満では resource, 8.1 以降では LDAP\Connection)
     */
    private $connection = null;

    /**
     * Ldap コンストラクタ。
     *
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * LDAP サーバーに接続する
     *
     * @return void
     * @throws Exception 接続に失敗した場合
     */
    public function connect(): void
    {
        /**
         * @var string $serverUrl LDAP サーバー URL
         * @var string $caCert CA 証明書のパス
         */
        $serverUrl = $this->config->get('ldap', 'server', '');
        $caCert = $this->config->get('ldap', 'ca_cert', '');

        /**
         * CA 証明書のパスが設定されている場合
         * @var string $resolvedCaCert 解決された CA 証明書のパス
         */
        if (!empty($caCert)) {
            $resolvedCaCert = $this->config->resolvePath($caCert);
            if (file_exists($resolvedCaCert)) {
                putenv("LDAPTLS_CACERT=" . $resolvedCaCert);
            }
        }

        /**
         * LDAP サーバーに接続
         */
        $this->connection = @ldap_connect($serverUrl);
        if ($this->connection === false) {
            throw new Exception("Failed to connect to LDAP server: {$serverUrl}");
        }

        /**
         * オプションの設定
         */
        ldap_set_option($this->connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($this->connection, LDAP_OPT_REFERRALS, 0);
    }

    /**
     * 指定されたユーザー名とパスワードで LDAP サーバーにバインド（認証）する
     *
     * @param string $username バインドに使用するユーザー名 (e.g. user@domain.internal)
     * @param string $password パスワード
     * @return bool バインドに成功した場合は true、失敗した場合は false
     * @throws Exception 接続されていない場合
     */
    public function bind(string $username, string $password): bool
    {
        if (!$this->connection) {
            $this->connect();
        }

        return @ldap_bind($this->connection, $username, $password);
    }

    /**
     * LDAP サーバーからユーザー属性を検索して取得する
     *
     * @param string $username 検索対象のアカウント名 (sAMAccountName 等)
     * @return array 検索結果のエントリ配列
     * @throws Exception 検索に失敗した場合、または接続されていない場合
     */
    public function search(string $username): array
    {
        /**
         * 接続されていない場合は接続する
         */
        if (!$this->connection) {
            $this->connect();
        }

        /**
         * 設定値を取得
         * @var string $baseDn
         * @var string $filterPattern
         * @var string $attributesStr
         */
        $baseDn = $this->config->get('ldap', 'base_dn', '');
        $filterPattern = $this->config->get('ldap_search', 'filter', '');
        $attributesStr = $this->config->get('ldap_search', 'attributes', '');

        /**
         * 検索条件を設定
         * @var array $attributes
         */
        $attributes = array_filter(array_map('trim', explode(',', $attributesStr)));

        /**
         * 検索を実行
         * @var bool|resource $sr
         */
        $filter = sprintf($filterPattern, $username);
        $sr = @ldap_search($this->connection, $baseDn, $filter, $attributes);
        if ($sr === false) {
            throw new Exception("LDAP search failed for filter '{$filter}'.");
        }

        /**
         * 検索結果を取得
         * @var array|bool $entries
         */
        $entries = @ldap_get_entries($this->connection, $sr);
        if ($entries === false) {
            throw new Exception("Failed to get LDAP entries.");
        }

        return $entries;
    }
}
