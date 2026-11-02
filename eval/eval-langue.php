<?php
/**
 * Mesure la détection de langue sur le jeu de test.
 *
 *     wp eval-file eval/eval-langue.php
 *
 * Deux erreurs n'ont pas le même coût :
 *  - rater du wolof (wo/mix classé fr) : le client est servi en français,
 *    l'expérience échoue en silence. C'est la faute grave.
 *  - classer du français en wolof : Naya répond en wolof à quelqu'un qui
 *    n'en veut pas. Visible, gênant, mais rattrapable au message suivant.
 */

if ( ! class_exists( 'Naya_Langue' ) ) {
	echo "Naya n'est pas active.\n";
	return;
}

$fichier = __DIR__ . '/jeu-test.jsonl';
if ( ! file_exists( $fichier ) ) {
	echo "Jeu de test introuvable : {$fichier}\n";
	return;
}

$total   = 0;
$justes  = 0;
$echecs  = array();
$matrice = array();

foreach ( file( $fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $ligne ) {
	$cas = json_decode( $ligne, true );
	if ( ! $cas || empty( $cas['texte'] ) ) {
		continue;
	}

	$attendu = $cas['langue'];
	$obtenu  = Naya_Langue::detecter( $cas['texte'] );
	$total++;

	$matrice[ $attendu ][ $obtenu ] = ( $matrice[ $attendu ][ $obtenu ] ?? 0 ) + 1;

	if ( $attendu === $obtenu ) {
		$justes++;
	} else {
		$echecs[] = array(
			'texte'   => $cas['texte'],
			'attendu' => $attendu,
			'obtenu'  => $obtenu,
			'note'    => $cas['note'] ?? '',
			'grave'   => ( 'fr' === $obtenu && 'fr' !== $attendu ),
		);
	}
}

printf( "\nDétection de langue : %d/%d justes (%d %%)\n\n", $justes, $total, $total ? round( 100 * $justes / $total ) : 0 );

echo "Matrice (attendu -> obtenu) :\n";
foreach ( array( 'wo', 'mix', 'fr' ) as $a ) {
	$ligne = array();
	foreach ( array( 'wo', 'mix', 'fr' ) as $o ) {
		$ligne[] = sprintf( '%s:%d', $o, $matrice[ $a ][ $o ] ?? 0 );
	}
	printf( "  %-4s -> %s\n", $a, implode( '  ', $ligne ) );
}

$graves = array_filter( $echecs, function ( $e ) {
	return $e['grave'];
} );

printf( "\nWolof manqué (faute grave) : %d\n", count( $graves ) );

if ( $echecs ) {
	echo "\nErreurs :\n";
	foreach ( $echecs as $e ) {
		printf(
			"  %s « %s »\n      attendu %s, obtenu %s%s\n",
			$e['grave'] ? '[GRAVE]' : '       ',
			$e['texte'],
			$e['attendu'],
			$e['obtenu'],
			$e['note'] ? ' — ' . $e['note'] : ''
		);
	}
}
echo "\n";
