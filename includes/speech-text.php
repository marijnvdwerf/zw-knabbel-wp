<?php
/**
 * Pure speech-text form checks.
 *
 * Deliberately free of WordPress dependencies so tests/php/speech-text.php can
 * execute it standalone; this file defines constants and functions only.
 *
 * @package KnabbelWP
 * @since   0.9.0
 */

declare(strict_types=1);

namespace KnabbelWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Keep in sync with the word limit in AI_DEFAULT_INSTRUCTION.
const AI_DEFAULT_MAX_WORDS = 65;

/**
 * Returns why speech text breaks the default prompt's form rules.
 *
 * The default prompt asks for one paragraph of at most AI_DEFAULT_MAX_WORDS
 * words. Any line break counts as a paragraph break; words are counted like
 * few-shot metrics, so punctuation and dashes do not count as words.
 *
 * @since 0.9.0
 * @param string $text Generated speech text.
 * @return string|null Untranslated reason for diagnostics, or null when the text conforms.
 */
function default_speech_text_violation( string $text ): ?string {
	$text = trim( $text );
	if ( 1 === preg_match( '/\R/u', $text ) ) {
		return 'Text contains more than one paragraph';
	}

	$word_count = few_shot_count_words( $text );
	if ( $word_count > AI_DEFAULT_MAX_WORDS ) {
		return sprintf( 'Text has %d words; the limit is %d', $word_count, AI_DEFAULT_MAX_WORDS );
	}

	return null;
}
