<?php

declare(strict_types=1);

namespace WicketPortus\Support;

/**
 * Produces operator-facing warning messages for the Portus export/import workflow.
 *
 * All methods are static because warnings carry no state — they are pure
 * string factories used by the admin page controller and future CLI layer.
 */
class WarningPrinter {

	/**
	 * Returns a wp-admin notice HTML block for inline display.
	 *
	 * @return string
	 */
	public static function sensitive_data_notice(): string {
		return sprintf(
			'<div class="notice notice-warning"><p><strong>%s</strong></p></div>',
			esc_html__( 'SENSITIVE DATA WARNING: This manifest contains credentials, API keys, and environment-specific secrets. Treat it as sensitive material. Do not commit to version control, share publicly, or store without encryption.', 'wicket-portus' )
		);
	}

	/**
	 * Returns a plain-text warning line suitable for CLI output or log entries.
	 *
	 * @return string
	 */
	public static function sensitive_data_text(): string {
		return '⚠  ' . __( 'SENSITIVE DATA WARNING: This manifest contains credentials, API keys, and environment-specific secrets. Treat it as sensitive material. Do not commit to version control, share publicly, or store without encryption.', 'wicket-portus' );
	}

	/**
	 * Renders a generic admin notice.
	 *
	 * @param string $message                       Unescaped message text.
	 * @param 'warning'|'error'|'info'|'success' $type
	 * @return string
	 */
	public static function admin_notice( string $message, string $type = 'warning' ): string {
		return sprintf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}
}
