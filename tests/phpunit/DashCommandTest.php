<?php

use EE\ExitException;
use EE\Tests\Dash\FakeHttpTransport as Http;
use EE\Tests\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

/**
 * `ee dash init`: pre-checks, EasyDash URLs, server registration and the per-site payloads (src/Dash_Command.php).
 *
 * HTTP goes to a fake Requests transport; `lsb_release`, `grep`/`echo` on authorized_keys and nested `ee` calls to the fake runner.
 */
class DashCommandTest extends TestCase {

	const API     = 'https://dash.easyengine.io/api/method/';
	const IP      = '203.0.113.10';
	const SSH_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILbESQqRcGdwnn/u1BkDCD9rDiFqgDhTHBHIIasaDpWV EasyEngine';

	private $env_backup;

	protected function setUp(): void {
		parent::setUp();
		// init() asks for the FQDN in an endless loop when the machine hostname isn't one (DASH-3); never reach that prompt.
		if ( ! filter_var( gethostname(), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) ) {
			$this->markTestSkipped( 'gethostname() is not a valid hostname here.' );
		}
		Http::install();
		$this->env_backup = getenv( 'EE_CONFIG_PATH' );
		putenv( 'EE_CONFIG_PATH=' . $this->sandbox() . '/config.yml' );
		$this->os( 'Ubuntu', '24.04' );
		$this->server_list( [ [ 'public_ipv4' => self::IP ] ] );
		$this->site_list( [] );
		Http::on( '/\.add_site$/', 200, '{"message":"ok"}' );
	}

	protected function tearDown(): void {
		Http::uninstall();
		putenv( 'EE_CONFIG_PATH=' . $this->env_backup );
		parent::tearDown();
	}

	private function os( $distributor, $release ) {
		$this->runner->on( '/^lsb_release -i$/', "Distributor ID:\t$distributor\n" );
		$this->runner->on( '/^lsb_release -r$/', "Release:\t$release\n" );
	}

	private function server_list( array $servers ) {
		Http::on( '/\.get_server_list$/', 200, json_encode( [ 'message' => $servers ] ) );
	}

	private function site_list( array $sites ) {
		Http::on( '/\.get_site_list$/', 200, json_encode( [ 'message' => $sites ] ) );
	}

	/** add_server success calls exit( 0 ), which would end the PHPUnit process. */
	private function add_server_answers( $status, $body = '{"exc":"rejected"}' ) {
		$this->assertFalse( $status >= 200 && $status < 300, 'A successful add_server exits the process.' );
		Http::on( '/\.add_server$/', $status, $body );
	}

	private function init( array $assoc_args = [] ) {
		$assoc_args += [ 'api' => 'tok:secret', 'org' => 'acme', 'ip' => self::IP, 'hostname' => 'srv.example.test' ];
		( new Dash_Command() )->init( [], $assoc_args );
	}

	/** Insert an enabled site with a docker-compose.yml. */
	private function site( $url, array $fields = [] ) {
		$dir = $this->sandbox();
		touch( "$dir/docker-compose.yml" );
		$this->insert_site(
			$fields + [
				'site_url'               => $url,
				'site_type'              => 'wp',
				'site_fs_path'           => $dir,
				'site_container_fs_path' => '/var/www/htdocs',
				'php_version'            => '8.2',
			]
		);

		return $dir;
	}

	/** @return array[] Decoded bodies of the add_site requests. */
	private function sent_sites() {
		return array_column( Http::matching( '/\.add_site$/' ), 'json' );
	}

	public function test_api_key_is_required() {
		$this->assert_ee_error(
			function () {
				$this->init( [ 'api' => '  ' ] );
			},
			'Please provide an EasyDash API key'
		);
		$this->assertSame( [], Http::$requests );
	}

	public function test_organization_is_required() {
		$this->assert_ee_error(
			function () {
				$this->init( [ 'org' => '' ] );
			},
			'Please provide an EasyDash organization name'
		);
		$this->assertSame( [], $this->runner->commands() );
	}

