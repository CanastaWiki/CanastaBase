<?php

/**
 * It is much easier to do parsing of YAML in PHP than in .sh; the standard way
 * to do YAML parsing in a shell script is to call yq, but yq requires
 * different executables for different architectures.
 *
 * Given that the YAML parsing is already in PHP, we do all the rest of the
 * installation in PHP too: Git download, Composer updates, applying patches,
 * etc. - it seems easier to do everything in one spot.
 */

$MW_HOME = getenv("MW_HOME");
$MW_VERSION = getenv("MW_VERSION");
$MW_VOLUME = getenv("MW_VOLUME");
$MW_ORIGIN_FILES = getenv("MW_ORIGIN_FILES");
$path = $argv[1];

$contentsData = [
    'extensions' => [],
    'skins' => []
];

// Track extensions/skins that need composer dependencies merged
$composerIncludes = [];

populateContentsData($path, $contentsData);

foreach (['extensions', 'skins'] as $type) {
    foreach ($contentsData[$type] as $name => $data) {
        // 'remove: true' allows for child files to remove an extension or
        // skin specified by any of their parent files.
        $remove = $data['remove'] ?? false;
        if ($remove) {
            continue;
        }

        $repository = $data['repository'] ?? null;
        $commit = $data['commit'] ?? null;
        $branch = $data['branch'] ?? null;
        $patches = $data['patches'] ?? null;
        $persistentDirectories = $data['persistent directories'] ?? null;
        $additionalSteps = $data['additional steps'] ?? null;
        $bundled = $data['bundled'] ?? false;
        $requiredExtensions = $data['required extensions'] ?? null;

        // Installation of extensions using their composer package (for SMW, etc.,)
        if ($data['composer-name'] ?? null) {
            $packageName = $data['composer-name'];
            $packageVersion = $data['composer-version'] ?? null;
            $packageString = $packageVersion ? "$packageName:$packageVersion" : $packageName;
            exec("composer require $packageString --working-dir=$MW_HOME --no-interaction");
        }

        if (!$bundled && !($data['composer-name'] ?? null)) {
            $gitCloneCmd = "git clone ";

            if ($repository === null) {
                $repository = "https://github.com/wikimedia/mediawiki-$type-$name";
                if ($branch === null) {
                    $branch = $MW_VERSION;
                    $gitCloneCmd .= "--single-branch -b $branch ";
                }
            }

            $gitCloneCmd .= "$repository $MW_HOME/canasta-$type/$name";
            $gitCheckoutCmd = "cd $MW_HOME/canasta-$type/$name && git checkout -q $commit";

            exec($gitCloneCmd);
            exec($gitCheckoutCmd);
        }

        if ($patches !== null) {
            foreach ($patches as $patch) {
                $gitApplyCmd = "cd $MW_HOME/canasta-$type/$name && git apply /tmp/$patch";
                exec($gitApplyCmd);
            }
        }

        if ($additionalSteps !== null) {
            foreach ($additionalSteps as $step) {
                if ($step === "composer update") {
                    // Dependencies will be resolved by the unified root-level composer update below.
                    $composerIncludes[] = "$type/$name/composer.json";
                } elseif ($step === "git submodule update") {
                    $submoduleUpdateCmd = "cd $MW_HOME/canasta-$type/$name && git submodule update --init";
                    exec($submoduleUpdateCmd);
                }
            }
        }

        // Generate gitinfo.json before removing .git, so Special:Version can show commit info
        $extPath = "$MW_HOME/canasta-$type/$name";
        $hash = trim(shell_exec("cd $extPath && git rev-parse HEAD 2>/dev/null") ?? '');
        if ($hash) {
            $date = trim(shell_exec("cd $extPath && git log -1 --format=%ct HEAD 2>/dev/null") ?? '');
            $branch = trim(shell_exec("cd $extPath && git rev-parse --abbrev-ref HEAD 2>/dev/null") ?? '');
            $remote = trim(shell_exec("cd $extPath && git config --get remote.origin.url 2>/dev/null") ?? '');
            $gitinfo = [
                'head' => $hash,
                'headSHA1' => $hash,
                'headCommitDate' => $date,
                'branch' => $branch,
                'remoteURL' => $remote
            ];
            file_put_contents("$extPath/gitinfo.json", json_encode($gitinfo));
        }

        // Remove .git directory after all git operations are complete, to reduce the size of the Docker image
        exec("rm -rf $MW_HOME/canasta-$type/$name/.git");

        if ($persistentDirectories !== null) {
            exec("mkdir -p $MW_ORIGIN_FILES/$type/$name");
            foreach ($persistentDirectories as $directory) {
                exec("mv $MW_HOME/canasta-$type/$name/$directory $MW_ORIGIN_FILES/$type/$name/");
                exec("ln -s $MW_VOLUME/$type/$name/$directory $MW_HOME/canasta-$type/$name/$directory");
            }
        }
    }
}

