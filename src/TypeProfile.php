<?php
/**
 * This file is part of TikaAllTheFiles.
 *
 * Copyright 2021 Matt Marjanovic
 *
 * TikaAllTheFiles is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as published
 * by the Free Software Foundation; either version 3 of the License, or any
 * later version.
 *
 * TikaAllTheFiles is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General
 * Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with TikaAllTheFiles.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @file
 */

declare( strict_types = 1 );

namespace MediaWiki\Extension\TikaAllTheFiles;

class TypeProfile {

  /** @var string Strategy for handler creation */
  public string $handlerStrategy;

  /** @var bool Should OCR be enabled for this file type? */
  public bool $allowOcr;

  /** @var string Specifies which languages shall be used for OCR */
  public string $ocrLanguages;

  /** @var string Strategy for creating text-search content */
  public string $contentStrategy;

  /** @var string What to include in text-search content */
  public string $contentComposition;

  /** @var string */
  public string $metadataStrategy;


  /**
   * Creates a new TypeProfile if a valid one can be configured from $label.
   *
   * @param string $label profile label (e.g., a mime-type)
   * @param array $configMap array of labels mapped to configuration blocks
   *
   * @return ?TypeProfile a new TypeProfile, or null
   */
  public static function newFromConfig( string $label,
                                        array $configMap ): ?TypeProfile {
    $tc = new TypeProfile();
    return $tc->resolveFromConfig( $label, $configMap );
  }


  // TODO(maddog) When PHP>=8 is available, use enum.
  public const HANDLER_STRATEGIES = [
      'fallback', // TATF-only handler only if no other handler
      'override', // TATF-only handler always
      'wrapping', // if other handler, wrap with TATF; otherwise use TATF-only
                                     ];

  // TODO(maddog) When PHP>=8 is available, use enum.
  // TODO(maddog) What if someone wants "add-metadata-to-content" but doesn't
  //              want any text extraction?  Well... if that someone appears,
  //              we will deal with it then.
  public const CONTENT_STRATEGIES = [
      'combine', // append any tika-content to any original text
      'only_tika', // tika-content, if any, or nothing
      'prefer_tika', // tika-content only, if any, otherwise original text
      'prefer_other', // original text only, if any, otherwise tika-content
      'no_tika', // original text only; skip tika text extraction
                                     ];
  // TODO(maddog) When PHP>=8 is available, use enum.
  public const CONTENT_COMPOSITIONS = [
      'text',
      'metadata',
      'text_and_metadata',
                                       ];

  // TODO(maddog) When PHP>=8 is available, use enum.
  public const METADATA_STRATEGIES = [
      'combine', // append any tika-metadata to any original metadata
      'only_tika', // tika-metadata, if any, or nothing
      'prefer_tika', // tika-metadata only, if any, otherwise original metadata
      'prefer_other', // original metadata only, if any, otherwise tika's
      'no_tika', // original metadata only; skip tika metadata extraction
                                     ];

  /**
   * Helper to simulate a real enum type:  verify that the value of an instance
   * member belongs to a set of valid values.
   *
   * @param string $member - the instance member to verify
   * @param array $list - list of valid values
   *
   * @return bool - returns true if the member has a valid value
   */
  private function checkEnum( string $member, array $list ): bool {
    if ( in_array( $this->$member, $list, true/*strict*/ ) ) {
      return true;
    }
    $imploded = implode( ', ', $list );
    Core::warn(
        "{$member} value '{$this->$member}' is not one of: {$imploded}." );
    return false;
  }


  /**
   * Check that this TypeProfile is valid.
   *
   * @return bool - return true if valid, false otherwise.
   */
  private function checkValidity(): bool {
    $checks = [
        $this->checkEnum( 'handlerStrategy', self::HANDLER_STRATEGIES ),
        $this->checkEnum( 'contentStrategy', self::CONTENT_STRATEGIES ),
        $this->checkEnum( 'contentComposition', self::CONTENT_COMPOSITIONS ),
        $this->checkEnum( 'metadataStrategy', self::METADATA_STRATEGIES ),
               ];
    return !in_array( false, $checks, /*strict=*/true );
  }


