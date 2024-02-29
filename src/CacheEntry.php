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

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;

use MediaWiki\Extension\TikaAllTheFiles\Exceptions\CachedTikaParserException;
use MediaWiki\Extension\TikaAllTheFiles\Exceptions\TikaParserException;


/**
 * Class representing the Tika query results/response for a file, as cached.
 *
 * A CacheEntry is linked to a file by the SHA1 hash of the file's contents
 * (not by the filename, since the same contents may be stored in different
 * files).
 *
 * Instances of this class are immutable.  (Operations which change the
 * contents will return a new instance.)
 */
class CacheEntry {

  /** The current version of the serialized format */
  public const CURRENT_VERSION = 1;

  /** @var string the SHA1 hash key for this entry */
  private string $sha1;

  /** @var ?DateTimeImmutable UTC timestamp of successful data
   *  (Failure timestamps are embedded in CachedTikaParserException.) */
  private ?DateTimeImmutable $successTimestamp;

  /** @var null|array|CachedTikaParserException cached metadata */
  private null|array|CachedTikaParserException $metadata;

  /** @var null|list<string>|CachedTikaParserException cached extracted text */
  private null|array|CachedTikaParserException $contents;


  /**
   * Private constructor.
   *
   * See newEmptyEntry(), resolveFromOther(), and the update*() methods for
   * public ways to create instances.
   *
   * @param string $sha1
   * @param null|DateTimeImmutable $successTimestamp
   * @param null|array|CachedTikaParserException $metadata
   * @param null|list<string>|CachedTikaParserException $contents
   */
  private function __construct( string $sha1,
                                ?DateTimeImmutable $successTimestamp,
                                null|array|CachedTikaParserException $metadata,
                                null|array|CachedTikaParserException $contents
                                ) {
    $this->sha1 = $sha1;
    $this->successTimestamp = $successTimestamp;
    $this->metadata = $metadata;
    $this->contents = $contents;
  }


  /**
   * Create a blank entry; all fields unresolved (except for the SHA1).
   *
   * @param $sha1 string the SHA1 hash identifying the entry.
   *
   * @return CacheEntry a new (mostly) blank instance
   */
  public static function newEmptyEntry( string $sha1 ): CacheEntry {
    return new CacheEntry( $sha1,
                           null,  // No success, no successTimestamp.
                           null, null );
  }


  /**
   * Return the SHA1 hash identifying the CacheEntry
   *
   * @return string
   */
  public function getSha1(): string {
    return $this->sha1;
  }


  /**
   * Return entry's metadata array if entry has valid metadata, or throws
   * an exception if the entry is caching a metadata failure.
   *
   * It is an error to call this if the metadata state is unknown.
   *
   * @return array
   * @throws TikaParserException
   */
  public function getMetadataOrThrow(): array {
    $value = $this->metadata;
    Core::insist( $value !== null );
    if ( $value instanceof TikaParserException ) {
      throw $value;
    }
    return $value;
  }


  /**
   * Return entry's contents array if entry has valid contents, or throws
   * an exception if the entry is caching a contents failure.
   *
   * It is an error to call this if the contents state is unknown.
   *
   * @return array
   * @throws TikaParserException
   */
  public function getContentsOrThrow(): array {
    $value = $this->contents;
    Core::insist( $value !== null );
    if ( $value instanceof TikaParserException ) {
      throw $value;
    }
    return $value;
  }


  /**
   * Test if the metadata status is unknown/unresolved.
   *
   * @return bool true if unresolved, false otherwise.
   */
  public function isMetadataUnknown(): bool {
    return $this->metadata === null;
  }


  /**
   * Test if the metadata is valid.
   *
   * @return bool true if valid, false otherwise.
   */
  public function isMetadataSuccess(): bool {
    return is_array( $this->metadata );
  }


  /**
   * Test if the metadata is a cached failure.
   *
   * @return bool true if failure, false otherwise.
   */
  public function isMetadataFailure(): bool {
    return $this->metadata instanceof \Exception;
  }


  /**
   * Test if the contents status is unknown/unresolved.
   *
   * @return bool true if unresolved, false otherwise.
   */
  public function isContentsUnknown(): bool {
    return $this->contents === null;
  }


  /**
   * Test if the contents is a cached failure.
   *
   * @return bool true if failure, false otherwise.
   */
  public function isContentsFailure(): bool {
    return $this->contents instanceof \Exception;
  }


