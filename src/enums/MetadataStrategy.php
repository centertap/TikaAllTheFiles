<?php
/**
 * This file is part of TikaAllTheFiles.
 *
 * Copyright 2023 Matt Marjanovic
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

namespace MediaWiki\Extension\TikaAllTheFiles\Enums;


enum MetadataStrategy: string {
  /** append any tika-metadata to any original metadata */
  case Combine = 'combine';

  /** tika-metadata, if any, or nothing */
  case OnlyTika = 'only_tika';

  /** tika-metadata only, if any, otherwise original metadata */
  case PreferTika = 'prefer_tika';

  /** original metadata only, if any, otherwise tika's */
  case PreferOther = 'prefer_other';

  /** original metadata only; skip tika metadata extraction */
  case NoTika = 'no_tika';
}
