# Release Notes

## Version 1.0.2
**Fixes**
   - Recognize and operate with MediaWiki versions 1.39, 1.40, 1.41.
   - Fix some issues with running under PHP 8.2.
   - Now tested with MW 1.39 and PHP 8.2.

## Version 1.0.1
**Fixes**
   - When asking Tika for metadata only, always disable OCR (otherwise,
     Tika appears to invoke Tesseract, even though we do not need or even
     receive any resulting text output).
---

## Version 1.0.0
**Initial version**
