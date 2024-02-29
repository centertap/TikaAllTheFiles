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

use \DateTimeImmutable;
use \DateTimeZone;
use FormatJson;
use FSFile;
use MapCacheLRU;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;
use \RuntimeException;

use MediaWiki\Extension\TikaAllTheFiles\Exceptions\TikaParserException;
use MediaWiki\Extension\TikaAllTheFiles\Exceptions\TikaSystemException;


/**
 * Query cache
 *
 * Three levels of caching:
 *
 *  * process-local cache
 *    - preserve results obtained during a single web-request, to avoid
 *      the "tika queried multiple times for the same file" problem
 *
 *  * ephemeral cross-process cache  [LEAST IMPORTANT]
 *    - e.g., redis
 *    - use existing MW infrastructure to keep bits in RAM(?) over
 *      multiple web-requests
 *
 *  * persistent on-disk cache
 *    - long-term caching of query results on filesystem
 *    - avoids hitting tika again at all
 *    - especially important for extracted text, since/if extracted
 *      text is not stashed in the database
 *
 * The cache key is the SHA1 hash of the file.  Why?  Because:
 *  - SHA1 hash is already stored by MW as standard metadata element.
 *  - It can be computed independently of Tika.
 *  - It works if the same file is stored under multiple paths/filenames.
 *
 * An additional, temporary local-process cache is also maintained that maps
 * filenames to SHA1 hashes.
 */
class QueryCache {

  /** @var LoggerInterface - handle to LoggerInterface for structured logging */
  private LoggerInterface $logger;

  /** @var ?MapCacheLRU - map from SHA1 string to CacheEntry */
  public ?MapCacheLRU $localCache;

  /** @var array<string,string> - map from pathname to SHA1 */
  public array $fileToSha1Map;


  /**
   * Construct a new QueryCache.
   *
   * @param LoggerInterface $logger where to do logging
   * @param int $localCacheSize number of entries in the local LRU cache
   */
  public function __construct( LoggerInterface $logger,
                               int $localCacheSize ) {
    $this->logger = $logger;
    $this->fileToSha1Map = [];
    $this->localCache =
        ( $localCacheSize > 0 ) ? new MapCacheLRU( $localCacheSize ) : null;
  }


  //============================================================================
  //
  // SHA1 hash operations
  //
  //============================================================================
  //
  // TODO(maddog) Accept File|FSFile|MediaHandlerState instead of
  //              a bare pathname.  (E.g., FSFile does its own SHA1 caching.)


  /**
   * Compute the SHA1 hash of the contents of a local file.
   *
   * @param string $filePath local pathname
   *
   * @return string SHA1 hash as calculated by FSFile::getSha1Base36()
   */
  private static function computeSha1( string $filePath ): string {
    $sha1 = (new FSFile( $filePath ))->getSha1Base36();
    '@phan-var string|false $sha1'; // NB: getSha1Base36() is mistyped.
    if ( $sha1 === false ) {
      throw new RuntimeException(
          "Failed to calculate SHA1 hash for '{$filePath}'" );
    }
    return $sha1;
  }


  /**
   * Record the SHA1 hash string for a file at the given pathname,
   * in a temporary process-local cache.
   *
   * @param string $sha1 SHA1 hash string
   * @param string $filePath pathname of the file with that hash
   *
   * @return void  This method returns no value.
   */
  private function indexFilePath( string $sha1, string $filePath ): void {
    $oldSha1 = $this->fileToSha1Map[$filePath] ?? null;
    if ( $oldSha1 === null ) {
      $this->fileToSha1Map[$filePath] = $sha1;
    } else {
      Core::insist( $sha1 === $oldSha1 );
    }
  }


