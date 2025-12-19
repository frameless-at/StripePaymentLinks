/**
 * Purchase Debug Viewer
 * Adds debug icons and collapsible metadata viewer to purchase repeater items
 */

(function() {
	'use strict';

	// Wait for DOM to be ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initPurchaseDebugViewer);
	} else {
		initPurchaseDebugViewer();
	}

	function initPurchaseDebugViewer() {
		// Check if we have debug data
		if (typeof splPurchaseDebugData === 'undefined') {
			return;
		}

		// Find all purchase repeater items
		const purchaseRepeater = document.querySelector('#Inputfield_spl_purchases');
		if (!purchaseRepeater) {
			return;
		}

		// Find all repeater items
		const repeaterItems = purchaseRepeater.querySelectorAll('.Inputfield_repeater_item');

		repeaterItems.forEach(function(item) {
			// Extract the repeater item ID from the data attribute or ID
			const itemId = extractItemId(item);

			if (!itemId || !splPurchaseDebugData[itemId]) {
				return;
			}

			// Find the header of the repeater item (where we'll add the debug button)
			const header = item.querySelector('.InputfieldHeader');
			if (!header) {
				return;
			}

			// Create debug button
			const debugBtn = createDebugButton();

			// Insert button before the toggle icon
			const toggleIcon = header.querySelector('.toggle-icon');
			if (toggleIcon) {
				header.insertBefore(debugBtn, toggleIcon);
			} else {
				header.appendChild(debugBtn);
			}

			// Create debug panel (initially hidden)
			const debugPanel = createDebugPanel(itemId);

			// Insert panel after the header
			header.parentNode.insertBefore(debugPanel, header.nextSibling);

			// Add click handler
			debugBtn.addEventListener('click', function(e) {
				e.preventDefault();
				e.stopPropagation();
				toggleDebugPanel(debugPanel, debugBtn);
			});
		});
	}

	function extractItemId(item) {
		// Try to get ID from various possible attributes
		if (item.id) {
			// ID might be like "Inputfield_spl_purchases_repeater1234"
			const match = item.id.match(/repeater(\d+)/);
			if (match) {
				return match[1];
			}
		}

		// Try data attributes
		if (item.dataset && item.dataset.page) {
			return item.dataset.page;
		}

		// Try to find a hidden input with the page ID
		const pageIdInput = item.querySelector('input[name*="[id]"]');
		if (pageIdInput && pageIdInput.value) {
			return pageIdInput.value;
		}

		return null;
	}

	function createDebugButton() {
		const btn = document.createElement('button');
		btn.className = 'spl-debug-btn';
		btn.type = 'button';
		btn.title = 'Show/Hide Purchase Debug Data';
		btn.innerHTML = '🔍 Debug';
		return btn;
	}

	function createDebugPanel(itemId) {
		const panel = document.createElement('div');
		panel.className = 'spl-debug-panel';
		panel.style.display = 'none';

		const data = splPurchaseDebugData[itemId];

		// Add validation messages
		if (data.validation && data.validation.length > 0) {
			const validationSection = document.createElement('div');
			validationSection.className = 'spl-debug-validation';
			validationSection.innerHTML = '<h3>Validation Status</h3>';

			const validationList = document.createElement('ul');
			data.validation.forEach(function(msg) {
				const li = document.createElement('li');
				li.className = 'spl-validation-' + msg.type;
				li.textContent = msg.text;
				validationList.appendChild(li);
			});

			validationSection.appendChild(validationList);
			panel.appendChild(validationSection);
		}

		// Add metadata
		if (data.metadata) {
			const metadataSection = document.createElement('div');
			metadataSection.className = 'spl-debug-metadata';
			metadataSection.innerHTML = '<h3>Purchase Metadata</h3>' + data.metadata;
			panel.appendChild(metadataSection);
		}

		return panel;
	}

	function toggleDebugPanel(panel, button) {
		if (panel.style.display === 'none') {
			panel.style.display = 'block';
			button.classList.add('active');
		} else {
			panel.style.display = 'none';
			button.classList.remove('active');
		}
	}

})();