// Create build-time symlinks in extensions/ and skins/ pointing to
// canasta-extensions/ and canasta-skins/. This mirrors what create-symlinks.sh
// does at runtime, but without user-extensions/user-skins (which don't exist
// at build time). The symlinks let composer.local.json reference extensions by
// their canonical extensions/Name/ path at both build time and runtime.
echo "Creating build-time symlinks for canasta extensions and skins...\n";
foreach (['extensions' => 'canasta-extensions', 'skins' => 'canasta-skins'] as $target => $source) {
    foreach (glob("$MW_HOME/$source/*", GLOB_ONLYDIR) as $dir) {
        $name = basename($dir);
        $link = "$MW_HOME/$target/$name";
        if (!file_exists($link)) {
            symlink("../$source/$name", $link);
        }
    }
}

// Create composer.local.json with specific entries for bundled extensions/skins
// that have composer dependencies. We use specific entries rather than wildcards
// to avoid broken composer.json files in extensions that don't need composer.
// Users who add extensions with composer dependencies should manually add
// entries to config/composer.local.json.
$allIncludes = $composerIncludes;
$composerLocal = [
    'extra' => [
        'merge-plugin' => [
            'include' => $allIncludes
        ]
    ]
];
$composerLocalJson = json_encode($composerLocal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
file_put_contents("$MW_HOME/composer.local.json", $composerLocalJson);

// Also save to $MW_ORIGIN_FILES/config/ so rsync populates user's config/ on
// first run.
exec("mkdir -p $MW_ORIGIN_FILES/config");
file_put_contents("$MW_ORIGIN_FILES/config/composer.local.json", $composerLocalJson);

// Run unified composer update at the MediaWiki root
echo "Running unified composer update...\n";
$composerUpdateCmd = "composer update --working-dir=$MW_HOME --no-dev --no-interaction";
passthru($composerUpdateCmd, $composerReturnCode);
if ($composerReturnCode !== 0) {
    echo "WARNING: composer update exited with code $composerReturnCode\n";
}

// Save a hash of composer.local.json + all referenced extension/skin
// composer.json files so run-all.sh can detect changes at runtime.
$hashFiles = ["$MW_HOME/composer.local.json"];
foreach ($allIncludes as $pattern) {
    foreach (glob("$MW_HOME/$pattern") as $f) {
        $hashFiles[] = $f;
    }
}
sort($hashFiles);
$combinedHash = '';
foreach ($hashFiles as $f) {
    $combinedHash .= md5_file($f);
}
exec("mkdir -p $MW_ORIGIN_FILES/config/persistent");
file_put_contents("$MW_ORIGIN_FILES/config/persistent/.composer-deps-hash", md5($combinedHash) . "\n");

/**
 * Recursive function to allow for loading a whole chain of YAML files (if
 * necessary), with each one defining its parent file via the "inherits"
 * field.
 *
 * This function populates the array $contentsData, with child YAML files
 * given the opportunity to overwrite what their parent file(s) specified
 * for any given extension or skin.
 */
function populateContentsData($pathOrURL, &$contentsData) {
    $yamlText = file_get_contents($pathOrURL);
    // If it's stored in, or came from, a MediaWiki wiki (such as
    // mediawiki.org), it may have a <syntaxhighlight> tag around it.
    if ( preg_match( '/<syntaxhighlight\s+lang=["\']yaml["\']>(.*?)<\/syntaxhighlight>/si', $yamlText, $matches ) ) {
        $yamlText = $matches[1];
    }
    $dataFromFile = yaml_parse($yamlText);

    if (array_key_exists('inherits', $dataFromFile)) {
        populateContentsData($dataFromFile['inherits'], $contentsData);
    }

    if (array_key_exists('extensions', $dataFromFile)) {
        foreach ($dataFromFile['extensions'] as $obj) {
            $extensionName = key($obj);
            $extensionData = $obj[$extensionName];
            $contentsData['extensions'][$extensionName] = $extensionData;
        }
    }

    if (array_key_exists('skins', $dataFromFile)) {
        foreach ($dataFromFile['skins'] as $obj) {
            $skinName = key($obj);
            $skinData = $obj[$skinName];
            $contentsData['skins'][$skinName] = $skinData;
        }
    }
}

?>