  /**
   * Ensure that an SHA1 hash is known for the contents of a file identified
   * by a pathname.
   *
   * If a SHA1 hash is provided for the filepath, it is stored in the
   * process-local cache and returned.  Otherwise, the process-local cache
   * is checked for an already-known value, and if not found, the hash is
   * simply calculated (and then stored in the local cache for later).
   *
   * @param ?string $sha1 optional SHA1 hash, if known
   * @param string $filePath pathname of a local file
   *
   * @return string the SHA1 hash for the file at $filePath
   */
  private function ensureSha1ForFilePath( ?string $sha1,
                                          string $filePath ): string {
    if ( $sha1 === null ) {
      $sha1 = $this->fileToSha1Map[$filePath] ?? null;
      if ( $sha1 === null ) {
        $sha1 = self::computeSha1( $filePath );
        $this->fileToSha1Map[$filePath] = $sha1;
        $this->logger->debug(
            __METHOD__ . ': computed sha1 {sha1} for path {path}',
            [ 'sha1' => $sha1,
              'path' => $filePath, ] );
      }
    } else {
      $this->logger->debug( __METHOD__ . ': index sha1 {sha1} for path {path}',
                            [ 'sha1' => $sha1,
                              'path' => $filePath, ] );
      $this->indexFilePath( $sha1, $filePath );
    }
    return $sha1;
  }


  //============================================================================
  //
  // Process-local cache layer
  //
  //============================================================================
  //
  // https://doc.wikimedia.org/mediawiki-core/master/php/classMapCacheLRU.html


  /**
   * Write a CacheEntry into the process-local cache.
   *
   * @param CacheEntry $entry
   *
   * @return void  This method returns no value.
   */
  private function stashLocally( CacheEntry $entry ): void {
    if ( $this->localCache !== null ) {
      $this->localCache->set( $entry->getSha1(), $entry );
    }
  }


  /**
   * Fetch a CacheEntry from the process-local cache.
   *
   * @param string $sha1 the SHA1 hash key of the entry
   *
   * @return ?CacheEntry the CacheEntry, or null if no entry with that key
   *  is in the process-local cache.
   */
  private function fetchLocally( string $sha1 ): ?CacheEntry {
    return $this->localCache?->get( $sha1 );
  }


  //============================================================================
  //
  // WANObjectCache cache layer
  //
  //============================================================================
  //
  // https://www.mediawiki.org/wiki/Special:MyLanguage/Manual:Object_cache
  //
  // TODO(maddog) Implement the WANObjectCache layer.
  //
  // Object cache interface?
  //  - local server cache?  MediaWikiServices->getLocalServerObjectCache() ?
  //  - WANObjectCache?


  /**
   * Determine if the WANObjectCache layer should be used for files with
   * the given TypeProfile.
   *
   * @unused-param $typeProfile
   *
   * @return bool true if the WANObjectCache layer should be used.
   */
  private function useObjectCache( TypeProfile $typeProfile ): bool {
    return false;
  }


  /**
   * Write a CacheEntry into the WANObjectCache layer, if that layer
   * is enabled for the given TypeProfile.
   *
   * @param TypeProfile $typeProfile
   * @param CacheEntry $entry
   *
   * @return void  This method returns no value.
   */
  /**
   * @unused-param $entry
   */
  private function maybeStashToObjectCache( TypeProfile $typeProfile,
                                            CacheEntry $entry ): void {
    if ( $this->useObjectCache( $typeProfile ) ) {
      Core::unreachable();
    }
  }


  /**
   * Fetch a CacheEntry from the WANObjectCache layer.
   *
   * @unused-param TypeProfile $typeProfile
   * @unused-param $sha1
   * @unused-param $onlyMetadata
   *
   * @return ?CacheEntry
   */
  private function fetchFromObject( TypeProfile $typeProfile,
                                    string $sha1,
                                    bool $onlyMetadata ): ?CacheEntry {
    Core::unreachable();
  }


  /**
   * Maybe upgrade (resolve more of) a CacheEntry using the WANObjectCache layer,
   * if that layer is enabled for the given TypeProfile.
   *
   * @param TypeProfile $typeProfile
   * @unused-param $entry
   * @unused-param $onlyMetadata
   *
   * @return array{CacheEntry, bool} a pair of a CacheEntry and a bool
   *    containing an "upgraded" flag.  If $entry has been updated, then
   *    (new instance, true) is returned; else ($entry, false) is returned.
   */
  private function maybeUpgradeFromObject( TypeProfile $typeProfile,
                                           CacheEntry $entry,
                                           bool $onlyMetadata ): array {
    $result = $entry;
    if ( $this->useObjectCache( $typeProfile ) ) {
      $fromFile = $this->fetchFromObject( $typeProfile,
                                          $entry->getSha1(),
                                          $onlyMetadata );
      if ( $fromFile !== null ) {
        $result = $entry->resolveFromOther( $fromFile );
      }
    }
    return [ $result, $result !== $entry ];
  }


