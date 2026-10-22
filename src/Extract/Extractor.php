<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

use Rasuvaeff\LinkPreview\Html\PageDocument;
use Rasuvaeff\Result\Result;

/**
 * @api
 */
interface Extractor
{
    /**
     * @param non-empty-string $pageUrl the URL the document was fetched from; relative references resolve against it
     *
     * @return Result<Metadata, ExtractFailure>
     */
    public function extract(PageDocument $document, string $pageUrl): Result;
}
