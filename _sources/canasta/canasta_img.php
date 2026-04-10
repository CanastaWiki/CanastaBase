<?php
/**
 * Entry point for serving non-public images to logged-in users in Canasta.
 *
 * This is a thin wrapper that delegates to CanastaAuthenticatedFileEntryPoint,
 * which extends MediaWiki's AuthenticatedFileEntryPoint with wiki farm support.
 *
 * In Canasta, images for each wiki are stored at /mediawiki/images/$wikiID.
 * The entry point injects the wiki ID into the file path so each wiki's
 * images are served from the correct directory.
 *
 * @file
 * @ingroup entrypoint
 * @see CanastaAuthenticatedFileEntryPoint
 */

define( 'MW_NO_OUTPUT_COMPRESSION', 1 );
define( 'MW_ENTRY_POINT', 'canasta_img' );
require __DIR__ . '/includes/WebStart.php';

require_once __DIR__ . '/CanastaAuthenticatedFileEntryPoint.php';

use Canasta\FileRepo\CanastaAuthenticatedFileEntryPoint;
use MediaWiki\Context\RequestContext;
use MediaWiki\EntryPointEnvironment;
use MediaWiki\MediaWikiServices;

( new CanastaAuthenticatedFileEntryPoint(
	RequestContext::getMain(),
	new EntryPointEnvironment(),
	MediaWikiServices::getInstance()
) )->run();
