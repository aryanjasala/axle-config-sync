// End-to-end tests for the drift watch and the WP-CFM import. Every test
// checks the outcome in the database or the files through WP-CLI as well as
// on screen.
const { test, expect, wp, wpEval, logInAs, appPasswordClient } = require( './utils' );
const { succeeds, installFixture, removeFixture, resetSync, readFile, option, hasAbilities, writeWpCfmBundle } = require( './seed' );

const SCREEN = '/wp-admin/options-general.php?page=axle-config-sync';

/** Runs WP-CLI and returns its exit code instead of throwing. */
function exitCode( ...args ) {
	try {
		wp( ...args );
		return 0;
	} catch ( error ) {
		return error.status;
	}
}

/** Runs WP-CLI and returns its output even when it exits with 1, as drift does. */
function output( ...args ) {
	try {
		return wp( ...args );
	} catch ( error ) {
		return String( error.stdout || '' ).trim();
	}
}

/** Changes an option the way a signed-in admin would, so who changed it is recorded. */
function changeAsAdmin( name, value ) {
	wpEval( `wp_set_current_user( 1 ); update_option( ${ JSON.stringify( name ) }, ${ JSON.stringify( value ) } );` );
}

/** The row of an item on the Drift tab. */
const driftRow = ( page, id ) => page.locator( `.acs-drift tr[data-item="${ id }"]` );