  //============================================================================
  //
  // Persistent, File System cache layer
  //
  //============================================================================
  //
  // https://doc.wikimedia.org/mediawiki-core/master/php/filebackendarch.html
  // https://doc.wikimedia.org/mediawiki-core/master/php/classFileBackend.html#details
  // https://www.mediawiki.org/wiki/Manual:$wgFileBackends
  // https://doc.wikimedia.org/mediawiki-core/master/php/classFSFileBackend.html
  //
  //
  // Admin must add an entry to $wgFileBackends:
  //
  // $wgFileBackends[] = [
  //   'name' => 'tatf-cache',
  //   'class' => FSFileBackend::class,
  //   'domainId' => '',
  //   'basePath' => $wgBaseDirectory . '/tatf-cache',
  //   'fileMode' => 0644,       // (same as MW default)
  //   'directoryMode' => 0755,  // (MW default is 0777)
  //   'lockManager' => 'nullLockManager',
  // ];
  //
  //
  // Storage scheme, for SHA1 = "abcXXX":
  //
  //   [basePath]/a/ab/
  //      abcXXX.base.json
  //        ---> everything except contents
  //             (though... could include contents too if it is short enough)
  //      abcXXX.contents.json
  //        ---> just the contents content, when it exists


  /**
   * Determine if the File System cache layer should be used for files with
   * the given TypeProfile.
   *
   * @unused-param $typeProfile
   *
   * @return bool true if the File System cache layer should be used.
   */
  private function useFileCache( TypeProfile $typeProfile ): bool {
    return ( $typeProfile->cacheFileBackend !== false );
  }


  /**
   * Given a SHA1 hash, generate "storage paths" for the corresponding files
   * in the file-based persistent cache.
   *
   * @return array{string, string, string}  return 3-tuple of strings:
   *    (containing directory, base filepath, contents filepath)
   */
  private function makeFileCachePaths( string $backendName,
                                       string $sha1 ): array {
    // NB:  This all assumes that $sha1 contains only ASCII characters.
    $prefix = "mwstore://{$backendName}/{$sha1[0]}/{$sha1[0]}{$sha1[1]}/";
    return [ $prefix,
             $prefix . $sha1 . '.base.json',
             $prefix . $sha1 . '.contents.json', ];
  }


  /**
   * Write a CacheEntry into the File System cache.
   *
   * @param TypeProfile $typeProfile profile for the file being analyzed
   * @param CacheEntry $entry
   *
   * @return void  This method returns no value.
   */
  private function maybeStashToFileSystem( TypeProfile $typeProfile,
                                           CacheEntry $entry ): void {
    if ( !$this->useFileCache( $typeProfile ) ) {
      return;
    }
    $backendName = $typeProfile->cacheFileBackend;
    Core::insist( $backendName !== false );
    $backend = MediaWikiServices::getInstance()
        ->getFileBackendGroup()->get( $backendName );

    [ $sm, $sc ] = $entry->serialize();

    [ $storageDirectory,
      $basePath,
      $contentsPath ] = $this->makeFileCachePaths( $backendName,
                                                   $entry->getSha1() );

    $status = $backend->prepare( ['dir' => $storageDirectory ] );
    if ( !$status->isGood() ) {
      throw new RuntimeException(
          "Failed to prepare `{$storageDirectory}` for `{$entry->getSha1()}`:  {$status}" );
    }

    // Set up operation: create/overwrite $basePath file.
    $operations = [ [ 'op' => 'create',
                      'dst' => $basePath,
                      'content' => FormatJson::encode( $sm ),
                      'overwrite' => true, ] ];
    if ( is_array( $sc ) ) {
      // Set up operation: create/overwrite $contentsPath file.
      $operations[] = [ 'op' => 'create',
                        'dst' => $contentsPath,
                        'content' => FormatJson::encode( $sc ),
                        'overwrite' => true, ];
    } else {
      Core::insist( ( $sc === false ) || ( $sc === null ) );
      // Set up operation: delete $contentsPath file if it exists.
      $operations[] = [ 'op' => 'delete',
                        'src' => $contentsPath,
                        'ignoreMissingSource' => true, ];
    }

    $options = [];
    $status = $backend->doOperations( $operations, $options );
    if ( !$status->isGood() ) {
      throw new RuntimeException(
          "Failed to write files for `{$entry->getSha1()}`: {$status}" );
    }
  }


