/**
 * SeoKit field: keeps the two previews in step with what is being typed.
 *
 * The previews show the derived value whenever an input is empty, which is
 * the same rule the renderer follows. If these two ever disagree the preview
 * is lying, so the fallback logic lives in one place here and mirrors
 * ResolveService exactly: empty input means derived value.
 */
(function () {
	'use strict';

	/**
	 * Rough guidance rather than hard limits. Google measures pixels, not
	 * characters, and truncates at different widths on phone and desktop, so
	 * anyone promising an exact cutoff is guessing with more confidence than
	 * the data supports. These bands are wide on purpose.
	 */
	var DESCRIPTION_SHORT = 70;
	var DESCRIPTION_LONG_FACTOR = 1.1;

	function init(field) {
		if (field.dataset.seokitReady === '1') {
			return;
		}
		field.dataset.seokitReady = '1';

		var derived = {
			title: field.dataset.derivedTitle || '',
			description: field.dataset.derivedDescription || ''
		};

		var limit = parseInt(field.dataset.descriptionLength, 10) || 155;

		var inputs = {
			title: field.querySelector('[data-seokit-input="title"]'),
			description: field.querySelector('[data-seokit-input="description"]')
		};

		function paint(key) {
			var input = inputs[key];
			if (!input) {
				return;
			}

			var value = input.value.trim() || derived[key];
			var targets = field.querySelectorAll('[data-seokit-preview="' + key + '"]');

			for (var i = 0; i < targets.length; i++) {
				targets[i].textContent = value;
				targets[i].classList.toggle('is-empty', value === '');
			}

			if (key === 'description') {
				count(value);
			}
		}

		function count(value) {
			var counter = field.querySelector('[data-seokit-counter]');
			var output = field.querySelector('[data-seokit-counter-value]');

			if (!counter || !output) {
				return;
			}

			var length = value.length;
			var state = 'ok';

			if (length === 0) {
				state = 'empty';
			} else if (length < DESCRIPTION_SHORT) {
				state = 'short';
			} else if (length > limit * DESCRIPTION_LONG_FACTOR) {
				state = 'long';
			}

			counter.className = 'seokit-counter is-' + state;
			output.textContent = length + ' / ' + limit;
		}

		Object.keys(inputs).forEach(function (key) {
			if (!inputs[key]) {
				return;
			}

			inputs[key].addEventListener('input', function () {
				paint(key);
			});

			paint(key);
		});
	}

	function scan() {
		var fields = document.querySelectorAll('[data-seokit-field]');

		for (var i = 0; i < fields.length; i++) {
			init(fields[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', scan);
	} else {
		scan();
	}

	// Slideouts, Matrix blocks and the entry type switcher all inject field
	// HTML long after load. Watching for it beats guessing at which Craft
	// event fires for which of them, and init() is idempotent.
	if (window.MutationObserver) {
		var observer = new MutationObserver(function (mutations) {
			for (var i = 0; i < mutations.length; i++) {
				if (mutations[i].addedNodes.length > 0) {
					scan();

					return;
				}
			}
		});

		observer.observe(document.body, { childList: true, subtree: true });
	}
})();
