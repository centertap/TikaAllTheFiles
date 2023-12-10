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

use Config;
use FormatMetadata;
use IContextSource;
use Psr\Log\LoggerInterface;


class MetadataMapper {

  /**
   * Handle to a LoggerInterface for structured logging
   * @var LoggerInterface
   */
  private LoggerInterface $logger;

  /**
   * Handle to this extension's configuration
   * @var Config
   */
  private Config $config;


  /**
   * Constructor
   *
   * @param LoggerInterface $logger - logger for logging
   * @param Config $config - extension's configuration
   */
  public function __construct( LoggerInterface $logger, Config $config ) {
    $this->logger = $logger;
    $this->config = $config;
  }


  /**
   * Map a Tika-extracted metadata property to something meaningful to the wiki.
   *
   * @param string $tikaName - Tika's name for the property
   * @param mixed $tikaValue - Tika's value for the property; can be a single
   *  JSON-serializable atomic value, or an array of such values
   * @param false|IContextSource $context - optional context for string rendering
   *
   * @return ?ProcessedProperty - returns null if this property should be
   *  discarded; otherwise returns a ProcessedProperty
   */
  public function map( $tikaName, $tikaValue, $context ): ?ProcessedProperty {
    $configMap = $this->config->get( 'PropertyMap' );
    $args =
        $configMap[ $tikaName ] ??
        $configMap[ '!' ] ??
        self::$propertyMap[ $tikaName ] ??
        $configMap[ '*' ] ??
        true;

    if ( $args === false ) {
      // Discard/ignore this property.
      $this->logger->debug( "Discarding {$tikaName}" );
      return null;
    } elseif ( $args === true ) {
      $processor = 'self::processTrivially';
      $args = [];
    } else {
      $processor = array_shift( $args );
    }
    Core::insist( is_callable( $processor ) );

    $this->logger->debug(
        'map:  prop- {tika_name}  processor- {processor}  args- {args}  val- {tika_value}',
        [ 'tika_name' => $tikaName,
          'processor' => $processor,
          'args' => var_export( $args, true ),
          'tika_value' => var_export( $tikaValue, true ) ] );

    $args = array_merge( [ $tikaName, $tikaValue, $context ], $args );
    return call_user_func_array( $processor, $args );

    // TODO(maddog) What if $result[$mw_key] has already been assigned,
    //              as a result of another property?
    //              (Use first, use last, make a list, ...?)
  }


  // Requires one or two map arguments:
  //  - arg1:  tag to be displayed
  //  - arg2:  if different from arg1, tag to tell getFormattedData() how to
  //           transform the values
  //
  /**
   * Try to process a Tika metadata property using the existing Mediawiki core
   * metadata machinery.
   *
   * @param string $tikaName @unused-param
   * @param string|array $tikaValues
   * @param false|IContextSource $context - optional context for string rendering
   * @param string $displayedTag name to be displayed for the property
   * @param ?string $formatAsTag optional name that is recognized by Mediawiki
   *  machinery for transforming the values, if different from $displayedTag
   *
   * @return ProcessedProperty
   */
  public static function anyToMWFormatter(
      string $tikaName, $tikaValues,
      $context,
      string $displayedTag,
      ?string $formatAsTag = null ): ProcessedProperty {
    $formatAsTag ??= $displayedTag;

    $tikaValues = is_array( $tikaValues ) ? $tikaValues : [ $tikaValues ];
    $formattedValues = [];
    foreach ( $tikaValues as $value ) {
      $tags = [ $formatAsTag => $value ];
      $tags = FormatMetadata::getFormattedData( $tags, $context );
      $formattedValues[] = $tags[ $formatAsTag ];
    }

    $loweredCaseTag = strtolower( $displayedTag );
    $exiffedTag = 'exif-' . $loweredCaseTag;

    // Emulate MH::addMeta(), kind of...
    $msg = wfMessage( $exiffedTag, false );
    // (The message *should* exist, since we are assuming that $mwTag is
    // something the MW core uses/understands.  Otherwise, we should be
    // supplying our own text string for the name.)
    $readableName =
        $msg->exists() ? $msg->text() : wfEscapeWikiText( $displayedTag );

    return new ProcessedProperty( $readableName,
                                  $formattedValues,
                                  $loweredCaseTag,
                                  $exiffedTag );
  }