  /**
   * Fetch a CacheEntry from the File System cache.
   *
   * @param TypeProfile $typeProfile profile for the file being analyzed
   * @param string $sha1 the SHA1 hash key of the entry
   * @param bool $onlyMetadata true if only metadata (not extracted contents)
   *   needs to be fetched from cache
   *
   * @return ?CacheEntry the CacheEntry, or null if no entry with that key
   *  is in the File System cache.
   */
  private function fetchFromFileSystem( TypeProfile $typeProfile,
                                        string $sha1,
                                        bool $onlyMetadata ): ?CacheEntry {
    $backendName = $typeProfile->cacheFileBackend;
    Core::insist( $backendName !== false );
    $backend = MediaWikiServices::getInstance()
        ->getFileBackendGroup()->get( $backendName );

    [ $_,  /*storageDirectory*/
      $basePath,
      $contentsPath ] = $this->makeFileCachePaths( $backendName, $sha1 );

    $paths = [ $basePath ];
    if ( !$onlyMetadata ) {
      $paths[] = $contentsPath;
    }

    $jsons = $backend->getFileContentsMulti( [ 'srcs' => $paths,
                                               'latest' => true, ] );
    $baseJson = $jsons[$basePath];
    $contentsJson = false;
    if ( !$onlyMetadata ) {
      $contentsJson = $jsons[$contentsPath];
    }

    if ( $baseJson === false ) {
      // No cached results.
      Core::insist( $onlyMetadata || ( $contentsJson === false ) );
      return null;
    }

    $this->logger->debug( "Found SHA1 `{$sha1}` in file system cache." );

    $status = FormatJson::parse( $baseJson, FormatJson::FORCE_ASSOC );
    if ( !$status->isGood() ) {
      throw new RuntimeException(
          "Bad JSON for `{$sha1}` for base `{$basePath}`: {$status}" );
    }
    $baseSerialized = $status->getValue();

    $contentsSerialized = null;
    if ( $contentsJson !== false ) {
      $status = FormatJson::parse( $contentsJson, FormatJson::FORCE_ASSOC );
      if ( !$status->isGood() ) {
        throw new RuntimeException(
            "Bad JSON for `{$sha1}` for contents `{$contentsPath}`: {$status}" );
      }
      $contentsSerialized = $status->getValue();
    }

    return CacheEntry::unserialize( $baseSerialized, $contentsSerialized );
  }


  /**
   * Maybe upgrade (resolve more of) a CacheEntry using the File System layer,
   * if that layer is enabled for the given TypeProfile.
   *
   * @param TypeProfile $typeProfile
   * @param CacheEntry $entry
   * @param bool $onlyMetadata
   *
   * @return array{CacheEntry, bool} a pair of a CacheEntry and a bool
   *    containing an "upgraded" flag.  If $entry has been updated, then
   *    (new instance, true) is returned; else ($entry, false) is returned.
   */
  private function maybeUpgradeFromFile( TypeProfile $typeProfile,
                                         CacheEntry $entry,
                                         bool $onlyMetadata ): array {
    $result = $entry;
    if ( $this->useFileCache( $typeProfile ) ) {
      $fromFile = $this->fetchFromFileSystem( $typeProfile,
                                              $entry->getSha1(),
                                              $onlyMetadata );
      if ( $fromFile !== null ) {
        $result = $entry->resolveFromOther( $fromFile );
      }
    }
    return [ $result, $result !== $entry ];
  }


  //============================================================================
  //
  // General, top-level caching interface
  //
  //============================================================================

  // TODO(maddog) Do we need to worry about issues with caching failures?
  //              Like... should/could a recent failure overwrite an earlier
  //              cached success?
  //
  // TODO(maddog) Add a config params for the maximum size of contents (or
  //              metadata?) to stash locally, or in object-cache.


