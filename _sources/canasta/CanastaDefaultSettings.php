<?php

# Protect against web entry
if ( !defined( 'MEDIAWIKI' ) ) {
	exit;
}

$canastaLocalSettingsFilePath = getenv( 'MW_VOLUME' ) . '/config/LocalSettings.php';
$canastaCommonSettingsFilePath = getenv( 'MW_VOLUME' ) . '/config/CommonSettings.php';

// Check if installation is configured:
// - New installations: MW_SECRET_KEY is set in environment
// - Existing installations: config/LocalSettings.php or config/CommonSettings.php exists
$hasSecretKeyEnv = getenv( 'MW_SECRET_KEY' ) !== false && getenv( 'MW_SECRET_KEY' ) !== '';
$hasLocalSettings = file_exists( $canastaLocalSettingsFilePath );
$hasCommonSettings = file_exists( $canastaCommonSettingsFilePath );
$isConfigured = $hasSecretKeyEnv || $hasLocalSettings || $hasCommonSettings;

if ( defined( 'MW_CONFIG_CALLBACK' ) ) {
	// Called from WebInstaller or similar entry point

	if ( !$isConfigured ) {
		// Remove all variables, WebInstaller should decide that "$IP/LocalSettings.php" does not exist.
		$vars = array_keys( get_defined_vars() );
		foreach ( $vars as $v => $k ) {
			unset( $$k );
		}
		unset( $vars, $v, $k );
		return;
	}
}
// WebStart entry point

// Check that installation is configured
if ( !$isConfigured ) {
	// Emulate that "$IP/LocalSettings.php" does not exist

	// Set CANASTA_CONFIG_FILE for NoLocalSettings template work correctly in includes/CanastaNoLocalSettings.php
	define( "CANASTA_CONFIG_FILE", $canastaLocalSettingsFilePath );

	// Do the same what function wfWebStartNoLocalSettings() does
	require_once "$IP/includes/CanastaNoLocalSettings.php";
	die();
}

// Canasta default settings below

$wgServer = getenv( 'MW_SITE_SERVER' ) ?? 'http://localhost';

## The URL base path to the directory containing the wiki;
## defaults for all runtime URL paths are based off of this.
## For more information on customizing the URLs
## (like /w/index.php/Page_title to /wiki/Page_title) please see:
## https://www.mediawiki.org/wiki/Manual:Short_URL
$wgScriptPath = "/w";
$wgScriptExtension = ".php";
$wgArticlePath = '/wiki/$1';
$wgStylePath = $wgScriptPath . '/skins';

## The URL path to static resources (images, scripts, etc.)
$wgResourceBasePath = $wgScriptPath;

# SyntaxHighlight_GeSHi
$wgPygmentizePath = '/usr/bin/pygmentize';

# SVG Converters
$wgSVGConverter = 'rsvg';

# Binary paths
$wgDiff3 = "/usr/bin/diff3";
$wgImageMagickConvertCommand = "/usr/bin/convert";
$wgUseImageMagick = true;

# File uploads enabled by default (Canasta is configured for uploads)
$wgEnableUploads = true;

# Disable pingback
$wgPingback = false;

# Use local files instead of Wikimedia Commons
$wgUseInstantCommons = false;

# Database connection (from environment, can be overridden by config/LocalSettings.php)
$wgDBtype = "mysql";
$wgDBserver = "db";
$wgDBuser = "root";
$wgDBpassword = getenv( 'MYSQL_PASSWORD' ) ?: 'mediawiki';

# Secret key (from environment, can be overridden by config/LocalSettings.php)
$secretKey = getenv( 'MW_SECRET_KEY' );
if ( $secretKey ) {
	$wgSecretKey = $secretKey;
}
$wgAuthenticationTokenVersion = "1";

# MySQL defaults
$wgDBprefix = "";
$wgDBssl = false;
$wgDBTableOptions = "ENGINE=InnoDB, DEFAULT CHARSET=binary";

# Semantic MediaWiki: store smw.json on the persistent volume
$smwgConfigFileDir = getenv( 'MW_VOLUME' ) . '/config/smw';