  /**
   * Trivially process a Tika metadata property.
   *
   * @param string $tikaName
   * @param string|array $tikaValues
   * @param false|IContextSource $context @unused-param
   *
   * @return ProcessedProperty
   */
  public static function processTrivially( string $tikaName, $tikaValues,
                                           $context ): ProcessedProperty {
    // "Simply" pass through Tika's name and values (html-escaped).
    $tikaValues = is_array( $tikaValues ) ? $tikaValues : [ $tikaValues ];

    foreach ( $tikaValues as &$value ) {
      $value = htmlspecialchars( $value );
    }

    // TODO(maddog) For element-id, probably should filter the characters in
    //              $tikaName (to make sure it is a legal HTML element id).
    return new ProcessedProperty( htmlspecialchars( $tikaName ),
                                  $tikaValues,
                                  strtolower( $tikaName ),
                                  'exif-' . $tikaName );
  }


  // Constants of Convenience
  //
  //   - for properties that we have figured out...
  private const TEXT_VIA_MWF = 'self::anyToMWFormatter';
  private const TEXT_BAG_VIA_MWF = 'self::anyToMWFormatter';
  private const REAL_VIA_MWF = 'self::anyToMWFormatter';
  private const INTEGER_VIA_MWF = 'self::anyToMWFormatter';
  // TODO(maddog) FormatMetadata::getFormattedMetadata() does not know how
  //              to parse ISO8601 datetimes!
  private const DATE_VIA_MWF = 'self::anyToMWFormatter';
  //
  //   - and those we have yet to map nicely...
  private const PROP_TEXT = 'self::processTrivially';
  private const PROP_TEXT_BAG = 'self::processTrivially';
  private const PROP_BOOLEAN = 'self::processTrivially';
  private const PROP_INTEGER = 'self::processTrivially';
  private const PROP_INTEGER_SEQUENCE = 'self::processTrivially';
  private const PROP_RATIONAL = 'self::processTrivially';
  private const PROP_REAL = 'self::processTrivially';
  private const PROP_DATE = 'self::processTrivially';
  private const PROP_CLOSED_CHOICE = 'self::processTrivially';