	/**
	 * @dataProvider supported_os_provider
	 */
	public function test_pre_checks_accept_ubuntu_22_04_and_later( $release ) {
		$this->os( 'Ubuntu', $release );

		self::call_private( new Dash_Command(), 'pre_checks' );

		$this->assertSame( [ 'lsb_release -i', 'lsb_release -r' ], $this->runner->commands() );
	}

	public function supported_os_provider() {
		return [ '22.04' => [ '22.04' ], '24.04' => [ '24.04' ], '26.04' => [ '26.04' ] ];
	}

	/**
	 * @dataProvider unsupported_os_provider
	 */
	public function test_pre_checks_refuse_other_systems( $distributor, $release, $message ) {
		$this->os( $distributor, $release );

		$this->assert_ee_error(
			function () {
				self::call_private( new Dash_Command(), 'pre_checks' );
			},
			$message
		);
	}

	public function unsupported_os_provider() {
		return [
			'old ubuntu' => [ 'Ubuntu', '20.04', 'only supported on Ubuntu 22.04 or later' ],
			'centos'     => [ 'CentOS', '9', 'only supported on Ubuntu or Debian' ],
			'no lsb'     => [ '', '', 'only supported on Ubuntu or Debian' ],
		];
	}

	/**
	 * DASH-10: Debian passes the distribution check, then its release (12) is compared with Ubuntu's 22.04.
	 *
	 * @group known-bug
	 * @group DASH-10
	 */
	public function test_pre_checks_accept_a_current_debian() {
		$this->os( 'Debian', '12' );

		try {
			self::call_private( new Dash_Command(), 'pre_checks' );
		} catch ( ExitException $e ) {
			$this->fail( 'Debian 12 was refused: ' . $this->stderr() );
		}
		$this->assertSame( '', $this->stderr() );
	}

	public function test_server_lookup_posts_the_organization_with_the_token() {
		$this->init();

		$lookups = Http::matching( '/get_server_list$/' );
		$this->assertCount( 1, $lookups );
		$this->assertSame( self::API . 'easydash.easydash.doctype.server.server.get_server_list', $lookups[0]['url'] );
		$this->assertSame( 'POST', $lookups[0]['method'] );
		$this->assertSame( [ 'organization' => 'acme' ], $lookups[0]['json'] );
		$this->assertSame( 'tok:secret', $lookups[0]['headers']['Token'] );
		$this->assertSame( 'application/json', $lookups[0]['headers']['Content-Type'] );
	}

	public function test_a_known_server_is_not_registered_again() {
		$this->init();

		$this->assertStringContainsString( 'Server with IP ' . self::IP . ' already exists on EasyDash. Skipping server addition.', $this->stdout() );
		$this->assertSame( [], Http::matching( '/add_server$/' ) );
		$this->assertSame( [], $this->runner->matching( '/authorized_keys/' ) );
	}

	public function test_a_new_server_is_registered_with_its_ip_and_organization() {
		$this->server_list( [ [ 'public_ipv4' => '198.51.100.7' ] ] );
		$this->add_server_answers( 400 );

		$this->init();

		$adds = Http::matching( '/add_server$/' );
		$this->assertCount( 1, $adds );
		$this->assertSame( self::API . 'easydash.easydash.doctype.server.server.add_server', $adds[0]['url'] );
		$this->assertSame( 'POST', $adds[0]['method'] );
		// v1.0.1 (tagged on main with dash-command#1) also sends the EE version; develop lacks it (REL-4).
		$this->assertSame( [ 'hostname', 'public_ipv4', 'organization', 'ee_version' ], array_keys( $adds[0]['json'] ) );
		$this->assertSame( EE_VERSION, $adds[0]['json']['ee_version'] );
		$this->assertSame( self::IP, $adds[0]['json']['public_ipv4'] );
		$this->assertSame( 'acme', $adds[0]['json']['organization'] );
		$this->assertSame( 'tok:secret', $adds[0]['headers']['Token'] );
		$this->assertStringContainsString( 'Failed to integrate server with EasyDash. Response: {"exc":"rejected"}', $this->stderr() );
		$this->assertStringContainsString( 'Status code: 400', $this->stderr() );
	}

