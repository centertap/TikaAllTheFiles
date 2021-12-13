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
use File;
use FormatMetadata;
use GlobalVarConfig;
use MediaWiki\Logger\LoggerFactory;
use MWException;
use Psr\Log\LoggerInterface;


/**
 * Common core functionality used by multiple TATF components
 */
class Core {
  /**
   * Prefix for our global configuration parameters
   */
  public const CONFIG_PREFIX = 'wgTikaAllTheFiles_';

  /**
   * Name of log-group we use for debug logging
   */
  public const LOG_GROUP = 'TikaAllTheFiles';


  /** @var LoggerInterface - our logger, tagging entries with LOG_GROUP */
  private LoggerInterface $logger;

  /** @var Config - our extension configuration */
  private Config $config;

  /** @var MetadataMapper - a MetadataMapper instance to use over and over */
  private MetadataMapper $metadataMapper;


  /**
   * Construct a new TATF logger, when a Core instance is not available.
   *
   * @return LoggerInterface
   */
  public static function newLogger(): LoggerInterface {
    return LoggerFactory::getInstance( self::LOG_GROUP );
  }


  private const STASH_TAG = 'opaque_tatf_tika_metadata';

  /**
   * Stash an array of TATF-managed metadata in an obfuscated way in someone
   * else's metadata array.
   *
   * @param array &$other - the receiving array
   * @param array $tatfMetadata - the TATF metadata
   *
   * @return void
   */
  public static function stashTatfMetadata( array &$other, array $tatfMetadata
                                            ): void {
    $other[ self::STASH_TAG ] = new OpaqueTatfMetadata( $tatfMetadata );
  }


  /**
   * Recover obfuscated TATF-managed metadata from another metadata array.
   *
   * @param array $other - the other metadata array
   *
   * @return ?array - returns TATF metadata array if found, or null
   */
  public static function unstashTatfMetadata( array $other ): ?array {
    $stash = $other[ self::STASH_TAG ] ?? null;
    if ( $stash instanceof OpaqueTatfMetadata ) {
      return $stash->metadata;
    } elseif ( is_array( $stash ) ) {
      // If OpaqueTatfMetadata goes through json_encode+json_decode,
      // it turns into a plain array.
      // TODO(maddog) ...so, maybe we should not bother with the object?
      //              (Or, if inadvertently formatting/rendering an array
      //              is an issue, maybe we can bury the entries into an
      //              even deeper subarray or something?)
      return $stash[ 'metadata' ] ?? null;
    }
    return null;
  }


  /**
   * Strictly assert a runtime invariant, logging an error and backtrace
   * if $condition is false.
   *
   * @param bool $condition the asserted condition
   * @param string $msg description of the condition
   *
   * @return void This function returns no value.
   * @throws MWException if $condition is false.
   */
  public static function insist( bool $condition, string $msg = '' ): void {
    if ( !$condition ) {
      // The $callerOffset parameter "2" tells wfLogWarning to identify
      // the function/line that called us as the location of the error.
      wfLogWarning( self::LOG_GROUP . ' Failed to insist that: ' . $msg, 2 );
      throw new MWException( 'Failed to insist that: ' . $msg );
    }
  }

  /**
   * Log an error and backtrace indicating that purportedly unreachable code
   * has in fact been reached.
   *
   * @throws MWException always.
   */
  public static function unreachable()/*: never*/ {
    // The $callerOffset parameter "2" tells wfLogWarning to identify
    // the function/line that called us as the location of the error.
    wfLogWarning( self::LOG_GROUP . ' REACHED THE UNREACHABLE', 2 );
    throw new MWException( 'REACHED THE UNREACHABLE' );
  }


  /**
   * Log a (structured) warning message
   *
   * @param string $message - the message
   * @param array $context - optional array of context values
   *
   * @return void
   */
  public static function warn( string $message, array $context = [] ): void {
    self::newLogger()->warning( $message, $context );
  }


  public function __construct() {
    $this->logger = self::newLogger();
    // TODO(maddog) We should do this instead, but then we would have to keep
    //              repeating our CONFIG_PREFIX everywhere we go.
    //$this->config = MediaWikiServices::getInstance()->getConfigFactory()
    //    ->makeConfig( 'TikaAllTheFiles' );
    $this->config = new GlobalVarConfig( self::CONFIG_PREFIX );
    $this->metadataMapper = new MetadataMapper( $this->logger, $this->config );
  }