test.describe( 'Drift watch', () => {
	test.beforeAll( () => {
		installFixture();
		resetSync();
	} );

	test.beforeEach( () => {
		wp( 'axle-config-sync', 'export', 'blogname', 'blogdescription' );
	} );

	test.afterEach( () => {
		resetSync();
	} );

	test.afterAll( () => {
		removeFixture();
	} );

	test( 'the CLI lists drift, exits 1, and the expected list silences it', () => {
		expect( exitCode( 'axle-config-sync', 'drift' ) ).toBe( 0 );

		wp( 'option', 'update', 'blogname', 'Changed on production' );
		expect( exitCode( 'axle-config-sync', 'drift' ) ).toBe( 1 );
		const items = JSON.parse( output( 'axle-config-sync', 'drift', '--format=json' ) );
		expect( items.map( ( item ) => item.item ) ).toEqual( [ 'options/blogname' ] );
		expect( items[ 0 ].site ).toBe( 'Changed on production' );
		expect( items[ 0 ].files ).toBe( 'E2E' );
		expect( items[ 0 ].by ).toBe( 'WP-CLI' );

		wp( 'axle-config-sync', 'expected', 'add', 'options/blogname' );
		expect( exitCode( 'axle-config-sync', 'drift' ) ).toBe( 0 );
		expect( wp( 'axle-config-sync', 'expected', 'list', '--format=ids' ) ).toBe( 'options/blogname' );
		wp( 'axle-config-sync', 'expected', 'remove', 'options/blogname' );
		expect( exitCode( 'axle-config-sync', 'drift' ) ).toBe( 1 );
	} );

	test( 'a daily check is scheduled and a check stores what it found', () => {
		expect( wp( 'cron', 'event', 'list', '--hook=axle_config_sync_drift_check', '--format=count' ) ).toBe( '1' );

		wp( 'option', 'update', 'blogname', 'Changed on production' );
		expect( output( 'axle-config-sync', 'drift', '--check', '--format=count' ) ).toBe( '1' );
		const stored = option( 'axle_config_sync_drift' );
		expect( Object.keys( stored.items ) ).toEqual( [ 'options/blogname' ] );
		expect( stored.via ).toBe( 'cli' );
		expect( stored.checked_at ).toBeGreaterThan( 0 );
	} );

	test( 'the drift tab says who changed a setting, and keeps the live value in the file', async ( { page } ) => {
		changeAsAdmin( 'blogname', 'Changed by the admin' );

		await page.goto( `${ SCREEN }&tab=drift` );
		const row = driftRow( page, 'options/blogname' );
		await expect( row ).toContainText( 'by admin' );
		await expect( row.locator( '.axle-diff del' ) ).toContainText( 'E2E' );
		await expect( row.locator( '.axle-diff ins' ) ).toContainText( 'Changed by the admin' );
		await expect( row.locator( 'time.axle-time' ) ).toHaveCount( 1 );

		await row.getByRole( 'button', { name: 'Write to file' } ).click();
		await expect( row.locator( '.axle-confirm' ) ).toBeFocused();
		await expect( row.locator( '.axle-confirm' ) ).toContainText( 'Nothing changes on the site' );
		await row.getByRole( 'button', { name: 'Write this file' } ).click();

		await expect( page.locator( '.acs-notice' ) ).toContainText( 'Wrote 1 file' );
		expect( readFile( 'options/blogname.json' ).value ).toBe( 'Changed by the admin' );
		expect( option( 'blogname' ) ).toBe( 'Changed by the admin' );
		await expect( page.locator( '.acs-drift-list' ) ).toHaveCount( 0 );
		expect( exitCode( 'axle-config-sync', 'drift' ) ).toBe( 0 );
	} );

	test( 'put back from files goes through the preview and can be undone', async ( { page } ) => {
		changeAsAdmin( 'blogname', 'Changed by the admin' );

		await page.goto( `${ SCREEN }&tab=drift` );
		await driftRow( page, 'options/blogname' ).getByRole( 'link', { name: 'Put back from files' } ).click();
		await expect( page.locator( '.acs-preview-item[data-item="options/blogname"]' ) ).toContainText( 'Will be applied' );
		await page.locator( '#acs-apply' ).click();
		await page.locator( '#acs-apply-now' ).click();

		await expect( page.locator( '.acs-notice' ) ).toContainText( 'Applied 1 item' );
		expect( option( 'blogname' ) ).toBe( 'E2E' );
		expect( exitCode( 'axle-config-sync', 'drift' ) ).toBe( 0 );
	} );

	test( 'a change made by an apply is not drift and not recorded as someone\'s change', () => {
		wp( 'axle-config-sync', 'export', 'blogname' );
		const file = readFile( 'options/blogname.json' );
		file.value = 'From git';
		wpEval( `file_put_contents( WP_CONTENT_DIR . "/axle-config/options/blogname.json", base64_decode( "${ Buffer.from( JSON.stringify( file, null, 4 ) ).toString( 'base64' ) }" ) );` );

		wp( 'axle-config-sync', 'apply', 'blogname', '--yes' );

		expect( option( 'blogname' ) ).toBe( 'From git' );
		expect( exitCode( 'axle-config-sync', 'drift' ) ).toBe( 0 );
		const actors = option( 'axle_config_sync_drift_actors' ) || {};
		expect( Object.keys( actors ) ).not.toContain( 'options/blogname' );
	} );

	test( 'expect this change moves an item to the expected list and back', async ( { page } ) => {
		wp( 'option', 'update', 'blogdescription', 'A new tagline' );

		await page.goto( `${ SCREEN }&tab=drift` );
		await driftRow( page, 'options/blogdescription' ).getByRole( 'button', { name: 'Expect this change' } ).click();
		await expect( page.locator( '.acs-notice' ) ).toContainText( 'Added to the expected list' );
		await expect( page.locator( '.acs-expected-list' ) ).toContainText( 'options/blogdescription' );
		expect( exitCode( 'axle-config-sync', 'drift' ) ).toBe( 0 );

		await page.getByRole( 'button', { name: 'Take off the list' } ).click();
		await expect( page.locator( '.acs-notice' ) ).toContainText( 'Taken off the expected list' );
		await expect( driftRow( page, 'options/blogdescription' ) ).toBeVisible();
		expect( exitCode( 'axle-config-sync', 'drift' ) ).toBe( 1 );
	} );

	test( 'admins see a notice that they can dismiss until something else drifts', async ( { page } ) => {
		wp( 'option', 'update', 'blogname', 'Changed on production' );
		output( 'axle-config-sync', 'drift', '--check' );

		await page.goto( '/wp-admin/index.php' );
		const notice = page.locator( '.axle-drift-notice' );
		await expect( notice ).toContainText( '1 setting was changed on this site after its file was written (options/blogname)' );
		await expect( notice.getByRole( 'link', { name: 'Review the changes' } ) ).toHaveAttribute( 'href', /tab=drift/ );

		await notice.getByRole( 'link', { name: 'Dismiss until something else changes' } ).click();
		await expect( page.locator( '.axle-drift-notice' ) ).toHaveCount( 0 );
		await page.goto( '/wp-admin/index.php' );
		await expect( page.locator( '.axle-drift-notice' ) ).toHaveCount( 0 );

		wp( 'option', 'update', 'blogdescription', 'Another change' );
		output( 'axle-config-sync', 'drift', '--check' );
		await page.goto( '/wp-admin/index.php' );
		await expect( page.locator( '.axle-drift-notice' ) ).toContainText( '2 settings were changed' );
	} );

	test( 'email and webhook stay off until switched on, then carry the change with secrets masked', async ( { page } ) => {
		wp( 'option', 'update', 'acme_settings', JSON.stringify( { mode: 'live', api_key: 'sk_live_51Hc8xQ2vR8sT4wK1zN6bY3c' } ), '--format=json' );
		wp( 'axle-config-sync', 'track', 'acme_*' );
		wp( 'axle-config-sync', 'export', 'acme_settings' );

		wp( 'option', 'update', 'blogname', 'First change' );
		output( 'axle-config-sync', 'drift', '--check' );
		expect( option( 'acs_fixture_mail' ) ).toBeNull();
		expect( option( 'acs_fixture_hook' ) ).toBeNull();

		await page.goto( `${ SCREEN }&tab=drift` );
		await page.getByLabel( 'Email me when new drift is found' ).check();
		await page.getByLabel( 'Send to' ).fill( 'ops@example.com' );
		await page.getByLabel( 'Webhook' ).fill( 'https://8.8.8.8/acs-hook' );
		await page.getByRole( 'button', { name: 'Save alerts' } ).click();
		await expect( page.locator( '.acs-notice' ) ).toContainText( 'Drift alerts saved' );
		expect( option( 'axle_config_sync_settings' ).drift ).toMatchObject( { email: true, email_to: 'ops@example.com', webhook: 'https://8.8.8.8/acs-hook' } );

		wp( 'option', 'update', 'acme_settings', JSON.stringify( { mode: 'test', api_key: 'sk_live_51Hc8xQ2vR8sT4wK1zN6bY3c' } ), '--format=json' );
		output( 'axle-config-sync', 'drift', '--check' );

		const mail = option( 'acs_fixture_mail' );
		expect( mail.to ).toBe( 'ops@example.com' );
		expect( mail.message ).toContain( 'options/acme_settings' );
		expect( mail.message ).toContain( 'Site:' );
		expect( mail.message ).not.toContain( 'sk_live' );
		const hook = option( 'acs_fixture_hook' );
		expect( hook.url ).toBe( 'https://8.8.8.8/acs-hook' );
		const payload = JSON.parse( hook.body );
		expect( payload.event ).toBe( 'drift_detected' );
		expect( payload.items.map( ( item ) => item.id ) ).toEqual( [ 'options/acme_settings' ] );
		expect( hook.body ).not.toContain( 'sk_live' );
		expect( hook.body ).toContain( 'ACME_SETTINGS_API_KEY' );
	} );

	test( 'Site Health reports drift and the screen and REST route agree', async ( { page } ) => {
		const health = 'echo ( new AryanX\\Axle\\ConfigSync\\Health\\SiteHealth( AryanX\\Axle\\ConfigSync\\Plugin::instance()->engine(), AryanX\\Axle\\ConfigSync\\Plugin::instance()->drift() ) )->test_drift()["status"];';
		wp( 'option', 'update', 'blogname', 'Changed on production' );
		output( 'axle-config-sync', 'drift', '--check' );
		expect( wpEval( health ) ).toBe( 'recommended' );

		await page.goto( `${ SCREEN }&tab=drift` );
		await expect( page.locator( '.nav-tab-active' ) ).toContainText( 'Drift' );
		await expect( page.locator( '.nav-tab-active .axle-badge' ) ).toHaveText( '1' );

		const client = await appPasswordClient( 'admin', 'acs-drift-rest' );
		try {
			const rest = await client.call( 'GET', '/axle-config-sync/v1/drift' );
			expect( rest.status ).toBe( 200 );
			expect( rest.body.items.map( ( item ) => item.id ) ).toEqual( [ 'options/blogname' ] );
			expect( rest.body.items[ 0 ].after ).toBe( 'Changed on production' );
		} finally {
			await client.dispose();
		}

		wp( 'axle-config-sync', 'export', 'blogname' );
		output( 'axle-config-sync', 'drift', '--check' );
		expect( wpEval( health ) ).toBe( 'good' );
	} );

	test( 'the ability is read-only and an editor cannot use the route or the ability', async ( { browser } ) => {
		wp( 'option', 'update', 'blogname', 'Changed on production' );
		if ( ! succeeds( 'user', 'get', 'acs-editor' ) ) {
			wp( 'user', 'create', 'acs-editor', 'acs-editor@example.com', '--role=editor', '--user_pass=editor-pass-123' );
		}
		const { context } = await logInAs( browser, 'acs-editor', 'editor-pass-123' );
		const editor = await appPasswordClient( 'acs-editor', 'acs-editor-drift' );
		const admin = await appPasswordClient( 'admin', 'acs-admin-drift' );
		try {
			expect( ( await editor.call( 'GET', '/axle-config-sync/v1/drift' ) ).status ).toBe( 403 );
			if ( hasAbilities() ) {
				expect( ( await editor.call( 'GET', '/wp-abilities/v1/abilities/axle-config-sync/get-drift/run' ) ).status ).toBe( 403 );
				const ok = await admin.call( 'GET', '/wp-abilities/v1/abilities/axle-config-sync/get-drift/run' );
				expect( ok.status ).toBe( 200 );
				expect( ok.body.items.map( ( item ) => item.id ) ).toEqual( [ 'options/blogname' ] );
				const post = await admin.call( 'POST', '/wp-abilities/v1/abilities/axle-config-sync/get-drift/run', { input: {} } );
				expect( [ 400, 404, 405 ] ).toContain( post.status );
			}
		} finally {
			await editor.dispose();
			await admin.dispose();
			await context.close();
			wp( 'user', 'delete', 'acs-editor', '--yes', '--reassign=1' );
		}
	} );

	test( 'on nginx the screen and Site Health say what to add to the server config', async ( { page } ) => {
		wp( 'option', 'update', 'acs_fixture_server', 'nginx' );
		wpEval( 'unlink( WP_CONTENT_DIR . "/axle-config/.htaccess" ); delete_transient( "axle_config_sync_reachable" );' );

		await page.goto( SCREEN );
		const warning = page.locator( '.acs-webroot-reachable' );
		await expect( warning ).toContainText( 'nginx ignores the .htaccess file' );
		await expect( warning.locator( 'pre' ) ).toContainText( 'location ^~ /wp-content/axle-config/' );
		// The CLI container cannot reach the site on its public URL, so the probe may not get through there: both answers say what to add.
		const served = wpEval( 'echo ( new AryanX\\Axle\\ConfigSync\\Health\\SiteHealth( AryanX\\Axle\\ConfigSync\\Plugin::instance()->engine(), AryanX\\Axle\\ConfigSync\\Plugin::instance()->drift() ) )->test_served()["status"];' );
		expect( [ 'critical', 'recommended' ] ).toContain( served );

		// A fixed server config is found by "Check again" (the .htaccess stands in for it here).
		wp( 'axle-config-sync', 'export', 'blogname' );
		await page.getByRole( 'button', { name: 'Check again' } ).click();
		await expect( page.locator( '.acs-webroot-ok' ) ).toContainText( 'Protected' );
	} );
} );

