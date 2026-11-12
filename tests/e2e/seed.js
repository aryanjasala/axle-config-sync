// Shared set-up for the specs, the accessibility screens, the screenshots and the demo.
const { wp, wpEval } = require( './utils' );

const DIR = 'WP_CONTENT_DIR . "/axle-config"';
const FIXTURE_OPTIONS = [ 'acs_fixture_constants', 'acs_fixture_home', 'acs_fixture_mail', 'acs_fixture_hook', 'acs_fixture_server', 'acme_api_key', 'acme_payload', 'acs_demo_link', 'acme_settings', 'acme_list', 'wpcfm_settings', 'axle_config_sync_drift', 'axle_config_sync_drift_actors' ];

/** Whether a WP-CLI command succeeds. */
function succeeds( ...args ) {
	try {
		wp( ...args );
		return true;
	} catch ( error ) {
		return false;
	}
}

/** Copies the fixture into mu-plugins. */
function installFixture() {
	wpEval( 'wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( WP_PLUGIN_DIR . "/axle-config-sync/tests/e2e/fixtures/axle-config-sync-fixture.php", WPMU_PLUGIN_DIR . "/axle-config-sync-fixture.php" );' );
}

/** Removes the fixture. */
function removeFixture() {
	wpEval( '@unlink( WPMU_PLUGIN_DIR . "/axle-config-sync-fixture.php" );' );
}

/** Deletes the config directory, the change sets, the state and the test options. */
function resetSync() {
	wpEval( `$d = ${ DIR }; if ( is_dir( $d ) ) { $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ); foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); } rmdir( $d ); }` );
	wp( 'db', 'query', 'TRUNCATE TABLE wp_axle_config_sync_changesets' );
	for ( const name of [ 'axle_config_sync_settings', 'axle_config_sync_state', ...FIXTURE_OPTIONS ] ) {
		succeeds( 'option', 'delete', name );
	}
	succeeds( 'transient', 'delete', 'axle_config_sync_reachable' );
	wpEval( '$d = WP_CONTENT_DIR . "/config"; if ( is_dir( $d ) ) { foreach ( glob( $d . "/*" ) as $f ) { is_file( $f ) && unlink( $f ); } rmdir( $d ); } delete_metadata( "user", 0, "axle_config_sync_drift_seen", "", true ); delete_metadata( "user", 0, "axle_config_sync_steps_dismissed", "", true );' );
	wp( 'option', 'update', 'blogname', 'E2E' );
	wp( 'option', 'update', 'default_role', 'subscriber' );
	wp( 'option', 'update', 'admin_email', 'admin@example.com' );
}

/** Writes a WP-CFM bundle file, as WP-CFM 1.7 does: a `.label` and raw option values. */
function writeWpCfmBundle( name, values, label ) {
	const encoded = Buffer.from( JSON.stringify( { '.label': label || name, ...values }, null, 4 ) ).toString( 'base64' );
	wpEval( `$d = WP_CONTENT_DIR . "/config"; wp_mkdir_p( $d ); file_put_contents( $d . "/${ name }.json", base64_decode( "${ encoded }" ) );` );
}

/** Sets test constants (read by the fixture on the next request). */
function setConstants( constants ) {
	wp( 'option', 'update', 'acs_fixture_constants', JSON.stringify( constants ), '--format=json' );
}

/** Reads a config file as JSON, or null. */
function readFile( relative ) {
	const text = wpEval( `$f = ${ DIR } . "/${ relative }"; echo is_file( $f ) ? file_get_contents( $f ) : "null";` );
	return JSON.parse( text );
}

/** Writes a config file, as git would. */
function writeFile( relative, data ) {
	const encoded = Buffer.from( JSON.stringify( data, null, 4 ) + '\n' ).toString( 'base64' );
	wpEval( `$f = ${ DIR } . "/${ relative }"; wp_mkdir_p( dirname( $f ) ); file_put_contents( $f, base64_decode( "${ encoded }" ) );` );
}

/** Changes the value in an item file, keeping the rest. */
function setFileValue( id, value ) {
	const file = `${ id }.json`;
	const data = readFile( file ) || { schema: 1, provider: id.split( '/' )[ 0 ], id, owner: 'core' };
	data.value = value;
	writeFile( file, data );
}

/** Every config file's text, joined, to search for leaked values. */
function allFileText() {
	return wpEval( `$d = ${ DIR }; $out = ""; if ( is_dir( $d ) ) { foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d, FilesystemIterator::SKIP_DOTS ) ) as $f ) { $out .= file_get_contents( $f->getPathname() ); } } echo $out;` );
}

