<?php
/**
 * This file is part of TikaAllTheFiles.
 *
 * Copyright 2023 Matt Marjanovic
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

use MediaWiki\Extension\TikaAllTheFiles\Enums\{ ContentComposition,
                                                ContentStrategy,
                                                HandlerStrategy,
                                                MetadataStrategy,
                                                };

class TypeProfile {

  /** @var HandlerStrategy Strategy for handler creation */
  public HandlerStrategy $handlerStrategy;

  /** @var bool Should OCR be enabled for this file type? */
  public bool $allowOcr;

  /** @var string Specifies which languages shall be used for OCR */
  public string $ocrLanguages;

  /** @var ContentStrategy Strategy for creating text-search content */
  public ContentStrategy $contentStrategy;

  /** @var ContentComposition What to include in text-search content */
  public ContentComposition $contentComposition;

  /** @var MetadataStrategy Strategy for choosing metadata sources */
  public MetadataStrategy $metadataStrategy;


  // TODO(maddog) Does it really make sense to ever return null, or is it
  //              better to just explode if no valid/complete profile can
  //              be created from the configuration?
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
    // Map from each member/property name to pair of config parameter name and
    // function to parse config parameter value.
    $unresolved = [
        'handlerStrategy' => ['handler_strategy', HandlerStrategy::from(...)],
        'allowOcr' => ['allow_ocr', fn ($x) => $x],
        'ocrLanguages' => ['ocr_languages', fn ($x) => $x],
        'contentStrategy' => ['content_strategy', ContentStrategy::from(...)],
        'contentComposition' => ['content_composition',
                                 ContentComposition::from(...)],
        'metadataStrategy' => ['metadata_strategy', MetadataStrategy::from(...)],
                   ];
    while ( $label !== null ) {
      $block = self::resolveStringLabel( $label, $configMap, $visitedLabels );
      unset( $label );

      if ( $block === null ) {
        break;
      }

      foreach ( $unresolved as $member => [$key, $parser] ) {
        if ( self::tryResolve( $member, $key, $parser, $block ) ) {
          unset( $unresolved[$member] );
        }
      }

      // Suppress false positive (phan does not realize that the size of
      // $unresolved can change.)
      // @phan-suppress-next-line PhanSuspiciousValueComparisonInLoop
      if ( count( $unresolved ) === 0 ) {
        return $this;
      }

      // Try the next block in the chain, if any.
      $label = $block[ 'inherit' ] ?? null;
      unset( $block );
    }

    $remaining = implode( ', ', array_column( $unresolved, 0 ) );
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
                               string $key,
                               $parser,
                               array $block ): bool {
    if ( !isset( $this->$member ) ) {
      $value = $block[ $key ] ?? null;
      if ( $value === null ) {
        return false;
      }
      $this->$member = $parser($value);
    }
    return true;
  }

}
