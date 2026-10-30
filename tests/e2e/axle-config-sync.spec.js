// End-to-end tests on the plugin's own Docker site. Every test checks the
// outcome in the database through WP-CLI as well as on screen.
const { test, expect, wp, wpEval, logInAs, appPasswordClient } = require( './utils' );
const { succeeds, installFixture, removeFixture, resetSync, setConstants, readFile, writeFile, setFileValue, allFileText, option, hasAbilities } = require( './seed' );

const SCREEN = '/wp-admin/options-general.php?page=axle-config-sync';
const READ_ONLY_MODE = 'https://github.com/aryanjasala/axle-read-only-mode/releases/download/v3.0.0/axle-read-only-mode.zip';

/** Runs WP-CLI and returns its exit code instead of throwing. */
function exitCode( ...args ) {
	try {
		wp( ...args );
		return 0;
	} catch ( error ) {
		return error.status;
	}
}

/** The row of an item on the Status tab. */
const statusRow = ( page, id ) => page.locator( `.acs-items tr[data-item="${ id }"]` );

/** Picks items on the Apply tab and opens the preview. */
async function preview( page, ids ) {
	await page.goto( `${ SCREEN }&tab=apply` );
	for ( const id of ids ) {
		await page.locator( `input[name="items[]"][value="${ id }"]` ).check();
	}
	await page.getByRole( 'button', { name: 'Preview' } ).click();
	await expect( page.getByRole( 'heading', { name: 'Preview' } ) ).toBeVisible();
}

/** Applies from the preview, accepting the confirmation. */
async function applyFromPreview( page ) {
	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await page.locator( '#acs-apply' ).click();
	await expect( page.locator( '.acs-notice' ) ).toBeVisible();
}

