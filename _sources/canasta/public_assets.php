<?php
/**
 * Public assets server for Canasta wiki farms.
 *
 * Serves files from /mediawiki/public_assets/{wiki_id}/ without authentication.
 * Use for logos and other assets that should be publicly accessible on both
 * public and private wikis.
 *
 * Unlike canasta_img.php, this script does not require MediaWiki bootstrap,
 * making it faster for serving static assets.
 *
 * @file
 * @ingroup entrypoint
 */

$configPath = '/mediawiki/config/wikis.yaml';

// Parse request path
$requestUri = $_SERVER['REQUEST_URI'] ?? '';

// Handle subdir wikis: /wiki1/public_assets/... -> extract wiki1
// For domain-based wikis: /public_assets/...
$pathParts = explode( '/public_assets', $requestUri, 2 );
$subdir = trim( $pathParts[0], '/' );
$filePath = $pathParts[1] ?? '';

// Remove query string from file path if present
if ( ( $queryPos = strpos( $filePath, '?' ) ) !== false ) {
    $filePath = substr( $filePath, 0, $queryPos );
}

// Determine wiki ID from wikis.yaml
$wikiId = null;
if ( file_exists( $configPath ) ) {
    $config = yaml_parse_file( $configPath );
    if ( isset( $config['wikis'] ) ) {
        $serverName = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // Remove port from server name for matching
        $serverNameNoPort = preg_replace( '/:.*$/', '', $serverName );

        foreach ( $config['wikis'] as $wiki ) {
            $wikiUrl = $wiki['url'] ?? '';
            // Remove port from wiki URL for matching
            $wikiUrlNoPort = preg_replace( '/:.*$/', '', $wikiUrl );

            if ( !empty( $subdir ) ) {
                // Subdir wiki: match domain/path
                if ( $wikiUrl === "$serverName/$subdir" ||
                     $wikiUrl === "$serverNameNoPort/$subdir" ||
                     $wikiUrlNoPort === "$serverNameNoPort/$subdir" ) {
                    $wikiId = $wiki['id'];
                    break;
                }
            } else {
                // Domain-based wiki: match domain (with or without port)
                if ( $wikiUrl === $serverName ||
                     $wikiUrl === $serverNameNoPort ||
                     $wikiUrlNoPort === $serverName ||
                     $wikiUrlNoPort === $serverNameNoPort ) {
                    $wikiId = $wiki['id'];
                    break;
                }
            }
        }
    }
}

if ( !$wikiId ) {
    http_response_code( 404 );
    echo "Wiki not found";
    exit;
}

// Build full file path
$assetsBaseDir = "/mediawiki/public_assets/$wikiId";
$fullPath = $assetsBaseDir . $filePath;

// Security: prevent directory traversal
$realPath = realpath( $fullPath );
$assetsDir = realpath( $assetsBaseDir );

if ( $realPath === false || $assetsDir === false || strpos( $realPath, $assetsDir ) !== 0 ) {
    http_response_code( 404 );
    echo "File not found";
    exit;
}

if ( !is_file( $realPath ) ) {
    http_response_code( 404 );
    echo "File not found";
    exit;
}

// Determine content type
$mimeTypes = [
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
    'webp' => 'image/webp',
    'css' => 'text/css',
    'js' => 'application/javascript',
    'json' => 'application/json',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf' => 'font/ttf',
    'otf' => 'font/otf',
    'eot' => 'application/vnd.ms-fontobject',
    'xml' => 'application/xml',
    'gz' => 'application/gzip',
];
$ext = strtolower( pathinfo( $realPath, PATHINFO_EXTENSION ) );
$contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

// Serve file with public caching headers
header( 'Content-Type: ' . $contentType );
header( 'Content-Length: ' . filesize( $realPath ) );
header( 'Cache-Control: public, max-age=86400' ); // 24 hours
header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', filemtime( $realPath ) ) . ' GMT' );

// Handle If-Modified-Since for conditional requests
if ( isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
    $ifModifiedSince = strtotime( $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
    if ( $ifModifiedSince !== false && filemtime( $realPath ) <= $ifModifiedSince ) {
        http_response_code( 304 );
        exit;
    }
}

readfile( $realPath );
