<?php
	require 'includes/bootstrap.php';
	$_SERVER['HTTP_USER_AGENT'] = '';
	$_SERVER['SERVER_NAME'] = '127.0.0.1';
	define( 'GF_DIGEST_DOING_TESTS', true );
	require dirname( __FILE__ ) . '/../gravityforms-digest/gravityforms-digest.php';
?>
