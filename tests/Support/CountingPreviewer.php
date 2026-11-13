<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Tests\Support;

use Rasuvaeff\LinkPreview\LinkPreviewer;
use Rasuvaeff\LinkPreview\PageInfo;
use Rasuvaeff\LinkPreview\PreviewFailure;
use Rasuvaeff\LinkPreview\PreviewFailureReason;
use Rasuvaeff\Result\Result;

/**
 * A LinkPreviewer double that counts calls and can be flipped to fail.
 */
final class CountingPreviewer implements LinkPreviewer
{
    public int $previewCalls = 0;

    public int $fromHtmlCalls = 0;

    public bool $fail = false;

    public function __construct(private readonly PageInfo $info) {}

    #[\Override]
    public function preview(string $url): Result
    {
        ++$this->previewCalls;

        return $this->fail
            ? Result::err(error: new PreviewFailure(PreviewFailureReason::Network))
            : Result::ok(value: $this->info);
    }

    #[\Override]
    public function fromHtml(string $html, string $pageUrl, ?string $charset = null): Result
    {
        ++$this->fromHtmlCalls;

        return Result::ok(value: $this->info);
    }
}
