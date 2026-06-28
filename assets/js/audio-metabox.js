/**
 * Vaani Audio meta box — Generate buttons.
 *
 * Queues background audio generation over admin-ajax.php and swaps the matching
 * language row's status cell with the HTML returned by the server.
 */
document.addEventListener( 'DOMContentLoaded', () => {
	const config = window.vaaniAudio;
	const box = document.getElementById( 'vaani_audio' );

	if ( ! config || ! box ) {
		return;
	}

	box.addEventListener( 'click', async ( event ) => {
		const button = event.target.closest( '.vaani-generate-audio' );
		if ( ! button ) {
			return;
		}

		const row = button.closest( 'tr[data-lang]' );
		const lang = button.dataset.lang;
		const statusCell = row ? row.querySelector( '.vaani-status' ) : null;

		if ( ! lang ) {
			return;
		}

		button.disabled = true;
		if ( statusCell ) {
			statusCell.textContent = config.i18n.queueing;
		}

		const body = new URLSearchParams( {
			action: config.action,
			nonce: config.nonce,
			postId: config.postId,
			lang,
		} );

		try {
			const response = await fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} );

			const data = await response.json();

			if ( data?.success && data?.data?.statusHtml && statusCell ) {
				statusCell.innerHTML = data.data.statusHtml;
			} else if ( statusCell ) {
				statusCell.textContent = data?.data?.message || config.i18n.error;
			}
		} catch ( error ) {
			if ( statusCell ) {
				statusCell.textContent = config.i18n.error;
			}
		} finally {
			button.disabled = false;
		}
	} );
} );
