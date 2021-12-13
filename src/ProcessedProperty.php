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
 * Struct for holding the results of processing a Tika property through
 * MetadataMapper.
 */
class ProcessedProperty {

  /**
   * @var string Human-readable (maybe even localized) name of the property
   *  for display to user or search
   */
  private string $name;

  /**
   * @var array List of human-readable values for the property, as strings,
   *  for display to user or search
   *
   * Single-valued properties will have single-valued arrays.
   */
  private array $values;

  /**
   * @var string Lower-cased canonical/internal name for the property to
   *  determine its visibility, e.g., like an entry in the return value
   *  of FormatMetadata::getVisibleFields())
   */
  private string $visibilityTag;

  /**
   * @var string the "id" attribute provided to the HTML element used to
   *  render this property; typically "exif-" prepended to $visibilityTag
   */
  private string $id;


  /**
   * @param string $name
   * @param array $values
   * @param string $visibilityTag
   * @param string $id
   */
  public function __construct( string $name, array $values,
                               string $visibilityTag, string $id ) {
    $this->name = $name;
    $this->values = $values;
    $this->visibilityTag = $visibilityTag;
    $this->id = $id;
  }


  /**
   * @return string
   */
  public function name(): string {
    return $this->name;
  }


  /**
   * @return array
   */
  public function values(): array {
    return $this->values;
  }


  /**
   * @return string
   */
  public function visibilityTag(): string {
    return $this->visibilityTag;
  }


  /**
   * @return string
   */
  public function id(): string {
    return $this->id;
  }
}
