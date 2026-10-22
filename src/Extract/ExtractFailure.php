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
}