  /**
   * Decide if a CacheEntry has enough elements resolved, or if more data
   * needs to be fetched from other cache layers or from Tika iteself.
   *
   * @param CacheEntry $entry the CacheEntry being testes
   * @param bool $onlyMetadata true if only metadata is needed
   *
   * @return bool true if $entry is resolved enough; false otherwise
   */
  private function isEntryResolvedEnough( CacheEntry $entry,
                                          bool $onlyMetadata ): bool {
    if ( $entry->isMetadataUnknown() ) {
      return false;
    }
    if ( $onlyMetadata ) {
      return true;
    }
    if ( $entry->isContentsUnknown() ) {
      return false;
    }
    return true;
  }


  /**
   * Stash an entry into the cache.
   *
   * This method assumes that the SHA1 hash key of $entry does in fact
   * match the SHA1 hash for the contents of the file at $filePath.
   *
   * @param TypeProfile $typeProfile the profile for the file in question
   * @param string $filePath local path to the file
   * @param CacheEntry $entry the entry for the file
   *
   * @return void  This method returns no value.
   */
  private function stash( TypeProfile $typeProfile,
                          string $filePath,
                          CacheEntry $entry ): void {
    $sha1 = $entry->getSha1();
    $this->indexFilePath( $sha1, $filePath );
    $this->stashLocally( $entry );
    $this->maybeStashToObjectCache( $typeProfile, $entry );
    $this->maybeStashToFileSystem( $typeProfile, $entry );
  }


  /**
   * Fetch the minimal requested data from the shallowest level of cache
   * necessary to satisfy the request.
   *
   * @param TypeProfile $typeProfile the profile for the file in question
   * @param string $sha1 the SHA1 hash for the file
   * @param bool $onlyMetadata true if only metadata is needed
   *
   * @return CacheEntry  returns a CacheEntry, which may be an empty entry
   */
  private function fetch( TypeProfile $typeProfile,
                          string $sha1,
                          bool $onlyMetadata ): CacheEntry {
    $entry = $this->fetchLocally( $sha1 ) ?? CacheEntry::newEmptyEntry( $sha1 );
    if ( !$this->isEntryResolvedEnough( $entry, $onlyMetadata ) ) {
      [ $entry, $upgraded ] = $this->maybeUpgradeFromObject( $typeProfile,
                                                             $entry,
                                                             $onlyMetadata );
      if ( $upgraded ) {
        $this->stashLocally( $entry );
      }
    }
    if ( !$this->isEntryResolvedEnough( $entry, $onlyMetadata ) ) {
      [ $entry, $upgraded ] = $this->maybeUpgradeFromFile( $typeProfile,
                                                           $entry,
                                                           $onlyMetadata );
      if ( $upgraded ) {
        $this->maybeStashToObjectCache( $typeProfile, $entry );
        $this->stashLocally( $entry );
      }
    }

    return $entry;
  }


  /**
   * Auxillary method to execute a Tika query and update a CacheEntry with
   * the result (be it a response or an exception).
   *
   * @param CacheEntry $entry initial/current state of cache
   * @param DateTimeImmutable $now nominal timestamp for this query's execution
   * @param TikaQueryProvider $tika something that can talk to Tika
   * @param TypeProfile $typeProfile the TypeProfile in effect
   * @param string $filePath local filepath of file to analyze
   * @param bool $onlyMetadata true if only metadata is required,
   *    not extracted text contents
   *
   * @return CacheEntry the value of $entry amended with the Tika result
   *
   * @throws TikaSystemException if a Tika query is attempted, but there is a
   *    failure to set up the Tika query or to communicate with Tika server
   */
  private function doTikaQuery( CacheEntry $entry,
                                DateTimeImmutable $now,
                                TikaQueryProvider $tika,
                                TypeProfile $typeProfile,
                                string $filePath,
                                bool $onlyMetadata ): CacheEntry {
    try {
      $response = $tika->queryTika( $typeProfile, $filePath, $onlyMetadata );
    } catch ( TikaParserException $e ) {
      $this->logger->debug( __METHOD__ . ': Tika exception:  {exception}',
                            [ 'exception' => var_export( $e, true ) ] );
      return $entry->updateFromException( $now, $onlyMetadata, $e );
    }
    $this->logger->debug( __METHOD__ . ': Tika response:  {response}',
                          [ 'response' => var_export( $response, true ) ] );
    return $entry->updateFromResponse( $now, $onlyMetadata, $response );
  }


