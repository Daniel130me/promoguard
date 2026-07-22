<?php
/**
 * Cross-platform recursive PHP syntax check.
 *
 * @package PromoGuard\Tools
 */

$root       = dirname( __DIR__ );
$directories = array( 'src', 'tests' );
$files      = array(
	$root . '/promoguard-for-woocommerce.php',
	$root . '/uninstall.php',
);

foreach ( $directories as $directory ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root . '/' . $directory, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( $file instanceof SplFileInfo && 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
		}
	}
}

$failed = false;

foreach ( $files as $file ) {
	$command = escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file );
	passthru( $command, $exit_code );

	if ( 0 !== $exit_code ) {
		$failed = true;
	}
}

exit( $failed ? 1 : 0 );
