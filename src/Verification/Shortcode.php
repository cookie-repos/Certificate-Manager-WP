<?php
/**
 * Verification Shortcode
 *
 * @package CertificateManager
 */

namespace CertificateManager\Verification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verification shortcode class
 */
class Shortcode {
	
	/**
	 * Verification service
	 *
	 * @var Services\VerificationService
	 */
	private $verification_service;
	
	/**
	 * Settings
	 *
	 * @var Core\Settings
	 */
	private $settings;
	
	/**
	 * Constructor
	 *
	 * @param Services\VerificationService $verification_service Verification service.
	 * @param Core\Settings               $settings Settings instance.
	 */
	public function __construct( $verification_service, $settings ) {
		$this->verification_service = $verification_service;
		$this->settings = $settings;
	}
	
	/**
	 * Render verification form
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Form HTML.
	 */
	public function render( $atts ) {
		$atts = shortcode_atts( array(
			'mode' => 'search',
		), $atts, 'certificate_verification' );
		if ( 'search' !== $atts['mode'] ) {
			$atts['mode'] = 'search';
		}
		$style = sanitize_key( $this->settings->get( 'verification_style', 'editorial' ) );
		$style = in_array( $style, array( 'editorial', 'registry', 'heritage', 'folio', 'night' ), true ) ? $style : 'editorial';
		$accent = sanitize_key( $this->settings->get( 'verification_accent', 'indigo' ) );
		$accent = in_array( $accent, array( 'indigo', 'emerald', 'violet', 'amber', 'charcoal' ), true ) ? $accent : 'indigo';
		$layouts = array(
			'editorial' => '--cm-form-background:#f7f7f5;--cm-form-surface:#ffffff;--cm-form-text:#18211e;--cm-form-muted:#626a65;--cm-form-border:#dfe3dd;--cm-form-shadow:0 12px 32px rgba(25,36,29,.08);',
			'registry' => '--cm-form-background:#edf2f6;--cm-form-surface:#ffffff;--cm-form-text:#12233b;--cm-form-muted:#576b80;--cm-form-border:#cfdbe6;--cm-form-shadow:0 12px 28px rgba(20,49,79,.11);',
			'heritage' => '--cm-form-background:#f4f1e9;--cm-form-surface:#fffdf8;--cm-form-text:#172d4d;--cm-form-muted:#6f6657;--cm-form-border:#e6dac0;--cm-form-shadow:0 18px 45px rgba(50,40,20,.14);',
			'folio' => '--cm-form-background:#f3f1ed;--cm-form-surface:#ffffff;--cm-form-text:#20201f;--cm-form-muted:#6a6864;--cm-form-border:#dedbd4;--cm-form-shadow:0 10px 26px rgba(34,31,26,.09);',
			'night' => '--cm-form-background:#121619;--cm-form-surface:#1c2327;--cm-form-text:#f2f4f1;--cm-form-muted:#b9c3c2;--cm-form-border:#344046;--cm-form-shadow:0 18px 42px rgba(0,0,0,.32);',
		);
		$accents = array(
			'indigo' => '--cm-form-primary:#315efb;--cm-form-primary-soft:#e8edff;',
			'emerald' => '--cm-form-primary:#0b8a61;--cm-form-primary-soft:#e2f6ee;',
			'violet' => '--cm-form-primary:#7641d9;--cm-form-primary-soft:#f0e9ff;',
			'amber' => '--cm-form-primary:#a66a00;--cm-form-primary-soft:#fff2d5;',
			'charcoal' => '--cm-form-primary:#292929;--cm-form-primary-soft:#ececec;',
		);
		$theme_variables = $layouts[ $style ] . $accents[ $accent ];
		$input_id = wp_unique_id( 'cm-certificate-number-' );
		
		ob_start();
		
		?>
		<div class="cm-verification-form cm-verification-form-<?php echo esc_attr( $style ); ?>" style="<?php echo esc_attr( $theme_variables ); ?>">
			<style>
				.cm-verification-form { background:var(--cm-form-background); border:1px solid var(--cm-form-border); border-radius:2px; border-top:4px solid var(--cm-form-primary); box-shadow:var(--cm-form-shadow); box-sizing:border-box; color:var(--cm-form-text); margin:24px 0; max-width:720px; overflow:hidden; padding:0; }
				.cm-verification-form-header { background:var(--cm-form-surface); border-bottom:1px solid var(--cm-form-border); padding:30px 34px 26px; }
				.cm-verification-form-header p { color:var(--cm-form-primary); font-size:12px; font-weight:750; letter-spacing:.1em; margin:0 0 9px; text-transform:uppercase; }
				.cm-verification-form h2 { color:var(--cm-form-text); font-family:Georgia,"Times New Roman",serif; font-size:30px; font-weight:500; letter-spacing:-.035em; line-height:1.15; margin:0; }
				.cm-verification-form-content { background:var(--cm-form-surface); padding:30px 34px 34px; }
				.cm-verification-form-copy { color:var(--cm-form-muted); line-height:1.6; margin:0 0 22px; }
				.cm-verification-form label { color:var(--cm-form-text); display:block; font-size:14px; font-weight:700; margin-bottom:8px; }
				.cm-verification-form-row { display:flex; gap:10px; }
				.cm-verification-form input { background:#fff; border:1px solid var(--cm-form-border); border-radius:10px; box-sizing:border-box; color:var(--cm-form-text); flex:1; font:inherit; min-height:48px; padding:0 13px; }
				.cm-verification-form input:focus { border-color:var(--cm-form-primary); box-shadow:0 0 0 3px var(--cm-form-primary-soft); outline:0; }
				.cm-verification-form button { background:var(--cm-form-primary); border:1px solid var(--cm-form-primary); border-radius:10px; color:#fff; cursor:pointer; font:inherit; font-weight:700; min-height:48px; padding:0 18px; }
				.cm-verification-form-registry { border-color:var(--cm-form-border); border-radius:8px; border-top:1px solid var(--cm-form-border); }
				.cm-verification-form-registry .cm-verification-form-header { background:var(--cm-form-primary); border:0; }
				.cm-verification-form-registry .cm-verification-form-header p,.cm-verification-form-registry h2 { color:#fff; font-family:inherit; font-weight:700; }
				.cm-verification-form-heritage { border:5px double var(--cm-form-primary); border-radius:0; margin-left:auto; margin-right:auto; max-width:640px; text-align:center; }
				.cm-verification-form-heritage .cm-verification-form-header { background:var(--cm-form-surface); padding:38px 34px 24px; }
				.cm-verification-form-heritage h2 { font-size:34px; }
				.cm-verification-form-heritage .cm-verification-form-content { padding:28px 38px 38px; text-align:left; }
				.cm-verification-form-folio { border-left:8px solid var(--cm-form-primary); border-radius:0; border-top:1px solid var(--cm-form-border); box-shadow:none; max-width:680px; }
				.cm-verification-form-folio .cm-verification-form-header { background:var(--cm-form-surface); border:0; padding-bottom:8px; }
				.cm-verification-form-folio .cm-verification-form-content { padding-top:20px; }
				.cm-verification-form-folio h2 { font-family:inherit; font-size:26px; font-weight:700; }
				.cm-verification-form-night { background:var(--cm-form-surface); border-color:var(--cm-form-border); border-radius:12px; border-top:1px solid var(--cm-form-border); }
				.cm-verification-form-night .cm-verification-form-header { background:#20292e; border-color:var(--cm-form-border); }
				.cm-verification-form-night h2 { font-family:inherit; font-weight:700; }
				.cm-verification-form-night input { background:#151b1f; border-color:var(--cm-form-border); color:var(--cm-form-text); }
				@media (max-width:600px) { .cm-verification-form { border-radius:0; } .cm-verification-form-registry,.cm-verification-form-night { border-radius:8px; } .cm-verification-form-header,.cm-verification-form-content,.cm-verification-form-heritage .cm-verification-form-header,.cm-verification-form-heritage .cm-verification-form-content { padding:24px; } .cm-verification-form-row { flex-direction:column; } }
			</style>
			<header class="cm-verification-form-header"><p><?php esc_html_e( 'Certificate register', 'certificate-manager' ); ?></p><h2><?php esc_html_e( 'Verify Certificate', 'certificate-manager' ); ?></h2></header>
			<div class="cm-verification-form-content">
			
			<?php if ( 'search' === $atts['mode'] ) : ?>
				<form method="get" action="<?php echo esc_url( $this->get_verification_url() ); ?>">
					<p class="cm-verification-form-copy"><?php esc_html_e( 'Enter the certificate number to check its current status.', 'certificate-manager' ); ?></p>
					<label for="<?php echo esc_attr( $input_id ); ?>"><?php esc_html_e( 'Certificate number', 'certificate-manager' ); ?></label>
					<div class="cm-verification-form-row"><input type="text" id="<?php echo esc_attr( $input_id ); ?>" name="certificate_number" placeholder="CERT-2026-0001" required autocomplete="off"><button type="submit"><?php esc_html_e( 'Verify certificate', 'certificate-manager' ); ?></button></div>
				</form>
			<?php endif; ?>
			</div>
		</div>
		<?php
		
		return ob_get_clean();
	}
	
	/**
	 * Get verification URL
	 *
	 * @return string Verification page URL.
	 */
	private function get_verification_url() {
		return $this->settings->get_verification_destination_url();
	}
}
