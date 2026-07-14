#!/usr/bin/php
<?php
declare(strict_types=1);

/**
 * bin/merge.php
 *
 * PHPソースファイルを1ファイルに統合するビルドスクリプト
 *
 * 出力形式:
 *   - クラスファイル群を namespace OpenVpnLdapPlusAuth { } ブロックに統合
 *   - エントリポイントを namespace { } ブロックに統合
 *   - 各ファイルの前後に Begin/End コメントを付与
 *   - bootstrap.php は統合から除外（省略）
 *   - require/require_once はコメントアウト
 *
 * Usage:
 *   php bin/merge.php [--config=<path>]
 *
 * Composerから実行:
 *   composer run build
 *   composer run build -- --config=path/to/merge.conf
 */

require_once __DIR__ . '/dependency_resolver.php';

// プロジェクトルート = bin/ の親ディレクトリ
$projectRoot = (string) realpath(__DIR__ . '/..');
$projectName = basename($projectRoot);

// --config オプションの解析
$configFile = __DIR__ . '/merge.conf';
foreach (array_slice($argv, 1) as $arg) {
    if (strncmp($arg, '--config=', 9) === 0) {
        $configFile = substr($arg, 9);
    }
}

if (!is_file($configFile)) {
    fwrite(STDERR, "Error: config file not found: {$configFile}\n");
    exit(1);
}

$buildConfig = parse_ini_file($configFile, true);
if ($buildConfig === false) {
    fwrite(STDERR, "Error: failed to parse config file: {$configFile}\n");
    exit(1);
}

$srcDir = $projectRoot . '/src';

foreach ($buildConfig as $sectionName => $section) {
    $entryRelative = $section['entry'] ?? '';
    $outputRelative = $section['output'] ?? '';

    if (empty($entryRelative) || empty($outputRelative)) {
        fwrite(STDERR, "Error: section [{$sectionName}] must have 'entry' and 'output'.\n");
        exit(1);
    }

    $entryFile  = $projectRoot . '/' . $entryRelative;
    $outputFile = $projectRoot . '/' . $outputRelative;

    if (!is_file($entryFile)) {
        fwrite(STDERR, "Error: entry file not found: {$entryFile}\n");
        exit(1);
    }

    $outputDir = dirname($outputFile);
    if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
        fwrite(STDERR, "Error: failed to create output directory: {$outputDir}\n");
        exit(1);
    }

    fwrite(STDOUT, "Building [{$sectionName}]...\n");

    $content = buildMergedFile($entryFile, $srcDir, $projectRoot, $projectName);

    if (file_put_contents($outputFile, $content) === false) {
        fwrite(STDERR, "Error: failed to write: {$outputFile}\n");
        exit(1);
    }

    // *nix環境では実行権限を付与
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        chmod($outputFile, 0755);
    }

    fwrite(STDOUT, "  => {$outputFile}\n");
}

fwrite(STDOUT, "Done.\n");

// ---------------------------------------------------------------------------
// ビルド関数群
// ---------------------------------------------------------------------------

/**
 * エントリポイントから統合PHPファイルの文字列を生成する
 *
 * @param string $entryFile   エントリポイントファイルの絶対パス
 * @param string $srcDir      ソースディレクトリの絶対パス
 * @param string $projectRoot プロジェクトルートの絶対パス
 * @param string $projectName プロジェクト名（コメント用）
 * @return string 統合後のPHPファイル内容
 */
function buildMergedFile(
    string $entryFile,
    string $srcDir,
    string $projectRoot,
    string $projectName
): string {
    // 依存クラスファイルを収集（エントリポイント自身は含まない）
    $visited    = [];
    $classFiles = collectClassFiles($entryFile, $srcDir, $visited);

    // ===== namespace OpenVpnLdapPlusAuth { } ブロックの構築 =====
    $namespaceBlock = buildNamespaceBlock($classFiles, $projectRoot, $projectName);

    // ===== namespace { } ブロックの構築 =====
    $globalBlock = buildGlobalBlock($entryFile, $projectRoot, $projectName);

    // ===== 最終出力の組み立て =====
    $output  = "#!/usr/bin/php\n";
    $output .= "<?php\n";
    $output .= "declare(strict_types=1);\n";
    $output .= "\n";

    if ($namespaceBlock !== '') {
        $output .= $namespaceBlock;
        $output .= "\n";
    }

    $output .= $globalBlock;

    return $output;
}

/**
 * クラスファイル群から namespace OpenVpnLdapPlusAuth { } ブロックを構築する
 *
 * @param string[] $classFiles クラスファイルの配列
 * @param string   $projectRoot プロジェクトルートの絶対パス
 * @param string   $projectName プロジェクト名
 * @return string namespace ブロックの文字列（クラスファイルなしの場合は空文字）
 */
function buildNamespaceBlock(array $classFiles, string $projectRoot, string $projectName): string
{
    if (empty($classFiles)) {
        return '';
    }

    // 全クラスファイルから標準ライブラリ use 宣言を収集・重複除去
    $stdUses = [];
    foreach ($classFiles as $classFile) {
        foreach (collectStandardUses($classFile) as $use) {
            $stdUses[$use] = true;
        }
    }

    $block  = "namespace OpenVpnLdapPlusAuth {\n";

    // 標準ライブラリ use 宣言を先頭に一括出力
    foreach (array_keys($stdUses) as $use) {
        $block .= "    use {$use};\n";
    }
    if (!empty($stdUses)) {
        $block .= "\n";
    }

    // クラスファイルの内容を追加
    foreach ($classFiles as $classFile) {
        $relPath = getRelativePath($classFile, $projectRoot, $projectName);
        $content = processClassFileContent($classFile);

        $block .= "    // ---- Begin {$relPath} ----\n";
        $block .= $content;
        $block .= "    // ---- End {$relPath} ----\n";
        $block .= "\n";
    }

    $block .= "}\n";

    return $block;
}

