<?php
/**
 * Fast regression tests for the default speech-text form rules.
 *
 * @package KnabbelWP
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__, 2 ) . '/includes/few-shot-selection.php';
require_once dirname( __DIR__, 2 ) . '/includes/speech-text.php';

( static function (): void {
	$ensure = static function ( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	};

	$words = static fn ( int $count ): string => implode( ' ', array_fill( 0, $count, 'woord' ) ) . '.';

	$ensure(
		null === KnabbelWP\default_speech_text_violation(
			'In Roosendaal is 3,8 miljoen euro – voor 24 scholen – vrijgemaakt. De gemeente begint “volgend jaar”.'
		),
		'A short single paragraph must conform.'
	);
	$ensure( null === KnabbelWP\default_speech_text_violation( $words( 65 ) ), 'Exactly 65 words must conform.' );
	$ensure(
		null === KnabbelWP\default_speech_text_violation( $words( 65 ) . ' – … ”' ),
		'Punctuation and dashes must not count as words.'
	);
	$ensure(
		'Text has 66 words; the limit is 65' === KnabbelWP\default_speech_text_violation( $words( 66 ) ),
		'More than 65 words must be rejected with the word count.'
	);
	$ensure(
		null === KnabbelWP\default_speech_text_violation( 'Één ouder in Zundert zei: “Prima!”. Daarná volgt overleg.' ),
		'Accented Dutch words must count as single words.'
	);
	$ensure( 6 === KnabbelWP\few_shot_count_words( 'Één ouder in Zundert zei: “Prima!”.' ), 'Accented words must count once each.' );
	foreach ( array( "Eerste alinea.\n\nTweede alinea.", "Eerste regel.\nTweede regel.", "Eerste alinea.\r\n\r\nTweede alinea." ) as $text ) {
		$ensure(
			'Text contains more than one paragraph' === KnabbelWP\default_speech_text_violation( $text ),
			'Line breaks must be rejected as multiple paragraphs.'
		);
	}
	$ensure( null === KnabbelWP\default_speech_text_violation( "\n  Eén alinea met witruimte eromheen.  \n" ), 'Surrounding whitespace must be ignored.' );

	echo "Speech text tests passed.\n";
} )();
