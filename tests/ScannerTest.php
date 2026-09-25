<?php
/**
 * Scanner tests: href resolution and internal-link detection.
 *
 * @package DragonInternalLinks
 */

namespace DragonInternalLinks\Tests;

use DragonInternalLinks\Scanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-scanner.php';

final class ScannerTest extends TestCase {

	private const BASE = 'https://example.test/blog/hello-world/';

	protected function setUp(): void {
		dragoninternallinks_test_reset();
		// Subdirectory install with a mixed-case host.
		$GLOBALS['dragoninternallinks_test']['home_url'] = 'https://Example.test/blog';
	}

	/**
	 * @return array<string,array{string,?string}>
	 */
	public static function hrefs(): array {
		return array(
			'root-relative keeps the subdirectory once' => array( '/blog/hello/', 'https://example.test/blog/hello/' ),
			'scheme-relative gets the home scheme'      => array( '//example.test/blog/hello/', 'https://example.test/blog/hello/' ),
			'document-relative'                         => array( 'about/', 'https://example.test/blog/hello-world/about/' ),
			'dot-dot segment'                           => array( '../other/', 'https://example.test/blog/other/' ),
			'dot segment'                               => array( './x', 'https://example.test/blog/hello-world/x' ),
			'query-only'                                => array( '?page_id=12', 'https://example.test/blog/hello-world/?page_id=12' ),
			'absolute is normalised to lowercase'       => array( 'HTTPS://WWW.EXAMPLE.TEST/Blog/X', 'https://www.example.test/Blog/X' ),
			'absolute with dot segments'                => array( 'https://example.test/a/../b', 'https://example.test/b' ),
			'fragment-only'                             => array( '#top', null ),
			'mailto'                                    => array( 'mailto:a@example.test', null ),
			'tel'                                       => array( 'tel:123', null ),
			'javascript'                                => array( 'javascript:void(0)', null ),
			'empty'                                     => array( '', null ),
		);
	}

	#[DataProvider( 'hrefs' )]
	public function test_resolve_url( string $href, ?string $expected ): void {
		$this->assertSame( $expected, ( new Scanner() )->resolve_url( $href, self::BASE ) );
	}

	public function test_host_comparison_is_case_insensitive_and_www_agnostic(): void {
		$scanner = new Scanner();

		$this->assertTrue( $scanner->is_internal_link( 'HTTPS://EXAMPLE.TEST/blog/x' ) );
		$this->assertTrue( $scanner->is_internal_link( 'https://www.example.test/blog/x' ) );
		$this->assertTrue( $scanner->is_internal_link( '/blog/x' ) );
		$this->assertFalse( $scanner->is_internal_link( 'https://other.test/blog/x' ) );
		$this->assertFalse( $scanner->is_internal_link( 'https://notexample.test/' ) );
		$this->assertFalse( $scanner->is_internal_link( 'mailto:a@example.test' ) );
		$this->assertFalse( $scanner->is_internal_link( '#top' ) );
	}

	public function test_extract_links_resolves_each_href_against_the_post_permalink(): void {
		// The configured host is mixed case, and that is the spelling the lookup
		// must receive: core compares it against home_url() case-sensitively.
		$GLOBALS['dragoninternallinks_test']['url_to_postid'] = array(
			'https://Example.test/blog/hello/'                  => 7,
			'https://Example.test/blog/hello-world/about/'      => 8,
			'https://Example.test/blog/hello-world/?page_id=12' => 12,
		);

		$content = '<p><a href="/blog/hello/">a</a> <a href="about/">b</a> <a href="?page_id=12">c</a> <a href="https://other.test/">d</a> <a href="mailto:x@y.z">e</a></p>';
		$links   = ( new Scanner() )->extract_links( $content, self::BASE );

		$this->assertSame( array( 7, 8, 12 ), array_column( $links, 'target_id' ) );
		$this->assertSame( array( '/blog/hello/', 'about/', '?page_id=12' ), array_column( $links, 'url' ) );
		$this->assertSame(
			array( 'https://Example.test/blog/hello/', 'https://Example.test/blog/hello-world/about/', 'https://Example.test/blog/hello-world/?page_id=12' ),
			array_column( dragoninternallinks_test_calls( 'url_to_postid' ), 0 )
		);
	}

	public function test_url_to_post_id_resolves_root_relative_against_the_site_origin(): void {
		$GLOBALS['dragoninternallinks_test']['url_to_postid'] = array( 'https://Example.test/blog/hello/' => 7 );

		$this->assertSame( 7, ( new Scanner() )->url_to_post_id( '/blog/hello/' ) );
	}

	public function test_the_post_lookup_gets_the_configured_host_spelling(): void {
		// Resolution lowercases the host for comparison, but core's
		// url_to_postid() compares the URL host against the configured one
		// case-sensitively and returns 0 when they differ, so a site configured
		// with a mixed-case host would find none of its own links.
		$scanner = new Scanner();

		$GLOBALS['dragoninternallinks_test']['url_to_postid']['https://Example.test/blog/?page_id=12'] = 12;

		$this->assertSame( 12, $scanner->url_to_post_id( '/blog/?page_id=12' ) );

		$asked = dragoninternallinks_test_calls( 'url_to_postid' );
		$this->assertSame( 'https://Example.test/blog/?page_id=12', $asked[0][0] );
	}