/**
 * エントリポイントから namespace { } ブロックを構築する
 *
 * @param string $entryFile   エントリポイントファイルの絶対パス
 * @param string $projectRoot プロジェクトルートの絶対パス
 * @param string $projectName プロジェクト名
 * @return string global namespace ブロックの文字列
 */
function buildGlobalBlock(string $entryFile, string $projectRoot, string $projectName): string
{
    $relPath = getRelativePath((string) realpath($entryFile), $projectRoot, $projectName);

    // エントリポイントから use 宣言を収集
    $uses = collectAllUses($entryFile);

    $block  = "namespace {\n";

    // use 宣言を先頭に出力（グローバルnamespaceでは非複合名は不要なため除外）
    foreach ($uses as $use) {
        // 'Exception', 'PDO' などバックスラッシュなしの非複合名は namespace{} 内では無効
        if (strpos($use, '\\') === false) {
            continue;
        }
        $block .= "    use {$use};\n";
    }
    if (!empty($uses)) {
        $block .= "\n";
    }

    // エントリポイントの本体
    $block .= "    // ---- Begin {$relPath} ----\n";
    $block .= processEntryFileContent($entryFile);
    $block .= "    // ---- End {$relPath} ----\n";

    $block .= "}\n";

    return $block;
}

/**
 * クラスファイルの内容をブロック埋め込み用に加工する
 * - <?php / declare / namespace / use 宣言を除去
 * - 各行に4スペースインデントを追加
 *
 * @param string $filePath クラスファイルパス
 * @return string 加工後の文字列（末尾に改行あり）
 */
function processClassFileContent(string $filePath): string
{
    $content = file_get_contents($filePath);
    if ($content === false) {
        return '';
    }

    $lines  = explode("\n", $content);
    $result = [];

    foreach ($lines as $line) {
        // 除去対象: <?php, declare(strict_types=1);, namespace OpenVpnLdapPlusAuth;, use ...;
        if (preg_match('/^<\?php\s*$/', $line)) {
            continue;
        }
        if (preg_match('/^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*$/', $line)) {
            continue;
        }
        if (preg_match('/^\s*namespace\s+OpenVpnLdapPlusAuth\s*;\s*$/', $line)) {
            continue;
        }
        if (preg_match('/^\s*use\s+[^;]+;\s*$/', $line)) {
            continue;
        }
        // 空行はそのまま、それ以外は4スペースインデントを追加
        $result[] = ($line === '' || trim($line) === '') ? '' : '    ' . $line;
    }

    // 先頭・末尾の空行を除去
    while (!empty($result) && trim($result[0]) === '') {
        array_shift($result);
    }
    while (!empty($result) && trim($result[count($result) - 1]) === '') {
        array_pop($result);
    }

    if (empty($result)) {
        return '';
    }

    return implode("\n", $result) . "\n";
}

/**
 * エントリポイントの内容をブロック埋め込み用に加工する
 * - shebang / <?php / declare / use 宣言を除去
 * - require/require_once/include/include_once をコメントアウト
 *   （bootstrap.php は内容を展開せず、コメントアウトのみ）
 * - 各行に4スペースインデントを追加
 *
 * @param string $filePath エントリポイントファイルパス
 * @return string 加工後の文字列（末尾に改行あり）
 */
function processEntryFileContent(string $filePath): string
{
    $content = file_get_contents($filePath);
    if ($content === false) {
        return '';
    }

    $lines  = explode("\n", $content);
    $result = [];

    foreach ($lines as $line) {
        // shebang
        if (preg_match('/^#!/', $line)) {
            continue;
        }
        // <?php
        if (preg_match('/^<\?php\s*$/', $line)) {
            continue;
        }
        // declare(strict_types=1);
        if (preg_match('/^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*$/', $line)) {
            continue;
        }
        // use 宣言（先頭でまとめて出力済みのため除去）
        if (preg_match('/^\s*use\s+[^;]+;\s*$/', $line)) {
            continue;
        }
        // require / require_once / include / include_once をコメントアウト
        if (preg_match('/^\s*(require_once|require|include_once|include)\s+/', $line)) {
            $result[] = '    // ' . $line;
            continue;
        }
        // 4スペースインデント
        $result[] = ($line === '' || trim($line) === '') ? '' : '    ' . $line;
    }

    // 先頭・末尾の空行を除去
    while (!empty($result) && trim($result[0]) === '') {
        array_shift($result);
    }
    while (!empty($result) && trim($result[count($result) - 1]) === '') {
        array_pop($result);
    }

    if (empty($result)) {
        return '';
    }

    return implode("\n", $result) . "\n";
}

/**
 * ファイルパスをコメント用の相対パスに変換する
 * 形式: {projectName}\{projectRoot からの相対パス}
 *
 * @param string $filePath    ファイルの絶対パス
 * @param string $projectRoot プロジェクトルートの絶対パス
 * @param string $projectName プロジェクト名
 * @return string バックスラッシュ区切りの相対パス
 */
function getRelativePath(string $filePath, string $projectRoot, string $projectName): string
{
    // パス区切り文字を統一
    $filePath    = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
    $projectRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $projectRoot), DIRECTORY_SEPARATOR);

    $relative = ltrim(str_replace($projectRoot, '', $filePath), DIRECTORY_SEPARATOR);

    // コメント内ではバックスラッシュに統一（Windowsスタイル）
    $relative = str_replace('/', '\\', $relative);

    return $projectName . '\\' . $relative;
}