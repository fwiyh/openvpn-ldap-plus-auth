<?php

/**
 * OpenVPN LDAP + RDBMS 認証スクリプト
 * PSR-4 オートローダーを含むブートストラップファイル
 */
spl_autoload_register(function (string $class): void {
    /**
     * @var string $prefix
     */
    $prefix = 'OpenVpnLdapPlusAuth\\';
    /**
     * @var string $baseDir
     */
    $baseDir = __DIR__ . '/';

    /**
     * @var int $len
     */
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    /**
     * @var string $relativeClass
     */
    $relativeClass = substr($class, $len);
    /**
     * @var string $file
     */
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