test.describe( 'Axle Config Sync', () => {
	test.beforeAll( () => {
		installFixture();
		resetSync();
	} );

	test.afterEach( () => {
		resetSync();
	} );

	test.afterAll( () => {
		removeFixture();
	} );

	test( 'export, status, apply and undo the site title', async ( { page } ) => {
		wp( 'option', 'update', 'blogname', 'Bean There' );

		await page.goto( `${ SCREEN }&tab=export` );
		await page.getByRole( 'button', { name: 'Export all tracked items' } ).click();
		await expect( page.locator( '.acs-notice' ) ).toContainText( 'Wrote' );
		expect( readFile( 'options/blogname.json' ) ).toEqual( { schema: 1, provider: 'options', id: 'options/blogname', owner: 'core', value: 'Bean There' } );
		expect( readFile( 'manifest.json' ).environment ).toBe( 'production' );

		wp( 'option', 'update', 'blogname', 'Edited on the site' );
		await page.goto( SCREEN );
		await expect( statusRow( page, 'options/blogname' ) ).toContainText( 'Changed on site' );
		await expect( page.locator( '.acs-env' ) ).toHaveText( 'production' );

		await preview( page, [ 'options/blogname' ] );
		await expect( page.locator( '.acs-preview-item[data-item="options/blogname"]' ) ).toContainText( 'Will be applied' );
		await applyFromPreview( page );
		await expect( page.locator( '.acs-notice' ) ).toContainText( 'Applied 1 item as change set #1' );
		expect( option( 'blogname' ) ).toBe( 'Bean There' );
		expect( wp( 'db', 'query', 'SELECT via, status, items_count FROM wp_axle_config_sync_changesets WHERE id = 1', '--skip-column-names' ) ).toBe( 'admin\tapplied\t1' );

		await page.getByRole( 'button', { name: 'Undo #1' } ).click();
		await expect( page.locator( '.acs-notice' ) ).toContainText( 'Undid change set #1' );
		expect( option( 'blogname' ) ).toBe( 'Edited on the site' );
		expect( wp( 'db', 'query', 'SELECT status FROM wp_axle_config_sync_changesets WHERE id = 1', '--skip-column-names' ) ).toBe( 'undone' );
	} );

	test( 'page IDs travel as paths and resolve to the new ID', async ( { page } ) => {
		const first = wp( 'post', 'create', '--post_type=page', '--post_title=About', '--post_status=publish', '--porcelain' );
		wp( 'option', 'update', 'show_on_front', 'page' );
		wp( 'option', 'update', 'page_on_front', first );
		wp( 'axle-config-sync', 'export', 'page_on_front', 'show_on_front' );
		expect( readFile( 'options/page_on_front.json' ).value ).toEqual( { $ref: 'post', path: 'about', type: 'page' } );

		wp( 'post', 'delete', first, '--force' );
		const second = wp( 'post', 'create', '--post_type=page', '--post_title=About', '--post_status=publish', '--porcelain' );
		expect( second ).not.toBe( first );
		wp( 'axle-config-sync', 'apply', 'page_on_front', 'show_on_front', '--yes' );
		expect( String( option( 'page_on_front' ) ) ).toBe( second );

		wp( 'post', 'delete', second, '--force' );
		await preview( page, [ 'options/page_on_front' ] );
		const item = page.locator( '.acs-preview-item[data-item="options/page_on_front"]' );
		await expect( item ).toContainText( 'Blocked, will be skipped' );
		await expect( item.locator( '.acs-problems' ) ).toHaveText( 'Page "about" does not exist on this site.' );
		expect( Number( option( 'page_on_front' ) ) ).toBe( 0 );
	} );

	test( 'a classic menu comes back with its items, order, parents and location', async ( { page } ) => {
		const about = wp( 'post', 'create', '--post_type=page', '--post_title=About', '--post_status=publish', '--porcelain' );
		const news = wp( 'term', 'create', 'category', 'News', '--porcelain' );
		wp( 'menu', 'create', 'Main menu' );
		const parent = wp( 'menu', 'item', 'add-post', 'main-menu', about, '--porcelain' );
		wp( 'menu', 'item', 'add-term', 'main-menu', 'category', news );
		wp( 'menu', 'item', 'add-custom', 'main-menu', 'Docs', 'http://localhost:8913/docs', `--parent-id=${ parent }` );
		wp( 'menu', 'location', 'assign', 'main-menu', 'primary' );
		wp( 'axle-config-sync', 'export', 'menus/main-menu', 'menu-locations' );
		expect( readFile( 'menus/main-menu.json' ).value.items[ 2 ] ).toMatchObject( { title: 'Docs', type: 'custom', url: '{{home_url}}/docs', parent: 1 } );
		expect( readFile( 'menu-locations.json' ).value ).toEqual( { primary: { $ref: 'menu', slug: 'main-menu' } } );

		wp( 'menu', 'delete', 'main-menu' );
		await preview( page, [ 'menus/main-menu', 'menu-locations' ] );
		await expect( page.locator( '.acs-preview-item[data-item="menu-locations"]' ) ).toContainText( 'Will be applied' );
		await applyFromPreview( page );

		const items = JSON.parse( wp( 'menu', 'item', 'list', 'main-menu', '--fields=db_id,type,title,object_id,menu_item_parent,position,link', '--format=json' ) );
		expect( items.map( ( item ) => [ item.type, item.title, item.position ] ) ).toEqual( [
			[ 'post_type', 'About', 1 ],
			[ 'taxonomy', 'News', 2 ],
			[ 'custom', 'Docs', 3 ],
		] );
		expect( String( items[ 0 ].object_id ) ).toBe( about );
		expect( String( items[ 1 ].object_id ) ).toBe( news );
		expect( String( items[ 2 ].menu_item_parent ) ).toBe( String( items[ 0 ].db_id ) );
		expect( items[ 2 ].link ).toBe( 'http://localhost:8913/docs' );
		expect( JSON.parse( wp( 'menu', 'list', '--fields=slug,locations', '--format=json' ) ).find( ( m ) => 'main-menu' === m.slug ).locations ).toEqual( [ 'primary' ] );
		wp( 'post', 'delete', about, '--force' );
		wp( 'menu', 'delete', 'main-menu' );
	} );

	test( 'site URLs become placeholders and expand to the target URL', () => {
		wp( 'option', 'update', 'acs_demo_link', 'http://localhost:8913/docs/start' );
		wp( 'axle-config-sync', 'track', 'acs_demo_link' );
		wp( 'axle-config-sync', 'export', 'acs_demo_link' );
		expect( readFile( 'options/acs_demo_link.json' ).value ).toBe( '{{home_url}}/docs/start' );

		wp( 'option', 'update', 'acs_fixture_home', 'https://live.example.com' );
		wp( 'axle-config-sync', 'apply', 'acs_demo_link', '--yes' );
		expect( option( 'acs_demo_link' ) ).toBe( 'https://live.example.com/docs/start' );
	} );

	test( 'secrets stay out of files and resolve from a constant', async ( { page } ) => {
		const key = 'x7Gq9LmP2vR8sT4wK1zN6bY3cH5jD0fQ';
		wp( 'option', 'update', 'acme_api_key', key );
		wp( 'axle-config-sync', 'track', 'acme_api_key' );
		wp( 'axle-config-sync', 'export', '--all' );
		expect( readFile( 'options/acme_api_key.json' ).value ).toEqual( { $secret: 'ACME_API_KEY' } );
		expect( allFileText() ).not.toContain( key );
		expect( exitCode( 'axle-config-sync', 'secrets', 'check' ) ).toBe( 0 );

		// Without the constant the live value is kept, and the screen says why.
		wp( 'option', 'update', 'acme_api_key', 'live-only-value-123' );
		await page.goto( `${ SCREEN }&item=options/acme_api_key` );
		await expect( page.locator( '.acs-warnings' ) ).toContainText( 'Secret ACME_API_KEY is not set on this site, so the live value is kept.' );
		wp( 'axle-config-sync', 'apply', 'acme_api_key', '--yes' );
		expect( option( 'acme_api_key' ) ).toBe( 'live-only-value-123' );

		// The constant changes the file side while the site side moved too: a conflict, so force.
		setConstants( { AXLE_SECRET_ACME_API_KEY: 'from-the-constant-123' } );
		expect( exitCode( 'axle-config-sync', 'apply', 'acme_api_key', '--yes' ) ).toBe( 1 );
		wp( 'axle-config-sync', 'apply', 'acme_api_key', '--yes', '--force' );
		expect( option( 'acme_api_key' ) ).toBe( 'from-the-constant-123' );

		// A secret committed by hand fails the CI check.
		setFileValue( 'options/acme_api_key', key );
		expect( exitCode( 'axle-config-sync', 'secrets', 'check' ) ).toBe( 1 );
	} );

	test( 'an environment file sets values for that environment only', () => {
		setConstants( { AXLE_CONFIG_SYNC_ENV: 'production' } );
		wp( 'axle-config-sync', 'export', 'admin_email' );
		writeFile( 'env/production.json', { schema: 1, values: { 'options/admin_email': { '': 'ops@example.org' } } } );
		expect( wp( 'axle-config-sync', 'env' ) ).toContain( 'AXLE_CONFIG_SYNC_ENV' );

		wp( 'axle-config-sync', 'apply', 'admin_email', '--yes', '--allow-dangerous' );
		expect( option( 'admin_email' ) ).toBe( 'ops@example.org' );
		expect( readFile( 'options/admin_email.json' ).value ).toBe( 'admin@example.com' );

		// Exporting again keeps the shared file's value: production's stays in env/.
		wp( 'axle-config-sync', 'export', 'admin_email' );
		expect( readFile( 'options/admin_email.json' ).value ).toBe( 'admin@example.com' );
	} );

	test( 'dangerous changes need the checkbox on screen and --allow-dangerous on the CLI', async ( { page } ) => {
		wp( 'axle-config-sync', 'export', 'default_role' );
		setFileValue( 'options/default_role', 'administrator' );

		expect( exitCode( 'axle-config-sync', 'apply', 'default_role', '--yes' ) ).toBe( 1 );
		expect( option( 'default_role' ) ).toBe( 'subscriber' );

		await preview( page, [ 'options/default_role' ] );
		await expect( page.locator( '.acs-preview-item[data-item="options/default_role"] .acs-badge-danger' ) ).toBeVisible();
		const box = page.getByLabel( 'I have read this: apply the dangerous changes marked above' );
		await expect( box ).toHaveAttribute( 'required', '' );
		// The browser refuses to submit with the box unticked, before any confirmation.
		await page.locator( '#acs-apply' ).click();
		await expect( page.getByRole( 'heading', { name: 'Preview' } ) ).toBeVisible();
		expect( option( 'default_role' ) ).toBe( 'subscriber' );

		await box.check();
		await applyFromPreview( page );
		expect( option( 'default_role' ) ).toBe( 'administrator' );
	} );

	test( 'a change on both sides is a conflict and needs force', async ( { page } ) => {
		wp( 'axle-config-sync', 'export', 'blogname' );
		wp( 'option', 'update', 'blogname', 'Site side' );
		setFileValue( 'options/blogname', 'File side' );

		await page.goto( `${ SCREEN }&state=conflict` );
		await expect( statusRow( page, 'options/blogname' ) ).toContainText( 'Conflict' );
		expect( exitCode( 'axle-config-sync', 'status' ) ).toBe( 2 );
		expect( exitCode( 'axle-config-sync', 'apply', 'blogname', '--yes' ) ).toBe( 1 );
		expect( option( 'blogname' ) ).toBe( 'Site side' );

		await preview( page, [ 'options/blogname' ] );
		await page.getByLabel( /Apply the items that changed both in the files and on this site/ ).check();
		await applyFromPreview( page );
		expect( option( 'blogname' ) ).toBe( 'File side' );
		expect( exitCode( 'axle-config-sync', 'status' ) ).toBe( 0 );
	} );

	test( 'a serialized PHP object in a file is written as a plain string', () => {
		wp( 'option', 'update', 'acme_payload', 'safe' );
		wp( 'axle-config-sync', 'track', 'acme_payload' );
		wp( 'axle-config-sync', 'export', 'acme_payload' );
		const payload = 'O:8:"WP_Theme":1:{s:4:"name";s:4:"evil";}';
		setFileValue( 'options/acme_payload', payload );

		wp( 'axle-config-sync', 'apply', 'acme_payload', '--yes' );
		expect( wpEval( 'var_export( get_option( "acme_payload" ) );' ) ).toBe( `'${ payload.replace( /'/g, "\\'" ) }'` );
	} );

	test( 'the screen warns when the config directory is served over HTTP', async ( { page } ) => {
		wp( 'axle-config-sync', 'export', 'blogname' );
		await page.goto( SCREEN );
		await expect( page.locator( '.acs-webroot-ok' ) ).toBeVisible();

		wpEval( 'unlink( WP_CONTENT_DIR . "/axle-config/.htaccess" ); delete_transient( "axle_config_sync_reachable" );' );
		await page.goto( SCREEN );
		await expect( page.locator( '.acs-webroot-reachable' ) ).toContainText( 'Anyone can download these files' );
		await expect( page.locator( '.acs-webroot-reachable pre' ) ).toContainText( 'location ^~ /wp-content/axle-config/' );
	} );

	test( 'tracking rules are saved to config-sync.json', async ( { page } ) => {
		wp( 'option', 'update', 'acme_settings', JSON.stringify( { mode: 'live' } ), '--format=json' );
		await page.goto( `${ SCREEN }&tab=track` );
		await page.getByLabel( 'Tracked plugin options' ).fill( 'acme_*=acme' );
		await page.getByLabel( 'Roles' ).uncheck();
		await page.getByRole( 'button', { name: 'Save tracking rules' } ).click();
		await expect( page.locator( '.acs-notice' ) ).toContainText( 'Tracking rules saved to config-sync.json' );

		const rules = readFile( 'config-sync.json' );
		expect( rules.options ).toEqual( [ 'acme_*' ] );
		expect( rules.owners ).toEqual( { 'acme_*': 'acme' } );
		expect( rules.providers.roles ).toBe( false );

		await page.goto( `${ SCREEN }&tab=track&s=acme` );
		await expect( page.locator( '.acs-options-list' ) ).toContainText( 'acme_settings' );
		const ids = wp( 'axle-config-sync', 'status', '--format=ids' ).split( ' ' );
		expect( ids ).toContain( 'options/acme_settings' );
		expect( ids.some( ( id ) => id.startsWith( 'roles/' ) ) ).toBe( false );
	} );

	test( 'the JavaScript hooks shape the preview and see the apply', async ( { page } ) => {
		wp( 'axle-config-sync', 'export', 'blogname' );
		wp( 'option', 'update', 'blogname', 'Changed' );
		await page.addInitScript( () => {
			document.addEventListener( 'DOMContentLoaded', () => {
				window.wp.hooks.addFilter( 'axleConfigSync.previewWarnings', 'test', ( list ) => [ ...list, 'Added by a filter' ] );
				window.wp.hooks.addFilter( 'axleConfigSync.diffRenderer', 'test', ( html, context ) => `<p class="custom-diff">${ context.item }</p>` );
				window.wp.hooks.addAction( 'axleConfigSync.applied', 'test', ( data ) => {
					window.acsApplied = data.changeset;
				} );
			} );
		} );

		await preview( page, [ 'options/blogname' ] );
		const item = page.locator( '.acs-preview-item[data-item="options/blogname"]' );
		await expect( item.locator( '.acs-warnings' ) ).toContainText( 'Added by a filter' );
		await expect( item.locator( '.custom-diff' ) ).toHaveText( 'options/blogname' );

		await page.evaluate( () => window.wp.hooks.addFilter( 'axleConfigSync.confirmMessage', 'test', () => 'Custom confirmation' ) );
		let message = '';
		page.once( 'dialog', ( dialog ) => {
			message = dialog.message();
			dialog.accept();
		} );
		await page.locator( '#acs-apply' ).click();
		await expect( page.locator( '.acs-notice' ) ).toContainText( 'Applied' );
		expect( message ).toBe( 'Custom confirmation' );
		expect( await page.evaluate( () => window.acsApplied ) ).toBe( 1 );
		expect( option( 'blogname' ) ).toBe( 'E2E' );
	} );

	test( 'abilities report status, apply and undo without showing secrets', async () => {
		test.skip( ! hasAbilities(), 'The Abilities API needs WordPress 6.9.' );
		wp( 'option', 'update', 'acme_api_key', 'x7Gq9LmP2vR8sT4wK1zN6bY3cH5jD0fQ' );
		wp( 'axle-config-sync', 'track', 'acme_api_key' );
		wp( 'axle-config-sync', 'export', '--all' );
		wp( 'option', 'update', 'blogname', 'Changed by hand' );

		const client = await appPasswordClient( 'admin', 'acs-agent' );
		try {
			const status = await client.call( 'GET', '/wp-abilities/v1/abilities/axle-config-sync/get-status/run?input[state]=changed_on_site' );
			expect( status.status ).toBe( 200 );
			expect( status.body.items.map( ( item ) => item.id ) ).toEqual( [ 'options/blogname' ] );

			const diff = await client.call( 'GET', '/wp-abilities/v1/abilities/axle-config-sync/get-item-diff/run?input[item]=options/acme_api_key' );
			expect( diff.body.site_value ).toEqual( { $secret: 'ACME_API_KEY' } );
			expect( JSON.stringify( diff.body ) ).not.toContain( 'x7Gq9' );

			const applied = await client.call( 'POST', '/wp-abilities/v1/abilities/axle-config-sync/apply-config/run', { input: { items: [ 'options/blogname' ] } } );
			expect( applied.status ).toBe( 200 );
			expect( option( 'blogname' ) ).toBe( 'E2E' );
			expect( wp( 'db', 'query', `SELECT via FROM wp_axle_config_sync_changesets WHERE id = ${ applied.body.changeset }`, '--skip-column-names' ) ).toBe( 'ability' );

			const undone = await client.call( 'POST', '/wp-abilities/v1/abilities/axle-config-sync/undo-apply/run', { input: { changeset: applied.body.changeset } } );
			expect( undone.status ).toBe( 200 );
			expect( option( 'blogname' ) ).toBe( 'Changed by hand' );

			setFileValue( 'options/default_role', 'administrator' );
			const dangerous = await client.call( 'POST', '/wp-abilities/v1/abilities/axle-config-sync/apply-config/run', { input: { items: [ 'options/default_role' ] } } );
			expect( dangerous.body.code ).toBe( 'axle_config_sync_dangerous' );
			expect( option( 'default_role' ) ).toBe( 'subscriber' );
		} finally {
			await client.dispose();
		}
	} );

	test( 'an editor gets 403 on the screen, the REST route and the abilities', async ( { browser } ) => {
		if ( ! succeeds( 'user', 'get', 'acs-editor' ) ) {
			wp( 'user', 'create', 'acs-editor', 'acs-editor@example.com', '--role=editor', '--user_pass=editor-pass-123' );
		}
		const { context, page } = await logInAs( browser, 'acs-editor', 'editor-pass-123' );
		const client = await appPasswordClient( 'acs-editor', 'acs-editor-app' );
		try {
			const response = await page.goto( SCREEN );
			expect( response.status() ).toBe( 403 );

			const rest = await client.call( 'GET', '/axle-config-sync/v1/status' );
			expect( rest.status ).toBe( 403 );
			const apply = await client.call( 'POST', '/axle-config-sync/v1/apply', { items: [ 'options/blogname' ] } );
			expect( apply.status ).toBe( 403 );

			if ( hasAbilities() ) {
				const ability = await client.call( 'GET', '/wp-abilities/v1/abilities/axle-config-sync/get-status/run' );
				expect( ability.status ).toBe( 403 );
			}
			expect( Number( wp( 'db', 'query', 'SELECT COUNT(*) FROM wp_axle_config_sync_changesets', '--skip-column-names' ) ) ).toBe( 0 );
		} finally {
			await client.dispose();
			await context.close();
			wp( 'user', 'delete', 'acs-editor', '--yes', '--reassign=1' );
		}
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
			// Only remove what this suite downloaded; a mounted copy is someone's source.
			if ( ! wasInstalled ) {
				succeeds( 'plugin', 'delete', 'axle-read-only-mode' );
			}
		} );

		test( 'a freeze refuses apply on the screen, the CLI and the ability', async ( { page } ) => {
			test.skip( ! installed, 'Axle Read-Only Mode could not be downloaded.' );
			wp( 'axle-config-sync', 'export', 'blogname' );
			setFileValue( 'options/blogname', 'From git' );

			wp( 'axle-read-only-mode', 'on', '--minutes=0' );
			try {
				expect( exitCode( 'axle-config-sync', 'apply', 'blogname', '--yes' ) ).toBe( 1 );

				await page.goto( `${ SCREEN }&tab=apply` );
				await expect( page.locator( '.acs-page' ) ).toContainText( 'Axle Read-Only Mode has frozen this site' );
				await page.locator( 'input[name="items[]"][value="options/blogname"]' ).check();
				await page.getByRole( 'button', { name: 'Preview' } ).click();
				// The freeze stops the form itself.
				await expect( page.locator( 'body' ) ).toContainText( 'frozen' );

				const rest = await appPasswordClient( 'admin', 'acs-frozen-rest' );
				try {
					const response = await rest.call( 'POST', '/axle-config-sync/v1/apply', { items: [ 'options/blogname' ] } );
					expect( response.status ).toBe( 423 );
				} finally {
					await rest.dispose();
				}

				if ( hasAbilities() ) {
					const client = await appPasswordClient( 'admin', 'acs-frozen' );
					try {
						const ability = await client.call( 'POST', '/wp-abilities/v1/abilities/axle-config-sync/apply-config/run', { input: { items: [ 'options/blogname' ] } } );
						expect( ability.status ).toBe( 423 );
					} finally {
						await client.dispose();
					}
				}
			} finally {
				wp( 'axle-read-only-mode', 'off' );
			}

			expect( option( 'blogname' ) ).toBe( 'E2E' );
			expect( Number( wp( 'db', 'query', 'SELECT COUNT(*) FROM wp_axle_config_sync_changesets', '--skip-column-names' ) ) ).toBe( 0 );
		} );
	} );
} );
