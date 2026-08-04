<?php

declare(strict_types=1);

namespace viesrood\seokit\models;

/**
 * The resolved SEO state of one page, plus where every value came from.
 *
 * This is the only thing the renderer, the control panel screen and the audit
 * ever read. One truth, three views. The origin trail is not a debugging
 * extra: "why is this og:image wrong" is the question a configurable SEO
 * plugin makes expensive to answer, so the answer is carried along with the
 * value from the start.
 */
final class MetaResolution
{
    /** Derived by SeoKit from the element itself. */
    public const SOURCE_DERIVED = 'derived';

    /** From the plugin settings (site name, defaults, organization). */
    public const SOURCE_SETTINGS = 'settings';

    /** From the entry type profile. */
    public const SOURCE_PROFILE = 'profile';

    /** Typed into the seokit field on this element. */
    public const SOURCE_FIELD = 'field';

    /** Set from a template with craft.seokit.set(). */
    public const SOURCE_TEMPLATE = 'template';

    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, array{source: string, detail: string}> */
    private array $origins = [];

    /**
     * Record a value together with its origin.
     *
     * Later calls win, which is what makes the resolution order (derive,
     * profile, field, template) a plain sequence of set() calls instead of a
     * tangle of conditionals.
     */
    public function set(string $key, mixed $value, string $source, string $detail = ''): self
    {
        if ($value === null || $value === '' || $value === []) {
            return $this;
        }

        $this->values[$key] = $value;
        $this->origins[$key] = ['source' => $source, 'detail' => $detail];

        return $this;
    }

    /**
     * Record a value that is allowed to be false or empty.
     *
     * set() ignores empty values on purpose, so a fallback never has to check
     * whether the previous layer produced anything. Booleans need the
     * opposite: "do not index this page" is exactly the false-ish value that
     * has to survive.
     */
    public function setStrict(string $key, mixed $value, string $source, string $detail = ''): self
    {
        $this->values[$key] = $value;
        $this->origins[$key] = ['source' => $source, 'detail' => $detail];

        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? $default;

        return is_scalar($value) ? (string)$value : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        return (bool)($this->values[$key] ?? $default);
    }

    /**
     * @return array<int, mixed>
     */
    public function getArray(string $key): array
    {
        $value = $this->values[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }

    public function remove(string $key): self
    {
        unset($this->values[$key], $this->origins[$key]);

        return $this;
    }

    /**
     * @return array{source: string, detail: string}|null
     */
    public function origin(string $key): ?array
    {
        return $this->origins[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Flat, human readable trail: one line per resolved value.
     *
     * Feeds the control panel screen and the devMode HTML comment.
     *
     * @return array<int, array{key: string, value: string, source: string, detail: string}>
     */
    public function trail(): array
    {
        $trail = [];

        foreach ($this->values as $key => $value) {
            $origin = $this->origins[$key] ?? ['source' => 'unknown', 'detail' => ''];

            $trail[] = [
                'key' => $key,
                'value' => self::stringify($value),
                'source' => $origin['source'],
                'detail' => $origin['detail'],
            ];
        }

        return $trail;
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return sprintf('[%d]', count($value));
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        return get_debug_type($value);
    }
}
