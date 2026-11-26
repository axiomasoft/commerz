<?php

declare(strict_types=1);

namespace Commerz\Catalog\Routes\Data;

use Packman\Attributes\Api;

/** Structured SEO metadata stored in `cmz_url_routes.seo_meta`. */
#[Api(since: '0.1')]
final readonly class SeoMeta
{
    /** @param  array<string, mixed>  $extra */
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?string $robots = null,
        public ?string $ogTitle = null,
        public ?string $ogDescription = null,
        public ?string $ogImage = null,
        public array $extra = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = $this->extra;

        foreach ([
            'title' => $this->title,
            'description' => $this->description,
            'robots' => $this->robots,
            'og_title' => $this->ogTitle,
            'og_description' => $this->ogDescription,
            'og_image' => $this->ogImage,
        ] as $key => $value) {
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /** @param  array<array-key, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $known = ['title', 'description', 'robots', 'og_title', 'og_description', 'og_image'];
        $extra = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && ! in_array($key, $known, true)) {
                $extra[$key] = $value;
            }
        }

        return new self(
            title: isset($data['title']) && is_string($data['title']) ? $data['title'] : null,
            description: isset($data['description']) && is_string($data['description']) ? $data['description'] : null,
            robots: isset($data['robots']) && is_string($data['robots']) ? $data['robots'] : null,
            ogTitle: isset($data['og_title']) && is_string($data['og_title']) ? $data['og_title'] : null,
            ogDescription: isset($data['og_description']) && is_string($data['og_description']) ? $data['og_description'] : null,
            ogImage: isset($data['og_image']) && is_string($data['og_image']) ? $data['og_image'] : null,
            extra: $extra,
        );
    }
}
