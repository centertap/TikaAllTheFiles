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

use MediaHandlerFactory;
use MediaWiki\Hook\MediaWikiServicesHook;
use MediaWiki\MediaWikiServices;
// use MWException;

class Hooks implements MediaWikiServicesHook {

  /**
   * Handle to a common Core object
   *
   * @var Core
   */
  private Core $core;


  /**
   * Constructor --- only used by MW hook machinery
   */
  public function __construct() {
    $this->core = new Core();
  }


  /**
   * Implementation of MediaWikiServicesHook.
   *
   * @param MediaWikiServices $services
   * @return void
   */
  public function onMediaWikiServices( $services ) {
    // TODO(maddog) Spammy logging to help diagnose the doubled manipulator
    //              bug, as described further below.
    // $this->core->getLogger()->debug(
    //     'onMediaWikiServices hook executes {exception}',
    //     [ 'exception' => new MWException() ] );

    $services->addServiceManipulator(
        'MediaHandlerFactory',
        /** @unused-param $container */
        function ( MediaHandlerFactory $oldFactory,
                   MediaWikiServices $container ) {
          # TODO(maddog) Due to some bug(*), in at least MW 1.35.3, this service
          #              manipulator gets added twice.  So, we try to catch any
          #              double-execution and prevent wrapping-the-wrapper.
          #
          #    (*)
          #     Setup.php
          #       ExtensionRegistry::getInstance()->loadFromQueue();
          #         (SemanticMediaWiki)
          #           MediaWikiServices::getInstance();
          #             --> run onMediaWikiServices hook
          #                   (hooks call addServiceManipulator() )
          #        ...
          #        MediaWikiServices::resetGlobalInstance( new GlobalVarConfig(), 'quick' );
          #          -> creates a new MediaWikiServices instance
          #          -> run onMediaWikiServices hook
          #                   (hooks call addServiceManipulator() )
          #          -> self::$instance->importWiring( $oldInstance, [ 'BootstrapConfig' ] );
          #             - causes service manipulators from the original instance
          #               to be added to the new instance
          #                 - TA-DA!  All SM's are now duplicated.
          if ( $oldFactory instanceof TatfMediaHandlerFactory ) {
            $this->core->getLogger()->warning(
                'Here we go again!  Our Service Manipulator appears to have ' .
                'been added twice.  We will skip wrapping ourselves.' );
            return null;
          }
          return new TatfMediaHandlerFactory( $oldFactory );
        } );
  }

}
