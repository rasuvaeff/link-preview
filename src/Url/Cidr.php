<?php

declare(strict_types=1);

namespace Rasuvaeff\LinkPreview\Url;

/**
 * @internal
 */
final readonly class Cidr
{
    /**
     * @param non-empty-string $network packed address, 4 or 16 bytes
     * @param int<0, max> $prefix
     */
    private function __construct(
        private string $network,
        private int $prefix,
    ) {}

    /**
     * Parses `a.b.c.d`, `a.b.c.d/n`, `x:y::z` or `x:y::z/n`; a bare address is a host route.
     */
    public static function parse(string $rule): ?self
    {
        $slash = strpos($rule, '/');
        $address = $slash === false ? $rule : substr($rule, 0, $slash);
        $packed = inet_pton($address);

        if ($packed === false || $packed === '') {
            return null;
        }

        $bits = \strlen($packed) * 8;

        if ($slash === false) {
            return new self(network: $packed, prefix: $bits);
        }

        $length = substr($rule, $slash + 1);

        if (preg_match('/\A\d{1,3}\z/', $length) !== 1 || (int) $length > $bits) {
            return null;
        }

        return new self(network: $packed, prefix: max(0, (int) $length));
    }

    /**
     * @param string $packed an address in its `inet_pton()` form
     */
    public function contains(string $packed): bool
    {
        if (\strlen($packed) !== \strlen($this->network)) {
            return false;
        }

        $fullBytes = intdiv($this->prefix, 8);

        if (substr($packed, 0, $fullBytes) !== substr($this->network, 0, $fullBytes)) {
            return false;
        }

        $remainder = $this->prefix % 8;

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (\ord($packed[$fullBytes]) & $mask) === (\ord($this->network[$fullBytes]) & $mask);
    }
}
