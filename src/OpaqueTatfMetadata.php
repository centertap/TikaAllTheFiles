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

/**
 * Opaque container for stashing TATF handler's metadata.
 */
class OpaqueTatfMetadata {

  /**
   * @var array the actual metadata array
   */
  public array $metadata;


  /**
   * @param array $metadata to stash away
   */
  public function __construct( array $metadata ) {
    $this->metadata = $metadata;
  }


  /**
   * Q: What is inside this object?
   * A: Beats me.
   *
   * @return string
   */
  public function __toString(): string {
    return '¯\_(ツ)_/¯';
  }
}
