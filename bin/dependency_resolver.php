<?php
declare(strict_types=1);

/**
 * PHP ファイルの依存関係を解決するユーティリティ関数群
 *
 * Functions:
 *   - parseDependencies(string $filePath): array
 *   - resolveClassToFile(string $fqcn, string $srcDir): ?string
 *   - resolveRequirePath(string $reqExpr, string $fileDir): ?string
 *   - isBootstrapFile(string $filePath): bool
 *   - isProjectClassFile(string $filePath): bool
 *   - collectClassFiles(string $entryFile, string $srcDir, array &$visited): string[]
 *   - collectStandardUses(string $filePath): string[]
 *   - collectAllUses(string $filePath): string[]
 */

/**
 * ファイルの依存関係（use宣言・require文）をパースする
 *
 * @param string $filePath 対象ファイルパス
 * @return array{uses: string[], requires: string[]} パース結果
 */
function parseDependencies(string $filePath): array
{
    $content = file_get_contents($filePath);
    if ($content === false) {
        return ['uses' => [], 'requires' => []];
    }

    // use 宣言を収集（行頭のみ対象）
    $uses = [];
    if (preg_match_all('/^use\s+([^;]+);/m', $content, $matches)) {
        foreach ($matches[1] as $useStmt) {
            foreach (array_map('trim', explode(',', $useStmt)) as $part) {
                $uses[] = ltrim($part, '\\');
            }
        }
    }

    // require / require_once / include / include_once を収集
    $requires = [];
    if (preg_match_all('/(?:require_once|require|include_once|include)\s+([^;]+);/', $content, $reqMatches)) {
        $requires = $reqMatches[1];
    }

    return ['uses' => $uses, 'requires' => $requires];
}

/**
 * FQCN を srcDir 配下のファイルパスに解決する
 * PSR-4: OpenVpnLdapPlusAuth\ => src/
 *
 * @param string $fqcn 完全修飾クラス名
 * @param string $srcDir ソースディレクトリ
 * @return string|null ファイルパス（存在しない場合は null）
 */
function resolveClassToFile(string $fqcn, string $srcDir): ?string
{
    $prefix = 'OpenVpnLdapPlusAuth\\';
    if (strncmp($fqcn, $prefix, strlen($prefix)) !== 0) {
        return null;
    }
    $relative = str_replace('\\', '/', substr($fqcn, strlen($prefix)));
    $fullPath = rtrim($srcDir, '/\\') . '/' . $relative . '.php';
    return is_file($fullPath) ? $fullPath : null;
}

/**
 * require文の引数文字列を実際のファイルパスに解決する
 *
 * @param string $reqExpr require文の引数部分
 * @param string $fileDir 対象ファイルのディレクトリ
 * @return string|null 解決されたファイルパス
 */
function resolveRequirePath(string $reqExpr, string $fileDir): ?string
{
    // __DIR__ を実際のディレクトリに置換
    $req = str_replace('__DIR__', "'{$fileDir}'", $reqExpr);
    // 文字列連結 ('...' . '...') を除去
    $req = preg_replace("/['\"]\\s*\\.\\s*['\"]/", '', $req);
    // 前後のクォートを除去
    $req = trim(trim($req), "'\"");

    if (empty($req)) {
        return null;
    }

    $resolved = realpath($req);
    return $resolved !== false ? $resolved : null;
}

/**
 * ファイルが bootstrap.php かどうか判定する
 *
 * @param string $filePath ファイルパス
 * @return bool bootstrap.php の場合は true
 */
function isBootstrapFile(string $filePath): bool
{
    return basename($filePath) === 'bootstrap.php';
}

/**
 * ファイルが OpenVpnLdapPlusAuth namespace のクラスファイルかどうか判定する
 *
 * @param string $filePath ファイルパス
 * @return bool クラスファイルの場合は true
 */
function isProjectClassFile(string $filePath): bool
{
    $content = file_get_contents($filePath);
    if ($content === false) {
        return false;
    }
    return (bool) preg_match('/^namespace\s+OpenVpnLdapPlusAuth\s*;/m', $content);
}

/**
 * エントリポイントから依存するプロジェクトクラスファイルを再帰的に収集する
 * bootstrap.php はスキップし、エントリポイント自身は含めない
 *
 * @param string $entryFile エントリポイントファイルパス
 * @param string $srcDir ソースディレクトリ
 * @param array<string, bool> $visited 訪問済みマップ（再帰用）
 * @return string[] クラスファイルの配列（依存関係順、重複なし）
 */
function collectClassFiles(string $entryFile, string $srcDir, array &$visited = []): array
{
    $real = realpath($entryFile);
    if ($real === false || isset($visited[$real])) {
        return [];
    }
    $visited[$real] = true;

    $files = [];
    $deps = parseDependencies($real);

    // use 宣言から OpenVpnLdapPlusAuth\* のクラスファイルを解決
    foreach ($deps['uses'] as $class) {
        $classFile = resolveClassToFile($class, $srcDir);
        if ($classFile !== null) {
            $files = array_merge($files, collectClassFiles($classFile, $srcDir, $visited));
        }
    }

    // require 系から依存ファイルを解決
    foreach ($deps['requires'] as $req) {
        $resolved = resolveRequirePath($req, dirname($real));
        if ($resolved === null || isBootstrapFile($resolved)) {
            continue; // bootstrap.php はスキップ
        }
        if (isProjectClassFile($resolved)) {
            $files = array_merge($files, collectClassFiles($resolved, $srcDir, $visited));
        }
    }

    // このファイル自身がクラスファイルなら追加
    if (isProjectClassFile($real)) {
        $files[] = $real;
    }

    return $files;
}

/**
 * クラスファイルから標準ライブラリの use 宣言のみを収集する
 * （OpenVpnLdapPlusAuth\* は除外）
 *
 * @param string $filePath 対象ファイルパス
 * @return string[] use 宣言の配列（クラス名のみ）
 */
function collectStandardUses(string $filePath): array
{
    $deps = parseDependencies($filePath);
    $result = [];
    foreach ($deps['uses'] as $use) {
        if (strncmp($use, 'OpenVpnLdapPlusAuth\\', 20) !== 0) {
            $result[] = $use;
        }
    }
    return $result;
}

/**
 * ファイルからすべての use 宣言を収集する（エントリポイント用）
 *
 * @param string $filePath 対象ファイルパス
 * @return string[] use 宣言の配列（クラス名のみ、重複なし）
 */
function collectAllUses(string $filePath): array
{
    $deps = parseDependencies($filePath);
    return array_values(array_unique($deps['uses']));
}