  /**
   * Test if the contents are valid.
   *
   * @return bool true if valid, false otherwise.
   */
  public function isContentsSuccess(): bool {
    return is_array( $this->contents );
  }


  /**
   * Check invariants beyond what is already expressed by field types.
   *
   * | Meta | Cont |
   * |------|------|
   * | null | null | everything unknown
   * | null | fail | M+C query failed, M-only unknown
   * | null | good | FORBIDDEN
   * | fail | null | M-only failed, M+C query unknown
   * | fail | fail | both types of query failed
   * | fail | good | FORBIDDEN
   * | good | null | M-only ok, M+C query unknown
   * | good | fail | M-only ok, M+C query failed
   * | good | good | everything ok
   *
   * @return void  This method returns no value.
   */
  private function checkInvariants(): void {
    // Metadata must be valid if contents is valid.
    if ( $this->isContentsSuccess() ) {
      Core::insist( $this->isMetadataSuccess(),
                    'if contents is good, metadata must also be good' );
    }

    // If and only if successTimestamp is non-null, then at least one of
    // metadata or contents must bear valid data.  Given the CS->MS constraint,
    // only metadata matters.
    Core::insist(
        $this->isMetadataSuccess() xor ( $this->successTimestamp === null ),
        'metadata and/or contents is good, xor successTimestamp is null' );
  }


  /**
   * Update entry using data extracted from the response of a successful
   * Tika query.
   *
   * If $onlyMetadata is true, the contents field will be left alone.
   *
   * @param DateTimeImmutable $timestamp  timestamp for the query/response
   * @param bool $onlyMetadata  true if the request was for metadata-only
   * @param array $response  the response from Tika
   *
   * @return CacheEntry a new instance of CacheEntry
   */
  public function updateFromResponse( DateTimeImmutable $timestamp,
                                      bool $onlyMetadata,
                                      array $response ): CacheEntry {
    $newContents = [ trim( $response["X-TIKA:content"] ?? '' ) ];
    // Remove tika-content from the response, leaving all the other metadata.
    unset( $response["X-TIKA:content"] );
    $newMetadata = $response;

    // NB: This preserves an earlier contents failure if we don't have
    //     a new contents success.
    return new CacheEntry(
        $this->sha1,
        $timestamp,
        $newMetadata,
        $onlyMetadata ? $this->contents : $newContents, );
  }


  /**
   * Update entry using the exception thrown by a failed Tika query.
   *
   * If $onlyMetadata is true, the exception will be cached as a failed
   * metadata-only query.  Conversely, if false, the exception will be
   * cached as a failed contents query.
   *
   * @param DateTimeImmutable $timestamp  timestamp for the query/response
   * @param bool $onlyMetadata  true if the request was for metadata-only
   * @param TikaParserException $e  the exception thrown by the Tika failure
   *
   * @return CacheEntry a new instance of CacheEntry
   */
  public function updateFromException( DateTimeImmutable $timestamp,
                                       bool $onlyMetadata,
                                       TikaParserException $e ): CacheEntry {
    $cachedException = new CachedTikaParserException( $e, $timestamp,
                                                      $onlyMetadata );
    if ( $onlyMetadata ) {
      $newMetadata = $cachedException;
      $newContents = $this->contents;
    } else {
      $newMetadata = $this->metadata;
      $newContents = $cachedException;
    }
    // NB: The above logic preserves the earlier metadata or contents
    //     status if we don't have a new failure for that query type.
    //     E.g., given a contents-query failure,
    //     (M null, C null) becomes (M null, C fail) --- that tells us
    //     not to bother with another C query, but that an M query
    //     may succeed.
    //
    //     (Presumably, at least one of M or C is null when we are called,
    //     otherwise why was a query performed?)
    return new CacheEntry( $this->sha1,
                           $this->successTimestamp,
                           $newMetadata,
                           $newContents );
  }