	public function test_the_easydash_key_is_appended_to_authorized_keys_only_when_missing() {
		$this->server_list( [] );
		$this->add_server_answers( 400 );
		$grep = 'grep -q "' . self::SSH_KEY . '" /root/.ssh/authorized_keys';

		$this->init();
		$this->assertSame( [ $grep ], $this->runner->matching( '/authorized_keys/' ) );

		$this->runner->reset();
		$this->os( 'Ubuntu', '24.04' );
		$this->runner->on( '/^grep -q /', '', 1 );
		$this->init();
		$this->assertSame( [ $grep, 'echo "' . self::SSH_KEY . '" >> /root/.ssh/authorized_keys' ], $this->runner->matching( '/authorized_keys/' ) );
	}

	public function test_the_public_ip_comes_from_ipify_when_not_given() {
		Http::on( '#^https://api\.ipify\.org/?$#', 200, "198.51.100.23\n" );
		$this->server_list( [ [ 'public_ipv4' => '198.51.100.23' ] ] );

		$this->init( [ 'ip' => '' ] );

		$ipify = Http::matching( '/ipify/' );
		$this->assertCount( 1, $ipify );
		$this->assertSame( 'GET', $ipify[0]['method'] );
		$this->assertStringContainsString( 'Server with IP 198.51.100.23 already exists', $this->stdout() );
	}

	public function test_a_custom_api_url_without_trailing_slash_is_normalised() {
		file_put_contents( getenv( 'EE_CONFIG_PATH' ), "ed-api-url: https://dash.example.test/api/method\n" );
		$this->server_list( [] );
		$this->add_server_answers( 400 );

		$this->init();

		$this->assertSame(
			[
				'https://dash.example.test/api/method/easydash.easydash.doctype.server.server.get_server_list',
				'https://dash.example.test/api/method/easydash.easydash.doctype.server.server.add_server',
			],
			array_column( Http::$requests, 'url' )
		);
	}

	/**
	 * DASH-11: lookups use `ed_base_url . '/api/method/…'`, registration `ed_api_url . …`; they diverge when ed-api-url doesn't end in /api/method/.
	 *
	 * @group known-bug
	 * @group DASH-11
	 */
	public function test_lookup_and_registration_share_the_configured_api_url() {
		file_put_contents( getenv( 'EE_CONFIG_PATH' ), "ed-api-url: https://dash.example.test/proxy/\n" );
		$this->server_list( [] );
		$this->add_server_answers( 400 );

		$this->init();

		$prefixes = array_map(
			function ( $url ) {
				return substr( $url, 0, strpos( $url, 'easydash.easydash.' ) );
			},
			array_column( Http::$requests, 'url' )
		);
		$this->assertSame( [ 'https://dash.example.test/proxy/', 'https://dash.example.test/proxy/' ], $prefixes );
	}

	public function test_wp_site_payload() {
		$this->site(
			'example.test',
			[
				'site_ssl'             => 'le',
				'alias_domains'        => 'example.test, www.example.test,blog.example.test',
				'db_name'              => 'example_test',
				'cache_nginx_fullpage' => 1,
				'admin_tools'          => 1,
				'mailhog_enabled'      => 0,
			]
		);
		$this->runner->on( "/^ee shell example\.test --command='wp config get table_prefix' --skip-tty$/", "wp_x1\n" );

		$this->init();

		$sites = $this->sent_sites();
		$this->assertCount( 1, $sites );
		$site = $sites[0];
		unset( $site['server'] );
		// SQLite column types reach the payload as strings on PHP < 8.1 and as ints on 8.1+.
		foreach ( [ 'admin_tools', 'mailhog', 'redis_cache' ] as $int_column ) {
			$site[ $int_column ] = (int) $site[ $int_column ];
		}
		$this->assertSame(
			[
				'domain'           => 'example.test',
				'site_type'        => 'wp',
				'organization'     => 'acme',
				'enabled'          => true,
				'alias_domains'    => 'www.example.test,blog.example.test',
				'ssl'              => true,
				'http_basic_auth'  => false,
				'admin_tools'      => 1,
				'mailhog'          => 0,
				'php_version'      => '8.2',
				'public_directory' => '',
				'enable_database'  => true,
				'redis_cache'      => 1,
				'multisite'        => 0,
				'table_prefix'     => 'wp_x1',
			],
			$site
		);
		$this->assertStringContainsString( 'Site example.test integrated with EasyDash successfully.', $this->stdout() );
	}

