<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

/**
 * @api
 */
enum ExtractFailure: string
{
    /** the page carries nothing this extractor reads, or only values it refuses */
    case NoMetadata = 'no_metadata';

    /** the source is present but its content cannot be read (broken JSON, not an oEmbed object) */
    case InvalidData = 'invalid_data';

    /** a JSON-LD block uses a remote or custom `@context`; it is never fetched, the block is skipped */
    case UnsupportedContext = 'unsupported_context';

    /** a document the source points to could not be fetched */
    case Unavailable = 'unavailable';
}
