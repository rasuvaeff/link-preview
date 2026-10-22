<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Extract;

use Rasuvaeff\Result\Result;
use Rasuvaeff\Schema\Schema;
use Rasuvaeff\Schema\SchemaInterface;

/**
 * The shapes of the JSON answers the package reads, as `rasuvaeff/schema` schemas.
 *
 * Every schema is open (`additionalProperties: true`): services add fields all the time and
 * only the listed ones are read. Listed fields may be absent or `null`; a listed field of the
 * wrong type makes the whole answer unreadable, and {@see self::parse()} says where
 * (`$.thumbnail_url: ...`) without echoing a value. The same schema generates valid answers for
 * property tests (`->arbitrary()`).
 *
 * oEmbed is not modelled as a `discriminated` union on `type`: the specification asks `photo` for
 * `url` and `video`/`rich` for `html`, but real endpoints deviate, and an answer that lacks a
 * field the spec wants is still worth reading.
 *
 * @api
 */
final readonly class Schemas
{
    private function __construct() {}

    /**
     * An oEmbed answer (oembed.com), the fields this package reads.
     */
    public static function oEmbed(): SchemaInterface
    {
        return Schema::object([
            'type' => self::text(),
            'title' => self::text(),
            'author_name' => self::text(),
            'provider_name' => self::text(),
            'thumbnail_url' => self::text(),
            'url' => self::text(),
            'html' => self::text(),
        ], additionalProperties: true);
    }

    /**
     * The Wikipedia REST `page/summary` answer.
     */
    public static function wikipediaSummary(): SchemaInterface
    {
        return Schema::object([
            'title' => Schema::string()->min(1),
            'lang' => self::text(),
            'description' => self::text(),
            'extract' => self::text(),
            'thumbnail' => Schema::object(['source' => self::text()], additionalProperties: true)->nullable()->optional(),
            'content_urls' => Schema::object([
                'desktop' => Schema::object(['page' => self::text()], additionalProperties: true)->nullable()->optional(),
            ], additionalProperties: true)->nullable()->optional(),
        ], additionalProperties: true);
    }

    /**
     * The Rutube `api/video/<id>` answer.
     */
    public static function rutubeVideo(): SchemaInterface
    {
        return Schema::object([
            'title' => Schema::string()->min(1),
            'description' => self::text(),
            'thumbnail_url' => self::text(),
            'created' => self::text(),
            'publication_ts' => self::text(),
            'embed_url' => self::text(),
            'author' => Schema::object(['name' => self::text()], additionalProperties: true)->nullable()->optional(),
        ], additionalProperties: true);
    }

    /**
     * @return Result<array<string, mixed>, string> the parsed answer, or where it is wrong: `$.path: kind`
     *         pairs, never a value from the input
     */
    public static function parse(SchemaInterface $schema, string $json): Result
    {
        $decoded = Json::object(Json::decode($json));

        if ($decoded === null) {
            return self::unreadable('$: not a JSON object');
        }

        $parsed = $schema->parse($decoded);

        if ($parsed->ok && \is_array($parsed->value)) {
            /** @var array<string, mixed> $value */
            $value = $parsed->value;

            /** @var Result<array<string, mixed>, string> $result */
            $result = Result::ok(value: $value);

            return $result;
        }

        $problems = array_map(
            static fn($error): string => $error->toPath() . ': ' . $error->message,
            \array_slice($parsed->errors, 0, 5),
        );

        return self::unreadable($problems === [] ? '$: unreadable' : implode('; ', $problems));
    }

    /**
     * @return Result<array<string, mixed>, string>
     */
    private static function unreadable(string $diagnostic): Result
    {
        /** @var Result<array<string, mixed>, string> $result */
        $result = Result::err(error: $diagnostic);

        return $result;
    }

    private static function text(): SchemaInterface
    {
        return Schema::string()->nullable()->optional();
    }
}
