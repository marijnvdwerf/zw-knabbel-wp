<?php
/**
 * Story processing status definitions.
 *
 * Defines the possible processing states for stories sent to the Babbel API.
 *
 * @package KnabbelWP
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KnabbelWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents the processing status of a story.
 *
 * @since 0.1.0
 */
enum StoryStatus: string {

	case Scheduled  = 'scheduled';
	case Processing = 'processing';
	case Sent       = 'sent';
	case Error      = 'error';
	case Deleted    = 'deleted';

	/**
	 * Returns the translated display label.
	 *
	 * @since 0.8.0
	 * @return string Human-readable status label.
	 */
	public function label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Enum instance method; the sniff predates PHP 8.1 enums.
		return match ( $this ) {
			self::Scheduled  => __( 'Scheduled', 'zw-knabbel-wp' ),
			self::Processing => __( 'Processing', 'zw-knabbel-wp' ),
			self::Sent       => __( 'Sent', 'zw-knabbel-wp' ),
			self::Error      => __( 'Error', 'zw-knabbel-wp' ),
			self::Deleted    => __( 'Deleted', 'zw-knabbel-wp' ),
		};
	}
}
