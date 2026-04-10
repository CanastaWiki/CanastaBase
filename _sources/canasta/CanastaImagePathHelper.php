<?php
/**
 * Helper for Canasta wiki farm image path manipulation.
 *
 * Extracted from CanastaAuthenticatedFileEntryPoint so the logic
 * can be unit tested without requiring MediaWiki classes.
 *
 * @file
 * @ingroup entrypoint
 */

namespace Canasta\FileRepo;

class CanastaImagePathHelper {

	/**
	 * Inject the wiki ID into an image path for wiki farm routing.
	 *
	 * Transforms paths like /a/ab/File.png into /wikiID/a/ab/File.png
	 * by replacing the last occurrence of /images or /canasta_img.php
	 * with the wiki-ID-prefixed version.
	 *
	 * @param string $path The original request path
	 * @param string $wikiId The wiki ID to inject
	 * @return string The path with wiki ID injected
	 */
	public static function injectWikiId( string $path, string $wikiId ): string {
		$path = self::strReplaceLast(
			'/images', "/images/$wikiId", $path
		);
		$path = self::strReplaceLast(
			'/canasta_img.php', "/canasta_img.php/$wikiId", $path
		);
		return $path;
	}

	/**
	 * Replace the last occurrence of a substring.
	 *
	 * @param string $search
	 * @param string $replace
	 * @param string $subject
	 * @return string
	 */
	public static function strReplaceLast(
		string $search, string $replace, string $subject
	): string {
		$pos = strrpos( $subject, $search );
		if ( $pos !== false ) {
			$subject = substr_replace(
				$subject, $replace, $pos, strlen( $search )
			);
		}
		return $subject;
	}
}
