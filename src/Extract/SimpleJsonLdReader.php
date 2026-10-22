<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

use Rasuvaeff\Result\Result;

/**
 * A reader for the JSON-LD that sites embed for search engines: plain JSON with
 * the schema.org context, optionally with a `@graph`.
 *
 * It is not a JSON-LD processor. The only contexts it accepts are the schema.org
 * ones (`https://schema.org`, `http://schema.org/`, or a list of them); any
 * other string, an inline context object or a missing context is refused with
 * {@see JsonLdFailure::UnsupportedContext}, and nothing is ever downloaded.
 *
 * @api
 */
final readonly class SimpleJsonLdReader implements JsonLdReader
{
    #[\Override]
    public function nodes(string $json): Result
    {
        $data = Json::decode($json);

        if ($data === null) {
            return Result::err(error: JsonLdFailure::InvalidJson);
        }

        $roots = Json::items($data);
        $nodes = [];

        foreach ($roots as $root) {
            $object = Json::object($root);

            if ($object === null) {
                continue;
            }

            if (!$this->supportsContext($object['@context'] ?? null)) {
                return Result::err(error: JsonLdFailure::UnsupportedContext);
            }

            if (isset($object['@type'])) {
                $nodes[] = $object;
            }

            foreach (Json::items($object['@graph'] ?? null) as $member) {
                $node = Json::object($member);

                if ($node !== null) {
                    $nodes[] = $node;
                }
            }
        }

        return Result::ok(value: $nodes);
    }

    private function supportsContext(mixed $context): bool
    {
        $entries = Json::items($context);

        if ($entries === []) {
            return false;
        }

        foreach ($entries as $entry) {
            if (!\is_string($entry) || preg_match('~\Ahttps?://(?:www\.)?schema\.org(?:/(?:docs/jsonldcontext\.json)?)?\z~i', $entry) !== 1) {
                return false;
            }
        }

        return true;
    }
}
