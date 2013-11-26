<?php
	require 'includes/bootstrap.php';
	$_SERVER['HTTP_USER_AGENT'] = '';
	define( 'GF_DIGEST_DOING_TESTS', true );
	require dirname( __FILE__ ) . '/../gravityforms-digest/gravityforms-digest.php';
?>
