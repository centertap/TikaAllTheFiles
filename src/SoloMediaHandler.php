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
use TransformParameterError;


class SoloMediaHandler extends MediaHandler {

  /**
   * Handle to a common Core object used by this handler
   *
   * @var Core
   */
  private Core $core;

  /**
   * The TypeProfile which defines the operation of this handler
   *
   * @var TypeProfile
   */
  private TypeProfile $typeProfile;


  /**
   * Constructor --- only used by TatfMediaHandlerFactory
   *
   * @param Core $core - shared Core object for this handler
   * @param TypeProfile $typeProfile - the profile defining this handler
   */
  public function __construct( Core $core, TypeProfile $typeProfile ) {
    $this->core = $core;
    $this->typeProfile = $typeProfile;
  }


  /**
   * Implementation of getEntireText().
   *
   * Technically, we should override getPageText() (which will be called by
   * MediaHandler::getEntireText()).  But, given the other screwball stuff
   * we are willing to do, might as well eliminate the middle-man.
   *
   * @override
   */
  public function getEntireText( File $file ) {
    $this->core->getLogger()->debug( 'getEntireText() for "{title}"',
                                     [ 'title' => $file->getTitle(), ] );
    $otherContent = null;  // There is no other content to merge/etc.
    $otherFormattedMetadata = null;  // There is no other metadata to merge/etc.
    return $this->core->generateTextContent(
        $this->typeProfile, $file, $otherContent, $otherFormattedMetadata )
        ?? false;
  }


  /**
   * Implementation of getMetadata().
   * This is the method that extracts metadata from a file.
   *
   * The lacking documentation for this method tells us about the types of
   * $file and $path (mostly), but it doesn't explain what they are for or
   * how they should be used.  From looking through implementations of other
   * MediaHandler's in core and some extensions, it looks like:
   *  * The $file object is sometimes used to cache the computed metadata
   *    (in a dynamically glommed-on member).
   *  * When metadata needs to be computed from scratch, usually $path is
   *    used to get the filepath to the file.
   * It looks like this is deprecated in 1.37 as part of an effort to tidy up
   * these interfaces.
   *
   * @override
   * @unused-param $image
   */
  public function getMetadata( $image, $path ) {
    $this->core->getLogger()->debug( 'getMetadata() for "{path}"',
                                     [ 'path' => $path, ] );

    $otherMetadata = null;  // There is no other metadata to merge/etc.
    $metadata = $this->core->generateMetadata(
        $this->typeProfile, $path, $otherMetadata );
    $serialized_metadata = ( $metadata === null ) ? '' : serialize( $metadata );
    return $serialized_metadata;
  }


  /**
   * Implementation of formatMetadata().
   * This is the method that makes metadata visible to the user.
   *
   * Though not clearly documented in the API, this needs to return false
   * when there is no metadata to display.
   *
   * @override
   */
  public function formatMetadata( $file, $context = false ) {
    $tikaMetadata = Core::unstashTatfMetadata( $file->getMetadataArray() );
    return $this->core->formatMetadataForMwUi( $this->typeProfile,
                                               $tikaMetadata,
                                               /*otherFormattedMetadata=*/null,
                                               $context ) ?? false;
  }


  // ***************************************************************************
  // Methods we need to override, to signal features that we don't support.
  // ***************************************************************************

  // Sorry, we don't know how to render/transform/thumbnail any files.
  /**
   * @override
   * @unused-param $file
   */
  public function canRender( $file ) {
    return false;
  }

  // ***************************************************************************
  // Methods that we don't need to override, because the default implementations
  // are satisfactory.
  // ***************************************************************************
  //
  // public function convertMetadataVersion( $metadata, $version = 1 )  ???
  // public function getMetadataType( $image ) -> false (and unused)
  // public function isMetadataValid( $image, $metadata ) -> self::METADATA_GOOD
  // public function getCommonMetaArray( File $file ) -> false
  // public function getScriptedTransform( $image, $script, $params ) -> false
  // public function getThumbType( $ext, $mime, $params = null )  ???
  //
  // public function mustRender( $file ) -> false
  // public function isMultiPage( $file ) -> false
  // public function pageCount( File $file ) -> false
  // public function isVectorized( $file ) -> false
  // public function isAnimatedImage( $file ) -> false
  // public function canAnimateThumbnail( $file ) -> true (ignored; see previous)
  //
  // public function isEnabled() -> true
  //
  // public function getPageDimensions( File $image, $page ) -> false
  // public function getPageText( File $image, $page ) -> false
  //
  // public function getShortDesc( $file ) -> /sane default/
  // public function getLongDesc( $file ) -> /sane default/
  // public function getDimensionsString( $file ) -> ''
  // public function parserTransformHook( $parser, $file ) -> void/no-op
  // public function verifyUpload( $fileName ) -> Status::newGood()
  // public function removeBadFile( $dstPath, $retval = 0 )  ???
  // public function filterThumbnailPurgeList( &$files, $options ) -> void/no-op
  // public function canRotate() -> false
  // public function getRotation( $file ) -> 0
  // public function getAvailableLanguages( File $file ) -> []
  // public function getMatchedLanguage( $userPreferredLanguage, array $availableLanguages ) -> null
  // public function getDefaultRenderLanguage( File $file ) -> null
  // public function getLength( $file ) -> 0.0
  // public function isExpensiveToThumbnail( $file ) -> false
  // public function supportsBucketing() -> false
  // public function sanitizeParamsForBucketing( $params ) -> $params (no-op)
  // public function getWarningConfig( $file ) -> null
  // public function getContentHeaders( $metadata ) {


  // **************************************************************************
  // Abstract methods we don't really implement, for features we don't support.
  //
  // (Abstract methods in MW 1.35)
  // **************************************************************************

  // Used for embedding images in wikitext, which we don't support.
  /**
   * @override
   */
  public function getParamMap(): array {
    return [];
  }

  // Used for generating thumbnails, which we don't support.
  /**
   * @override
   * @unused-param $name
   * @unused-param $value
   */
  public function validateParam( $name, $value ): bool {
    return false;
  }

  // Used for generating thumbnail filenames, but we don't support thumbnails.
  /**
   * @override
   * @unused-param $params
   */
  public function makeParamString( $params ) {
    return "";
  }

  // Does the inverse of makeParamString().
  /**
   * @override
   * @unused-param $str
   */
  public function parseParamString( $str ) {
    return false;
  }

  // Prepares thumbnail parameters for makeParamString().
  /**
   * @override
   * @unused-param $image
   * @unused-param $params
   */
  public function normaliseParams( $image, &$params ) {
    return false;
  }

  // Extract the size of an image.
  /**
   * @override
   * @unused-param $image
   * @unused-param $path
   */
  public function getImageSize( $image, $path ) {
    return false;
  }

  // Transform an image (e.g., to make thumbnails).
  /**
   * @override
   * @unused-param $file
   * @unused-param $dstPath
   * @unused-param $dstUrl
   * @unused-param $params
   * @unused-param $flags
   */
  public function doTransform( $file, $dstPath, $dstUrl, $params, $flags = 0 ) {
    return new TransformParameterError( $params );
  }

}