	public function test_non_wp_site_payload_has_no_table_prefix_and_its_own_public_dir() {
		$this->site( 'app.test', [ 'site_type' => 'php', 'site_container_fs_path' => '/var/www/htdocs/current/public', 'site_ssl' => null ] );

		$this->init();

		$site = $this->sent_sites()[0];
		$this->assertSame( 'current/public', $site['public_directory'] );
		$this->assertSame( '', $site['table_prefix'] );
		$this->assertFalse( $site['ssl'] );
		$this->assertFalse( $site['enable_database'] );
		$this->assertSame( '', $site['alias_domains'] );
		$this->assertSame( [], $this->runner->matching( '/^ee shell /' ) );
	}

	public function test_http_auth_is_reported_when_the_site_has_auth_users() {
		$this->site( 'a.test' );
		$this->site( 'b.test' );
		EE::db()->table( 'auth_users' )->insert( [ 'site_url' => 'b.test', 'username' => 'u', 'password' => 'p' ] );

		$this->init();

		$this->assertSame( [ 'a.test' => false, 'b.test' => true ], array_column( $this->sent_sites(), 'http_basic_auth', 'domain' ) );
	}

	/**
	 * @dataProvider latest_php_provider
	 */
	public function test_latest_php_version_is_resolved_in_the_container( $php_v_output, $expected ) {
		$this->site( 'example.test', [ 'site_type' => 'php', 'php_version' => 'latest' ] );
		$this->runner->on( '/^ee shell example\.test --skip-tty --command="php -v"$/', $php_v_output );

		$this->init();

		$this->assertSame( $expected, $this->sent_sites()[0]['php_version'] );
	}

	public function latest_php_provider() {
		return [
			'parsed'   => [ "PHP 8.3.14 (cli) (built: Nov 21 2024)\nCopyright (c) The PHP Group\n", '8.3' ],
			'fallback' => [ "OCI runtime exec failed\n", '8.2' ],
		];
	}

	public function test_sites_below_php_8_1_are_skipped_but_html_sites_are_sent() {
		$this->site( 'old.test', [ 'site_type' => 'php', 'php_version' => '7.4' ] );
		$this->site( 'static.test', [ 'site_type' => 'html', 'php_version' => '7.4' ] );

		$this->init();

		$this->assertSame( [ 'static.test' ], array_column( $this->sent_sites(), 'domain' ) );
		$this->assertStringContainsString( 'Skipping site old.test integration with EasyDash as PHP version is less than 8.1.', $this->stderr() );
	}

	public function test_sites_already_on_easydash_or_without_compose_file_are_skipped() {
		$this->site( 'known.test' );
		$gone = $this->site( 'gone.test' );
		unlink( "$gone/docker-compose.yml" );
		$this->site( 'new.test' );
		$this->site_list( [ [ 'domain' => 'known.test' ] ] );

		$this->init();

		$this->assertSame( [ 'new.test' ], array_column( $this->sent_sites(), 'domain' ) );
		$this->assertStringContainsString( 'Site known.test already exists on EasyDash. Skipping site addition.', $this->stdout() );
		$this->assertStringContainsString( 'docker-compose.yml file not found for site: gone.test. Skipping.', $this->stderr() );
		$lookup = Http::matching( '/get_site_list$/' )[0];
		$this->assertSame( self::API . 'easydash.easydash.doctype.site.site.get_site_list', $lookup['url'] );
		$this->assertSame( [ 'organization' => 'acme' ], $lookup['json'] );
	}

