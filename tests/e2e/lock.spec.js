// "Managed in git, locked on production": a save of a setting git owns is refused on
// production, "Change it here anyway" is time-limited and logged, and the lock never
// stops WP-CLI, deploys or the plugin's own apply. Every test checks the database too.
const { test, expect, wp, wpEval, logInAs, appPasswordClient } = require( './utils' );
const { succeeds, installFixture, removeFixture, resetSync, setConstants, setFileValue, option } = require( './seed' );

const SCREEN = '/wp-admin/options-general.php?page=axle-config-sync';
const GENERAL = '/wp-admin/options-general.php';
const READ_ONLY_MODE = 'https://github.com/aryanjasala/axle-read-only-mode/releases/download/v3.0.0/axle-read-only-mode.zip';

/** The lock status as JSON, from WP-CLI. */
const lockStatus = () => JSON.parse( wp( 'axle-config-sync', 'lock', 'status', '--format=json' ) );

/** Saves the site title on Settings > General. */
async function saveTitle( page, title ) {
	await page.goto( GENERAL );
	await page.locator( '#blogname' ).fill( title );
	await page.locator( '#submit' ).click();
}

test.describe( 'Managed in git, locked on production', () => {
	test.beforeAll( () => {
		installFixture();
	} );

	test.beforeEach( () => {
		resetSync();
		wp( 'axle-config-sync', 'export', 'blogname', 'blogdescription' );
	} );

	test.afterEach( () => {
		resetSync();
	} );

	test.afterAll( () => {
		removeFixture();
	} );

	test( 'a managed setting says so on its screen, and a save is refused with a notice', async ( { page } ) => {
		await page.goto( GENERAL );
		await expect( page.locator( '.axle-managed-badge' ) ).toHaveCount( 2 );
		await expect( page.locator( '#blogname' ) ).toHaveAttribute( 'aria-describedby', /axle-managed-\d+/ );
		await expect( page.locator( '.axle-managed-summary' ) ).toContainText( 'Managed in git' );

		await saveTitle( page, 'Typed on production' );
		await expect( page.locator( '.axle-lock-notice' ) ).toContainText( 'Not saved' );
		await expect( page.locator( '.axle-lock-notice' ) ).toContainText( 'blogname' );
		expect( option( 'blogname' ) ).toBe( 'E2E' );

		// A setting git does not own saves as usual.
		await page.goto( GENERAL );
		await page.locator( '#new_admin_email' ).fill( 'ops@example.com' );
		await page.locator( '#submit' ).click();
		await expect( page.locator( '.axle-lock-notice' ) ).toHaveCount( 0 );
	} );

	test( 'Change it here anyway opens a window, is logged and stays out of drift until a deploy', async ( { page } ) => {
		await saveTitle( page, 'Typed on production' );
		await page.locator( '.axle-lock-notice' ).getByRole( 'button', { name: 'Change it here anyway' } ).click();
		await expect( page.locator( '.axle-lock-notice' ) ).toContainText( 'You can change blogname here until' );
		expect( lockStatus().overrides.map( ( open ) => open.option ) ).toEqual( [ 'blogname' ] );

		await saveTitle( page, 'Typed on production' );
		expect( option( 'blogname' ) ).toBe( 'Typed on production' );
		expect( lockStatus().released ).toEqual( [ 'options/blogname' ] );
		expect( wp( 'axle-config-sync', 'expected', 'list', '--format=ids' ) ).toContain( 'options/blogname' );
		expect( wp( 'axle-config-sync', 'drift', '--format=count' ) ).toBe( '0' );

		// The next deploy puts the file value back and the setting is managed again.
		wp( 'axle-config-sync', 'apply', 'blogname', '--yes' );
		expect( option( 'blogname' ) ).toBe( 'E2E' );
		expect( lockStatus().released ).toEqual( [] );

		await page.goto( SCREEN + '&tab=track' );
		await expect( page.locator( '.acs-lock' ) ).toContainText( 'Managed settings' );
	} );

	test( 'the window ends, and only the person who chose it can save in it', async () => {
		wp( 'axle-config-sync', 'lock', 'status' );
		wpEval( 'update_option( "axle_config_sync_lock_overrides", array( "blogname" => array( "until" => time() - 5, "user" => 1, "at" => time() - 1800 ) ), false );' );

		const client = await appPasswordClient( 'admin', 'acs-lock-expired' );
		try {
			const refused = await client.call( 'POST', '/wp/v2/settings', { title: 'Too late' } );
			expect( refused.status ).toBe( 403 );
			expect( refused.body.code ).toBe( 'axle_config_sync_managed' );
			expect( option( 'blogname' ) ).toBe( 'E2E' );

			const granted = await client.call( 'POST', '/axle-config-sync/v1/lock/override', { option: 'blogname' } );
			expect( granted.status ).toBe( 200 );
			expect( granted.body.message ).toContain( 'You can change blogname here until' );
			const saved = await client.call( 'POST', '/wp/v2/settings', { title: 'In the window' } );
			expect( saved.status ).toBe( 200 );
			expect( option( 'blogname' ) ).toBe( 'In the window' );

			// Someone else with the same capability is still locked out.
			if ( ! succeeds( 'user', 'get', 'acs-lock-admin2' ) ) {
				wp( 'user', 'create', 'acs-lock-admin2', 'acs-lock2@example.com', '--role=administrator', '--user_pass=admin2-pass-123' );
			}
			const second = await appPasswordClient( 'acs-lock-admin2', 'acs-lock-second' );
			try {
				const other = await second.call( 'POST', '/wp/v2/settings', { title: 'Someone else' } );
				expect( other.status ).toBe( 403 );
				expect( option( 'blogname' ) ).toBe( 'In the window' );
			} finally {
				await second.dispose();
				wp( 'user', 'delete', 'acs-lock-admin2', '--yes', '--reassign=1' );
			}
		} finally {
			await client.dispose();
		}
	} );

	test( 'the REST refusal carries the wall data the editor turns into a notice', async () => {
		const client = await appPasswordClient( 'admin', 'acs-lock-wall' );
		try {
			const response = await client.call( 'POST', '/wp/v2/settings', { title: 'Typed in the editor' } );
			expect( response.status ).toBe( 403 );
			expect( response.body.data.axle_wall ).toMatchObject( {
				plugin: 'axle-config-sync',
				capability: 'manage_options',
				action_label: 'Change it here anyway',
				request_route: 'axle-config-sync/v1/lock/override',
				request_data: { option: 'blogname' },
			} );
		} finally {
			await client.dispose();
		}
	} );

	test( 'someone without the owning capability cannot choose Change it here anyway', async ( { browser } ) => {
		if ( ! succeeds( 'user', 'get', 'acs-lock-editor' ) ) {
			wp( 'user', 'create', 'acs-lock-editor', 'acs-lock-editor@example.com', '--role=editor', '--user_pass=editor-pass-123' );
		}
		const { context } = await logInAs( browser, 'acs-lock-editor', 'editor-pass-123' );
		const client = await appPasswordClient( 'acs-lock-editor', 'acs-lock-editor-app' );
		try {
			const response = await client.call( 'POST', '/axle-config-sync/v1/lock/override', { option: 'blogname' } );
			expect( response.status ).toBe( 403 );
			expect( lockStatus().overrides ).toEqual( [] );
		} finally {
			await client.dispose();
			await context.close();
			wp( 'user', 'delete', 'acs-lock-editor', '--yes', '--reassign=1' );
		}
	} );

	test( 'WP-CLI, the plugin\'s own apply and a deploy are never blocked', () => {
		wp( 'option', 'update', 'blogname', 'Changed by WP-CLI' );
		expect( option( 'blogname' ) ).toBe( 'Changed by WP-CLI' );
		wp( 'axle-config-sync', 'export', 'blogname' );

		setFileValue( 'options/blogname', 'From git' );
		wp( 'axle-config-sync', 'apply', 'blogname', '--yes' );
		expect( option( 'blogname' ) ).toBe( 'From git' );
		expect( lockStatus().locking ).toBe( true );
	} );

	test( 'the screen apply and undo are not blocked either', async ( { page } ) => {
		setFileValue( 'options/blogname', 'From git' );
		await page.goto( SCREEN );
		await page.locator( 'input[name="items[]"][value="options/blogname"]' ).check();
		await page.getByLabel( 'Bulk actions' ).selectOption( 'apply' );
		await page.locator( '#acs-bulk-go' ).click();
		await page.locator( '#acs-apply' ).click();
		await page.locator( '#acs-apply-now' ).click();
		await expect( page.locator( '.acs-notice' ) ).toContainText( 'Applied 1 item' );
		expect( option( 'blogname' ) ).toBe( 'From git' );

		await page.getByRole( 'button', { name: 'Undo #1' } ).click();
		await page.locator( '#acs-undo-1 button[type="submit"]' ).click();
		await expect( page.locator( '.acs-notice' ) ).toContainText( 'Undid change set #1' );
		expect( option( 'blogname' ) ).toBe( 'E2E' );
	} );

	test( 'staging, the switch and an unreadable config directory leave settings editable', async ( { page } ) => {
		// Not production.
		setConstants( { WP_ENVIRONMENT_TYPE: 'staging' } );
		await saveTitle( page, 'Staging title' );
		await expect( page.locator( '.axle-lock-notice' ) ).toHaveCount( 0 );
		expect( option( 'blogname' ) ).toBe( 'Staging title' );
		setConstants( {} );

		// The switch on the Track tab.
		wp( 'option', 'update', 'blogname', 'E2E' );
		await page.goto( SCREEN + '&tab=track' );
		await page.getByLabel( 'Refuse saves of managed settings on production' ).uncheck();
		await page.getByRole( 'button', { name: 'Save lock settings' } ).click();
		await expect( page.locator( '.acs-notice' ) ).toContainText( 'Lock settings saved' );
		await saveTitle( page, 'Switch is off' );
		expect( option( 'blogname' ) ).toBe( 'Switch is off' );
		await page.goto( SCREEN + '&tab=track' );
		await page.getByLabel( 'Refuse saves of managed settings on production' ).check();
		await page.getByRole( 'button', { name: 'Save lock settings' } ).click();

		// Fail open: the config directory cannot be read.
		wp( 'option', 'update', 'blogname', 'E2E' );
		wpEval( 'rename( WP_CONTENT_DIR . "/axle-config", WP_CONTENT_DIR . "/axle-config-away" );' );
		try {
			await saveTitle( page, 'Directory gone' );
			await expect( page.locator( '.axle-lock-notice' ) ).toHaveCount( 0 );
			expect( option( 'blogname' ) ).toBe( 'Directory gone' );
		} finally {
			wpEval( 'if ( is_dir( WP_CONTENT_DIR . "/axle-config-away" ) ) { rename( WP_CONTENT_DIR . "/axle-config-away", WP_CONTENT_DIR . "/axle-config" ); }' );
		}
	} );

	test( 'the Customizer marks a managed control', async ( { page } ) => {
		await page.goto( '/wp-admin/customize.php' );
		await page.getByRole( 'heading', { name: 'Site Identity' } ).click();
		await expect( page.locator( '#customize-control-blogname .customize-control-notifications-container li' ).first() ).toContainText( 'Managed in git', { timeout: 30_000 } );
	} );

	test( 'the ability and the command report the lock, and nothing here writes it', async () => {
		const status = lockStatus();
		expect( status.managed ).toEqual( [ 'blogdescription', 'blogname' ] );
		expect( status.environment ).toBe( 'production' );
		expect( wp( 'axle-config-sync', 'lock', 'end' ) ).toContain( 'Ended 0 open overrides' );
	} );

	test.describe( 'with Axle Read-Only Mode', () => {
		let installed = false;
		let wasInstalled = false;

		test.beforeAll( () => {
			wasInstalled = succeeds( 'plugin', 'is-installed', 'axle-read-only-mode' );
			installed = wasInstalled ? succeeds( 'plugin', 'activate', 'axle-read-only-mode' ) : succeeds( 'plugin', 'install', READ_ONLY_MODE, '--activate' );
		} );

		test.afterAll( () => {
			succeeds( 'axle-read-only-mode', 'off' );
			succeeds( 'plugin', 'deactivate', 'axle-read-only-mode' );
			if ( ! wasInstalled ) {
				succeeds( 'plugin', 'delete', 'axle-read-only-mode' );
			}
		} );

		test( 'a freeze means no override is given', async () => {
			test.skip( ! installed, 'Axle Read-Only Mode could not be downloaded.' );
			wp( 'axle-read-only-mode', 'on', '--minutes=0' );
			const client = await appPasswordClient( 'admin', 'acs-lock-frozen' );
			try {
				const response = await client.call( 'POST', '/axle-config-sync/v1/lock/override', { option: 'blogname' } );
				expect( response.status ).not.toBe( 200 );
				expect( lockStatus().overrides ).toEqual( [] );
			} finally {
				await client.dispose();
				wp( 'axle-read-only-mode', 'off' );
			}
		} );
	} );
} );
