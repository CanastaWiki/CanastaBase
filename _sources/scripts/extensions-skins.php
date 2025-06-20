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
        if ($data['composer-name']) {
            $packageName = $data['composer-name'];
            $packageVersion = $data['composer-version'] ?? null;
            $packageString = $packageVersion ? "$packageName:$packageVersion" : $packageName;
            exec("composer require $packageString --working-dir=$MW_HOME --no-interaction");
            continue;
        }

        if ($persistentDirectories !== null) {
            exec("mkdir -p $MW_ORIGIN_FILES/canasta-$type/$name");
            foreach ($persistentDirectories as $directory) {
                exec("mv $MW_HOME/canasta-$type/$name/$directory $MW_ORIGIN_FILES/canasta-$type/$name/");
                exec("ln -s $MW_VOLUME/canasta-$type/$name/$directory $MW_HOME/canasta-$type/$name/$directory");
            }
        }
 
        if (!$bundled) {
            $gitCloneCmd = "git clone ";

            if ($repository === null) {
                $repository = "https://github.com/wikimedia/mediawiki-$type-$name";
                if ($branch === null) {
                    $branch = $MW_VERSION;
                    $gitCloneCmd .= "--single-branch -b $branch ";
                }
            }

            $gitCloneCmd .= "$repository $MW_HOME/$type/$name";
            $gitCheckoutCmd = "cd $MW_HOME/$type/$name && git checkout -q $commit";

            exec($gitCloneCmd);
            exec($gitCheckoutCmd);
        }

        if ($patches !== null) {
            foreach ($patches as $patch) {
                $gitApplyCmd = "cd $MW_HOME/$type/$name && git apply /tmp/$patch";
                exec($gitApplyCmd);
            }
        }

        if ($additionalSteps !== null) {
            foreach ($additionalSteps as $step) {
                if ($step === "composer update") {
                    $composerInstallCmd = "composer install --working-dir=$MW_HOME/$type/$name --no-interaction --no-dev";
                    shell_exec("$composerInstallCmd");
                } elseif ($step === "git submodule update") {
                    $submoduleUpdateCmd = "cd $MW_HOME/$type/$name && git submodule update --init";
                    exec($submoduleUpdateCmd);
                }
            }
        }
    }
}

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