  /**
   * Get the logger.
   *
   * @return LoggerInterface
   */
  public function getLogger(): LoggerInterface {
    return $this->logger;
  }


  /**
   * Generate extracted text content from a file for search-engine indexing,
   * possibly via a Tika query.
   *
   * @param TypeProfile $typeProfile profile defining how to handle $file
   * @param File $file the file to examine
   * @param ?string $otherContent optional extracted text content retrieved
   *  by another handler
   * @param ?array $otherFormattedMetadata optional metadata retrieved by
   *  another handler, already formatted as by MediaHandler::formatMetadata()
   *
   * @return ?string - returns a string or null
   * @throws MWException in case of errors
   */
  public function generateTextContent(
      TypeProfile $typeProfile,
      File $file,
      ?string $otherContent,
      ?array $otherFormattedMetadata ): ?string {
    if ( ( $typeProfile->contentStrategy === 'no_tika' ) ||
         ( ( $typeProfile->contentStrategy === 'prefer_other' ) &&
           ( $otherContent !== null ) ) ) {
      // No need to query Tika at all.
      return $otherContent;
    }

    $filePath = $file->getLocalRefPath();
    self::insist( $filePath !== false,
                  "Local path for file {$file->getName()} is known" );

    $onlyMetadata = ( $typeProfile->contentComposition === 'metadata' );

    $response = $this->queryTika( $typeProfile, $filePath, $onlyMetadata );

    $this->logger->debug( 'Tika response:  {response}',
                          [ 'response' => $response ] );

    $tikaContent = trim( $response["X-TIKA:content"] ?? '' );
    // Remove tika-content from the response, leaving all the other metadata.
    unset( $response["X-TIKA:content"] );

    switch ( $typeProfile->contentComposition ) {
      case 'text':
        // Nothing to do.
        break;
      case 'metadata':
      case 'text_and_metadata':
        // Append the same metadata that we would show to user (albeit
        // formatted a little differently).  The point is to allow the user
        // to search for something they saw in the displayed metadata.
        $formattedMetadata = $this->formatMetadataForTextContent(
            $typeProfile, $response, $otherFormattedMetadata,
            /*context=*/false );
        $this->logger->debug( "Add metadata to content\n {$formattedMetadata}" );
        if ( $formattedMetadata ) {
          $tikaContent = implode( ' ', [ $tikaContent, $formattedMetadata ] );
        }
        break;
      default:
        self::unreachable();
        break;
    }

    $newContent = self::mergeContent( $typeProfile->contentStrategy,
                                      $otherContent, $tikaContent );

    $this->logger->debug( 'new content:  {new_content}',
                          [ 'new_content' => $newContent ] );
    return $newContent;
  }


  /**
   * Generate metadata for a file, possibly via a Tika query.
   *
   * @param TypeProfile $typeProfile profile defining how to handle the file
   * @param string $filePath path to file on local filesystem
   * @param ?array $otherMetadata optional array of metadata retrieved by
   *  another handler
   *
   * @return ?array - returns an array of metadata, or null
   * @throws MWException in case of errors
   */
  public function generateMetadata( TypeProfile $typeProfile,
                                    string $filePath,
                                    ?array $otherMetadata ): ?array {
    $strategy = $typeProfile->metadataStrategy;
    if ( ( $strategy === 'no_tika' ) ||
         ( ( $strategy === 'prefer_other' ) && ( $otherMetadata !== null ) ) ) {
      // No need to query Tika at all.
      return $otherMetadata;
    }

    $response = $this->queryTika( $typeProfile, $filePath,
                                  /*$onlyMetadata:*/true );
    $this->logger->debug( 'Tika response:  {response}',
                          [ 'response' => var_export( $response, true ) ] );
    $tikaMetadata = $response;

    // 'no_tika' strategy has already been taken care of.
    // For all other strategies, we try to sneak $tikaMetadata into the
    // $otherMetadata (if it exists).
    $newMetadata = $otherMetadata ?? [];
    self::stashTatfMetadata( $newMetadata, $tikaMetadata );
    $this->logger->debug( 'new metadata:  {new_metadata}',
                          [ 'new_metadata' =>
                            var_export( $newMetadata, true ) ] );
    return $newMetadata;
  }


