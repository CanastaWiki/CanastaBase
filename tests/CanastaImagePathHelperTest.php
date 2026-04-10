<?php

declare( strict_types=1 );

use Canasta\FileRepo\CanastaImagePathHelper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Canasta\FileRepo\CanastaImagePathHelper
 */
class CanastaImagePathHelperTest extends TestCase {

	// --- strReplaceLast ---

	public function testStrReplaceLastBasic(): void {
		$this->assertSame(
			'/images/main/a/ab/File.png',
			CanastaImagePathHelper::strReplaceLast(
				'/images', '/images/main', '/images/a/ab/File.png'
			)
		);
	}

	public function testStrReplaceLastNoMatch(): void {
		$this->assertSame(
			'/other/a/ab/File.png',
			CanastaImagePathHelper::strReplaceLast(
				'/images', '/images/main', '/other/a/ab/File.png'
			)
		);
	}

	public function testStrReplaceLastMultipleOccurrences(): void {
		$this->assertSame(
			'/images/images/main',
			CanastaImagePathHelper::strReplaceLast(
				'/images', '/images/main', '/images/images'
			)
		);
	}

	public function testStrReplaceLastEmptySubject(): void {
		$this->assertSame(
			'',
			CanastaImagePathHelper::strReplaceLast(
				'/images', '/images/main', ''
			)
		);
	}

	// --- injectWikiId ---

	public function testInjectWikiIdImagesPath(): void {
		$this->assertSame(
			'/images/main/a/ab/File.png',
			CanastaImagePathHelper::injectWikiId(
				'/images/a/ab/File.png', 'main'
			)
		);
	}

	public function testInjectWikiIdCanastaImgPath(): void {
		$this->assertSame(
			'/canasta_img.php/draft/a/ab/File.png',
			CanastaImagePathHelper::injectWikiId(
				'/canasta_img.php/a/ab/File.png', 'draft'
			)
		);
	}

	public function testInjectWikiIdThumbPath(): void {
		$this->assertSame(
			'/images/main/thumb/a/ab/File.png/120px-File.png',
			CanastaImagePathHelper::injectWikiId(
				'/images/thumb/a/ab/File.png/120px-File.png', 'main'
			)
		);
	}

	public function testInjectWikiIdArchivePath(): void {
		$this->assertSame(
			'/images/docs/archive/a/ab/20240101000000!File.png',
			CanastaImagePathHelper::injectWikiId(
				'/images/archive/a/ab/20240101000000!File.png', 'docs'
			)
		);
	}

	public function testInjectWikiIdNoImagesPrefix(): void {
		$this->assertSame(
			'/other/path/File.png',
			CanastaImagePathHelper::injectWikiId(
				'/other/path/File.png', 'main'
			)
		);
	}

	public function testInjectWikiIdSpecialCharacters(): void {
		$this->assertSame(
			'/images/my_wiki/a/ab/File with spaces.png',
			CanastaImagePathHelper::injectWikiId(
				'/images/a/ab/File with spaces.png', 'my_wiki'
			)
		);
	}

	public function testInjectWikiIdTranscodedPath(): void {
		$this->assertSame(
			'/images/main/transcoded/a/ab/Video.webm/Video.webm.720p.vp9.webm',
			CanastaImagePathHelper::injectWikiId(
				'/images/transcoded/a/ab/Video.webm/Video.webm.720p.vp9.webm',
				'main'
			)
		);
	}

	public function testInjectWikiIdBothImagesAndCanastaImg(): void {
		// Path containing both /images and /canasta_img.php — both get injected
		$this->assertSame(
			'/canasta_img.php/main/images/main/a/File.png',
			CanastaImagePathHelper::injectWikiId(
				'/canasta_img.php/images/a/File.png', 'main'
			)
		);
	}
}
