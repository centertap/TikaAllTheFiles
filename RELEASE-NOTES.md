# Release Notes

## Version 1.0.1
**Fixes**
   - When asking Tika for metadata only, always disable OCR (otherwise,
     Tika appears to invoke Tesseract, even though we do not need or even
     receive any resulting text output).
---

## Version 1.0.0
**Initial version**