  // The metadata handling in MW core is a bit all-over-the-place.
  //
  // Where can metadata end up?
  //
  // o serialized md is stored for a File instance in database.
  //   o initially obtained by:
  //     - Either...
  //       ... LocalFile::upgradeRow() -> LocalFile::loadFromFile() ->
  //       ... LocalFile::upload() ->
  //       ... LocalFile::recordUpload3() ->
  //       -> FileRepo::getFileProps()
  //          -> MWFileProps::getPropsFromPath()
  //             -> MediaHandler::getMetadata()    /the buck stops here/
  //   o retrieved from DB by calling File::getMetadata()
  //     o (or from cache in memcached/etc)
  //     o (cached in File instance as File::metadata property)
  //
  // o unserialized
  //     - PdfHandler jams a 'pdfMetaArray' property into File objects
  //
  //
  // How does metadata get shown to user?
  //
  // : TypicalHandler::formatMetadata()
  //   - collect array of (tag, value) pairs
  //   - pass to...
  //
  //   : MediaHandler::formatMetadataHelper()
  //     - send array of (tag, value) pairs through...
  //       : FormatMetadata::getFormattedData()
  //         - takes array of (tag, value) pairs
  //           - value may be an array of values
  //             - value['_type'] is a special value that determines the
  //               presentation type of the array, e.g., "ul" for "unordered
  //               list" or "lang" for "per-language values".  Such a value
  //               will be interpreted and then removed from the array.
  //             - arrays can be multi-language arrays ("lang" type), i.e.,
  //               pairs of (language-code, language-specific-value)
  //         - is aware of an idiosyncratic set of tags, which are
  //             - generally camel-cased English phrases derived from names
  //                of EXIF/XMP/etc properties ("ISOSpeedRatings")
  //             - or, very small subset derived from Dublin Core properties
  //                ("dc-contributor")
  //         - may remove some pairs ("ResolutionUnit") (after interpretation)
  //         - for each tag, its value(s) are transformed into human-readable
  //           text:
  //             - some multi-values are collapsed into a single string
  //               ("GPSTimeStamp" has H/M/S as three values)
  //             - some values converted by lookup in i18n/exif message set
  //             - some values are just passed through htmlspecialchars(),
  //               i.e., left as-is after HTML-escaping.
  //             - for unrecognized tags, value is passed through formatNum(),
  //               which does some idiosyncratic thing, treating the value as
  //               some kind of number
  //         - for each tag, value(s) are passed through flattenArrayReal()
  //             - leaves non-arrays alone
  //             - returns the single value of single-value arrays
  //             - if it is a multiple-language array:
  //                 - generates an HTML <ul> that preferentially shows the
  //                   four highest-priority languages
  //             - otherwise generate an HTML <ul> or <ul> list
  //         - returns an array of (tag, value) pairs
  //             - same tags as input (except some might be missing)
  //             - values converted to single strings bearing language-context
  //               specific readable text, possibly with HTML list elements
  //
  //         - UNFORTUNATELY, this mixes the per-value text rendering with the
  //           HTML-presentation of lists of values, which should be two
  //           separate concerns.
  //
  //     - get array of (tag, value-string) pairs from FM::getFormattedData()
  //     - loop through each pair, constructing two new arrays for "visible"
  //       and "collapsed" tags
  //       - lower-case each tag!
  //       - classify each tag as "visible" or "collapsed" depending on whether
  //         or not it appears in FormatMetadata::getVisibleFields()
  //         - which actually comes from the page MediaWiki:Metadata-fields
  //           (and then always get lower-cased)
  //       - MediaHandler::addMeta() transforms each (tag, value-string) pair
  //         into a named-triple:
  //         - prepend "exif-" to the (lower-cased) tag
  //         - lookup exif-tag in message-database:
  //           - use msg->text() if msg exists,
  //           - else do wfEscapeWikiText() on original tag
  //         - return [    id:  exif-tag
  //                     name:  message-looked-up tag
  //                    value:  value-string ]
  //       - [id/name/value] triples are put in visible/collapse arrays
  //       - return an array of the two arrays
  //
  //   - return array-of-two-arrays from TypicalHandler::formatMetadata()
  //
  //
  //
  // Our goal:
  //  - input:  array of (tikaName => tikaValue) or (tikaName => tikaValues-bag)
  //  - output: array of tuples of
  //      - element-id   - 'exif-' + lower-cased-MW-tag
  //      - lower-cased-MW-tag     - for visibility lookup
  //      - readable property name    - look up message for exif-(lc-mw-tag)
  //      - readable value string(s)  - tikaValue(s) maybe transformed via
  //                                     FM::getFormattedData()
  //
  //  FM::getFormattedData() wants to operate on arrays of arrays of values
  //  and make HTML lists --- but we can co-opt the value conversion and avoid
  //  the HTML lists by calling it with on (tag, value) pair at a time.
  //
  //  tikaName --> ( processorFunc, arg1, arg2, ... )
  //
  //     processorFunc:
  //          <-- (tikaName, tikaValues, arg1, arg2, ...)
  //          --> (readable property name string,
  //               [list of readable value strings],
  //               tag for visibility lookup,
  //               html element-id
  //              )
  //
  //
  //  later on:
  //    - can use FM::flattenArrayReal() to generate HTML lists of values
  //
  //    -

