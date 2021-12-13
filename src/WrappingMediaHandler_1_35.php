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


// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
class WrappingMediaHandler_1_35 extends WrappingMediaHandlerBase {

  /**
   * Constructor --- only used by TatfMediaHandlerFactory
   *
   * @param Core $core - shared Core object for this handler
   * @param TypeProfile $typeProfile - the profile defining this handler
   * @param MediaHandler $wrapped - underlying native handler to be wrapped
   */
  public function __construct( Core $core, TypeProfile $typeProfile,
                               MediaHandler $wrapped ) {
    parent::__construct( $core, $typeProfile, $wrapped );
  }


  // **************************************************************************
  // Public functions of MediaHandler in MW 1.35
  //
  // Unless otherwise noted (or implemented), non-static, non-final functions
  // are overridden by wrapper implementations in WrappingMediaHandlerBase.
  //
  // **************************************************************************
  //
  // public static function getHandler( $type )
  // abstract public function getParamMap();
  // abstract public function validateParam( $name, $value );
  // abstract public function makeParamString( $params );
  // abstract public function parseParamString( $str );
  // abstract public function normaliseParams( $image, &$params );
  // abstract public function getImageSize( $image, $path );
  //
  // public function getMetadata( $image, $path )
  //   - novel override in WrappingMediaHandlerBase
  //
  // public static function getMetadataVersion()
  // public function convertMetadataVersion( $metadata, $version = 1 )
  // public function getMetadataType( $image )
  // public function isMetadataValid( $image, $metadata )
  // public function getCommonMetaArray( File $file )
  // public function getScriptedTransform( $image, $script, $params )
  // final public function getTransform( $image, $dstPath, $dstUrl, $params )
  // abstract public function doTransform( $image, $dstPath,
  //                                       $dstUrl, $params, $flags = 0 );
  // public function getThumbType( $ext, $mime, $params = null )
  // public function canRender( $file )
  // public function mustRender( $file )
  // public function isMultiPage( $file )
  // public function pageCount( File $file )
  // public function isVectorized( $file )
  // public function isAnimatedImage( $file )
  // public function canAnimateThumbnail( $file )
  // public function isEnabled()
  // public function getPageDimensions( File $image, $page )
  // public function getPageText( File $image, $page )
  //
  // public function getEntireText( File $file )
  //   - novel override in WrappingMediaHandlerBase
  //
  // public function formatMetadata( $image, $context = false )
  //   - novel override in WrappingMediaHandlerBase
  //
  // public function getShortDesc( $file )
  // public function getLongDesc( $file )
  // public static function getGeneralShortDesc( $file )
  // public static function getGeneralLongDesc( $file )
  // public static function fitBoxWidth( $boxWidth, $boxHeight, $maxHeight )
  // public function getDimensionsString( $file )
  // public function parserTransformHook( $parser, $file )
  // public function verifyUpload( $fileName )
  // public function removeBadFile( $dstPath, $retval = 0 )
  // public function filterThumbnailPurgeList( &$files, $options )
  // public function canRotate()
  // public function getRotation( $file )
  // public function getAvailableLanguages( File $file )
  // public function getMatchedLanguage( $userPreferredLanguage,
  //                                     array $availableLanguages )
  // public function getDefaultRenderLanguage( File $file )
  // public function getLength( $file )
  // public function isExpensiveToThumbnail( $file )
  // public function supportsBucketing()
  // public function sanitizeParamsForBucketing( $params )
  // public function getWarningConfig( $file )
  // public static function getPageRangesByDimensions( $pagesByDimensions )
  // public function getContentHeaders( $metadata )
}