test.describe( 'WP-CFM import', () => {
	const BUNDLE = {
		blogname: 'Bean There',
		acme_list: 'a:2:{i:0;s:1:"a";i:1;s:1:"b";}',
		siteurl: 'http://localhost',
		acme_object: 'O:8:"stdClass":1:{s:1:"x";i:1;}',
		widget_text: 'a:0:{}',
	};

	/** md5 of a WP-CFM file, to prove it is not touched. */
	const cfmHash = () => wpEval( 'echo md5_file( WP_CONTENT_DIR . "/config/main.json" );' );

	test.beforeAll( () => {
		installFixture();
		resetSync();
	} );

	test.beforeEach( () => {
		writeWpCfmBundle( 'main', BUNDLE, 'Main settings' );
		wp( 'option', 'update', 'wpcfm_settings', JSON.stringify( { bundles: [ { label: 'Main settings', name: 'main', config: Object.keys( BUNDLE ) }, { label: 'Later', name: 'later', config: [ 'posts_per_page' ] } ] } ) );
	} );

	test.afterEach( () => {
		resetSync();
	} );

	test.afterAll( () => {
		removeFixture();
	} );

	test( 'a dry run maps each item, says why one is left out and writes nothing', () => {
		const rows = JSON.parse( wp( 'axle-config-sync', 'import', 'wp-cfm', '--dry-run', '--format=json' ).replace( /Success:.*$/s, '' ) );
		const byKey = {};
		rows.forEach( ( row ) => {
			byKey[ `${ row.bundle }/${ row.wpcfm }` ] = row;
		} );

		expect( byKey[ 'main/blogname' ] ).toMatchObject( { item: 'options/blogname', result: 'new' } );
		expect( byKey[ 'main/acme_list' ] ).toMatchObject( { item: 'options/acme_list', result: 'new' } );
		expect( byKey[ 'main/siteurl' ] ).toMatchObject( { item: '', result: 'skip' } );
		expect( byKey[ 'main/siteurl' ].reason ).toContain( 'never syncs' );
		expect( byKey[ 'main/acme_object' ] ).toMatchObject( { result: 'skip' } );
		expect( byKey[ 'main/acme_object' ].reason ).toContain( 'PHP object' );
		expect( byKey[ 'main/widget_text' ].reason ).toContain( 'per sidebar' );
		expect( byKey[ 'later/posts_per_page' ] ).toMatchObject( { item: 'options/posts_per_page', result: 'new' } );

		expect( readFile( 'options/blogname.json' ) ).toBeNull();
		expect( readFile( 'options/acme_list.json' ) ).toBeNull();
	} );

	test( 'the import writes Config Sync files, tracks plugin options and changes nothing else', () => {
		const before = cfmHash();
		wp( 'option', 'update', 'blogname', 'E2E' );

		const result = wp( 'axle-config-sync', 'import', 'wp-cfm', '--bundle=main' );

		expect( result ).toContain( 'The site and the WP-CFM files were not changed' );
		expect( readFile( 'options/blogname.json' ).value ).toBe( 'Bean There' );
		expect( readFile( 'options/acme_list.json' ).value ).toEqual( [ 'a', 'b' ] );
		expect( readFile( 'options/siteurl.json' ) ).toBeNull();
		expect( readFile( 'options/acme_object.json' ) ).toBeNull();
		expect( readFile( 'config-sync.json' ).options ).toContain( 'acme_list' );
		expect( cfmHash() ).toBe( before );
		expect( option( 'blogname' ) ).toBe( 'E2E' );
		expect( option( 'acme_list' ) ).toBeNull();
		expect( output( 'axle-config-sync', 'status', '--state=changed_in_files', '--format=ids' ) ).toContain( 'options/blogname' );
	} );

	test( 'a serialized object is left out and never loaded', () => {
		wp( 'axle-config-sync', 'import', 'wp-cfm', '--bundle=main' );
		expect( readFile( 'options/acme_object.json' ) ).toBeNull();
		expect( wp( 'axle-config-sync', 'import', 'wp-cfm', '--bundle=main', '--dry-run', '--format=csv' ) ).toContain( 'PHP object' );
	} );

	test( 'the Import tab previews the mapping, asks first, then writes files', async ( { page } ) => {
		const before = cfmHash();
		await page.goto( `${ SCREEN }&tab=import` );
		await expect( page.locator( '.acs-bundles' ) ).toContainText( 'Main settings' );
		await expect( page.locator( '.acs-bundles' ) ).toContainText( 'Files and database' );
		await page.getByRole( 'button', { name: 'Preview the import' } ).click();

		const plan = page.locator( '.acs-import-plan' );
		await expect( plan.locator( 'tr[data-action="new"]' ).first() ).toBeVisible();
		await expect( plan.locator( 'tr', { hasText: 'acme_object' } ) ).toContainText( 'Left out' );
		await expect( plan.locator( 'tr', { hasText: 'acme_object' } ) ).toContainText( 'PHP object' );
		expect( readFile( 'options/blogname.json' ) ).toBeNull();

		await page.locator( '#acs-import-start' ).click();
		await expect( page.locator( '#acs-import-confirm' ) ).toBeFocused();
		await expect( page.locator( '#acs-import-confirm' ) ).toContainText( 'Nothing changes on the site' );
		await page.getByRole( 'button', { name: 'Write the files', exact: true } ).click();

		await expect( page.locator( '.acs-notice' ) ).toContainText( 'Nothing was applied to this site' );
		expect( readFile( 'options/blogname.json' ).value ).toBe( 'Bean There' );
		expect( cfmHash() ).toBe( before );
	} );

	test( 'the ability previews without writing, and an editor cannot import', async ( { browser } ) => {
		test.skip( ! hasAbilities(), 'The Abilities API needs WordPress 6.9.' );
		const admin = await appPasswordClient( 'admin', 'acs-import-admin' );
		try {
			const preview = await admin.call( 'GET', '/wp-abilities/v1/abilities/axle-config-sync/preview-wp-cfm-import/run?input[bundles][]=main' );
			expect( preview.status ).toBe( 200 );
			expect( preview.body.items.find( ( item ) => 'blogname' === item.key ).id ).toBe( 'options/blogname' );
			expect( preview.body.values ).toBeUndefined();
			expect( readFile( 'options/blogname.json' ) ).toBeNull();
		} finally {
			await admin.dispose();
		}

		if ( ! succeeds( 'user', 'get', 'acs-editor' ) ) {
			wp( 'user', 'create', 'acs-editor', 'acs-editor@example.com', '--role=editor', '--user_pass=editor-pass-123' );
		}
		const { context, page } = await logInAs( browser, 'acs-editor', 'editor-pass-123' );
		const editor = await appPasswordClient( 'acs-editor', 'acs-editor-import' );
		try {
			expect( ( await page.goto( `${ SCREEN }&tab=import` ) ).status() ).toBe( 403 );
			expect( ( await editor.call( 'POST', '/wp-abilities/v1/abilities/axle-config-sync/import-wp-cfm/run', { input: {} } ) ).status ).toBe( 403 );
			expect( readFile( 'options/blogname.json' ) ).toBeNull();
		} finally {
			await editor.dispose();
			await context.close();
			wp( 'user', 'delete', 'acs-editor', '--yes', '--reassign=1' );
		}
	} );
} );
