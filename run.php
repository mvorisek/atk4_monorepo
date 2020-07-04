<?php

// prepare "releases" branch:
// git commit --date="2000-1-1 00:00:00" -m "Prepare for releases - 2000-1-1" --allow-empty

date_default_timezone_set('UTC');

function run(string $dir, $cmd, bool $passthru = false): string
{
    $retCode = null;
    $output = null;
    echo "\n";
    echo '# ' . str_repeat('-', 78) . "\n";
    echo "# dir: " . $dir . "\n";
    echo "# cmd: " . $cmd . "\n";

    if (!$passthru) {
        exec('cd "' . $dir . '" && ' . $cmd, $output, $retCode);
    } else {
        $output = [];
        passthru('cd "' . $dir . '" && ' . $cmd, $retCode);
    }

    $output = implode("\n", $output);
    echo $output;
    echo "\n";

    if ($retCode !== 0) {
        throw new \Exception($output, $retCode);
    }

    return $output;
}

class Repo {
    public $alias;
    public $url;
    public $dir;
    public $branch = 'develop';
    /** @var Commit[] */
    public $commits = [];

    public function __construct(string $alias, string $url) {
        $this->alias = $alias;
        $this->url = $url;
        $this->dir = __DIR__ . '/source_repos/' . $alias;
    }
}

$deleteDirFunc = function ($dirPath) use(&$deleteDirFunc) {
    if (!is_dir($dirPath)) {
        return;
    }

    foreach (array_diff(scandir($dirPath), ['.', '..']) as $f) {
        $f = $dirPath . '/' . $f;
        if (is_dir($f)) {
            $deleteDirFunc($f);
        } else {
            unlink($f);
        }
    }

    rmdir($dirPath);
    
    if (is_dir($dirPath)) {
        rename($dirPath, $dirPath . '.permissue-' . md5(microtime(true)));
    }
};

// <config>
$relRepo = new Repo('../rel_repo', 'git@github.com:mvorisek/atk4_monorepo.git');
$relRepo->branch = 'releases';

$repos = [
    new Repo('atk4/core', 'https://github.com/atk4/core.git'),
    new Repo('atk4/dsql', 'https://github.com/atk4/dsql.git'),
    new Repo('atk4/data', 'https://github.com/atk4/data.git'),
    new Repo('atk4/schema', 'https://github.com/atk4/schema.git'), // needed as data-dev depends on it
    new Repo('atk4/ui', 'https://github.com/atk4/ui.git'),
];

$minDt = (new \DateTime('2020-1-1'))->setTimeZone(new \DateTimeZone('UTC'));

$cacheDir = __DIR__ . '/cache';
@mkdir($cacheDir);
// </config>

// clone repos and reset HEADs
foreach (array_merge([$relRepo], $repos) as $repo) {
    if (!is_dir($repo->dir)) {
        run(__DIR__, 'git clone "' . $repo->url . '" "' . $repo->dir . '"');
    }
/**/    run($repo->dir, 'git fetch origin "' . $repo->branch . '"');
/**/    run($repo->dir, 'git checkout -f -B "' . $repo->branch . '" --track "origin/' . $repo->branch . '"');
}

// get commits
class Commit
{
    /** @var Repo */
    public $repo;
    public $commitHash;
    /** @var \DateTime */
    public $dt;

    public function __construct(Repo $repo, string $commitHash, \DateTime $dt) {
        $this->repo = $repo;
        $this->commitHash = $commitHash;
        $this->dt = $dt;
    }

    public function __debugInfo() {
        $res = get_object_vars($this);
        $res['repo'] = $this->repo->alias;
        return $res;
    }
}

foreach ($repos as $repo) {
    $res = run($repo->dir, 'git log "' . $repo->branch . '" --no-merges --pretty="%H %cD"');
    foreach (explode("\n", $res) as $l) {
        [$h, $d] = explode(' ', $l, 2);
        $commit = new Commit($repo, $h, (new \DateTime($d))->setTimeZone(new \DateTimeZone('UTC')));
        $repo->commits[] = $commit;
    }

    // sort by date
    usort($repo->commits, function(Commit $cA, Commit $cB) {
        return $cA->dt <=> $cB->dt;
    });
}

// run tests and release if passing
$lastTestedDt = (new \DateTime(run($relRepo->dir, 'git log releases -1 --no-merges --pretty="%aD"')))->setTimeZone(new \DateTimeZone('UTC'));