	/**
	 * @dataProvider sub_type_provider
	 */
	public function test_multisite_flag_from_app_sub_type( $site_type, $app_sub_type, $expected ) {
		$this->site( 'example.test', [ 'site_type' => $site_type, 'app_sub_type' => $app_sub_type ] );

		$this->init();

		$this->assertSame( $expected, $this->sent_sites()[0]['multisite'] );
	}

	public function sub_type_provider() {
		return [
			'wp single'    => [ 'wp', 'wp', 0 ],
			'wp subdom'    => [ 'wp', 'subdom', 'subdom' ],
			'wp subdir'    => [ 'wp', 'subdir', 'subdir' ],
			'php'          => [ 'php', 'php', 0 ],
			'html'         => [ 'html', 'html', 0 ],
			'none'         => [ 'php', null, 0 ],
		];
	}

	/**
	 * DASH-7: site-type-php stores `mysql` as app_sub_type for PHP sites with a DB, which is sent as multisite "mysql".
	 *
	 * @group known-bug
	 * @group DASH-7
	 */
	public function test_php_site_with_a_database_is_not_multisite() {
		$this->site( 'example.test', [ 'site_type' => 'php', 'app_sub_type' => 'mysql', 'db_name' => 'example' ] );

		$this->init();

		$this->assertEmpty( $this->sent_sites()[0]['multisite'] );
	}

	/**
	 * DASH-1: a valid --hostname only skips the confirmation; gethostname() then overwrites it.
	 *
	 * @group known-bug
	 * @group DASH-1
	 */
	public function test_hostname_flag_is_what_gets_registered() {
		$this->server_list( [] );
		$this->add_server_answers( 400 );

		$this->init( [ 'hostname' => 'srv.example.test' ] );

		$this->assertSame( 'srv.example.test', Http::matching( '/add_server$/' )[0]['json']['hostname'] );
	}

	/**
	 * DASH-4: http_request() halts on transport errors, so the "assume the server doesn't exist" catch is dead and init aborts.
	 *
	 * @group known-bug
	 * @group DASH-4
	 */
	public function test_an_unreachable_server_lookup_falls_back_to_registration() {
		Http::fail( '/get_server_list$/' );
		$this->add_server_answers( 400 );

		try {
			$this->init();
		} catch ( ExitException $e ) {
			$this->fail( 'init aborted: ' . $this->stderr() );
		}
		$this->assertStringContainsString( 'Error connecting to EasyDash API to get server list.', $this->stderr() );
		$this->assertCount( 1, Http::matching( '/add_server$/' ) );
	}

	/**
	 * DASH-5: the EasyDash root SSH key is appended before add_server and stays authorised when registration is rejected.
	 *
	 * @group known-bug
	 * @group DASH-5
	 */
	public function test_a_rejected_registration_leaves_no_easydash_key_behind() {
		$this->server_list( [] );
		$this->runner->on( '/^grep -q /', '', 1 );
		$this->add_server_answers( 400 );

		try {
			$this->init();
		} catch ( ExitException $e ) {
			// Failing the run is fine; the key is what matters.
		}

		$commands = $this->runner->matching( '/authorized_keys/' );
		$appended = array_keys( preg_grep( '/>> \/root\/\.ssh\/authorized_keys$/', $commands ) );
		$removed  = array_keys( preg_grep( '/(sed|grep -v).*EasyEngine.*authorized_keys/', $commands ) );
		$this->assertTrue( empty( $appended ) || ( ! empty( $removed ) && max( $removed ) > max( $appended ) ), implode( "\n", $commands ) );
	}

