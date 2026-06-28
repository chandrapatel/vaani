/**
 * Vaani settings page — Test Connection button.
 *
 * Posts to admin-ajax.php with a nonce and renders the result inline.
 */
document.addEventListener( 'DOMContentLoaded', () => {
	const config = window.vaaniSettings;
	const button = document.getElementById( 'vaani-test-connection' );
	const result = document.getElementById( 'vaani-test-connection-result' );

	// Show translation- vs transliteration-specific settings based on the chosen
	// method, and within translation show the Mayura-only Tone row only when
	// Mayura is the selected model; Sarvam Translate supports formal tone only.
	const methodSelect = document.getElementById( 'vaani_translation_method' );
	const modelSelect = document.getElementById( 'vaani_translate_model' );
	if ( methodSelect || modelSelect ) {
		const translateRows = document.querySelectorAll( '.vaani-row-translate' );
		const transliterateRows = document.querySelectorAll( '.vaani-row-transliterate' );
		const mayuraRows = document.querySelectorAll( '.vaani-row-mayura' );

		const syncRows = () => {
			const isTransliterate =
				methodSelect && methodSelect.value === 'transliterate';
			const isMayura = ! modelSelect || modelSelect.value === 'mayura:v1';

			translateRows.forEach( ( row ) => {
				row.style.display = isTransliterate ? 'none' : '';
			} );
			transliterateRows.forEach( ( row ) => {
				row.style.display = isTransliterate ? '' : 'none';
			} );
			// Mayura rows are a subset of translate rows; hide them when the model
			// isn't Mayura even while translation is the active method.
			if ( ! isTransliterate ) {
				mayuraRows.forEach( ( row ) => {
					row.style.display = isMayura ? '' : 'none';
				} );
			}
		};

		if ( methodSelect ) {
			methodSelect.addEventListener( 'change', syncRows );
		}
		if ( modelSelect ) {
			modelSelect.addEventListener( 'change', syncRows );
		}
		syncRows();
	}

	if ( ! config || ! button || ! result ) {
		return;
	}

	const setResult = ( message, ok ) => {
		result.textContent = message;
		result.style.marginLeft = '8px';
		result.style.color = ok ? '#1a7f37' : '#b32d2e';
	};

	button.addEventListener( 'click', async () => {
		button.disabled = true;
		setResult( config.i18n.testing, true );
		result.style.color = '';

		const body = new URLSearchParams( {
			action: config.action,
			nonce: config.nonce,
		} );

		try {
			const response = await fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} );

			const data = await response.json();
			const message = data?.data?.message || config.i18n.error;
			setResult( message, !! data?.success );
		} catch ( error ) {
			setResult( config.i18n.error, false );
		} finally {
			button.disabled = false;
		}
	} );
} );
