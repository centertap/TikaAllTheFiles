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

use File;
use MediaHandler;


class WrappingMediaHandlerBase extends MediaHandler {

  /**
   * Handle to a common Core object used by this handler
   *
   * @var Core
   */
  protected Core $core;

  /**
   * The TypeProfile which defines the operation of this handler
   *
   * @var TypeProfile
   */
  protected TypeProfile $typeProfile;

  /**
   * The MediaHander which we are wrapping/shimming.
   *
   * @var MediaHandler
   */
  protected MediaHandler $wrapped;


  /**
   * Constructor --- only used by TatfMediaHandlerFactory
   *
   * @param Core $core - shared Core object for this handler
   * @param TypeProfile $typeProfile - the profile defining this handler
   * @param MediaHandler $wrapped - underlying native handler to be wrapped
   */
  public function __construct( Core $core, TypeProfile $typeProfile,
                               MediaHandler $wrapped ) {
    $this->core = $core;
    $this->typeProfile = $typeProfile;
    $this->wrapped = $wrapped;
  }


  /**
   * Implementation of getEntireText().
   *
   * Technically, we should override getPageText() (which will be called by
   * MediaHandler::getEntireText()).  But, given the other screwball stuff
   * we are willing to do, might as well eliminate the middle-man.
   *
   * (And, this way we can let getPageText() relegate to $wrapped directly,
   * and if something like ProofreadPages wants the original page-by-page
   * text, they might even get it.)
   *
   * {@inheritDoc}
   */
  public function getEntireText( File $file ) {
    $this->core->getLogger()->debug( 'getEntireText() for "{title}"',
                                     [ 'title' => $file->getTitle(), ] );

    // (Turn /false/ $otherXXX into /null/...)
    $otherContent = $this->wrapped->getEntireText( $file ) ?: null;
    $otherFormattedMetadata = $this->wrapped->formatMetadata( $file ) ?: null;

    return $this->core->generateTextContent(
        $this->typeProfile, $file, $otherContent, $otherFormattedMetadata )
        ?? false;
  }


  /**
   * Implementation of getMetadata().
   *
   * We *always* call the wrapped handler, because it is unlikely that it
   * will function correctly if it doesn't get to have its own metadata
   * attached to the file.  However, we *may* try to sneak our own metadata
   * blob into the wrapped handler's metadata.
   *
   * {@inheritDoc}
   */
  public function getMetadata( $image, $path ) {
    $otherSerialized = $this->relegateTo( __FUNCTION__, ...func_get_args() );

    $strategy = $this->typeProfile->metadataStrategy;
    if ( $strategy === 'no_tika' ) {
      return $otherSerialized;
    }

    $otherMetadata = null;
    if ( $otherSerialized !== '' ) {
      // TODO(maddog) Catch/ignore/handle unserialize errors appropriately.
      $otherMetadata = unserialize( $otherSerialized );

      // NB: unserialize() returns false if it fails... and some other
      // MediaHandlers (looking at you, PdfHandler!) go ahead serialize
      // the value false if they fail to create any metadata (instead of
      // behaving well and returning '' or serializing an empty array).
      if ( !$otherMetadata ) {
        $otherMetadata = null;
      }
    }

    $metadata = $this->core->generateMetadata(
        $this->typeProfile, $path, $otherMetadata );
    $serialized_metadata = ( $metadata === null ) ? '' : serialize( $metadata );
    return $serialized_metadata;
  }


  /**
   * Implementation of formatMetadata().
   * This is the method that makes metadata visible to the user.
   *
   * If we did manage to stash our own metadata inside $wrapped's metadata,
   * here we have a chance to display it to the user.
   *
   * Though not clearly documented in the API, this needs to return false
   * when there is no metadata to display.
   *
   * {@inheritDoc}
   */
  public function formatMetadata( $file, $context = false ) {
    $otherFormattedMetadata =
        $this->relegateTo( __FUNCTION__, ...func_get_args() );
    if ( $this->typeProfile->metadataStrategy === 'no_tika' ) {
      return $otherFormattedMetadata;
    }

    $tikaMetadata = null;
    $serializedMetadata = $file->getMetadata();
    if ( $serializedMetadata ) {
      $metadata = unserialize( $serializedMetadata );
      $tikaMetadata = Core::unstashTatfMetadata( $metadata ?: [] );
      if ( $tikaMetadata === null ) {
        $this->core->getLogger()->debug( 'No stashed TATF metadata???' );
        $this->core->getLogger()->debug( var_export( $metadata, true ) );
      }
    }

    return $this->core->formatMetadataForMwUi( $this->typeProfile,
                                               $tikaMetadata,
                                               $otherFormattedMetadata ?: null,
                                               $context ) ?? false;
  }