  /**
   * Format metadata for display, as MediaHandler::formatMetadata() does.
   *
   * @param TypeProfile $typeProfile profile defining how to handle the file
   * @param ?array $tikaMetadata optional Tika-generated metadata
   * @param ?array $otherFormattedMetadata optional metadata retrieved by
   *  another handler, already formatted as by MediaHandler::formatMetadata()
   * @param false|IContextSource $context - optional context for string rendering
   *
   * @return ?array - formatted metadata, in the format produced by
   *  MediaHandler::formatMetadata(), or null if there is no metadata to show.
   */
  public function formatMetadataForMwUi( TypeProfile $typeProfile,
                                         ?array $tikaMetadata,
                                         ?array $otherFormattedMetadata,
                                         $context ): ?array {
    $strategy = $typeProfile->metadataStrategy;

    // In case we have stashed TATF metadata in other, remove it from the
    // formatted results so that it is not shown to user.
    if ( $otherFormattedMetadata !== null ) {
      foreach ( $otherFormattedMetadata as $visibility => &$group ) {
        foreach ( $group as $index => $tuple ) {
          if ( $tuple['name'] === self::STASH_TAG ) {
            unset( $group[$index] );
          }
        }
      }
      $this->logger->debug( 'other collapsed ' . var_export( $otherFormattedMetadata['collapsed'], true ) );
    }

    // Short-circuit if we know $tikaMetadata will not get used.
    if ( ( $strategy === 'no_tika' ) ||
         ( ( $strategy === 'prefer_other' ) &&
           ( $otherFormattedMetadata !== null ) ) ) {
      return $otherFormattedMetadata;
    }

    $tikaFormattedMetadata = $this->formatTikaMetadataForMwUi( $tikaMetadata,
                                                               $context );

    // Merge the two sets of formatted metadata and return.
    return self::mergeMwFormattedMetadata( $strategy,
                                           $tikaFormattedMetadata,
                                           $otherFormattedMetadata );
  }


  /**
   * Map Tika-extracted metadata with MetadataMapper, and format for display
   * as MediaHandler::formatMetadata() does.
   *
   * @param ?array $tikaMetadata optional Tika-generated metadata
   * @param false|IContextSource $context - optional context for string rendering
   *
   * @return ?array - formatted metadata, in the format produced by
   *  MediaHandler::formatMetadata(), or null if there is no metadata to show.
   */
  private function formatTikaMetadataForMwUi( ?array $tikaMetadata,
                                              $context ): ?array {
    // If $tikaMetadata is null or simply empty, return null.
    if ( !$tikaMetadata ) {
      return null;
    }

    $visibleFields = FormatMetadata::getVisibleFields();

    $formatted = [ 'visible' => [],
                   'collapsed' => [], ];

    foreach ( $tikaMetadata as $tikaName => $tikaValue ) {
      $mapped = $this->metadataMapper->map( $tikaName, $tikaValue, $context );
      if ( $mapped === null ) {
        continue;
      }
      $visibility = in_array( $mapped->visibilityTag(), $visibleFields )
          ? 'visible' : 'collapsed';
      $formatted[ $visibility ][] = [
          'id' => $mapped->id(),
          'name' => $mapped->name(),
          'value' => FormatMetadata::flattenArrayReal( $mapped->values(),
                                                       /*type=*/'ul',
                                                       /*noHtml=*/false ),
                                  ];
    }
    // Alphabetic sort Tika metadata by property name
    // TODO(maddog) Fix comparisons for non-ascii names.
    usort( $formatted['visible'],
           static function ( $a, $b ) { return strcasecmp( $a['name'],
                                                           $b['name'] );
           } );
    usort( $formatted['collapsed'],
            static function ( $a, $b ) { return strcasecmp( $a['name'],
                                                            $b['name'] );
            } );

    return $formatted;
  }


