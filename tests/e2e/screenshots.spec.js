// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const { test, expect, wpEval } = require( './utils' );
const { STORAGE_STATE } = require( './utils/config' );
const { prepareSite, shot } = require( './utils/media' );
const { seedSite, driftSite, seedHistory, seedWpCfm, succeeds, unseedSite, removeFixture } = require( './seed' );

const SCREEN = '/wp-admin/options-general.php?page=axle-config-sync';

test.describe( 'Screenshots', () => {
	let context;
	let page;

	test.beforeAll( async ( { browser } ) => {
		test.setTimeout( 120_000 );
		prepareSite( 'Bean There Coffee' );
		seedSite();
		seedHistory();
		driftSite();

		context = await browser.newContext( { storageState: STORAGE_STATE, viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 } );
		page = await context.newPage();
	} );

	test.afterAll( async () => {
		await context.close();
		unseedSite();
		removeFixture();
	} );

	test( '1: status', async () => {
		await page.goto( SCREEN );
		await expect( page.locator( '.acs-items tbody tr' ).first() ).toBeVisible();
		await shot( page, 1 );
	} );

	test( '2: the preview', async () => {
		await page.goto( `${ SCREEN }&tab=apply` );
		for ( const id of [ 'options/blogname', 'options/default_role', 'options/posts_per_page' ] ) {
			await page.locator( `input[name="items[]"][value="${ id }"]` ).check();
		}
		await page.getByRole( 'button', { name: 'Preview' } ).click();
		await expect( page.locator( '.acs-confirm' ) ).toBeVisible();
		await shot( page, 2, { fullPage: true } );
	} );

	test( '3: one item', async () => {
		await page.goto( `${ SCREEN }&item=options/acme_settings` );
		await expect( page.locator( '.acs-diff' ) ).toBeVisible();
		await shot( page, 3 );
	} );

	test( '4: history', async () => {
		await page.goto( `${ SCREEN }&tab=history` );
		await expect( page.locator( '.acs-history' ) ).toBeVisible();
		await shot( page, 4 );
	} );

	test( '5: track', async () => {
		await page.goto( `${ SCREEN }&tab=track` );
		await expect( page.locator( '.acs-options-list' ) ).toBeVisible();
		await shot( page, 5, { fullPage: true } );
	} );

	test( '6: drift', async () => {
		wpEval( 'wp_set_current_user( 1 ); update_option( "blogdescription", "Fresh roast, every morning" );' );
		succeeds( 'axle-config-sync', 'drift', '--check' );
		await page.goto( `${ SCREEN }&tab=drift` );
		await expect( page.locator( '.acs-drift' ) ).toBeVisible();
		await shot( page, 6, { fullPage: true } );
	} );

	test( '7: import', async () => {
		seedWpCfm();
		await page.goto( `${ SCREEN }&tab=import` );
		await page.locator( '#acs-import-preview' ).click();
		await expect( page.locator( '.acs-import-plan' ) ).toBeVisible();
		await shot( page, 7, { fullPage: true } );
	} );
} );
