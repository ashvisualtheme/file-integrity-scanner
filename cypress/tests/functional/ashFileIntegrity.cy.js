/**
 * @file cypress/tests/functional/ashFileIntegrity.cy.js
 *
 * Copyright (c) 2025 AshVisualTheme
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Test for the File Integrity Scanner plugin.
 */

describe('File Integrity Scanner Plugin Tests', function() {
	// Variabel untuk menyimpan nama plugin agar mudah diubah jika perlu
	const pluginName = 'File Integrity Scanner';
	const pluginId = 'ashfileintegrityplugin'; // ID biasanya lowercase tanpa spasi

	it('Enables the plugin and verifies its actions', function() {
		// Login sebagai admin
		cy.login('admin', 'admin', 'publicknowledge');

		// Navigasi ke halaman Plugins
		cy.get('.app__nav a').contains('Website').click();
		cy.get('button[id="plugins-button"]').click();

		// Cari plugin dan aktifkan
		cy.get('input[id^="select-cell-' + pluginId + '-enabled"]').click();
		cy.get('div:contains(\'The plugin "' + pluginName + '" has been enabled.\')').should('be.visible');

		// Buka menu actions untuk plugin
		cy.get('a[id^="component-grid-settings-plugins-settingsplugingrid-category-generic-row-' + pluginId + '-settings-button-"]').as('actionsButton');
		cy.wait(1000); // Tunggu sebentar agar UI stabil
		cy.get('@actionsButton').click({force: true});

		// Verifikasi bahwa tindakan "Run Scan" dan "Clear Cache" ada
		cy.get('a:contains("Run File Integrity Scan")').should('be.visible');
		cy.get('a:contains("Clear Integrity Cache")').should('be.visible');
	});

	it('Executes the "Run Scan" action', function() {
		cy.login('admin', 'admin', 'publicknowledge');
		cy.visit('/index.php/publicknowledge/management/settings/website#tabs-plugins');
		cy.get('button#generic-plugins-button').click();

		// Buka menu actions
		cy.get('a[id^="component-grid-settings-plugins-settingsplugingrid-category-generic-row-' + pluginId + '-settings-button-"]').as('actionsButton');
		cy.wait(1000);
		cy.get('@actionsButton').click({force: true});

		// Klik link "Run Scan"
		cy.get('a[id^="runScan-"]').click();

		// Di dalam modal konfirmasi, klik OK
		cy.get('.pkp_modal_panel').should('be.visible');
		cy.get('.pkp_modal_panel').find('button:contains("Run Manual Scan")').click();

		// Verifikasi notifikasi sukses
		cy.get('div:contains("Scan completed. If any issues were found, a summary has been sent to the site\'s primary contact email.")').should('be.visible');
	});

	it('Executes the "Clear Cache" action', function() {
		cy.login('admin', 'admin', 'publicknowledge');
		cy.visit('/index.php/publicknowledge/management/settings/website#tabs-plugins');
		cy.get('button#generic-plugins-button').click();

		// Buka menu actions
		cy.get('a[id^="component-grid-settings-plugins-settingsplugingrid-category-generic-row-' + pluginId + '-settings-button-"]').as('actionsButton');
		cy.wait(1000);
		cy.get('@actionsButton').click({force: true});

		// Klik link "Clear Cache"
		cy.get('a[id^="clearCache-"]').click();

		// Di dalam modal konfirmasi, klik OK
		cy.get('.pkp_modal_panel').should('be.visible');
		cy.get('.pkp_modal_panel').find('button:contains("Clear Integrity Cache")').click();

		// Verifikasi notifikasi sukses
		cy.get('div:contains("The integrity cache has been successfully cleared.")').should('be.visible');
	});

	it('Saves settings and verifies the manual exclude value', function() {
		const excludePath1 = 'config.inc.php';
		const excludePath2 = 'plugins/generic/myCustomPlugin/version.xml';
		const excludeValue = `${excludePath1}\n${excludePath2}`;

		cy.login('admin', 'admin', 'publicknowledge');
		cy.visit('/index.php/publicknowledge/management/settings/website#tabs-plugins');
		cy.get('button#generic-plugins-button').click();

		// Buka menu actions dan klik Settings
		cy.get('a[id^="component-grid-settings-plugins-settingsplugingrid-category-generic-row-' + pluginId + '-settings-button-"]').as('actionsButton');
		cy.wait(1000);
		cy.get('@actionsButton').click({force: true});
		cy.get('a:contains("Settings")').click();

		// Tunggu modal settings muncul
		cy.get('.pkp_modal_panel').should('be.visible');

		// Isi textarea dan simpan
		cy.get('textarea[name="manualExcludes"]').clear().type(excludeValue);
		cy.get('.pkp_modal_panel').find('button:contains("Save")').click();

		// Verifikasi notifikasi sukses
		cy.get('div:contains("Your changes have been saved.")').should('be.visible');
	});
});
