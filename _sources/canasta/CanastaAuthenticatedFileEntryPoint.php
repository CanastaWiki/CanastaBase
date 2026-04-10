<?php
/**
 * Canasta extension of MediaWiki's AuthenticatedFileEntryPoint.
 *
 * Adds wiki farm support by injecting the wiki ID into the file path,
 * so images are served from /mediawiki/images/$wikiID/ instead of
 * /mediawiki/images/. All other behavior is inherited from the
 * upstream implementation.
 *
 * @file
 * @ingroup entrypoint
 */

namespace Canasta\FileRepo;

use File;
use MediaWiki\HookContainer\HookRunner;
use MediaWiki\Html\TemplateParser;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiEntryPoint;
use MediaWiki\Title\Title;
use Wikimedia\FileBackend\HTTPFileStreamer;

class CanastaAuthenticatedFileEntryPoint extends MediaWikiEntryPoint {

	/**
	 * Main entry point.
	 *
	 * This is a copy of AuthenticatedFileEntryPoint::execute() with
	 * wiki farm path injection added after path resolution. We extend
	 * MediaWikiEntryPoint directly (not AuthenticatedFileEntryPoint)
	 * because the parent's execute() and forbidden() are not designed
	 * for extension (forbidden is private, execute has no hook points).
	 */
	public function execute() {
		global $wikiID;

		$services = $this->getServiceContainer();
		$permissionManager = $services->getPermissionManager();

		$request = $this->getRequest();
		$publicWiki = $services->getGroupPermissionsLookup()
			->groupHasPermission( '*', 'read' );

		// Find the path assuming the request URL is relative to the
		// local public zone URL
		$baseUrl = $services->getRepoGroup()->getLocalRepo()
			->getZoneUrl( 'public' );
		if ( $baseUrl[0] === '/' ) {
			$basePath = $baseUrl;
		} else {
			$basePath = parse_url( $baseUrl, PHP_URL_PATH );
		}
		$path = $this->getRequestPathSuffix( "$basePath" );

		if ( $path === false ) {
			// Try instead assuming canasta_img.php is the base path
			$basePath = $this->getConfig( MainConfigNames::ImgAuthPath )
				?: $this->getConfig( MainConfigNames::ScriptPath )
				   . '/canasta_img.php';
			$path = $this->getRequestPathSuffix( $basePath );
		}

		if ( $path === false ) {
			$this->forbidden( 'img-auth-accessdenied', 'img-auth-notindir' );
			return;
		}

		if ( $path === '' || $path[0] !== '/' ) {
			$path = "/" . $path;
		}

		// --- Canasta wiki farm: inject wiki ID into path ---
		if ( isset( $wikiID ) && $wikiID !== '' ) {
			$path = self::strReplaceLast(
				"/images", "/images/$wikiID", $path
			);
			$path = self::strReplaceLast(
				"/canasta_img.php", "/canasta_img.php/$wikiID", $path
			);
		} else {
			wfDebugLog( 'canasta_img',
				'Warning: wikiID is not set or empty' );
		}
		// --- End Canasta wiki farm ---

		$user = $this->getContext()->getUser();

		// Various extensions may have their own backends that need
		// access. Check if there is a special backend and storage base
		// path for this file.
		$pathMap = $this->getConfig( MainConfigNames::ImgAuthUrlPathMap );
		foreach ( $pathMap as $prefix => $storageDir ) {
			$prefix = rtrim( $prefix, '/' ) . '/';
			if ( strpos( $path, $prefix ) === 0 ) {
				$be = $services->getFileBackendGroup()
					->backendFromPath( $storageDir );
				$filename = $storageDir
					. substr( $path, strlen( $prefix ) );
				$isAllowedUser = $permissionManager
					->userHasRight( $user, 'read' );
				if ( !$isAllowedUser ) {
					$this->forbidden(
						'img-auth-accessdenied',
						'img-auth-noread', $path
					);
					return;
				}
				if ( $be && $be->fileExists( [ 'src' => $filename ] ) ) {
					wfDebugLog( 'canasta_img',
						"Streaming `$filename`." );
					$be->streamFile( [
						'src' => $filename,
						'headers' => [
							'Cache-Control: private',
							'Vary: Cookie',
						],
					] );
				} else {
					$this->forbidden(
						'img-auth-accessdenied',
						'img-auth-nofile', $path
					);
				}
				return;
			}
		}

		// Get the local file repository
		$repo = $services->getRepoGroup()->getLocalRepo();
		$zone = strstr( ltrim( $path, '/' ), '/', true );

		if ( $zone === 'thumb' || $zone === 'transcoded' ) {
			$name = wfBaseName( dirname( $path ) );
			$filename = $repo->getZonePath( $zone )
				. substr( $path, strlen( "/" . $zone ) );
			if ( !$repo->fileExists( $filename ) ) {
				$this->forbidden(
					'img-auth-accessdenied',
					'img-auth-nofile', $filename
				);
				return;
			}
		} else {
			$name = wfBaseName( $path );
			$filename = $repo->getZonePath( 'public' ) . $path;
			$bits = explode( '!', $name, 2 );
			if ( str_starts_with( $path, '/archive/' )
				&& count( $bits ) == 2
			) {
				$file = $repo->newFromArchiveName( $bits[1], $name );
			} else {
				$file = $repo->newFile( $name );
			}
			if ( !$file || !$file->exists()
				|| $file->isDeleted( File::DELETED_FILE )
			) {
				$this->forbidden(
					'img-auth-accessdenied',
					'img-auth-nofile', $filename
				);
				return;
			}
		}

		$headers = [];
		$title = Title::makeTitleSafe( NS_FILE, $name );

		$hookRunner = new HookRunner( $services->getHookContainer() );
		if ( !$publicWiki ) {
			$headers['Cache-Control'] = 'private';
			$headers['Vary'] = 'Cookie';

			if ( !$title instanceof Title ) {
				$this->forbidden(
					'img-auth-accessdenied',
					'img-auth-badtitle', $name
				);
				return;
			}

			$authResult = [];
			if ( !$hookRunner->onImgAuthBeforeStream(
				$title, $path, $name, $authResult
			) ) {
				$this->forbidden(
					$authResult[0], $authResult[1],
					array_slice( $authResult, 2 )
				);
				return;
			}

			if ( !$permissionManager->userCan(
				'read', $user, $title
			) ) {
				$this->forbidden(
					'img-auth-accessdenied',
					'img-auth-noread', $name
				);
				return;
			}
		}

		$range = $this->environment->getServerInfo( 'HTTP_RANGE' );
		$ims = $this->environment->getServerInfo(
			'HTTP_IF_MODIFIED_SINCE'
		);

		if ( $range !== null ) {
			$headers['Range'] = $range;
		}
		if ( $ims !== null ) {
			$headers['If-Modified-Since'] = $ims;
		}

		if ( $request->getCheck( 'download' ) ) {
			$headers['Content-Disposition'] = 'attachment';
		}

		$hookRunner->onImgAuthModifyHeaders(
			$title->getTitleValue(), $headers
		);

		// Stream the requested file
		$this->prepareForOutput();

		[ $headers, $options ] = HTTPFileStreamer::preprocessHeaders(
			$headers
		);
		wfDebugLog( 'canasta_img', "Streaming `$filename`." );
		$repo->streamFileWithStatus( $filename, $headers, $options );

		$this->enterPostSendMode();
	}

