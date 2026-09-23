<?php

namespace OpenVpnLdapPlusAuth;

use DateTime;

/**
 * Class Logger
 *
 * ログメッセージを標準エラー出力に規定のフォーマットで出力するクラスです。
 * ログフォーマット: [#OVLPA#] [TIMESTAMP] [LEVEL] [MESSAGE]
 *
 * @package OpenVpnLdapPlusAuth
 */
class Logger
{
    /**
     * ログメッセージを出力します。
     *
     * @param string $level ログレベル (ERROR, WARN, INFO, DEBUG)
     * @param string $message ログに記録するメッセージ
     * @return void
     */
    public static function log(string $level, string $message): void
    {
        $timestamp = (new DateTime())->format('Y-m-d\TH:i:sP');
        $logMessage = sprintf("[#OVLPA#] [%s] [%s] %s\n", $timestamp, strtoupper($level), $message);
        error_log($logMessage);
    }

    /**
     * ERROR レベルのログを出力します。
     *
     * @param string $message ログメッセージ
     * @return void
     */
    public static function error(string $message): void
    {
        self::log('ERROR', $message);
    }

    /**
     * WARN レベルのログを出力します。
     *
     * @param string $message ログメッセージ
     * @return void
     */
    public static function warn(string $message): void
    {
        self::log('WARN', $message);
    }

    /**
     * INFO レベルのログを出力します。
     *
     * @param string $message ログメッセージ
     * @return void
     */
    public static function info(string $message): void
    {
        self::log('INFO', $message);
    }

    /**
     * DEBUG レベルのログを出力します。
     *
     * @param string $message ログメッセージ
     * @return void
     */
    public static function debug(string $message): void
    {
        self::log('DEBUG', $message);
    }
}
