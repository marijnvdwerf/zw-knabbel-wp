<?php
/**
 * WordPress AI Client integration for speech-text generation.
 *
 * Handles provider-independent speech text generation through WordPress Core.
 *
 * @package KnabbelWP
 * @since   0.5.0
 */

declare(strict_types=1);

namespace KnabbelWP;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Generic.Files.LineLength.TooLong -- Keep the reviewed prompt prose intact.
const AI_DEFAULT_INSTRUCTION = <<<'PROMPT'
Je bent radio-eindredacteur van ZuidWest FM, de radiozender van Streekomroep ZuidWest. De zender is actief in Roosendaal, Bergen op Zoom, Etten-Leur, Woensdrecht, Moerdijk, Halderberge, Steenbergen, Tholen, Rucphen en Zundert. Gebruik dit alleen als regionale context. Het is 2026.

Maak van het artikel een zelfstandig en natuurlijk radiobericht. Hanteer bij spanning tussen regels deze volgorde: brongetrouwheid, nieuwswaarde, natuurlijke spreektaal en daarna vorm.

Brongetrouwheid:
- laat iedere mededeling rechtstreeks door de bron ondersteunen en voeg niets toe;
- verander geen namen, plaatsen, data, tijden of aantallen en neem officiële namen, thema's, slogans en titels letterlijk over;
- behoud bronvermelding en onzekerheid waar die journalistiek nodig zijn: een plan, wens, mogelijkheid of vermoeden is geen vaststaand feit;
- voeg geen eigen oorzaak, gevolg, oordeel of achtergrond toe.

Nieuwsselectie:
- begin met het belangrijkste nieuwsfeit en kies daarna alleen details die de luisteraar nodig heeft;
- maak de tekst niet langer dan de bron rechtvaardigt en vul een kort bericht nooit op;
- sluit logisch af met een concreet feit, actie, datum of gevolg wanneer de bron dat biedt, niet met losse hoop, ambitie, mening of promotietaal.

Spreekstijl:
- schrijf volwassen Nederlands op B1-niveau met gewone woorden, actieve werkwoorden en concrete formuleringen;
- leg noodzakelijk jargon kort uit en kies waar passend woorden als “gekregen”, “gevonden” en “gebotst”;
- schrijf zoals een nieuwslezer praat: vloeiend, helder en met één hoofdgedachte per zin;
- gebruik komma's natuurlijk, maar verbind geen zelfstandige hoofdzinnen alleen met een komma en vermijd een stapeling van bijzinnen;
- noem een persoon eerst volledig en gebruik daarna natuurlijk een achternaam, voornaamwoord of duidelijke omschrijving;
- voorkom opvallende herhaling en vage verwijzingen; zet tijd, plaats en andere details bij het nieuwsfeit waarop ze betrekking hebben.

Vorm:
- lever één alinea van maximaal 65 woorden; er is geen minimum;
- gebruik bij voorkeur 4 of 5 zinnen; 3 of 6 zinnen mogen wanneer dat natuurlijker is;
- schrijf getallen boven twaalf als cijfer, bijvoorbeeld 24, 3000 en 3,8 miljoen.

Controleer vóór het antwoord of ieder feit klopt, de tekst prettig voor te lezen is en de grens van 65 woorden niet wordt overschreden.
PROMPT;
// phpcs:enable Generic.Files.LineLength.TooLong
const AI_ARTICLE_PROMPT = "Artikel:\n\"\"\"\n%s\n\"\"\"";
const AI_EXAMPLE_PROMPT = '%s — leer van toon, nieuwsselectie en formulering. Neem geen feiten over. '
	. 'De lengte en het zinsaantal kunnen afwijken van de instructie voor het nieuwe artikel.';

/**
 * Returns configured text-generation models.
 *
 * Model keys are "provider/model" setting values as understood by ai_parse_model_setting().
 *
 * @since 0.6.0
 * @return array<string, array{label: string, models: array<string, string>}>
 */
