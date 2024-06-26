# Release Notes

## Version 2.0.0
***Upgrading***
   - This version requires MediaWiki >= 1.37 and PHP >= 8.1.
     The major number has changed due to these new system requirements.
   - New configuration parameters are available, but the default values
     preserve the prior behavior, so no configuration changes should
     be necessary.
     - New global parameters (prefixed with `$wgTikaAllTheFiles_`):

       | parameter        | default |
       |------------------|---------|
       | `LocalCacheSize` | 16      |

     - New handler profile parameters:

       | parameter                        | default |
       |----------------------------------|---------|
       | `ignore_content_service_errors`  | `false` |
       | `ignore_content_parsing_errors`  | `false` |
       | `ignore_metadata_service_errors` | `false` |
       | `ignore_metadata_parsing_errors` | `false` |
       |                                  |         |
       | `cache_expire_success_before`    | `false` |
       | `cache_expire_failure_before`    | `false` |
       | `cache_file_backend`             | `false` |

**Features/Changes**
   - Handler profiles now have parameters to control when Tika errors should
     be ignored.  The default value for these parameters is `false`, preserving
     the original behavior of not ignoring Tika errors.
   - Tika queries are no longer retried on timeouts.  The slowest operations
     are text extractions (particularly OCR) on large documents, and there is
     no particular reason to expect Tika to get any faster the second time
     around, so timeout retries just make an inevitable failure take longer.
   - The 422 response code ("unprocessable") from Tika is now considered to
     be a non-retrying failure (rather than just being treated as an empty
     response).
   - Tika responses are now cached, to reduce (sometime dramatically) the
     number of repeat queries for the same file.
     - A process-local layer provides an in-memory LRU cache of responses for
       the N-most recently queried files during a single web request.
       (N == `LocalCacheSize` parameter)
     - An optional file-based layer can be configured to persistently cache
       Tika responses in the local filesystem, across multiple web requests.

**Fixes**
   - Fix a bug in metadata property processing (misspelled variable
     in `MetadataMapper::anyToMWFormatter()`).
   - Fix a bug in `Core::formatMetadataForTextContent()` --- return `null`
     if there is no metadata.
   - More robust checking of `curl_init()` outcome.
   - Remove workaround for "double-wrap-the-wrapper" bug of MW 1.35 (which
     was fixed in MW 1.36).
   - Stop using `MWException` (deprecated in MW >=1.40).
   - phan is now used to improve code quality.

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
