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

namespace MediaWiki\Extension\TikaAllTheFiles\Enums;


enum ContentStrategy: string {
  // TODO(maddog) What if someone wants "add-metadata-to-content" but doesn't
  //              want any text extraction?  Well... if that someone appears,
  //              we will deal with it then.

  /** append any tika-content to any original text */
  case Combine = 'combine';

  /** tika-content only, if any, or nothing */
  case OnlyTika = 'only_tika';

  /** tika-content only, if any, otherwise original text */
  case PreferTika = 'prefer_tika';

  /** original text only, if any, otherwise tika-content */
  case PreferOther = 'prefer_other';

  /** original text only; skip tika text extraction */
  case NoTika = 'no_tika';
}
