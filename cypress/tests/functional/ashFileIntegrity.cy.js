/**
 * @file cypress/tests/functional/ashFileIntegrity.cy.js
 *
 * Copyright (c) 2025 AshVisualTheme
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Test for the File Integrity Scanner plugin.
 */

describe('File Integrity Scanner Plugin Tests', function() {
	const pluginName = 'File Integrity Scanner';
	const pluginId = 'ashfileintegrityplugin';

	beforeEach(() => {
		// Login as admin and navigate to the plugins page
		cy.login('admin', 'admin', 'publicknowledge');
		cy.visit('/index.php/publicknowledge/management/settings/website#tabs-plugins');
		cy.get('button#generic-plugins-button').click();
	});

	// Function to open the actions menu for the plugin
	const openActionsMenu = () => {
		// Use a more robust selector for the actions button
		cy.get(`a[id*="-${pluginId}-settings-button-"]`).click();
	};

	it('Enables the plugin and verifies its actions', function() {
		// Find and enable the plugin
		cy.get(`input[id^="select-cell-${pluginId}-enabled"]`).check();
		cy.get(`div:contains('The plugin "${pluginName}" has been enabled.')`).should('be.visible');

		// Open the actions menu and verify actions
		openActionsMenu();
		cy.get('a:contains("Run Manual Scan")').should('be.visible');
		cy.get('a:contains("Clear Hash Cache")').should('be.visible');
	});

	it('Executes the "Run Scan" action', function() {
		openActionsMenu();

		// Click the "Run Scan" link
		cy.get('a[id^="runScan-"]').click();

		// In the confirmation modal, click OK
		cy.get('.pkp_modal_panel').should('be.visible');
		cy.get('.pkp_modal_panel').find('button:contains("Run Manual Scan")').click();

		// Verify the success notification
		cy.get('div:contains("Scan completed. If any issues were found, a summary has been sent to the site\'s primary contact email.")').should('be.visible');
	});

	it('Executes the "Clear Cache" action', function() {
		openActionsMenu();

		// Click the "Clear Cache" link
		cy.get('a[id^="clearCache-"]').click();

		// In the confirmation modal, click OK
		cy.get('.pkp_modal_panel').should('be.visible');
		cy.get('.pkp_modal_panel').find('button:contains("Clear Integrity Cache")').click();

		// Verify the success notification
		cy.get('div:contains("The integrity cache has been successfully cleared.")').should('be.visible');
	});

	it('Saves settings and verifies the manual exclude value', function() {
		const excludePath1 = 'config.inc.php';
		const excludePath2 = 'plugins/generic/myCustomPlugin/version.xml';
		const excludeValue = `${excludePath1}\n${excludePath2}`;

		openActionsMenu();
		cy.get('a:contains("Settings")').click();

		// Wait for the settings modal to appear and fill the textarea
		cy.get('.pkp_modal_panel').should('be.visible');
		cy.get('textarea[name="manualExcludes"]').clear().type(excludeValue);
		cy.get('.pkp_modal_panel').find('button:contains("Save")').click();

		// Verify the success notification
		cy.get('div:contains("Your changes have been saved.")').should('be.visible');

		// Re-open settings to verify the value was saved
		openActionsMenu();
		cy.get('a:contains("Settings")').click();
		cy.get('.pkp_modal_panel').should('be.visible');
		cy.get('textarea[name="manualExcludes"]').should('have.value', excludeValue);
	});
});
