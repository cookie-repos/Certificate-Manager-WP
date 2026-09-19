<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$certificate_manager_prefixes = array(
	'Mpdf\\PsrHttpMessageShim\\' => __DIR__ . '/mpdf/psr-http-message-shim/src/',
	'Mpdf\\PsrLogAwareTrait\\' => __DIR__ . '/mpdf/psr-log-aware-trait/src/',
	'Mpdf\\' => __DIR__ . '/mpdf/mpdf/src/',
	'DeepCopy\\' => __DIR__ . '/myclabs/deep-copy/src/DeepCopy/',
	'Psr\\Http\\Message\\' => __DIR__ . '/psr/http-message/src/',
	'Psr\\Log\\' => __DIR__ . '/psr/log/Psr/Log/',
	'setasign\\Fpdi\\' => __DIR__ . '/setasign/fpdi/src/',
);

spl_autoload_register(
	static function ( $class ) use ( $certificate_manager_prefixes ) {
		foreach ( $certificate_manager_prefixes as $prefix => $directory ) {
			if ( 0 !== strpos( $class, $prefix ) ) {
				continue;
			}

			$file = $directory . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
			return;
		}
	},
	true,
	true
);

require_once __DIR__ . '/mpdf/mpdf/src/functions.php';
require_once __DIR__ . '/myclabs/deep-copy/src/DeepCopy/deep_copy.php';
require_once __DIR__ . '/paragonie/random_compat/lib/random.php';

return true;
