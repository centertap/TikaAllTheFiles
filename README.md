# Tika All The Files!

**TikaAllTheFiles** (TATF) is an extension for
[MediaWiki](https://www.mediawiki.org/wiki/MediaWiki)
which facilitates full-text search over uploaded files, by using the
[Apache Tika](https://tika.apache.org/) content analysis toolkit, which
"detects and extracts metadata and text from over a thousand different file
types".
In practical terms:  if you already have 
[CirrusSearch](https://www.mediawiki.org/wiki/Extension:CirrusSearch)
set up and working on
your wiki, TATF will allow you to perform full-text searches over the contents
of almost any uploaded file --- not just the PDF's.

TATF's features and capabilities:
 * extract embedded digital text from any type of uploaded file and index
   for full-text search;
 * extract and index *printed* text from bitmap image
   files and from images embedded in document files, e.g., image-only PDF's
   (requires [Tesseract OCR](https://github.com/tesseract-ocr/tesseract))
 * extract metadata from any type of uploaded file for display on `File:` pages;
 * index metadata properties along with text, to enable simple searching for
   properties within full-text search;

---

**Brought to you by...** [![CTAP](images/CTAP-powered-by-132x47.png)](https://www.centertap.org/)

This extension is developed by the
[Center for Transparent Analysis and Policy](https://www.centertap.org/),
a 501(c)(3) charitable non-profit organization.  If this extension is useful
for your wiki, consider making a
[donation to support CTAP](https://www.centertap.org/how).
CTAP All The Donations!

---

 * [Prerequisites](#prerequisites)
 * [Theory of Operation](#operation)
 * [Installation](#installation)
 * [Configuration](#configuration)
 * [Post-configuration / Maintenance](#maintenance)
 * [Hints and Tips](#hints)
 * [Release Notes](#release-notes)
 * [Known Bugs](#known-bugs)
 * [License](#license)

---

<a name="prerequisites"/>

## Prerequisites

To make use of **TikaAllTheFiles** (TATF), you will need:

 * PHP >= 7.4.0

 * Mediawiki >= 1.35
   * *TATF is now developed/tested with MW 1.39.
     See [Known Bugs](#known-bugs) for possible issues with newer versions.*

 * [CirrusSearch](https://www.mediawiki.org/wiki/Extension:CirrusSearch)
   extension
   * *TATF's text extraction functionality will only be exercised by a search
     engine that performs full-text indexing and search, i.e., CirrusSearch.*

 * [Apache Tika Server](https://tika.apache.org/) >= 2.1.0
   * *See [Tika Tips](#tika-tips) below for tips.*

 * (optional) [Tesseract OCR](https://github.com/tesseract-ocr/tesseract)
   * *See [Tesseract OCR Tips](#tesseract-tips) below for tips.*

Setting up the prerequisites is beyond the scope of these instructions, but
some pointers for Tika and Tesseract are provided in
[Hints and Tips](#hints).

<a name="operation"/>

## Theory of Operation

**TikaAllTheFiles** (TATF) has defaults that should get it to do something
useful out-of-the-box, but it is helpful to understand how it works a bit
before installing it.

In MediaWiki, any operation on an uploaded file that requires interpreting its
content is provided by a _MediaHandler_.  Thumbnails, image display, metadata
extraction, text extraction, etc all depend on a MediaHandler.  Without a
MediaHandler for a file, MediaWiki only knows about its name, size, and MIME
type.

MediaHandlers are registered to MIME types.
MediaWiki provides a handful of MediaHandlers in its core code, e.g.,
`JpegHandler` for JPEG images (MIME type `image/jpeg`).  The rest are provided
by extensions.  The `PdfHandler` extension, which ships with MediaWiki and is
installed by default, provides a MediaHandler for PDF files (MIME type
`application/pdf`).

TATF works by providing a MediaHandler that knows how to extract text and
metadata by farming files out to a Tika server.  Unlike a typical media
extension, however, TATF
does not register its MediaHandler for specific MIME types,
Instead, it installs a special MediaHandlerFactory that knows how to
provide its MediaHandler for _any_ MIME type that shows up.
(It's called "Tika **All** The Files" for a reason.)

When MediaWiki needs a MediaHandler for a file, it asks TATF's factory and
the factory returns one of three results:

 * the original MediaHandler for that MIME type (as registered by core or
   another extension), if any;
 * a solo TATF MediaHandler;
 * a TATF MediaHandler that _wraps_ the original MediaHandler.

Which outcome occurs depends on the configuration of TATF's
[`MimeTypeProfiles`](#handler-profiles) parameter.

A TATF MediaHandler offers two types of functionality:

 * content:  providing text to be indexed by a search engine;
 * metadata:  recording and formatting metadata for display to the user.

The content and metadata functions are independent of each other; if both
are enabled and if both are invoked by MediaWiki for a given file, then
TATF will actually query Tika twice for that file.  One query would occur
when the file is initially uploaded (to record its metadata in the
database); the other would occur when the search engine indexes the file
(to obtain the text content to be indexed).

A *solo* TATF MediaHandler will simply provide its Tika-based content and/or
metadata services, and that's that.  It is not able to provide thumbnails
or previews or any other MediaHandler functionality.

A *wrapping* TATF MediaHandler is able to delegate to the wrapped MediaHandler
for any function beyond content or metadata.  Thus, TATF can be used to *add*
text extraction (and enhanced metadata extraction) for MIME types and
MediaHandlers that don't already support it.  This is, for example, what
enables TATF to be used to extract searchable text from bitmap image files.

Which of content and/or metadata functionality is provided by TATF, and
how the Tika results are blended with the native output of a wrapped
MediaHandler, is all configurable via the
[`MimeTypeProfiles`](#handler-profiles) parameter.

<a name="installation"/>

## Installation

The recommended installation method for **TikaAllTheFiles** (TATF) is to use
[`composer`](https://getcomposer.org/).  This will automatically install any
(future) PHP dependencies.

 * Go to your MediaWiki installation directory and run two `composer` commands:
   ```
   $ cd YOUR-MEDIA-WIKI-DIRECTORY
   $ COMPOSER=composer.local.json composer require --no-update centertap/tika-all-the-files
   $ composer update centertap/tika-all-the-files --with-dependencies --no-dev --optimize-autoloader
   ```
   The `require` command will add an entry for TATF to your
   `composer.local.json` file (creating the file if necessary).  The `update`
   command will update your `composer.lock` file and download/install TATF in
   the `extensions` directory.

   If you want to pin the major version of this extension (so that future
   updates do not inadvertently introduce breaking changes), change the first
   command to something like this (e.g., for major revision "194"):
   ```
   $ COMPOSER=composer.local.json composer require --no-update centertap/tika-all-the-files:^194.0.0
   ```

 * Edit your site's `LocalSettings.php` to load the extension:
   ```php
   ...
   wfLoadExtension( 'TikaAllTheFiles' );
   ...
   ```

 * Configure TATF as needed.  (See [Configuration](#configuration) below.)

 * Run some post-configuration commands to (re)index files that have already
   been uploaded to your wiki.
   (See [Post-configuration / Maintenance](#maintenance) below.)

<a name="configuation"/>

## Configuration

**TikaAllTheFiles** (TATF) has the following configuration parameters;
each of them has a prefix
of `$wgTikaAllTheFiles_` which has been omitted here for brevity:

| parameter                | default                  | description                                  |
|--------------------------|--------------------------|----------------------------------------------|
| `TikaServiceBaseUrl`     | `http://localhost:9998/` | Base URL of the Tika server                  |
| `QueryTimeoutSeconds`    | 5                        | Tika server response time limit (seconds)    |
| `QueryRetryCount`        | 2                        | Number of times to retry a failed Tika query |
| `QueryRetryDelaySeconds` | 2                        | Delay (seconds) before query retry           |
| `MimeTypeProfiles`       | *see below*              | Handler configuration, by mime-type          |
| `PropertyMap`            | `[]`                     | Additional mappings for Tika metadata        |

All the parameters have nominally reasonable defaults that should cause TATF
to do something useful --- most important is that `TikaServiceBaseUrl` points to
your Tika server.  More details on the parameters follow below.

### Tika service parameters

 * `$wgTikaAllTheFiles_TikaServiceBaseUrl`
   * string, default value: `http://localhost:9998/`
   * Specifies the URL of the Tika server.

 * `$wgTikaAllTheFiles_QueryTimeoutSeconds`
   * integer, default value: `5`
   * Specifies the timeout, in seconds, for a single Tika query.
     TATF will abort a request (and possibly try again) if the Tika server
     does not respond within this many seconds.

 * `$wgTikaAllTheFiles_QueryRetryCount`
   * integer, default value: `2`
   * Specifies the number of times TATF will retry a Tika query in the event
     of timeouts/errors/etc.
   * E.g., if zero, TATF will not retry after an initial failure.

 * `$wgTikaAllTheFiles_QueryRetryDelaySeconds`
   * integer, default value: `2`
   * Specifies the number of seconds TATF will wait before retrying a
     Tika query.

<a name="handler-profiles"/>

### Handler profiles

 * `$wgTikaAllTheFiles_MimeTypeProfiles`
   * array, default value:
     ```php
     [
        'defaults' => [
                        'handler_strategy' => 'fallback',
                        'allow_ocr' => false,
                        'ocr_languages' => '',
                        'content_strategy' => 'combine',
                        'content_composition' => 'text',
                        'metadata_strategy' => 'prefer_other',
                      ],
        '*' => 'defaults',
     ]
     ```

#### What the built-in default does

The effect of the built-in default profile configuration (shown above) is:

```
* Every MIME type is handled in 'fallback' mode.
* TATF will provide a "solo" handler for files that do not already have
  a handler (provided by the MW core or another extension).
* The TATF handler will provide Tika-extracted text for search indexing
  (but only text, not metadata).
* Text extraction will not use OCR.
* The TATF handler will provide Tika-extracted metadata to display on
  a file's File: page
```

#### Another profile configuration example

If you put the following in `LocalSettings.php`:
```php
$wgTikaAllTheFiles_MimeTypeProfiles['*'] = [
    'inherit' => 'defaults',
    'handler_strategy' => 'wrapping',
    'allow_ocr' => true,
    'content_composition' => 'text_and_metadata',
    'metadata_strategy' => 'combine',
    ];

$wgTikaAllTheFiles_MimeTypeProfiles['application/pdf'] = [
    'inherit' => '*',
    'allow_ocr' => false,
    ];
```
it will build on top of the built-in defaults with the result:
```
* Every MIME type is handled in 'wrapping' mode.
* TATF will provide a "solo" handler for files that do not already have
  a handler, and a "wrapping" handler for those that do.
* The TATF handler will provide both Tika-extracted text and metadata for
  search indexing, and combine that content with any content produced by
  a wrapped handler.
* Text extraction will use OCR if it is available --- but not for PDF files!
* The TATF handler will combine Tika-extracted metadata along with metadata
  from a wrapped handler, for display on a file's File: page.
```

#### Details of profile configuration

How **TikaAllTheFiles** (TATF) handles any particular file is determined
by the file's mime-type
(that is, mime-type as decided by the MW core).  TATF looks up the mime-type
in the `MimeTypeProfiles` array and assembles a *profile* which configures
a MediaHandler for the file.

The keys of the `MimeTypeProfiles` parameter array are called *labels*.
A label can be any arbitrary string, but `'*'` has a special meaning as
the *catch-all* label.

A *label* can map to:
 * a *profile block* (an array of profile parameters);
 * a reference to another label (a string);
 * the literal `false`, which causes profile assembly to abort.

A profile *block* contains profile parameters.  The special parameter
`'inherits'` can be used to reference another label/block.

Profile assembly for a mime-type works like this:
 1. Choose a root label.
    * If there is a label exactly matching the mime-type, use that.
    * Otherwise, if `'*'` is an existing label, use that.
    * Otherwise, abort.
 2. Starting with the root label as current and an empty profile, repeat
    until a complete profile is assembled:
    * Look up the value for the current label.
    * If unset or set to `false`, abort profile assembly.
    * Else, if set to a reference (string), that becomes the next current label.
    * Else, if set to a block (array), set any unknown profile parameters from
      the entries in the block.  If the block contains a string value
      for `'inherits'`, that becomes the next current label.

If a complete profile cannot be assembled for a mime-type, then TATF will leave
the file alone and it will get handled by the existing handler (if any) for
that mime-type.

A complete profile requires values for each of the following parameters:
 * `'handler_strategy'`: keyword - one of:
   * `'fallback'`: TATF will only handle this type if there is no other
     MediaHandler already enabled for the type.
   * `'override'`: TATF will take over handling of this type, by itself,
     ignoring any other MediaHandler.
   * `'wrapping'`: TATF handle this type, injecting its own behavior for
     text and metadata extraction, but allowing an existing MediaHandler to
     handle the rest of the MediaHandler API.
 * `'allow_ocr'`:  boolean - whether or not to allow Tika to perform OCR
 * `'ocr_languages'`:  string - which languages to enable for OCR;
   see [Tesseract OCR Tips](#tesseract-tips) below.
 * `'content_strategy'`: keyword for how to handle text extraction - one of:
   * `'no_tika'`: don't use Tika-extracted content at all
   * `'prefer_other'`: only use Tika-extracted content if no content is
     provided by another handler
   * `'combine'`: combine Tika-extracted content with any content provided by
     another handler
   * `'prefer_tika'`: only use content provided by another handler if there
     is no Tika-extracted content
   * `'only_tika'`: don't use another handler's content at all
 * `'content_composition'`: keyword describing what content should be
   indexed for full-text search; choose one of:
   * `'text'` - index extracted text
   * `'metadata'` - index metadata
   * `'text_and_metadata'` - index extracted text and metadata
 * `'metadata_strategy'`: keyword describing how TATF should handle metadata;
   choose one of:
   * `'no_tika'`: don't use Tika-extracted metadata at all
   * `'prefer_other'`: only use Tika-extracted metadata if no metadata is
     provided by another handler
   * `'combine'`: combine Tika-extracted metadata with any metadata provided by
     another handler
   * `'prefer_tika'`: only use metadata provided by another handler if there
     is no Tika-extracted metadata
   * `'only_tika'`: don't use another handler's metadata at all

### Metadata property processing

 * `$wgTikaAllTheFiles_PropertyMap`
   * array, default value: `[]`

**TikaAllTheFiles** (TATF) contains an internal property map which controls
how metadata properties are formatted, both when rendered on `File:` pages
and when added to search-indexable text content.  You can add new mappings,
or override existing mappings, by adding entries to
`$wgTikaAllTheFiles_PropertyMap`.

#### A `PropertyMap` example

Configuring `PropertyMap` like so:
```php
$wgTikaAllTheFiles_PropertyMap['dc:language'] = true;
$wgTikaAllTheFiles_PropertyMap['!'] = false;
```
will cause the `dc:language` property to be trivially formatted, and all other
properties will be discarded.

#### Details of `PropertyMap` configuration

The key of each key-value entry can take one of three forms:
 1. a Tika property name, e.g. `'some-name'`, to be matched exactly;
 2. the special string `'!'`, which matches to any property that does not have
    a specific (1) entry in `$wgTikaAllTheFiles_PropertyMap`;
 3. the special string `'*'`, which matches to any property that does not have
    a specific entry in either `$wgTikaAllTheFiles_PropertyMap` or in the
    internal property map.

A Tika property will be mapped to the first match in this order:
 1. entry in `$wgTikaAllTheFiles_PropertyMap` with exactly matching name;
 2. entry in `$wgTikaAllTheFiles_PropertyMap` with special name `'!'`;
 3. entry in internal property map with exactly matching name;
 4. entry in `$wgTikaAllTheFiles_PropertyMap` with special name `'*'`;
 5. fallback to value `true` if nothing matches.

The value of each entry can take one of three forms as well:
 1. `false` - drop/ignore the property;
 2. `true` - trivially format the property (render the name and
    value(s) as returned by Tika;
 3. `[ callable, arg1, arg2, ... ]` - process the property with `callable`.

In the third case, `callable` must be a PHP callable that accepts at least
three arguments:
 * Tika's name for the property (a string)
 * Tika's value for the property (either a single JSON-serializable atomic
   value, or an array of such values)
 * `false` or an `IContextSource` context for string rendering

Any additional `arg1`, `arg2`, etc, in the property map entry will be provided
as additional arguments to `callable`.  The return value of `callable` must
be either `null` (if the property should be discarded) or an instance of
`TikaAllTheFiles::ProcessedProperty`.  If you are still reading at this point,
you should look at the code to understand how/why to construct a
`ProcessedProperty`.

<a name="maintenance"/>

## Post-configuration / Maintenance

The search indexing and metadata recording operations for an uploaded file are
typically triggered once (each), when the file is uploaded.  That means that
when after you install and configure **TikaAllTheFiles** (TATF), you will want
to tell MediaWiki to repeat these operations for the files that have already
been uploaded to your wiki.

Likewise, when you upgrade TATF or change its configuration in a way that will
affect its content or metadata extraction, you may want to rescan any affected
files.

### Refresh Metadata

If you are using the metadata extraction features of TATF (e.g., profiles
with `metadata_strategy` other than `no_tika`), then you can force a refresh
of metadata for all uploaded files like so:
```
$ cd YOUR-WIKI-INSTALL-DIRECTORY/maintenance
$ php refreshMetadata.php --force
```
It is possible to refresh only a subset of files.  See
https://www.mediawiki.org/wiki/Manual:RefreshImageMetadata.php
for more information (or, use the `--help` option).

### Refresh Search Index

If you are using the content extraction features of TATF (e.g., profiles with
`content_strategy` other than `no_tika`), and if you are using CirrusSearch as
your search engine, then you can force a re-indexing of all uploaded files
like so:
```
$ cd YOUR-WIKI-INSTALL-DIRECTORY/extensions/CirrusSearch/maintenance/
$ php ForceSearchIndex.php
```
It is possible to re-index only a subset of files.  Use the `--help` option to
get a list of all the command-line options.

<a name="hints"/>

## Hints and Tips

<a name="tika-tips"/>

### Tika Tips

**TikaAllTheFiles** (TATF) doesn't do anything without access to a Tika server:

 * https://tika.apache.org/download.html
 * https://cwiki.apache.org/confluence/display/TIKA/TikaServer

If you want to quickly fire up a Tika server to try it out:

 * Install a Java runtime environment.  E.g., on Debian:
   ```
   $ apt install default-jre-headless
   ```
 * Download `tika-server-standard-2.1.0.jar`:
   ```
   $ wget https://dlcdn.apache.org/tika/2.1.0/tika-server-standard-2.1.0.jar
   ```
 * Start it up:
   ```
   $ java -jar tika-server-standard-2.1.0.jar
   ```

That should be enough to get a Tika server listening for queries at
`http://localhost:9998`.

<a name="tesseract-tips"/>

### Tesseract OCR Tips

See https://cwiki.apache.org/confluence/display/TIKA/TikaOCR for information
on installing and using Tesseract with Tika.

On Debian, it is as simple as `apt install tesseract`.  However, that by
itself will only the language pack for English.  You will need to install
more `tesseract-*` packages if you want support for other languages.

By default, Tika only enables English language support ("eng").  To enable
other languages, in addition to installing the appropriate Tesseract language
packs, you will need to override Tika's default configuration for the
`language` parameter of `TesseractOCRParser`.

You can do this in TATF by setting a [handler profile's](#handler-profiles)
`ocr_languages` parameter to a non-empty value.  The parameter should
be set to a list of Tesseract language codes, separated by `+` characters
(for example, `'ocr_languages' => 'eng+fra+jpn'`).

### OCR can be slow!

OCR is a really neat trick, but it can also be really slow, reportedly
increasing Tika query times by a factor of a hundred.  For that reason,
the TATF configuration defaults to disabling OCR (`'allow_ocr' => false`).

If you enable OCR:
 * make sure your Tika server(s) can handle the load;
 * seriously consider increasing `$wgTikaAllTheFiles_QueryTimeoutSeconds`,
   and/or keep an eye on timeout errors in your log files.

### PDF's and Tika and OCR

PDF's have an intricate relationship with Tika's OCR functionality; see
[the Tika wiki](https://cwiki.apache.org/confluence/display/tika/PDFParser%20(Apache%20PDFBox)) for the full scoop.

With Tika's default settings, it will do the following with PDF's:
 1. try to extract embedded digital text first;
 2. if no embedded digital text is found, and OCR is available 
    *and enabled by TATF*, render each page and attempt to extract text
    via OCR.

So, if you want Tika to fallback to OCR on image-only PDF's, you will need
to set `'allow_ocr' => true` for a PDF profile in your TATF configuration.

### `PdfHandler` Extension

MediaWiki comes with the `PdfHandler` extension, which (with the help of a few
external programs like `pdftotext`) can extract searchable text, extract
metadata, and display per-page previews and thumbnails of PDF documents.
In other words, `PdfHandler` does everything that TATF does and more, for
PDF files.  With the default configuration, TATF will let `PdfHandler`
take care of PDF files.

However, you may want to configure TATF to wrap `PdfHandler` instead, for
a number of possible reasons:
 * `PdfHandler` stores its extracted text in the wiki database along with
   the file metadata.  Thus, your wiki will end up storing three copies of
   the text for every PDF file:  in the database, in the search index (e.g.,
   Elasticstore), and in the original files.  To stop `PdfHandler` from
   doing this, unset `$wgPdftoText` in your local settings:
   ```
   unset( $wgPdftoText )
   ```
 * With OCR set up and enabled, TATF can extract text from images
   within PDF files and from image-only PDF files.  In a typical setup,
   `PdfHandler` can only extract embedded digital text from PDF's.
 * TATF will extract more metadata from PDF files.
 * TATF can combine metadata with text content for full-text search indexing.

For example:
```
$wgTikaAllTheFiles_MimeTypeProfiles['application/pdf'] = [
    'handler_strategy' => 'wrapping',
    'allow_ocr' => true,
    'content_strategy' => 'tika_only',
    'content_composition' => 'text_and_metadata',
    'metadata_strategy' => 'prefer_other',
    'inherits' => 'defaults',
    ];
```
will cause TATF to:
 * wrap `PdfHandler` (allowing `PdfHandler` to continue providing its page
   previews/thumbnails on the wiki);
 * prefer using only `PdfHandler`'s metadata;
 * use only Tika-extracted text content, with OCR enabled;
 * index the metadata along with the text content in searches.

<a name="release-notes"/>

## Release Notes

See [`RELEASE-NOTES.md`](RELEASE-NOTES.md).

<a name="known-bugs"/>

## Known Bugs

 * TATF is expected to work with MediaWiki 1.40 and 1.41, however it has not
   yet been tested with any version >1.39.  If there are any version-related
   issues, we would only expect them to affect MIME types configured to use
   the `wrapping` handler-strategy.

 * TATF's metadata property processing/formatting is still under development,
   and is currently pretty coarse.  The current efforts have focused on
   properties that would be found in document files (versus properties found
   in image files, which are already handled by MediaWiki).  We try to use
   existing MW core facilities for interpretation and localization, but Tika
   provides a lot of novel properties.  Setting up localization for Tika-only
   properties is on the ToDo list.

 * See `TODO` comments in the source code.

<a name="license"/>

## License

**TikaAllTheFiles** is licensed under GPL 3.0 (or any later version).
See [`LICENSE`](LICENSE) for details.

`SPDX-License-Identifier: GPL-3.0-or-later`