# Cache (Canasta uses APCu)
$wgMainCacheType = CACHE_ACCEL;
$wgMemCachedServers = [];

# Docker specific setup
# Exclude all private IP ranges
# see https://www.mediawiki.org/wiki/Manual:$wgCdnServersNoPurge
$wgUseCdn = true;
$wgCdnServersNoPurge = [];
$wgCdnServersNoPurge[] = '10.0.0.0/8';     // 10.0.0.0 – 10.255.255.255
$wgCdnServersNoPurge[] = '172.16.0.0/12';  // 172.16.0.0 – 172.31.255.255
$wgCdnServersNoPurge[] = '192.168.0.0/16'; // 192.168.0.0 – 192.168.255.255

# Configure Varnish cache purging
$wgCdnServers = [ 'varnish:80' ];
$wgInternalServer = preg_replace( '/^https:/', 'http:', $wgServer );

/**
 * Returns boolean value from environment variable
 * Must return the same result as isTrue function in run-apache.sh file
 * @param $value
 * @return bool
 */
function isEnvTrue( $name ): bool {
	$value = getenv( $name );
	switch ( $value ) {
		case "True":
		case "TRUE":
		case "true":
		case "1":
			return true;
	}
	return false;
}

$DOCKER_MW_VOLUME = getenv( 'MW_VOLUME' );

## Set $wgCacheDirectory to a writable directory on the web server
## to make your wiki go slightly faster. The directory should not
## be publicly accessible from the web.
$wgCacheDirectory = isEnvTrue( 'MW_USE_CACHE_DIRECTORY' ) ? "$DOCKER_MW_VOLUME/l10n_cache" : false;

# Include user defined CommonSettings.php file
if ( file_exists( $canastaCommonSettingsFilePath ) ) {
	require_once "$canastaCommonSettingsFilePath";
}

# Include user defined LocalSettings.php file
if ( file_exists( $canastaLocalSettingsFilePath ) ) {
	require_once "$canastaLocalSettingsFilePath";
}

# Load global settings files
# Check new path first (settings/global/), fall back to legacy path (settings/)
$globalSettingsDir = getenv( 'MW_VOLUME' ) . '/config/settings/global';
if ( !is_dir( $globalSettingsDir ) ) {
	$globalSettingsDir = getenv( 'MW_VOLUME' ) . '/config/settings';
}

$filenames = glob( $globalSettingsDir . '/*.php' );

if ( $filenames !== false && is_array( $filenames ) ) {
	sort( $filenames );

	foreach ( $filenames as $filename ) {
		require_once "$filename";
	}
}

# Include the FarmConfig
if ( file_exists( getenv( 'MW_VOLUME' ) . '/config/wikis.yaml' ) ) {
	require_once "$IP/FarmConfigLoader.php";
}

/**
 * Show a warning to users if $wgSMTP is not set.
 */
$wgHooks['SiteNoticeAfter'][] = function ( &$siteNotice, Skin $skin ) {
	global $wgSMTP, $wgEnableEmail, $wgEnableUserEmail;

	if ( !$wgEnableEmail || $wgSMTP ) {
		return;
	}
	$title = $skin->getTitle();
	if ( !$title->isSpecialPage() ) {
		return;
	}
	$specialPage = MediaWiki\MediaWikiServices::getInstance()
		->getSpecialPageFactory()
		->getPage( $title->getText() );
	if ( $specialPage == null ) {
		return;
	}
	$canonicalName = $specialPage->getName();
	// Only display this warning for pages that could result in an email getting sent.
	$specialPagesWithEmail = [ 'Preferences', 'CreateAccount' ];
	if ( $wgEnableUserEmail ) {
		$specialPagesWithEmail[] = 'Emailuser';
	}
	if ( !in_array( $canonicalName, $specialPagesWithEmail ) ) {
		return;
	}

	$warningText = 'Please note that mailing does not currently work on this wiki, because Canasta requires <a href="https://www.mediawiki.org/wiki/Manual:$wgSMTP">$wgSMTP</a> to be set in order to send emails.';
	$siteNotice .= Html::warningBox( '<span style="font-size: larger;">' . $warningText . '</span>' );
};