function ai_get_available_models(): array {
	if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
		return array();
	}

	try {
		$requirements = new ModelRequirements( array( CapabilityEnum::textGeneration() ), array() );
		$models       = array();

		foreach ( AiClient::defaultRegistry()->findModelsMetadataForSupport( $requirements ) as $provider_models_metadata ) {
			$provider        = $provider_models_metadata->getProvider();
			$provider_models = array();

			foreach ( $provider_models_metadata->getModels() as $model ) {
				$provider_models[ $provider->getId() . '/' . $model->getId() ] = $model->getName();
			}

			if ( array() !== $provider_models ) {
				$models[ $provider->getId() ] = array(
					'label'  => $provider->getName(),
					'models' => $provider_models,
				);
			}
		}

		return $models;
	} catch ( \Throwable $e ) {
		log( 'error', 'AiHandler', 'Could not list AI models', array( 'error' => $e->getMessage() ) );
		return array();
	}
}

/**
 * Parses a provider and model preference.
 *
 * @since 0.6.0
 * @param mixed $value The stored setting value.
 * @return array{0: string, 1: string}|null Provider and model ID, or null when unset or malformed.
 */
function ai_parse_model_setting( mixed $value ): ?array {
	if ( ! is_string( $value ) ) {
		return null;
	}

	$parts = explode( '/', $value, 2 );
	return 2 === count( $parts ) && ! in_array( '', $parts, true ) ? $parts : null;
}

/**
 * Generates speech text through the WordPress AI Client.
 *
 * @since 0.5.0
 * @since 0.7.0 Added the `$current_post_id` parameter and curated example mix.
 * @since 0.8.0 Omits the temperature parameter and rebuilds the prompt per attempt.
 * @param string $content         The source content.
 * @param int    $current_post_id Current post ID to exclude from examples, 0 for none.
 * @return string|null The generated content or null on failure.
 */
function ai_generate_content( string $content, int $current_post_id ): ?string {
	$options       = get_plugin_settings();
	$speech_prompt = (string) $options['speech_prompt'];
	$instruction   = '' !== trim( $speech_prompt ) ? $speech_prompt : AI_DEFAULT_INSTRUCTION;

	$messages = array();
	foreach ( get_few_shot_examples( $current_post_id ) as $example ) {
		$example_label = 'accepted' === $example['provenance']
			? 'VOORBEELD DAT DE REDACTIE DIRECT HEEFT GEACCEPTEERD'
			: 'VOORBEELD DAT DE REDACTIE HEEFT AANGEPAST';
		$messages[]    = new UserMessage(
			array( new MessagePart( sprintf( AI_EXAMPLE_PROMPT, $example_label ) . "\n\n" . sprintf( AI_ARTICLE_PROMPT, $example['input'] ) ) )
		);
		$messages[]    = new ModelMessage( array( new MessagePart( $example['output'] ) ) );
	}
	$messages[] = new UserMessage( array( new MessagePart( sprintf( AI_ARTICLE_PROMPT, $content ) ) ) );

	$model_preference = ai_parse_model_setting( $options['ai_model'] );

	// The prompt builder stores caught exceptions, so every attempt needs a fresh instance.
	$build_prompt = static function () use ( $messages, $instruction, $model_preference ): \WP_AI_Client_Prompt_Builder {
		$prompt = wp_ai_client_prompt( $messages )
			->using_system_instruction( $instruction )
			->using_max_tokens( 1000 );
		return null === $model_preference ? $prompt : $prompt->using_model_preference( $model_preference );
	};

	if ( ! $build_prompt()->is_supported_for_text_generation() ) {
		log( 'error', 'AiHandler', 'WordPress AI Client does not support text generation for this prompt' );
		return null;
	}

	$max_attempts = 3;
	for ( $attempt = 1; $attempt <= $max_attempts; ++$attempt ) {
		$result = $build_prompt()->generate_text();

		if ( is_wp_error( $result ) ) {
			log(
				'error',
				'AiHandler',
				'WordPress AI Client request failed',
				array(
					'error'      => $result->get_error_message(),
					'error_code' => $result->get_error_code(),
				)
			);
		} else {
			$result = trim( $result );
			if ( '' !== $result ) {
				log( 'info', 'AiHandler', 'Speech text generated successfully', array( 'content_length' => strlen( $result ) ) );
				return $result;
			}
			log( 'error', 'AiHandler', 'WordPress AI Client returned empty speech text' );
		}

		if ( $attempt < $max_attempts ) {
			$delay = 2 ** $attempt; // 2, 4 seconds.
			log( 'info', 'AiHandler', "Retry attempt {$attempt}/{$max_attempts} after {$delay}s delay" );
			sleep( $delay );
		}
	}

	log( 'error', 'AiHandler', "All {$max_attempts} retry attempts failed" );
	return null;
}