  /**
   * Format metadata in order to combine it with extracted text content
   * (so that it can show up in full-text searches).
   *
   * @param TypeProfile $typeProfile profile defining how to handle the file
   * @param ?array $tikaMetadata optional Tika-generated metadata
   * @param ?array $otherFormattedMetadata optional metadata retrieved by
   *  another handler, already formatted as by MediaHandler::formatMetadata()
   * @param false|IContextSource $context - optional context for string rendering
   *
   * @return ?string - returns null, or a string with the formatted output
   */
  public function formatMetadataForTextContent( TypeProfile $typeProfile,
                                                ?array $tikaMetadata,
                                                ?array $otherFormattedMetadata,
                                                $context ): ?string {
    $strategy = $typeProfile->metadataStrategy;

    $otherEntries = [];
    if ( ( $otherFormattedMetadata !== null ) &&
         ( $strategy !== 'only_tika' ) ) {
      foreach ( $otherFormattedMetadata as $visibility => $group ) {
        foreach ( $group as $tuple ) {
          // In case we have stashed TATF metadata in other, remove it from
          // other's formatted output.
          if ( $tuple['name'] === self::STASH_TAG ) {
            continue;
          }
          $otherEntries[] = "{$tuple['name']}: {$tuple['value']}\n";
        }
      }
    }

    $tikaEntries = [];
    if ( ( $tikaMetadata !== null ) && ( $strategy !== 'no_tika' ) ) {
      foreach ( $tikaMetadata as $tikaName => $tikaValue ) {
        $mapped =
            $this->metadataMapper->map( $tikaName, $tikaValue, $context );
        if ( $mapped === null ) {
          continue;
        }
        $flattenedValues = implode( ' ', $mapped->values() );
        $tikaEntries[] = "{$mapped->name()}: {$flattenedValues}\n";
      }
    }

    switch ( $strategy ) {
      case 'no_tika':
        $combinedEntries = $otherEntries;
        break;
      case 'prefer_other':
        $combinedEntries = $otherEntries ?: $tikaEntries;
        break;
      case 'combine':
        $combinedEntries = array_merge( $tikaEntries, $otherEntries );
        break;
      case 'prefer_tika':
        $combinedEntries = $tikaEntries ?: $otherEntries;
        break;
      case 'only_tika':
        $combinedEntries = $tikaEntries;
        break;
      default:
        self::unreachable();
        break;
    }
    return implode( '', $combinedEntries );
  }