$uniqueDts = [];
foreach ($repos as $repo) {
    foreach ($repo->commits as $c) {
        $uniqueDts[$c->dt->getTimestamp()] = $c->dt;
    }
}
ksort($uniqueDts);
$startedCounter = 0;
foreach ($uniqueDts as $dt) {
    if ($dt <= $lastTestedDt || $dt < $minDt) {
        continue;
    }

    echo str_repeat("\n", 10) . ++$startedCounter . ' iter, iter dt: ' . $dt->format('Y-m-d H:i:s') . ', now dt: ' . date('Y-m-d H:i:s') . "\n";

    // find latest commits
    $commits = [];
    foreach ($repos as $repo) {
        foreach ($repo->commits as $c) {
            if ($c->dt <= $dt || !isset($commits[$repo->alias])) {
                $commits[$repo->alias] = $c;
            }
        }
    }

    print_r($commits);

    // build composer.json
    $composerJson = [
        'name' => 'atk4/monorepo',
        'description' => 'Monorepo of major Atk4 repos',
        'type' => 'library',
        'homepage' => 'https://www.mvorisek.com/',
        'license' => 'proprietary',
        'authors' => [
            ['name' => 'Michael Voříšek', 'email' => 'mvorisek@mvorisek.cz']
        ],
        'config' => ['sort-packages' => true],
        'require' => array_merge([
            'php' => '^7.3',
       ], (function() use ($commits) {
            $res = [];
            foreach ($commits as $c) {
                $res[$c->repo->alias] = 'dev-' . $c->repo->branch . '#' . $c->commitHash;
            }

            return $res;
        })()),
    ];
    $saveComposerJsonFunc = function() use($relRepo, &$composerJson) {
        file_put_contents($relRepo->dir . '/composer.json', json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    };
    $composerInstallFunc = function() use($relRepo, &$composerJson, $saveComposerJsonFunc, $cacheDir) {
        @unlink($relRepo->dir . '/composer.lock');
        $composerJson['config']['discard-changes'] = true;
        @mkdir($cacheDir . '/d');
        @mkdir($cacheDir . '/c');
        $composerJson['config']['data-dir'] = $cacheDir . '/d';
        $composerJson['config']['cache-dir'] = $cacheDir . '/c';
        $saveComposerJsonFunc();
        run($relRepo->dir, 'composer install --no-interaction --no-ansi --prefer-source --no-suggest', true);
        unset($composerJson['config']['discard-changes']);
        unset($composerJson['config']['data-dir']);
        unset($composerJson['config']['cache-dir']);
        $saveComposerJsonFunc();
    };
    $composerJson['config']['vendor-dir'] = 'vendor2';
    $composerInstallFunc();
    unset($composerJson['config']['vendor-dir']);
    foreach ($repos as $repo) {
        $conf = json_decode(file_get_contents($relRepo->dir . '/vendor2/' . $repo->alias . '/composer.json'), true);

        foreach ($conf['require-dev'] ?? [] as $k => $v) {
            $composerJson['require-dev'][$k] = (isset($composerJson['require-dev'][$k]) ? $composerJson['require-dev'][$k] . ', ' : '') . $v;
        }

        foreach ($conf['autoload-dev']['psr-4'] ?? [] as $k => $vArr) {
            if (isset($composerJson['autoload-dev']['psr-4'][$k])) { // can be removed if needed, we support array below
                throw new Exception('Duplicate PSR-4 autoload in "' . $repo->alias . '" - "' .  $k . '"');
            }

            foreach ((array) $vArr as $v) {
                $composerJson['autoload-dev']['psr-4'][$k][] = 'vendor' . '/' . $repo->alias . '/' . $v;
            }
        }
    }

    $composerInstallFunc();

    // run tests
    $failedRepos = [];
    foreach ($commits as $c) {
        $c->passed = false;
        try {
            $repoDir = $relRepo->dir . '/vendor/' . $c->repo->alias;
            
            // create cache dir
            $cacheDirPhpunit = $relRepo->dir . '/vendor/phpunit.cache.local';
            @mkdir($cacheDirPhpunit);

            if ($c->repo->alias === 'atk4/ui') {
                run($repoDir . '/demos/_demo-data', 'phpw . create-sqlite-db.php');
            }

            // run
            run(
                $repoDir,
                'phpw ../..'
                    . ' -d sys_temp_dir="' . realpath($cacheDirPhpunit) . '"'
                    . ' -d session.save_path="' . realpath($cacheDirPhpunit) . '"'
                    . ' ../../phpunit/phpunit/phpunit --bootstrap ../../autoload.php --no-coverage -v'
                    . ' --filter "^(?!atk4\\\\core\\\\tests\\\\SessionTraitTest::)"', // @TODO probably remove or move at least to ui, but for unknown reasons, not needed on notebook
                true
            );
            $deleteDirFunc($cacheDirPhpunit);
            $c->passed = true;
        } catch (\Exception $e) {
            $failedRepos[] = $c->repo->alias;
        }
    }

//    exit; // debug

    // commit
    $relText = 'Build ' . $dt->format('Y-m-d H:i:s') . ': ' . (count($failedRepos) === 0 ? 'PASSED' : 'FAILED - ' . implode(', ', $failedRepos));
    $relDesc = 'Atk4 Monorepo Status: ' . "\n";
    foreach ($commits as $c) {
        $relDesc .= $c->repo->alias . ': ' . substr($c->commitHash, 0, 16) . ' (' . $c->dt->format('Y-m-d H:i:s') . '): ' . ($c->passed ? 'passed' : 'FAILED') . "\n";
    }
    run($relRepo->dir, 'git add composer.json composer.lock');
    run($relRepo->dir, 'git commit --date="' . $dt->format('r') . '" --allow-empty -am "' . $relText . '"' . implode('', array_map(function($l) {
        return ' -m "' . $l . '"';
    }, explode("\n", $relDesc))));
    run($relRepo->dir, 'git push --force');

    // set tag if tests passed
    if (count($failedRepos) === 0) {
        $relTag = 'v0.' . $dt->format('ymd.His');
        run($relRepo->dir, 'git tag "' . $relTag . '"');
        run($relRepo->dir, 'git push origin "' . $relTag . '"');
    }
}
