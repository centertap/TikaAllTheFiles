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
    $services->addServiceManipulator(
        'MediaHandlerFactory',
        /** @unused-param $container */
        function ( MediaHandlerFactory $oldFactory,
                   MediaWikiServices $container ) {
          // NB: MW-1.35 had a bug that caused double-wrapping.  This was fixed
          // in MW-1.36... but let's explode if it ever happens again.
          Core::insist( !($oldFactory instanceof TatfMediaHandlerFactory) );
          return new TatfMediaHandlerFactory( $oldFactory );
        } );
  }

}