  /**
   * Query a Tika server.
   *
   * @param TypeProfile $typeProfile profile specifying request parameters
   * @param string $filePath local path to the file to submit to Tika server
   * @param bool $onlyMetadata true if only metadata should be extracted;
   *  otherwise text and metadata will be extracted
   *
   * @return array of Tika properties
   *
   * @throws MWException if failure to set up the Tika query or unable to
   *  communicate with Tika server
   *
   * TODO(maddog) Work out the error/failure conditions better.
   */
  private function queryTika( TypeProfile $typeProfile,
                              string $filePath,
                              bool $onlyMetadata ): array {
    $tikaUrl = $this->config->get( 'TikaServiceBaseUrl' );
    $queryTimeoutSeconds = $this->config->get( 'QueryTimeoutSeconds' );
    $triesRemaining = 1 + $this->config->get( 'QueryRetryCount' );
    $retryDelaySeconds = $this->config->get( 'QueryRetryDelaySeconds' );

    try {
      $inputFile = fopen( $filePath, 'r' );
      $this->logger->debug( "inputFile is '{$inputFile}'" );
      if ( $inputFile === false ) {
        throw new MWException( "Failed to open '{$filePath}' for read" );
      }

      $inputSize = filesize( $filePath );
      if ( $inputSize === false ) {
        throw new MWException( "Failed to get size of '{$filePath}'" );
      }

      $options = [
          CURLOPT_PUT             => true,
          // Return response in return value of curl_exec()
          CURLOPT_RETURNTRANSFER  => true,
          // TODO(maddog) Only for debugging?
          //              CURLINFO_HEADER_OUT     => true,
          CURLOPT_HTTPHEADER      => [
              'Accept: application/json',
              // TODO(maddog) Should we provide a TATF profile parameter for
              //              this setting?
              //
              // If OCR is available and allowed, ocr_and_text will use it on
              // PDF's even if a PDF does seem to have embedded digital text,
              // because it might have more interesting text in inline images.
              //
              // Tika's default is 'auto'.
              // (See https://cwiki.apache.org/confluence/display/tika/PDFParser%20(Apache%20PDFBox) for more info.)
              //
              // TODO(maddog) Warning!  Maybe it's a Tika bug, but...
              //              if OCRSkipOcr is true, and strat is ocr_and_text,
              //              then tika appears to skip the text extraction
              //              altogether.
              //              'X-Tika-PDFOcrStrategy: ocr_and_text',
              //              'X-Tika-PDFOcrStrategy: auto',
                                      ],
          CURLOPT_INFILE          => $inputFile,
          CURLOPT_INFILESIZE      => $inputSize,
          CURLOPT_TIMEOUT         => $queryTimeoutSeconds,
                ];

      if ( $onlyMetadata ) {
        $options[CURLOPT_URL] = $tikaUrl . '/meta';
      } else {
        $options[CURLOPT_URL] = $tikaUrl . '/tika/text';
      }

      // See https://cwiki.apache.org/confluence/display/TIKA/TikaOCR
      //
      // Tika's default for skipOCR is 'false', but we always explicitly set
      // an overriding value via query header.
      $options[CURLOPT_HTTPHEADER][] =
          'X-Tika-OCRskipOcr: ' . ( $typeProfile->allowOcr ? 'false' : 'true' );

      // Tika's default for ocrLanguages is 'eng'.  We only set a per-query
      // value if the profile provides a non-empty value to set.  Languages
      // require associated Tesseract language packs.
      if ( $typeProfile->ocrLanguages !== '' ) {
        $options[CURLOPT_HTTPHEADER][] =
            "X-Tika-OCRLanguage: {$typeProfile->ocrLanguages}";
      }

      $this->logger->debug( "curl options: " . var_export( $options, true ) );

      while ( $triesRemaining > 0 ) {
        [ $response, $httpStatus ] = self::executeCurl( $options );

        if ( $response !== false ) {
          switch ( $httpStatus ) {
            case 200:
              // "Ok - request completed sucessfully"
              return json_decode( $response, true /*as array*/ );
            case 204: // "No content - request completed sucessfully, result is empty"
            case 422: // "Unprocessable Entity - Unsupported mime-type, encrypted document & etc"
              // TODO(maddog) Log a warning for the 422 case (and maybe 204)
              //              or, perhaps throw an exception?
              return [];
            case 500: // "Error - Error while processing document"
            case 503: // "Service Unavailable"
              // 503 can occur if Tika is restarting its child process (e.g., if
              // an earlier request caused it to keel over).
            default:
              // In all these cases, just continue to (maybe) retry.
              break;
          }
          $this->logger->warning( "Tika responded with status {$status}" );
        }
        --$triesRemaining;
        if ( $triesRemaining > 0 ) {
          $this->logger->warning( "Retrying Tika query for {$filePath}" );
        }
        // TODO(maddog) Maybe sleep() is not the smartest thing to do?
        sleep( $retryDelaySeconds );
      } // while ( $triesRemaining > 0 )

      // Retries exhausted.  Oh, well, at least we (re)tried.
      throw new MWException( "Tika query exhausted retries for {$filePath}" );
    }
    finally {
      fclose( $inputFile );
    }
  }