  /**
   * @var array
   *
   * Built-in property map - an array mapping Tika property names (strings) to
   * a choice of:
   *
   *  - false - discard/ignore the property
   *  - true - handle with self::processTrivially
   *  - [ processorFunction, ...extraArguments ] - handle with the function
   *
   *  processorFunction must be a callable, which will be called by our map()
   *  method with the arguments
   *   [ $tikaName, $tikaValue, $context, ...$extraArguments ]
   */
  private static $propertyMap = [
      // ======================================================================
      // Annoying properties that should not be indexed or rendered
      // ======================================================================

      'ICC:Green TRC' => false,
      'ICC:Blue TRC' => false,
      'ICC:Red TRC' => false,
      'File Name' => false,

      // ======================================================================
      // Tika Core Properties (not (yet) captured elsewhere)
      // ======================================================================

      // TikaCore TIKA_CONTENT
      // This is the Tika-extracted text from the document/image, which
      // we do not want blurted out in the rendered metadata accidentally.
      // We usually treat thie property specially anyway (i.e., removing
      // it from the metadata array).
      'X-TIKA:content' => false,

      // TikaCore TIKA_CONTENT_HANDLER
      'X-TIKA:content_handler' => [ self::PROP_TEXT ],

      // TikaCore TIKA_PARSED_BY
      'X-TIKA:Parsed-By' => [ self::PROP_TEXT_BAG ],
      // TikaCore ORIGINAL_RESOURCE_NAME
      'X-TIKA:origResourceName' => [ self::PROP_TEXT_BAG ],
      // TikaCore SOURCE_PATH
      'X-TIKA:sourcePath' => [ self::PROP_TEXT ],

      // TikaCore CONTENT_TYPE_HINT
      'Content-Type-Hint' => [ self::PROP_TEXT ],
      // TikaCore CONTENT_TYPE_USER_OVERRIDE
      'Content-Type-Override' => [ self::PROP_TEXT ],
      // TikaCore CONTENT_TYPE_PARSER_OVERRIDE
      'Content-Type-Parser-Override' => [ self::PROP_TEXT ],

      // ======================================================================
      // DublinCore
      // ======================================================================

      // DublinCore CONTRIBUTOR
      // TikaCore CONTRIBUTOR
      'dc:contributor' => [ self::TEXT_BAG_VIA_MWF, 'dc-contributor' ],
      // DublinCore COVERAGE
      // TikaCore COVERAGE
      'dc:coverage' => [ self::TEXT_VIA_MWF, 'dc-coverage' ],
      // DublinCore CREATED
      // TikaCore CREATED
      'dcterms:created' => [ self::PROP_DATE ],
      // DublinCore CREATOR
      // TikaCore CREATOR
      'dc:creator' => [ self::PROP_TEXT_BAG ],
      // DublinCore DATE
      'dc:date' => [ self::DATE_VIA_MWF, 'dc-date' ],
      // DublinCore DESCRIPTION
      // TikaCore DESCRIPTION
      'dc:description' => [ self::PROP_TEXT ],
      // DublinCore FORMAT
      // TikaCore FORMAT
      'dc:format' => [ self::PROP_TEXT ],
      // DublinCore IDENTIFIER
      // TikaCore IDENTIFIER
      'dc:identifier' => [ self::TEXT_VIA_MWF, 'Identifier' ],
      // DublinCore LANGUAGE
      // TikaCore LANGUAGE
      'dc:language' => [ self::PROP_TEXT ],
      // DublinCore MODIFIED
      // TikaCore MODIFIED
      'dcterms:modified' => [ self::PROP_DATE ],
      // DublinCore PUBLISHER
      // TikaCore PUBLISHER
      'dc:publisher' => [ self::TEXT_VIA_MWF, 'dc-publisher' ],
      // DublinCore RELATION
      // TikaCore RELATION
      'dc:relation' => [ self::TEXT_VIA_MWF, 'dc-relation' ],
      // DublinCore RIGHTS
      // TikaCore RIGHTS
      'dc:rights' => [ self::TEXT_VIA_MWF, 'dc-rights' ],
      // DublinCore SOURCE
      // TikaCore SOURCE
      'dc:source' => [ self::TEXT_VIA_MWF, 'dc-source' ],
      // DublinCore SUBJECT
      // TikaCore SUBJECT
      'dc:subject' => [ self::PROP_TEXT_BAG ],
      // DublinCore TYPE
      // TikaCore TYPE
      'dc:type' => [ self::TEXT_VIA_MWF, 'dc-type' ],
      // DublinCore TITLE
      // TikaCore TITLE
      'dc:title' => [ self::PROP_TEXT ],

      // ======================================================================
      // Geographic
      // ======================================================================

      // Geographic LATITUDE
      // TikaCore LATITUDE
      'geo:lat' => [ self::REAL_VIA_MWF, 'GPSLatitude' ],
      // Geographic LONGITUDE
      // TikaCore LONGITUDE
      'geo:long' => [ self::REAL_VIA_MWF, 'GPSLongitude' ],
      // Geographic ALTITUDE
      // TikaCore ALTITUDE
      'geo:alt' => [ self::REAL_VIA_MWF, 'GPSAltitude' ],


      // ======================================================================
      // XMP
      // ======================================================================

      // XMP ABOUT
      'xmp:About' => [ self::PROP_TEXT_BAG ],
      // XMP ADVISORY
      'xmp:Advisory' => [ self::PROP_TEXT_BAG ],
      // XMP CREATE_DATE
      'xmp:CreateDate' => [ self::PROP_DATE ],
      // XMP CREATOR_TOOL
      // TikaCore CREATOR_TOOL
      'xmp:CreatorTool' => [ self::PROP_TEXT ],
      // XMP IDENTIFIER
      'xmp:Identifier' => [ self::TEXT_VIA_MWF, 'Identifier' ],
      // XMP LABEL
      'xmp:Label' => [ self::TEXT_VIA_MWF, 'Label' ],
      // XMP METADATA_DATE
      // TikaCore METADATA_DATE
      'xmp:MetadataDate' => [ self::DATE_VIA_MWF, 'DateTimeMetadata' ],
      // XMP MODIFY_DATE
      'xmp:ModifyDate' => [ self::PROP_DATE ],
      // XMP NICKNAME
      'xmp:NickName' => [ self::TEXT_VIA_MWF, 'Nickname' ],
      // XMP RATING
      // TikaCore RATING
      'xmp:Rating' => [ self::INTEGER_VIA_MWF, 'Rating' ],

      // ======================================================================
      // PagedText
      // ======================================================================

      // PagedText N_PAGES
      'xmpTPg:NPages' => [ self::PROP_INTEGER ],

      // ======================================================================
      // Office
      // ======================================================================

      // Office AUTHOR
      'meta:author' => [ self::PROP_TEXT_BAG ],
      // Office CHARACTER_COUNT
      'meta:character-count' => [ self::PROP_INTEGER ],
      // Office CHARACTER_COUNT_WITH_SPACES
      'meta:character-count-with-spaces' => [ self::PROP_INTEGER ],
      // Office CREATION_DATE
      'meta:creation-date' => [ self::PROP_DATE ],
      // Office IMAGE_COUNT
      'meta:image-count' => [ self::PROP_INTEGER ],
      // Office INITIAL_AUTHOR
      'meta:initial-author' => [ self::PROP_TEXT ],
      // Office KEYWORDS --- co-populates with dc:subject, so we skip this.
      'meta:keyword' => false,
      // Office LAST_AUTHOR
      // TikaCore MODIFIER
      'meta:last-author' => [ self::PROP_TEXT ],
      // Office LINE_COUNT
      'meta:line-count' => [ self::PROP_INTEGER ],
      // Office MAPI_FROM_REPRESENTING_NAME
      'meta:mapi-from-representing-name' => [ self::PROP_TEXT ],
      // Office MAPI_FROM_REPRESENTING_EMAIL
      'meta:mapi-from-representing-email' => [ self::PROP_TEXT ],
      // Office MAPI_MESSAGE_CLASS
      'meta:mapi-message-class' => [ self::PROP_CLOSED_CHOICE,
                                     [ 'APPOINTMENT', 'CONTACT', 'NOTE',
                                       'STICKY_NOTE', 'POST', 'TASK',
                                       'UNKNOWN', 'UNSPECIFIED' ] ],
      // Office MAPI_MESSAGE_CLIENT_SUBMIT_TIME
      'meta:mapi-msg-client-submit-time' => [ self::PROP_DATE ],
      // Office MAPI_SENT_BY_SERVER_TYPE
      'meta:mapi-sent-by-server-type' => [ self::PROP_TEXT ],
      // Office OBJECT_COUNT
      'meta:object-count' => [ self::PROP_INTEGER ],
      // Office PAGE_COUNT
      'meta:page-count' => [ self::PROP_INTEGER ],
      // Office PARAGRAPH_COUNT
      'meta:paragraph-count' => [ self::PROP_INTEGER ],
      // Office PRINT_DATE
      // TikaCore PRINT_DATE
      'meta:print-date' => [ self::PROP_DATE ],
      // Office SAVE_DATE
      'meta:save-date' => [ self::PROP_DATE ],
      // Office SLIDE_COUNT
      'meta:slide-count' => [ self::PROP_INTEGER ],
      // Office TABLE_COUNT
      'meta:table-count' => [ self::PROP_INTEGER ],
      // Office WORD_COUNT
      'meta:word-count' => [ self::PROP_INTEGER ],

      // ======================================================================
      // OfficeOpenXMLCore
      // ======================================================================

      // OfficeOpenXMLCore CATEGORY
      'cp:category' => [ self::PROP_TEXT ],
      // OfficeOpenXMLCore CONTENT_STATUS
      'cp:contentStatus' => [ self::PROP_TEXT ],
      // OfficeOpenXMLCore LAST_MODIFIED_BY
      'cp:lastModifiedBy' => [ self::PROP_TEXT ],
      // OfficeOpenXMLCore LAST_PRINTED
      'cp:lastPrinted' => [ self::PROP_DATE ],
      // OfficeOpenXMLCore REVISION
      'cp:revision' => [ self::PROP_TEXT ],
      // OfficeOpenXMLCore VERSION
      'cp:version' => [ self::PROP_TEXT ],
      // OfficeOpenXMLCore SUBJECT
      //   --- co-populates with dc:subject, so we skip this one.
      'cp:subject' => false,

      // ======================================================================
      // OfficeOpenXMLExtended
      // ======================================================================

      // OfficeOpenXMLExtended TEMPLATE
      'extended-properties:Template' => [ self::PROP_TEXT ],
      // OfficeOpenXMLExtended MANAGER
      'extended-properties:Manager' => [ self::PROP_TEXT_BAG ],
      // OfficeOpenXMLExtended COMPANY
      'extended-properties:Company' => [ self::PROP_TEXT ],
      // OfficeOpenXMLExtended PRESENTATION_FORMAT
      'extended-properties:PresentationFormat' => [ self::PROP_TEXT ],
      // OfficeOpenXMLExtended NOTES
      'extended-properties:Notes' => [ self::PROP_INTEGER ],
      // OfficeOpenXMLExtended TOTAL_TIME
      'extended-properties:TotalTime' => [ self::PROP_INTEGER ],
      // OfficeOpenXMLExtended HIDDEN_SLIDES
      'extended-properties:HiddedSlides' => [ self::PROP_INTEGER ],
      // OfficeOpenXMLExtended APPLICATION
      'extended-properties:Application' => [ self::PROP_TEXT ],
      // OfficeOpenXMLExtended APP_VERSION
      'extended-properties:AppVersion' => [ self::PROP_TEXT ],
      // OfficeOpenXMLExtended DOC_SECURITY
      'extended-properties:DocSecurity' => [ self::PROP_INTEGER ],
      // OfficeOpenXMLExtended DOC_SECURITY_STRING
      'extended-properties:DocSecurityString' => [ self::PROP_CLOSED_CHOICE,
                                                   [ 'None',
                                                     'PasswordProtected',
                                                     'ReadOnlyRecommended',
                                                     'ReadOnlyEnforced',
                                                     'LockedForAnnotations',
                                                     'Unknown' ] ],
      // OfficeOpenXMLExtended COMMENTS
      // TikaCore COMMENTS
      'w:Comments' => [ self::TEXT_BAG_VIA_MWF, 'UserComment' ],

      // ======================================================================
      // PDF
      // ======================================================================

      // PDF DOC_INFO_CREATED
      'pdf:docinfo:created' => [ self::PROP_DATE ],
      // PDF DOC_INFO_CREATOR
      'pdf:docinfo:creator' => [ self::PROP_TEXT ],
      // PDF DOC_INFO_CREATOR_TOOL
      'pdf:docinfo:creator_tool' => [ self::PROP_TEXT ],
      // PDF DOC_INFO_MODIFICATION_DATE
      'pdf:docinfo:modified' => [ self::PROP_DATE ],
      // PDF DOC_INFO_KEY_WORDS
      'pdf:docinfo:keywords' => [ self::PROP_TEXT ],
      // PDF DOC_INFO_PRODUCER
      'pdf:docinfo:producer' => [ self::PROP_TEXT ],
      // PDF DOC_INFO_SUBJECT
      'pdf:docinfo:subject' => [ self::PROP_TEXT ],
      // PDF DOC_INFO_TITLE
      'pdf:docinfo:title' => [ self::PROP_TEXT ],
      // PDF DOC_INFO_TRAPPED
      'pdf:docinfo:trapped' => [ self::PROP_TEXT ],
      // PDF PDF_VERSION
      'pdf:PDFVersion' => [ self::PROP_RATIONAL ],
      // PDF PDFA_VERSION
      'pdfa:PDFVersion' => [ self::PROP_RATIONAL ],
      // PDF PDF_EXTENSION_VERSION
      'pdf:PDFExtensionVersion' => [ self::PROP_RATIONAL ],
      // PDF PDFAID_CONFORMANCE
      'pdfaid:conformance' => [ self::PROP_TEXT ],
      // PDF PDFAID_PART
      'pdfaid:part' => [ self::PROP_TEXT ],
      // PDF IS_ENCRYPTED
      'pdf:encrypted' => [ self::PROP_BOOLEAN ],
      // PDF PRODUCER
      'pdf:producer' => [ self::PROP_TEXT ],
      // PDF ACTION_TRIGGER
      'pdf:actionTrigger' => [ self::PROP_TEXT ],
      // PDF CHARACTERS_PER_PAGE
      'pdf:charsPerPage' => [ self::PROP_INTEGER_SEQUENCE ],
      // PDF UNMAPPED_UNICODE_CHARS_PER_PAGE
      'pdf:unmappedUnicodeCharsPerPage' => [ self::PROP_INTEGER_SEQUENCE ],
      // PDF HAS_XFA
      'pdf:hasXFA' => [ self::PROP_BOOLEAN ],
      // PDF HAS_XMP
      'pdf:hasXMP' => [ self::PROP_BOOLEAN ],
      // PDF XMP_LOCATION
      'pdf:xmpLocation' => [ self::PROP_TEXT ],
      // PDF HAS_ACROFORM_FIELDS
      'pdf:hasAcroFormFields' => [ self::PROP_BOOLEAN ],
      // PDF HAS_MARKED_CONTENT
      'pdf:hasMarkedContent' => [ self::PROP_BOOLEAN ],
      // PDF HAS_COLLECTION
      'pdf:hasCollection' => [ self::PROP_BOOLEAN ],
      // PDF EMBEDDED_FILE_DESCRIPTION
      'pdf:embeddedFileDescription' => [ self::PROP_TEXT ],

                   ];

}
