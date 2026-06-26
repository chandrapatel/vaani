/**
 * Vaani settings page — Test Connection button.
 *
 * Posts to admin-ajax.php with a nonce and renders the result inline.
 */
document.addEventListener( 'DOMContentLoaded', () => {
	const config = window.vaaniSettings;
	const button = document.getElementById( 'vaani-test-connection' );
	const result = document.getElementById( 'vaani-test-connection-result' );

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