  // **************************************************************************
  //
  // Relegate every other public non-static method of MediaHandler to $wrapped
  //
  //                      "WRAP ALL THE METHODS"
  //
  // **************************************************************************

  /**
   * Helper function for invoking wrapped methods.
   *
   * @param string $f - function to relegate to
   * @param mixed $args,... - arguments to forward
   * @return mixed
   */
  protected function relegateTo( $f, ...$args ) {
    return $this->wrapped->$f( ...$args );
  }

  // abstract
  /** {@inheritDoc} */
  public function getParamMap() {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  // abstract
  /** {@inheritDoc} */
  public function validateParam( $name, $value ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  // abstract
  /** {@inheritDoc} */
  public function makeParamString( $params ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  // abstract
  /** {@inheritDoc} */
  public function parseParamString( $str ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  // abstract
  /** {@inheritDoc} */
  public function normaliseParams( $image, &$params ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  // abstract
  /** {@inheritDoc} */
  public function getImageSize( $image, $path ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function convertMetadataVersion( $metadata, $version = 1 ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function getMetadataType( $image ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function isMetadataValid( $image, $metadata ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function getCommonMetaArray( File $file ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function getScriptedTransform( $image, $script, $params ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  // final public function getTransform( $image, $dstPath, $dstUrl, $params ) {
  //   "final"?  Sounds serious --- we will leave this one alone.
  // }

  // abstract
  /** {@inheritDoc} */
  public function doTransform( $image, $dstPath, $dstUrl, $params, $flags = 0 ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function getThumbType( $ext, $mime, $params = null ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function canRender( $file ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function mustRender( $file ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function isMultiPage( $file ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function pageCount( File $file ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function isVectorized( $file ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function isAnimatedImage( $file ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function canAnimateThumbnail( $file ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function isEnabled() {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function getPageDimensions( File $image, $page ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function getPageText( File $image, $page ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  // public function getEntireText( File $file ) {
  //   Psych!  We actually implemented this ourselves, up at the top.
  // }

  /** {@inheritDoc} */
  public function getShortDesc( $file ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function getLongDesc( $file ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function getDimensionsString( $file ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function parserTransformHook( $parser, $file ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function verifyUpload( $fileName ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function removeBadFile( $dstPath, $retval = 0 ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function filterThumbnailPurgeList( &$files, $options ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function canRotate() {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function getRotation( $file ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function getAvailableLanguages( File $file ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function getMatchedLanguage( $userPreferredLanguage,
                                      array $availableLanguages ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function getDefaultRenderLanguage( File $file ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function getLength( $file ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function isExpensiveToThumbnail( $file ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function supportsBucketing() {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function sanitizeParamsForBucketing( $params ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function getWarningConfig( $file ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  /** {@inheritDoc} */
  public function getContentHeaders( $metadata ) {
    return $this->relegateTo( __FUNCTION__, ...func_get_args() );
  }

  // **************************************************************************
  //  How about public static functions?
  //
  //  It is unlikely that we would need to wrap any, unless there was some
  //  overly-clever static:: versus self:: calling going on somewhere.
  //  These are mostly semi-internal helper methods.
  //
  //  Only getMetadataVersion() is marked "@stable to override", and this is
  //  probably a mistake (since it runs a "GetMetadataVersion" hook to alter
  //  its behavior.
  //
  //  Anyhow, let's keep an eye on them:
  //
  //  public static function getHandler( $type )
  //  public static function getMetadataVersion()
  //  public static function getGeneralShortDesc( $file )
  //  public static function getGeneralLongDesc( $file )
  //  public static function fitBoxWidth( $boxWidth, $boxHeight, $maxHeight )
  //  public static function getPageRangesByDimensions( $pagesByDimensions )
}
