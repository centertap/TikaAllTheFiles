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

use MediaHandler;
use MediaHandlerFactory;
use MediaWiki\Extension\TikaAllTheFiles\Enums\HandlerStrategy;


class TatfMediaHandlerFactory extends MediaHandlerFactory {

  /**
   * Handle to a common Core object used by this factory and its handlers
   *
   * @var Core
   */
  private Core $core;

  /**
   * The MediaHanderFactory which we are wrapping/shimming.
   *
   * @var MediaHandlerFactory
   */
  private MediaHandlerFactory $wrappedFactory;

  /**
   * Map from mime-types (strings) to MediaHandlers created by this factory
   *
   * @var array mapping mime-type => (Solo,Wrapping)MediaHandler
   */
  private array $ourHandlers;


  /**
   * Constructor --- invoked by a ServiceManipulator installed by our
   *                 MediaWikiServicesHook
   *
   * @param MediaHandlerFactory $wrappedFactory - pre-existing factory which
   *  will be wrapped/shimmed by this new factory
   */
  public function __construct( MediaHandlerFactory $wrappedFactory ) {
    $this->core = new Core();
    $this->wrappedFactory = $wrappedFactory;

    $this->core->getLogger()->debug(
        'Wrapping existing MediaHandlerFactory {factory}',
        [ 'factory' => $wrappedFactory ] );
  }


  /** {@inheritDoc} */
  public function getHandler( $type ) {
    $ourHandler = $this->ourHandlers[$type] ?? null;

    if ( $ourHandler === null ) {
      // TODO(maddog) Do we need to check isEnabled() on any handlers???
      $theirHandler = $this->wrappedFactory->getHandler( $type );
      $this->core->getLogger()->debug(
          'theirHandler for {type} is {theirHandler}',
          [ 'type' => $type,
            'theirHandler' => $theirHandler ] );
      // $theirHandler will be false if:
      //  (a) there is no handler registered for $type, or
      //  (b) the registered handler returns false from isEnabled().

      $typeProfile = $this->core->getMimeTypeProfile( $type );
      // TODO(maddog) Remove suppress when phan understands nullsafe operator.
      //              https://github.com/phan/phan/issues/4067
      // @phan-suppress-next-line PhanPossiblyUndeclaredProperty
      $strategy = $typeProfile?->handlerStrategy;

      // | strategy | theirs    || ours
      // |----------|-----------||--------------------------------
      // |     null | false     || false
      // |          | Something || Something
      // |----------|-----------||--------------------------------
      // | Fallback | false     || SoloMediaHandler
      // |          | Something || Something
      // |----------|-----------||--------------------------------
      // | Override | false     || SoloMediaHandler
      // |          | Something || SoloMediaHandler
      // |----------|-----------||--------------------------------
      // | Wrapping | false     || SoloMediaHandler
      // |          | Something || WrappingMediaHandler(Something)
      $ourHandler = match ( $strategy ) {
        // We won't try to handle this type at all.
        null => $theirHandler,

        // We will handle this type ourselves if not already handled.
        HandlerStrategy::Fallback =>
        $theirHandler ?: new SoloMediaHandler(
            $this->core, Core::insistNonNull($typeProfile) ),

        // We will always handle this type ourselves.
        HandlerStrategy::Override => new SoloMediaHandler(
            $this->core, Core::insistNonNull($typeProfile) ),

        // We will wrap the existing handler, or handle it ourselves if we
        // have to.
        HandlerStrategy::Wrapping => $this->maybeWrapHandler(
            $theirHandler ?: null, Core::insistNonNull($typeProfile), $type ),
      };
      $this->core->getLogger()->debug(
          'Execute "{strategy}" strategy for type "{type}": ' .
          'handler {theirs} becomes {ours}.',
          // TODO(maddog) Remove when phan understands nullsafe operator.
          //              https://github.com/phan/phan/issues/4067
          // @phan-suppress-next-line PhanPossiblyUndeclaredProperty
          [ 'strategy' => $strategy?->value,
            'type' => $type,
            'theirs' => $theirHandler,
            'ours' => $ourHandler ] );
      Core::insist( $ourHandler !== null );
      $this->ourHandlers[$type] = $ourHandler;
    }
    return $ourHandler;
  }


  /**
   * Attempt to wrap an existing MediaHandler with a TATF wrapping handler.
   * This always returns a viable MediaHandler, but whether or not it is a
   * wrapping handler depends on:
   *  - actually having an existing handler to wrap;
   *  - knowing how to wrap handlers for this version of MediaWiki
   *
   * @param ?MediaHandler $theirHandler the non-TATF handler provided by MW
   *  or other extensions for $type, if any
   * @param TypeProfile $typeProfile the TypeProfile that TATF has computed
   *  for $type
   * @param string $type the MIME type in question
   *
   * @return MediaHandler
   */
  private function maybeWrapHandler( ?MediaHandler $theirHandler,
                                     TypeProfile $typeProfile, string $type
                                     ): MediaHandler {
    // If there is nothing to wrap, we have no choice but to go solo.
    if ( $theirHandler === null ) {
      return new SoloMediaHandler( $this->core, $typeProfile );
    }

    // Simplify MW version string to "major.minor".
    $matches = [];
    Core::insist(
        preg_match( '#^(\d+\.\d+)#', MW_VERSION, $matches ) === 1 );
    $mwMajorMinor = $matches[1];

    switch ( $mwMajorMinor ) {
      case '1.37':
      case '1.38':
      case '1.39':
      case '1.40':
      case '1.41':
        return new WrappingMediaHandler_1_37( $this->core, $typeProfile,
                                              $theirHandler );
      default:
        Core::warn(
            'Unsure if wrapping works with MediaWiki {mw_major_minor}.' .
            '  Resorting to fallback mode for {type}.',
            [ 'mw_major_minor' => $mwMajorMinor,
              'type' => $type ] );
        return $theirHandler;
    }
  }
}