  /**
   * Update entry by expiring old results.
   *
   * If $successExpiry is provided, reverts valid metadata and/or contents
   * successes to unknown (null) if $successTimestamp <= $successExpiry.
   *
   * If $failureExpiry is provided, reverts cached metadata and/or contents
   * failures to unknown (null) if cached timestamp <= $successExpiry.
   *
   * @param ?DateTimeImmutable $successExpiry expiration for successes
   * @param ?DateTimeImmutable $failureExpiry expiration for failures
   *
   * @return CacheEntry a new instance of CacheEntry if anything has changed,
   *    otherwise $entry is returned if there are no changes.
   */
  public function updateFromExpiration( ?DateTimeImmutable $successExpiry,
                                        ?DateTimeImmutable $failureExpiry
                                        ): CacheEntry {
    $newSuccessTimestamp = $this->successTimestamp;
    $newMetadata = $this->metadata;
    $newContents = $this->contents;
    $changed = false;

    if ( ( $successExpiry !== null ) &&
         ( $this->successTimestamp !== null ) &&
         // TODO(maddog) Remove suppress when phan #3035 is fixed.
         // @phan-suppress-next-line PhanPluginComparisonObjectOrdering
         ( $this->successTimestamp <= $successExpiry ) ) {
      $newSuccessTimestamp = null;
      $changed = true;
      if ( $this->isMetadataSuccess() ) {
        $newMetadata = null;
      }
      if ( $this->isContentsSuccess() ) {
        $newContents = null;
      }
    }

    if ( $failureExpiry !== null ) {
      if ( $this->isMetadataFailure() ) {
        Core::insist( $this->metadata instanceof CachedTikaParserException );
        // TODO(maddog) Remove suppress when phan #3035 is fixed.
        // @phan-suppress-next-line PhanPluginComparisonObjectOrdering
        if ( $this->metadata->timestamp <= $failureExpiry ) {
          $newMetadata = null;
          $changed = true;
        }
      }
      if ( $this->isContentsFailure() ) {
        Core::insist( $this->contents instanceof CachedTikaParserException );
        // TODO(maddog) Remove suppress when phan #3035 is fixed.
        // @phan-suppress-next-line PhanPluginComparisonObjectOrdering
        if ( $this->contents->timestamp <= $failureExpiry ) {
          $newContents = null;
          $changed = true;
        }
      }
    }

    if ( !$changed ) {
      return $this;
    }

    return new CacheEntry(
        $this->sha1,
        $newSuccessTimestamp,
        $newMetadata,
        $newContents );
  }


  /**
   * Resolve unknown elements using data from another CacheEntry.
   *
   * The intent is to enable pulling progressively more information out of
   * deeper levels of cache (nothing -> metadata-only -> metadata+contents).
   *
   * @param CacheEntry $other the other entry
   *
   * @return CacheEntry  $this if nothing has changed; otherwise a new instance.
   */
  public function resolveFromOther( CacheEntry $other ): CacheEntry {
    Core::insist( $other->sha1 === $this->sha1 );
    $this->checkInvariants();
    $other->checkInvariants();

    $changed = false;
    $result = new CacheEntry(
        $this->sha1,
        $this->successTimestamp, $this->metadata, $this->contents );

    // |    $this    |
    // | Meta | Cont |
    // |------|------|
    // | null | null | Replace M,C (unless $other is null/null, too).
    // |------|------|
    // | null | good | FORBIDDEN by checkInvariants()
    // | fail | good | FORBIDDEN by checkInvariants()
    // |------|------|
    // | null | fail | If $other M is non-null, replace M.
    // |      |      |
    // | fail | null | If $other C is non-null, replace C.
    // |      |      | (Note: since $this M is fail, $this C must be not-good.
    // |      |      |  If $other C is good, then $other M is also good, and
    // |      |      |  something has gone wrong somewhere.)
    // |------|------|
    // | good | null | If $other C is non-null, replace C.
    // |------|------|
    // | fail | fail | No change (already resolved enough).
    // | good | fail | No change (already resolved enough).
    // | good | good | No change (already resolved enough).

    if ( ( $this->metadata === null ) && ( $other->metadata !== null ) ) {
      // $this timestamp should be null (from invariants).
      // $other timestamp should be non-null if-and-only-if $other M is good.
      $result->metadata = $other->metadata;
      $result->successTimestamp = $other->successTimestamp;
      $changed = true;
    }

    if ( ( $this->contents === null ) && ( $other->contents !== null ) ) {
      $result->contents = $other->contents;
      if ( $other->isContentsSuccess() ) {
        // $other timestamp should be non-null (from invariants).
        // $other M must be good, and *should* already match $this M.
        //   e.g., $this->metadata == $other->metadata;
        // $this successTimestamp *should* match $other's, too.
        // @phan-suppress-next-line PhanPluginComparisonObjectEqualityNotStrict
        Core::insist( $result->successTimestamp == $other->successTimestamp );
      }
      $changed = true;
    }

    if ( $changed ) {
      $result->checkInvariants();
      return $result;
    }

    return $this;
  }


