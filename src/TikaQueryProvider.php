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

use MediaWiki\Extension\TikaAllTheFiles\Exceptions\TikaParserException;
use MediaWiki\Extension\TikaAllTheFiles\Exceptions\TikaSystemException;


interface TikaQueryProvider {
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
   * @throws TikaSystemException if failure to set up the Tika query or unable to
   *  communicate with Tika server
   * @throws TikaParserException if Tika tries but fails to perform the query
   */
  public function queryTika( TypeProfile $typeProfile,
                             string $filePath,
                             bool $onlyMetadata ): array;
}