  /**
   * Execute an HTTP request using PHP's curl library.
   *
   * @param array $options array of CURLOPT_ parameters for the request
   *
   * @return array of [response, status].  response will be false if the
   *  request fails due to a curl error; otherwise it will be a string with
   *  the result of the request.  status is the integer HTTP status code
   *  (e.g., 200, 404, ...) status is only meaningful if response is not false.
   * @throws MWException for errors in preparing the curl request
   */
  private function executeCurl( array $options ) {
    try {
      $curl = curl_init();

      foreach ( $options as $option => $value ) {
        if ( !curl_setopt( $curl, $option, $value ) ) {
          throw new MWException(
              "Set curl option {$option} to {$value} failed." );
        }
      }

      $response = curl_exec( $curl );

      $totalTimeSeconds = curl_getinfo( $curl, CURLINFO_TOTAL_TIME_T ) / 1e6;
      $this->logger->debug( "Tika transaction took {$totalTimeSeconds}s" );

      // TODO(maddog) Should we try to diagnose timeout errors specially?
      //              ...probably yes --- it is unlikely that trying again
      //              will make anything go faster.
      //              (or do something more clever, like retry without OCR)
      //
      //              (FYI:  timeout is curl_errno CURLE_OPERATION_TIMEDOUT.)
      if ( ( $response === false ) || ( curl_errno( $curl ) !== 0 ) ) {
        $errno = curl_errno( $curl );
        $error = curl_error( $curl );
        $this->logger->warning(
            "Curl error {$errno} while trying to query Tika:  {$error}" );
        $response = false;
      }

      $status = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
      return [ $response, $status ];
    }
    finally {
      curl_close( $curl );
    }
  }


  /**
   * Get the TypeProfile for the specified mime-type.
   *
   * If $mimeType is an explicit label in the configuration, it will resolve
   * that label; otherwise, it will try to resolve the fallback label '*'.
   *
   * @param string $mimeType the mime-type in question
   *
   * @return ?TypeProfile the appropriate profile, or null if the mime-type
   *  cannot or should not be processed.
   */
  public function getMimeTypeProfile( string $mimeType ): ?TypeProfile {
    $typeProfiles = $this->config->get( 'MimeTypeProfiles' );
    if ( isset( $typeProfiles[ $mimeType ] ) ) {
      return TypeProfile::newFromConfig( $mimeType, $typeProfiles );
    } elseif ( isset( $typeProfiles[ '*' ] ) ) {
      return TypeProfile::newFromConfig( '*', $typeProfiles );
    } else {
      return null;
    }
  }


  /**
   * Merge two text content strings according to tika-vs-other strategy.
   *
   * @param string $strategy - see TypeProfile::CONTENT_STRATEGIES
   * @param ?string $other - possibly-null "other" content
   * @param string $tika - Tika-sourced content
   *
   * @return ?string merged content
   */
  private static function mergeContent(
      string $strategy, ?string $other, string $tika ): ?string {
    switch ( $strategy ) {
      case 'no_tika':
        return $other;
      case 'prefer_other':
        return ( $other !== null ) ? $other : $tika;
      case 'combine':
        if ( ( $other ?? '' ) === '' ) {
          return $tika;
        } elseif ( $tika === '' ) {
          return $other;
        } /* else */
        return implode( "\n", [ $other, $tika ] );
      case 'prefer_tika':
        return ( $tika !== '' ) ? $tika : $other;
      case 'only_tika':
        return $tika;
    }
    self::unreachable();
  }


  /**
   * Merge two "formatMetadata" arrays according to tika-vs-other strategy
   *
   * @param string $strategy - see TypeProfile::METADATA_STRATEGIES
   * @param ?array $tika - Tika-sourced formatted metadata
   * @param ?array $other - possibly-null "other" formatted metadata
   *
   * @return ?array merged formatted metadata
   */
  private static function mergeMwFormattedMetadata(
      string $strategy, ?array $tika, ?array $other ): ?array {
    switch ( $strategy ) {
      case 'no_tika':
        return $other;
      case 'prefer_other':
        return ( $other !== null ) ? $other : $tika;
      case 'combine':
        if ( ( $tika === null ) && ( $other === null ) ) {
          return null;
        }
        // Tika properties come after other, within each visibility category.
        return [
            'visible' => array_merge( ( $other ?? [] )['visible'] ?? [],
                                      ( $tika ?? [] )['visible'] ?? [] ),
            'collapsed' => array_merge( ( $other ?? [] )['collapsed'] ?? [],
                                        ( $tika ?? [] )['collapsed'] ?? [] ),
                ];
      case 'prefer_tika':
        return ( $tika !== null ) ? $tika : $other;
      case 'only_tika':
        return $tika;
    }
    self::unreachable();
  }
}