  /**
   * Serialize a DateTimeImmutable to string.
   *
   * @param DateTimeImmutable $ts timestamp
   * @return string
   */
  private static function serializeTimestamp( DateTimeImmutable $ts
                                              ): string {
    return $ts->format( DateTimeInterface::RFC3339_EXTENDED );
  }


  /**
   * Unserialize a DateTimeImmutable from string.
   *
   * @param string $s a string
   * @return DateTimeImmutable
   */
  private static function unserializeTimestamp( string $s ): DateTimeImmutable {
    $result = DateTimeImmutable::createFromFormat(
        DateTimeInterface::RFC3339_EXTENDED, $s );
    if ( $result === false ) {
      throw new RuntimeException(
          "Bad parse of date-time string '{$s}':\n" .
          var_export( DateTimeImmutable::getLastErrors(), true ) );
    }
    return $result;
  }


  /**
   * Serialize a CachedTikaParserException to array.
   *
   * @param CachedTikaParserException $e
   *
   * @return array
   */
  private static function serializeCachedException(
      CachedTikaParserException $e ): array {
    return [
        'timestamp' => self::serializeTimestamp( $e->timestamp ),
        'onlyMetadata' => $e->onlyMetadata,
        'previousMessage' => $e->getPrevious()?->getMessage(),
            ];
  }


  /**
   * Unserialize a CachedTikaParserException from array.
   *
   * @param array $a
   *
   * @return CachedTikaParserException
   */
  private static function unserializeCachedException(
      array $a ): CachedTikaParserException {
    // Check for missing keys.
    $missing_keys = array_diff_key(
        [ 'previousMessage' => true,
          'timestamp' => true,
          'onlyMetadata' => true, ], $a );
    if ( count( $missing_keys ) > 0 ) {
      throw new RuntimeException(
          'Unserialize failure, missing keys: ' . var_export( $missing_keys,
                                                              true ) );
    }
    return new CachedTikaParserException(
        new TikaParserException( $a['previousMessage'] ),
        self::unserializeTimestamp( $a['timestamp'] ),
        $a['onlyMetadata'] );
  }


  // JSON-serialized CacheEntry metadata
  //       {
  //         "schema": 1,
  //         "sha1": ".......",   < string
  //         "successTimestamp":  < string|null
  //               - null:  no successes
  //               - string:  date of metadata/contents success
  //         "metadata":  < false|object
  //               - false: failure/exception
  //               - object:  metadata data array
  //         "metadataFailure":  < null|object
  //               - null:  no exception
  //               - object:  cached exception bits
  //         "contents":  < null|false|true|object
  //               - null:  unknown/untested state
  //               - false: failure/exception
  //               - true:  data exists in separate file
  //               - object:  contents data array
  //         "contentsFailure":  < null|object
  //               - null:  no exception
  //               - object:  cached exception bits
  //       }
  //
  // JSON-serialized CacheEntry contents
  //       {
  //         "schema": 1,
  //         "sha1": string,
  //         "contents": object
  //       }

  /**
   * Serialize a CacheEntry, returning a pair (2-tuple).
   *
   * The first element of the pair is the serialization of the CacheEntry
   * as an array of non-object values (that can be JSON-encoded).
   *
   * The second element of the pair reflects on the the contents field of
   * the CacheEntry:
   *   - null if the contents is unknown/unresolved
   *   - false if the contents is a failure
   *   - array bearing the contents data itself if the contents is good
   *     (in which case the contents data is not stored in the first element).
   *
   * @return array{array,null|array|false} pair of (serialized-metadata,
   *                                                serialized-contents)
   */
  public function serialize(): array {
    $this->checkInvariants();

    // First, serialize everything into a single array of non-objects.
    $sm = [
        'schema' => self::CURRENT_VERSION,
        'sha1' => $this->sha1,
           ];

    if ( $this->successTimestamp !== null ) {
      $sm['successTimestamp'] =
          self::serializeTimestamp( $this->successTimestamp );
    } else {
      $sm['successTimestamp'] = null;
    }

    if ( is_array( $this->metadata ) ) {
      $sm['metadata'] = $this->metadata;
      $sm['metadataFailure'] = null;
    } elseif ( $this->metadata instanceof CachedTikaParserException ) {
      $sm['metadata'] = false;
      $sm['metadataFailure'] = self::serializeCachedException( $this->metadata );
    } else {
      $sm['metadata'] = null;
      $sm['metadataFailure'] = null;
    }

    if ( is_array( $this->contents ) ) {
      $sm['contents'] = $this->contents;
      $sm['contentsFailure'] = null;
    } elseif ( $this->contents instanceof CachedTikaParserException ) {
      $sm['contents'] = false;
      $sm['contentsFailure'] = self::serializeCachedException( $this->contents );
    } else {
      $sm['contents'] = null;
      $sm['contentsFailure'] = null;
    }

    // Second, split the contents, if any, out into a separate array.
    $sc = null;
    if ( is_array( $sm['contents'] ) ) {
      $sc = [
          'schema' => self::CURRENT_VERSION,
          'sha1' => $this->sha1,
          'contents' => $sm['contents'],
             ];
      $sm['contents'] = true;
    } elseif ( $sm['contents'] === false ) {
      $sc = false;
    }

    return [ $sm, $sc ];
  }


