<?php

namespace OpenVpnLdapPlusAuth;

use Exception;

/**
 * Config クラス
 *
 * 設定ファイルの解析およびパスの解決を担当するクラス
 *
 * @package OpenVpnLdapPlusAuth
 */
class Config
{
    /** 
     * @var array<string, array<string, mixed>> 設定配列
     */
    private array $settings = [];

    /** 
     * @var string 設定ファイルが存在するディレクトリのパス
     */
    private string $configDir;

    /**
     * コンストラクタ
     *
     * @param string $filePath 設定ファイルのパス
     * @throws Exception 設定ファイルが存在しない、または解析に失敗した場合
     */
    public function __construct(string $filePath)
    {
        /**
         * 設定ファイルのパスから、設定ファイルが存在するディレクトリのパスを取得
         * @var string $configDir 設定ファイルが存在するディレクトリのパス
         */
        $this->configDir = dirname($filePath);

        /**
         * 設定ファイルが存在しない場合、例外をスロー
         */
        if (!file_exists($filePath)) {
            throw new Exception("Configuration file '{$filePath}' not found.");
        }

        /**
         * 設定ファイルをパースする
         * @var mixed $parsed 設定ファイルのパース結果
         */
        $parsed = parse_ini_file($filePath, true);

        /**
         * 設定ファイルのパースに失敗した場合、例外をスロー
         */
        if ($parsed === false) {
            throw new Exception("Failed to parse configuration file '{$filePath}'.");
        }

        $this->settings = $parsed;
    }

    /**
     * 設定値を取得する
     *
     * @param string $section セクション名
     * @param string $key キー名
     * @param mixed $default デフォルト値
     * @return mixed
     */
    public function get(string $section, string $key, $default = null)
    {
        return $this->settings[$section][$key] ?? $default;
    }

    /**
     * 相対パス・絶対パスを判定し、絶対パスとして解決します。
     * 設定ファイルが存在するディレクトリを基準とします。
     *
     * @param string $path 解決対象のパス
     * @return string 解決された絶対パス
     */
    public function resolvePath(string $path): string
    {
        /**
         * パスが空の場合、空文字列を返す
         */
        if (empty($path)) {
            return '';
        }

        /**
         * 絶対パスの判定
         * Windows: ドライブレターから始まる場合 (e.g. C:\ や C:/) または バックスラッシュ/スラッシュから始まる場合
         * Linux/Unix: スラッシュから始まる場合
         */
        if (preg_match('/^[a-zA-Z]:[\\\\\/]/', $path) || strpos($path, '/') === 0 || strpos($path, '\\') === 0) {
            /**
             * 絶対パスの場合はそのまま返す
             */
            return $path;
        }

        /**
         * 相対パスの場合、設定ファイルが存在するディレクトリを基準に解決して返す
         * @var string $absolutePath 解決された絶対パス
         */
        $absolutePath = $this->configDir . DIRECTORY_SEPARATOR . $path;

        return $absolutePath;
    }
}