	/**
	 * Issue a standard HTTP 403 Forbidden header and error message.
	 *
	 * Reimplemented here because the parent class's forbidden() is
	 * private and cannot be inherited.
	 *
	 * @param string $msg1 Message key for header
	 * @param string $msg2 Message key for detail
	 * @param mixed ...$args Parameters for $msg2
	 */
	private function forbidden( $msg1, $msg2, ...$args ) {
		$args = ( isset( $args[0] ) && is_array( $args[0] ) )
			? $args[0] : $args;
		$context = $this->getContext();

		$msgHdr = $context->msg( $msg1 )->text();
		$detailMsg = $this->getConfig( MainConfigNames::ImgAuthDetails )
			? $context->msg( $msg2, $args )->text()
			: $context->msg( 'badaccess-group0' )->text();

		wfDebugLog(
			'canasta_img',
			"wfForbidden Hdr: "
				. $context->msg( $msg1 )->inLanguage( 'en' )->text()
				. " Msg: "
				. $context->msg( $msg2, $args )->inLanguage( 'en' )->text()
		);

		$this->status( 403 );
		$this->header( 'Cache-Control: no-cache' );
		$this->header( 'Content-Type: text/html; charset=utf-8' );
		$language = $context->getLanguage();
		$lang = $language->getHtmlCode();
		$this->header( "Content-Language: $lang" );
		$templateParser = new TemplateParser();
		$this->print(
			$templateParser->processTemplate( 'ImageAuthForbidden', [
				'dir' => $language->getDir(),
				'lang' => $lang,
				'msgHdr' => $msgHdr,
				'detailMsg' => $detailMsg,
			] )
		);
	}

	/**
	 * Replace the last occurrence of a substring.
	 *
	 * @param string $search
	 * @param string $replace
	 * @param string $subject
	 * @return string
	 */
	private static function strReplaceLast( $search, $replace, $subject ) {
		$pos = strrpos( $subject, $search );
		if ( $pos !== false ) {
			$subject = substr_replace(
				$subject, $replace, $pos, strlen( $search )
			);
		}
		return $subject;
	}
}