	/**
	 * DASH-6: the request headers, including the API token, are written to ee.log with EE::debug().
	 *
	 * @group known-bug
	 * @group DASH-6
	 */
	public function test_the_api_token_is_not_written_to_the_log_file() {
		$handler = new TestHandler();
		$backup  = self::get_static( 'EE', 'file_logger' );
		EE::set_file_logger( new Logger( 'ee', [ $handler ] ) );
		try {
			$this->init();
		} finally {
			EE::set_file_logger( $backup );
		}

		$this->assertStringNotContainsString( 'tok:secret', implode( "\n", array_column( $handler->getRecords(), 'message' ) ) );
	}

	/**
	 * DASH-8: a rejected add_server only warns; init then syncs every site for a server EasyDash doesn't know and exits 0.
	 *
	 * @group known-bug
	 * @group DASH-8
	 */
	public function test_a_rejected_registration_stops_before_the_site_sync() {
		$this->site( 'example.test' );
		$this->server_list( [] );
		$this->add_server_answers( 403, '{"exc":"forbidden"}' );

		$this->assert_ee_error(
			function () {
				$this->init();
			}
		);
		$this->assertSame( [], $this->sent_sites() );
	}

	/**
	 * DASH-12: one rejected add_site (EE::error) stops the sync, so later sites are never sent.
	 *
	 * @group known-bug
	 * @group DASH-12
	 */
	public function test_a_rejected_site_does_not_stop_the_sync() {
		$this->site( 'a.test' );
		$this->site( 'b.test' );
		Http::on( '/\.add_site$/', 500, '{"exc":"boom"}', 1 );

		try {
			$this->init();
		} catch ( ExitException $e ) {
			// Failing at the end is fine; sending every site is what matters.
		}

		$this->assertSame( [ 'a.test', 'b.test' ], array_column( $this->sent_sites(), 'domain' ) );
	}

	/**
	 * DASH-13: --ip isn't validated, so any string is registered as public_ipv4.
	 *
	 * @group known-bug
	 * @group DASH-13
	 */
	public function test_an_invalid_ip_is_rejected() {
		$this->server_list( [] );
		$this->add_server_answers( 400 );

		$this->assert_ee_error(
			function () {
				$this->init( [ 'ip' => 'not-an-ip' ] );
			}
		);
		$this->assertSame( [], Http::matching( '/add_server$/' ) );
	}

	/**
	 * DASH-14: disabled sites are started with `ee site enable` only to pass get_site_info()'s enabled check, which still fails.
	 *
	 * @group known-bug
	 * @group DASH-14
	 */
	public function test_a_disabled_site_is_sent_as_disabled_without_being_started() {
		$this->site( 'off.test', [ 'site_enabled' => 0 ] );

		try {
			$this->init();
		} catch ( ExitException $e ) {
			$this->fail( 'init aborted: ' . $this->stderr() );
		}

		$this->assertSame( [], $this->runner->matching( '/site enable/' ) );
		$sites = $this->sent_sites();
		$this->assertCount( 1, $sites );
		$this->assertFalse( $sites[0]['enabled'] );
	}

	/**
	 * DASH-16: site URLs are interpolated unescaped into the nested `ee shell` commands.
	 *
	 * @group known-bug
	 * @group DASH-16
	 */
	public function test_nested_ee_commands_are_shell_safe() {
		$this->assert_shell_safe(
			function ( $url ) {
				EE\Tests\TestDB::reset();
				$this->site( $url, [ 'php_version' => 'latest' ] );
				$this->init();
			},
			'/(^|\/)ee /'
		);
	}

	/**
	 * DASH-18: get_site_list is fetched once per site instead of once per run.
	 *
	 * @group known-bug
	 * @group DASH-18
	 */
	public function test_the_site_list_is_fetched_once() {
		$this->site( 'a.test' );
		$this->site( 'b.test' );
		$this->site( 'c.test' );

		$this->init();

		$this->assertCount( 1, Http::matching( '/get_site_list$/' ) );
	}
}
