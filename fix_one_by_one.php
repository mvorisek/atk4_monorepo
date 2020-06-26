<?php

function getDirContents(string $dir, int $onlyFiles = 0, string $excludeRegex = '~/\.git/~', int $maxDepth = -1): array {
    $results = [];
    $scanAll = scandir($dir);
    sort($scanAll);
    $scanDirs = []; $scanFiles = [];
    foreach($scanAll as $fName){
        if ($fName === '.' || $fName === '..') { continue; }
        $fPath = str_replace(DIRECTORY_SEPARATOR, '/', realpath($dir . '/' . $fName));
        if (strlen($excludeRegex) > 0 && preg_match($excludeRegex, $fPath . (is_dir($fPath) ? '/' : ''))) { continue; }
        if (is_dir($fPath)) {
            $scanDirs[] = $fPath;
        } elseif ($onlyFiles >= 0) {
            $scanFiles[] = $fPath;
        }
    }

    foreach ($scanDirs as $pDir) {
        if ($onlyFiles <= 0) {
            $results[] = $pDir;
        }
        if ($maxDepth !== 0) {
            foreach (getDirContents($pDir, $onlyFiles, $excludeRegex, $maxDepth - 1) as $p) {
                $results[] = $p;
            }
        }
    }
    foreach ($scanFiles as $p) {
        $results[] = $p;
    }

    return $results;
}

function updateKeysWithRelPath(array $paths, string $baseDir, bool $allowBaseDirPath = false): array {
    $results = [];
    $regex = '~^' . preg_quote(str_replace(DIRECTORY_SEPARATOR, '/', realpath($baseDir)), '~') . '(?:/|$)~s';
    $regex = preg_replace('~/~', '/(?:(?!\.\.?/)(?:(?!/).)+/\.\.(?:/|$))?(?:\.(?:/|$))*', $regex); // limited to only one "/xx/../" expr
    if (DIRECTORY_SEPARATOR === '\\') {
        $regex = preg_replace('~/~', '[/\\\\\\\\]', $regex) . 'i';
    }
    foreach ($paths as $p) {
        $rel = preg_replace($regex, '', $p, 1);
        if ($rel === $p) {
            throw new \Exception('Path relativize failed, path "' . $p . '" is not within basedir "' . $baseDir . '".');
        } elseif ($rel === '') {
            if (!$allowBaseDirPath) {
                throw new \Exception('Path relativize failed, basedir path "' . $p . '" not allowed.');
            } else {
                $results[$rel] = './';
            }
        } else {
            $results[$rel] = $p;
        }
    }
    return $results;
}

function getDirContentsWithRelKeys(string $dir, int $onlyFiles = 0, string $excludeRegex = '~/\.git/~', int $maxDepth = -1): array {
    return updateKeysWithRelPath(getDirContents($dir, $onlyFiles, $excludeRegex, $maxDepth), $dir);
}

function run(string $dir, $cmd): string
{
    $retCode = null;
    $output = null;
    echo "\n";
    echo "Running: " . $cmd . "\n";
    
    //exec('cd "' . $dir . '" && ' . $cmd, $output, $retCode);
    
    $output = [];
    passthru('cd "' . $dir . '" && ' . $cmd, $retCode);
    
    $output = implode("\n", $output);
    echo $output;
    echo "\n";

    if ($retCode !== 0) {
        throw new \Exception($output, $retCode);
    }

    return $output;
}

$repo = 'ui'; $remoteBranch = 'require_strict_types_cs'; $remoteStartCommit = 'ffeb9e70da86cae04efaf140bc6ed0488c561cdc';
//$repo = 'data'; $remoteBranch = 'require_strict_types_cs'; $remoteStartCommit = '667a5af81dbdd15a53540b30f2ed0720d8aa63ab';
$rootDir = __DIR__ . '/' . $repo;

$phpFiles = getDirContentsWithRelKeys($rootDir, 1, '~/\.git/|(?<!/|\.php)$|/vendor/~is');
//print_r($phpFiles);

/*run($rootDir, 'git checkout -f develop');
run($rootDir, 'git branch -D ' . $remoteBranch);
run($rootDir, 'git checkout -f -t origin/' . $remoteBranch);
run($rootDir, 'git reset --hard "' . $remoteStartCommit . '"');*/
echo 'exit;';exit;
$reached = false;
foreach ($phpFiles as $relFile => $absFile) {
    if (!$reached) {
        if ($relFile !== 'tests/DemoCallExitTest.php') {
            continue;
        }
        $reached = true;
    }
    
    // fix one
    run($rootDir, 'phpw . -d disable_functions=-exec vendor\friendsofphp\php-cs-fixer\php-cs-fixer fix -v "' . $absFile . '"');

    // fix all - always fail
   // run($rootDir, 'phpw . -d disable_functions=-exec vendor\friendsofphp\php-cs-fixer\php-cs-fixer fix -v');

    // retest
    $passed = true;
    try {
        run($rootDir, 'phpw . -d disable_functions= vendor\phpunit\phpunit\phpunit --no-coverage -v --stop-on-failure');
    } catch (\Exception $e) {
        $passed = false;
    }

    // if ok - commit
    if ($passed) {
        run($rootDir, 'git commit --allow-empty -am "Passed: ' . $relFile . '"');
        run($rootDir, 'git push --force');
    } else {
        run($rootDir, 'git reset --hard');
    }
}
