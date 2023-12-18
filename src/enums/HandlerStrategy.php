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


enum HandlerStrategy: string {
  /** TATF-only handler if there is no other handler */
  case Fallback = 'fallback';

  /** TATF-only handler always */
  case Override = 'override';

  /** If other handler, wrap with TATF; otherwise use TATF-only */
  case Wrapping = 'wrapping';
}
