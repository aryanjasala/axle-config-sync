// Records .github/media/demo.gif for the README. Run on demand: npm run demo
const { test, expect, wp } = require( './utils' );
const { prepareSite, recordDemo } = require( './utils/media' );
const { seedSite, setFileValue, unseedSite, removeFixture } = require( './seed' );

test( 'demo: a setting changed in git is previewed, applied and undone', async ( { browser } ) => {
	test.setTimeout( 180_000 );
	prepareSite( 'Bean There Coffee' );
	seedSite();
	setFileValue( 'options/blogdescription', 'Small-batch coffee, roasted fresh every week in Pune' );
	setFileValue( 'options/posts_per_page', '12' );

	try {
		await recordDemo( browser, async ( page, demo ) => {
			await page.goto( '/wp-admin/options-general.php?page=axle-config-sync' );
			await expect( page.locator( '.acs-items' ) ).toBeVisible();
			await demo.start();
			await demo.pause( 1500 );

			// Two settings changed in git.
			await demo.click( page.locator( '.acs-count.acs-state-changed_in_files' ), 300 );
			await demo.pause( 1500 );

			// Pick them and preview.
			await demo.click( page.getByRole( 'link', { name: 'Apply', exact: true } ), 300 );
			await demo.click( page.locator( '[data-acs-select="state:changed_in_files"]' ), 300 );
			await demo.click( page.getByRole( 'button', { name: 'Preview' } ), 300 );
			await expect( page.locator( '.acs-confirm' ) ).toBeVisible();
			await demo.moveTo( page.locator( '.acs-diff' ).first() );
			await demo.pause( 2500 );

			// Apply, then undo from History.
			await demo.click( page.locator( '#acs-apply' ), 300 );
			await expect( page.locator( '.acs-notice' ) ).toContainText( 'Applied' );
			await demo.pause( 2000 );
			await demo.click( page.locator( '.acs-undo-form button' ).first(), 300 );
			await expect( page.locator( '.acs-notice' ) ).toContainText( 'Undid' );
			await demo.pause( 2500 );
		} );
		expect( wp( 'option', 'get', 'posts_per_page' ) ).toBe( '10' );
	} finally {
		unseedSite();
		removeFixture();
	}
} );