/** An option as JSON. */
function option( name ) {
	try {
		return JSON.parse( wp( 'option', 'get', name, '--format=json' ) );
	} catch ( error ) {
		return null;
	}
}

/** Whether this WordPress has the Abilities API. */
function hasAbilities() {
	const [ major, minor ] = wp( 'core', 'version' ).split( '.' ).map( Number );
	return major > 6 || ( 6 === major && minor >= 9 );
}

/** A small site with settings worth syncing: a front page, a menu and a plugin option. */
function seedSite() {
	installFixture();
	resetSync();
	wp( 'option', 'update', 'blogname', 'Bean There Coffee' );
	wp( 'option', 'update', 'blogdescription', 'Small-batch coffee, roasted in Pune' );
	wp( 'option', 'update', 'posts_per_page', '10' );
	wp( 'option', 'update', 'date_format', 'F j, Y' );
	const about = wp( 'post', 'create', '--post_type=page', '--post_title=About', '--post_status=publish', '--porcelain' );
	wp( 'option', 'update', 'show_on_front', 'page' );
	wp( 'option', 'update', 'page_on_front', about );
	succeeds( 'menu', 'delete', 'main-menu' );
	wp( 'menu', 'create', 'Main menu' );
	wp( 'menu', 'item', 'add-post', 'main-menu', about );
	wp( 'menu', 'item', 'add-custom', 'main-menu', 'Shop', 'http://localhost:8913/shop' );
	wp( 'menu', 'location', 'assign', 'main-menu', 'primary' );
	wp( 'option', 'update', 'acme_settings', JSON.stringify( { mode: 'live', webhook_url: 'https://hooks.example.com/abc', api_key: 'sk_live_51Hc8xQ2vR8sT4wK1zN6bY3c' } ), '--format=json' );
	wp( 'axle-config-sync', 'track', 'acme_*', '--owner=acme' );
	wp( 'axle-config-sync', 'export', '--all' );
	return { about };
}

/** Moves the site away from the files in a few places, for status and preview screens. */
function driftSite() {
	wp( 'option', 'update', 'blogdescription', 'Coffee roasted in small batches' );
	setFileValue( 'options/posts_per_page', '12' );
	setFileValue( 'options/default_role', 'editor' );
	wp( 'option', 'update', 'blogname', 'Bean There' );
	setFileValue( 'options/blogname', 'Bean There Coffee Co.' );
	return true;
}

/** Changes settings on the site after the export, and runs a drift check. */
function driftWatch() {
	wp( 'option', 'update', 'blogname', 'Changed on production' );
	wp( 'option', 'update', 'blogdescription', 'Edited on the live site' );
	succeeds( 'axle-config-sync', 'drift', '--check' );
	return true;
}

/** Puts a WP-CFM bundle on the site, with a bundle list in the database. */
function seedWpCfm() {
	writeWpCfmBundle( 'main', { blogname: 'Bean There', acme_list: 'a:2:{i:0;s:1:"a";i:1;s:1:"b";}', siteurl: 'http://localhost', acme_object: 'O:8:"stdClass":0:{}', widget_text: 'a:0:{}' }, 'Main settings' );
	wp( 'option', 'update', 'wpcfm_settings', JSON.stringify( { bundles: [ { label: 'Main settings', name: 'main', config: [ 'blogname', 'acme_list', 'siteurl', 'acme_object', 'widget_text' ] } ] } ) );
	return true;
}

/** Applies one change through the CLI so History has a change set. */
function seedHistory() {
	setFileValue( 'options/date_format', 'j F Y' );
	wp( 'axle-config-sync', 'apply', 'options/date_format', '--yes' );
	return wp( 'axle-config-sync', 'changesets', '--format=ids' ).split( ' ' )[ 0 ];
}

/** Removes demo content. */
function unseedSite() {
	succeeds( 'menu', 'delete', 'main-menu' );
	const pages = wp( 'post', 'list', '--post_type=page', '--name=about', '--format=ids' );
	if ( pages ) {
		wp( 'post', 'delete', ...pages.split( ' ' ), '--force' );
	}
	wp( 'option', 'update', 'show_on_front', 'posts' );
	resetSync();
}

module.exports = { writeWpCfmBundle, succeeds, installFixture, removeFixture, resetSync, setConstants, readFile, writeFile, setFileValue, allFileText, option, hasAbilities, seedSite, driftSite, seedHistory, driftWatch, seedWpCfm, unseedSite };