  /**
   * Given a starting label, resolve a profile from a configuration map.
   *
   * @param string $rootLabel - the label to start with
   * @param array $configMap array of labels mapped to configuration blocks
   *
   * @return ?TypeProfile - returns $this if $rootLabel defines a valid profile,
   *  otherwise returns null
   */
  private function resolveFromConfig( string $rootLabel,
                                      array $configMap ): ?TypeProfile {
    $visitedLabels = []; // array used as hash-set
    $label = $rootLabel;
    $unresolved = [
          'handlerStrategy' => 'handler_strategy',
          'allowOcr' => 'allow_ocr',
          'ocrLanguages' => 'ocr_languages',
          'contentStrategy' => 'content_strategy',
          'contentComposition' => 'content_composition',
          'metadataStrategy' => 'metadata_strategy',
                   ];
    while ( $label !== null ) {
      $block = self::resolveStringLabel( $label, $configMap, $visitedLabels );
      unset( $label );

      if ( $block === null ) {
        break;
      }

      foreach ( $unresolved as $member => $key ) {
        if ( self::tryResolve( $member, $key, $block ) ) {
          unset( $unresolved[$member] );
        }
      }

      // Suppress false positive (phan does not realize that the size of
      // can change $unresolved.)
      // @phan-suppress-next-line PhanSuspiciousValueComparisonInLoop
      if ( count( $unresolved ) === 0 ) {
        if ( $this->checkValidity() ) {
          return $this;
        }
        Core::warn(
            "Unable to create a valid profile for label '{$rootLabel}'" );
        return null;
      }

      // Try the next block in the chain, if any.
      $label = $block[ 'inherit' ] ?? null;
      unset( $block );
    }

    $remaining = implode( ', ', $unresolved );
    Core::warn( "Unable to create a complete profile for label '{$rootLabel}'; unresolved values for: {$remaining}" );
    return null;
  }


  /**
   * Resolves a label to a configuration block, dereferencing any aliases
   * and detecting referential loops.
   *
   * @param ?string $label - the label to resolve
   * @param array $configMap array of labels mapped to configuration blocks
   * @param array &$visitedLabels - list of labels which have been visited;
   *  passed by reference and modified (with addition of newly visited labels)
   *
   * @return ?array - returns a configuration block (array) if $label can be
   *  resolved, otherwise returns null.
   */
  private static function resolveStringLabel(
      ?string $label, array $configMap, array &$visitedLabels ): ?array {
    while ( is_string( $label ) ) {
      if ( isset( $visitedLabels[ $label ] ) ) {
        Core::warn(
            "Referential loop detected in MimeTypeProfiles involving labels: {labels}",
            [ 'labels' => implode( ', ', array_keys( $visitedLabels ) ) ] );
        return null;
      }
      $visitedLabels[ $label ] = true;

      $label = $configMap[ $label ] ?? null;
      if ( $label === null ) {
        Core::warn(
            "Dangling reference '{label}' in MimeTypeProfiles",
            [ 'label' => $label ] );
      }
    }
    // Not a string anymore (or ever)?
    // Must be a block (an array), or null, or false.
    return is_array( $label ) ? $label : null;
  }


  /**
   * If not already set, try to resolve a profile property from a configuration
   * block and set the member variable.
   *
   * @param string $member - name of the member variable to check and/or set
   * @param string $key - key for the property in a config block
   * @param array $block - a configuration block
   *
   * @return bool - returns false if the member variable is left unset,
   *  otherwise true.
   */
  private function tryResolve( string $member,
                               string $key, array $block ): bool {
    if ( !isset( $this->$member ) ) {
      $value = $block[ $key ] ?? null;
      if ( $value === null ) {
        return false;
      }
      $this->$member = $value;
    }
    return true;
  }

}