  // TODO(maddog)  Consider using MediaWiki\Json\JsonUnserializable

  /**
   * Unserialize a CacheEntry, from 1 or 2 arrays.
   *
   * @param array $sm the primary data array
   * @param ?array $sc optional array with contents data
   *
   * @return CacheEntry
   */
  public static function unserialize( array $sm, ?array $sc ): CacheEntry {
    // Validate schema version of serialized data.
    $schema = $sm['schema'] ?? null;
    if ( $schema !== self::CURRENT_VERSION ) {
      throw new RuntimeException(
          "Schema mismatch:  {$schema} !== " . self::CURRENT_VERSION );
    }
    // Check for missing keys in the primary array.
    $missing_keys_m = array_diff_key(
        [ 'sha1' => true,
          'successTimestamp' => true,
          'metadata' => true,
          'metadataFailure' => true,
          'contents' => true,
          'contentsFailure' => true, ], $sm );
    if ( count( $missing_keys_m ) > 0 ) {
      throw new RuntimeException(
          'Unserialize failure, missing keys: ' . var_export( $missing_keys_m,
                                                              true ) );
    }
    // If provided, validate schema/keys of secondary contents array.
    if ( $sc !== null ) {
      $missing_keys_c = array_diff_key(
          [ 'schema' => true,
            'sha1' => true,
            'contents' => true, ], $sc );
      if ( count( $missing_keys_c ) > 0 ) {
        throw new RuntimeException(
            'Unserialize failure, missing contents keys: ' .
            var_export( $missing_keys_c, true ) );
      }
      Core::insist( $sc['schema'] === $sm['schema'] );
      Core::insist( $sc['sha1'] === $sm['sha1'] );
      Core::insist( is_array( $sc['contents'] ) );
    }

    // Construct a CacheEntry.
    $result = self::newEmptyEntry( $sm['sha1'] );

    if ( $sm['successTimestamp'] === null ) {
      // No success timestamp means no successes!
      Core::insist( ( $sm['metadata'] === false ) ||
                    ( $sm['metadata'] === null ) );
      Core::insist( ( $sm['contents'] === false ) ||
                    ( $sm['contents'] === null ) );
    } else {
      $result->successTimestamp =
          self::unserializeTimestamp( $sm['successTimestamp'] );
    }

    if ( is_array( $sm['metadata'] ) ) {
      $result->metadata = $sm['metadata'];
      Core::insist( $sm['successTimestamp'] !== null );
      Core::insist( $sm['metadataFailure'] === null );
    } elseif ( $sm['metadata'] === null ) {
      // Nothing to do - leave metadata null and unknown.
    } else {
      Core::insist( $sm['metadata'] === false );
      $result->metadata =
          self::unserializeCachedException( $sm['metadataFailure'] );
    }

    if ( is_array( $sm['contents'] ) ) {
      $result->contents = $sm['contents'];
      Core::insist( $sm['successTimestamp'] !== null );
      Core::insist( $sm['contentsFailure'] === null );
    } elseif ( $sm['contents'] === true ) {
      if ( $sc === null ) {
        // Nothing to do - leave contents null (i.e., unloaded, actually).
      } else {
        $result->contents = $sc['contents'];
      }
      Core::insist( $sm['successTimestamp'] !== null );
      Core::insist( $sm['contentsFailure'] === null );
    } elseif ( $sm['contents'] === null ) {
      // Nothing to do - leave contents null (i.e., truly unknown).
    } else {
      Core::insist( $sm['contents'] === false );
      $result->contents =
          self::unserializeCachedException( $sm['contentsFailure'] );
    }

    $result->checkInvariants();
    return $result;
  }

}