	public function test_an_off_site_url_keeps_its_own_host(): void {
		$scanner = new Scanner();

		$scanner->url_to_post_id( 'https://other.test/page' );

		$asked = dragoninternallinks_test_calls( 'url_to_postid' );
		$this->assertSame( 'https://other.test/page', $asked[0][0] );
	}

	public function test_a_failed_store_leaves_the_existing_index_alone_and_is_reported(): void {
		// The old rows used to be deleted before the new ones were written, so a
		// refused insert destroyed the post's index while the scan still reported
		// the links it had found.
		$GLOBALS['dragoninternallinks_test']['posts'][5] = (object) array(
			'ID'           => 5,
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_content' => '<p><a href="/blog/hello/">a</a></p>',
			'post_date'    => '2026-01-01 00:00:00',
		);
		$GLOBALS['dragoninternallinks_test']['url_to_postid']['https://Example.test/blog/hello/'] = 7;
		$GLOBALS['wpdb']->returns['insert'] = false;

		$scanner = new Scanner();
		$scanner->scan_post( 5 );

		$this->assertTrue( $scanner->scan_failed(), 'The caller is told the index was not replaced.' );
		$this->assertContains( 'ROLLBACK', array_column( $GLOBALS['wpdb']->calls_to( 'query' ), 0 ) );
	}

	public function test_a_clean_rescan_commits_and_reports_no_failure(): void {
		$GLOBALS['dragoninternallinks_test']['posts'][5] = (object) array(
			'ID'           => 5,
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_content' => '<p><a href="/blog/hello/">a</a></p>',
			'post_date'    => '2026-01-01 00:00:00',
		);
		$GLOBALS['dragoninternallinks_test']['url_to_postid']['https://Example.test/blog/hello/'] = 7;
		$GLOBALS['wpdb']->returns['insert'] = 1;
		$GLOBALS['wpdb']->returns['delete'] = 1;
		$GLOBALS['wpdb']->returns['query']  = 1;

		$scanner = new Scanner();
		$links   = $scanner->scan_post( 5 );

		$this->assertCount( 1, $links );
		$this->assertFalse( $scanner->scan_failed() );
		$this->assertContains( 'COMMIT', array_column( $GLOBALS['wpdb']->calls_to( 'query' ), 0 ) );
	}

	private function link_row( int $target, ?string $status, ?string $type = 'post', int $parent = 0, ?string $parent_status = null, string $url = '/blog/x/' ): array {
		return array(
			'id'             => $target,
			'source_post_id' => 5,
			'target_post_id' => $target,
			'link_url'       => $url,
			'source_title'   => 'Source',
			'target_title'   => null === $status ? null : 'Target',
			'target_status'  => $status,
			'target_type'    => null === $status ? null : $type,
			'target_parent'  => null === $status ? null : (string) $parent,
			'parent_status'  => $parent_status,
		);
	}

	public function test_attachment_links_are_broken_only_when_the_attachment_is(): void {
		$uploads = sys_get_temp_dir() . '/dil-uploads-' . uniqid();
		mkdir( $uploads . '/2026/09', 0777, true );
		file_put_contents( $uploads . '/2026/09/kept.pdf', 'x' );
		$GLOBALS['dragoninternallinks_test']['uploads'] = array(
			'basedir' => $uploads,
			'baseurl' => 'https://Example.test/blog/wp-content/uploads',
		);

		$GLOBALS['wpdb']->returns['get_results'] = array(
			$this->link_row( 11, 'inherit', 'attachment', 0 ),
			$this->link_row( 12, 'inherit', 'attachment', 5, 'publish' ),
			$this->link_row( 13, 'inherit', 'attachment', 5, 'private' ),
			$this->link_row( 14, 'inherit', 'attachment', 99, null ),
			$this->link_row( 15, 'inherit', 'attachment', 6, 'draft' ),
			$this->link_row( 16, 'trash', 'attachment', 0 ),
			$this->link_row( 17, 'draft', 'post' ),
			$this->link_row( 18, null ),
			$this->link_row( 19, null, null, 0, null, 'https://example.test/blog/wp-content/uploads/2026/09/kept.pdf?ver=2' ),
			$this->link_row( 20, 'trash', 'attachment', 0, null, '/blog/wp-content/uploads/2026/09/kept.pdf' ),
			$this->link_row( 21, null, null, 0, null, '/blog/wp-content/uploads/2026/09/gone.pdf' ),
			$this->link_row( 22, null, null, 0, null, '/blog/wp-content/uploads/../../../etc/passwd' ),
		);

		$broken = ( new Scanner() )->find_broken_links();

		unlink( $uploads . '/2026/09/kept.pdf' );
		rmdir( $uploads . '/2026/09' );
		rmdir( $uploads . '/2026' );
		rmdir( $uploads );

		$this->assertSame( array( 15, 16, 17, 18, 21, 22 ), array_map( 'intval', array_column( $broken, 'target_post_id' ) ) );

		// An attachment under an unpublished post is shown with its parent's status.
		$this->assertSame( 'draft', $broken[0]['target_status'] );
		$this->assertSame( 'trash', $broken[1]['target_status'] );
	}
}
