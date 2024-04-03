<?php
/**
 * This file is part of TikaAllTheFiles.
 *
 * Copyright 2024 Matt Marjanovic
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
class WrappingMediaHandler_1_37 extends WrappingMediaHandlerBase {

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


  // This method determines whether a handler class gets treated like a
  // new >=1.37 class or an old <1.37 class.  The default implementation
  // in MediaHandler has logic to figure this out, but in our case, we
  // always want to be treated the same as our $wrapped handler.
  /** {@inheritDoc} */
  protected function useLegacyMetadata() {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }


  // **************************************************************************
  // Public functions of MediaHandler in MW 1.37
  //
  // Unless otherwise noted (or implemented), non-static, non-final functions
  // are overridden by wrapper implementations in WrappingMediaHandlerBase.
  //
  // **************************************************************************
  //
  //
  // public static function getHandler( $type )
  // abstract public function getParamMap();
  // abstract public function validateParam( $name, $value );
  // abstract public function makeParamString( $params );
  // abstract public function parseParamString( $str );
  // abstract public function normaliseParams( $image, &$params );
  // public function getImageSize( $image, $path )
  //   - was abstract before 1.37; deprecated in 1.37


  // New in 1.37.
  /**
   * @override
   * @unused-param $state
   * (NB: $state *does* get passed along to wrapped handler, but we do not
   *      use it after that.)
   */
  public function getSizeAndMetadata( $state, $path ) {
    $sizeAndMetadata = $this->relegateTo( __FUNCTION__, ...func_get_args() );
    // Documentation for this method says:
    //   "If this returns null, the caller will fall back to getImageSize()
    //    and getMetadata()."
    // So, if $wrapped returns null, we should just return null, too, and
    // let the caller call getMetadata() instead.
    if ( $sizeAndMetadata === null ) {
      return null;
    }

    $otherMetadata = $sizeAndMetadata[ 'metadata' ] ?? null;
    $metadata = $this->core->generateMetadata(
        $this->typeProfile, $path, $otherMetadata );

    if ( $metadata === null ) {
      unset( $sizeAndMetadata[ 'metadata' ] );
    } else {
      $sizeAndMetadata[ 'metadata' ] = $metadata;
    }
    return $sizeAndMetadata;
  }


  // public function getMetadata( $image, $path )
  //   - novel override in WrappingMediaHandlerBase
  //
  //   - Deprecated since 1.37.
  //
  //   - Newer subclasses should specialize getSizeAndMetadata() instead,
  //     but getMetadata() stills exists, with a NOP implementation in
  //     MediaHandler.  Older subclasses still specialize this.
  //
  //   - This will get called if $wrapped is an older "legacy" class
  //     that itself specializes getMetadata(), so we still need to
  //     intervene here.  In other words: if the $wrapped handler is
  //     still using this, e.g., as a 1.35 handler would, then treat
  //     this like we would treat a 1.35 handler.
  //
  //
  // final public function getSizeAndMetadataWithFallback( $file, $path )
  // public static function getMetadataVersion()
  // public function convertMetadataVersion( $metadata, $version = 1 )
  // public function getMetadataType( $image )
  // public function isMetadataValid( $image, $metadata )

  // New in 1.37.
  /**
   * @override
   * @suppress PhanUnusedPublicMethodParameter
   */
  public function isFileMetadataValid( $image ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

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

  // New in 1.37.
  /**
   * @override
   */
  public function useSplitMetadata() {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }
}