  /**
   * Find the requested Tika response in the cache, or query Tika directly if
   * necessary.
   *
   * Any new response is cached appropriately.
   *
   * @param TikaQueryProvider $tika something that can talk to Tika
   * @param TypeProfile $typeProfile the TypeProfile in effect
   * @param string $filePath local filepath of file to analyze
   * @param bool $onlyMetadata true if only metadata is required,
   *    not extracted text contents
   *
   * @return CacheEntry containing the Tika response
   *
   * @throws TikaSystemException if a Tika query is attempted, but there is a
   *    failure to set up the Tika query or to communicate with Tika server
   */
  private function doQueryCacheOrTika( TikaQueryProvider $tika,
                                       TypeProfile $typeProfile,
                                       string $filePath,
                                       bool $onlyMetadata ): CacheEntry {
    $sha1 = $this->ensureSha1ForFilePath( /*$sha1*/null, $filePath );

    $entry = $this->fetch( $typeProfile, $sha1, $onlyMetadata );

    $original = $entry;
    $entry = $entry->updateFromExpiration( $typeProfile->expireEarlierSuccess,
                                           $typeProfile->expireEarlierFailure );
    if ( $entry !== $original ) {
      $this->stash( $typeProfile, $filePath, $entry );
    }

    if ( $this->isEntryResolvedEnough( $entry, $onlyMetadata ) ) {
      $this->logger->debug(
          __METHOD__ . ": cache hit for sha1 {sha1} at path {path}\n{entry}",
          [ 'sha1' => $sha1,
            'path' => $filePath,
            'entry' => var_export( $entry, true ) ] );
      return $entry;
    }

    $this->logger->debug(
        __METHOD__ . ': cache miss for sha1 {sha1} at path {path}',
        [ 'sha1' => $sha1,
          'path' => $filePath, ] );

    // Well, I guess we do need to talk to Tika after all.
    $now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

    // If contents is known already, then we only need to query for metadata.
    // (This can occur if we have cached a failed contents query, but have never
    // cached/attempted a metadata-only query.)
    $onlyMetadata = $onlyMetadata || ( !$entry->isContentsUnknown() );

    $entry = $this->doTikaQuery( $entry, $now, $tika, $typeProfile, $filePath,
                                 $onlyMetadata );
    if ( $entry->isMetadataUnknown() ) {
      // This (unknown metadata) should only be possible if we performed a
      // contents query, and it failed (e.g., due to timeout).
      Core::insist( !$onlyMetadata );
      // We will redo as metadata-only, to decide the metadata question.
      $this->logger->debug( __METHOD__ . ': Requery as metadata-only' );
      $onlyMetadata = true;
      $entry = $this->doTikaQuery( $entry, $now, $tika, $typeProfile, $filePath,
                                   $onlyMetadata );
      Core::insist( !$entry->isMetadataUnknown() );
    }

    $this->stash( $typeProfile, $filePath, $entry );
    return $entry;
  }


  /**
   * Get a Tika-response for the given local file, either from the cache
   * or by querying Tika itself.
   *
   * @param TikaQueryProvider $tika something that can talk to Tika
   * @param TypeProfile $typeProfile the TypeProfile in effect
   * @param string $filePath local filepath of file to analyze
   * @param bool $onlyMetadata true if only metadata is required,
   *    not extracted text contents
   *
   * @return array{array, list<string>} tuple of (metadata, extracted-text)
   *
   * @throws TikaSystemException if a Tika query is attempted, but there is a
   *    failure to set up the Tika query or to communicate with Tika server
   *
   * @throws TikaParserException if Tika failed to analyze the file, whenever
   *    the request occurred (i.e., such failures are cached)
   */
  public function queryCacheOrTika( TikaQueryProvider $tika,
                                    TypeProfile $typeProfile,
                                    string $filePath,
                                    bool $onlyMetadata ): array {
    //wfLogWarning( core::LOG_GROUP . ' ' . __METHOD__ . ': ' );
    $entry = $this->doQueryCacheOrTika( $tika, $typeProfile,
                                        $filePath,
                                        $onlyMetadata );
    if ( $onlyMetadata ) {
      return [ $entry->getMetadataOrThrow(),
               [''], ];
    } else {
      return [ $entry->getMetadataOrThrow(),
               $entry->getContentsOrThrow(), ];
    }
  }

}
