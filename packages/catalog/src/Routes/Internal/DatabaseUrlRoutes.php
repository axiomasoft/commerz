<?php

declare(strict_types=1);

namespace Commerz\Catalog\Routes\Internal;

use Commerz\Catalog\Common\Internal\Documents;
use Commerz\Catalog\Routes\Contracts\UrlRoutes;
use Commerz\Catalog\Routes\Data\AssignCanonicalSlug;
use Commerz\Catalog\Routes\Data\CanonicalRoute;
use Commerz\Catalog\Routes\Data\RedirectRoute;
use Commerz\Catalog\Routes\Data\ResolveRoute;
use Commerz\Catalog\Routes\Data\SeoMeta;
use Commerz\Catalog\Routes\Data\UrlRouteId;
use Commerz\Catalog\Routes\Data\UrlRouteView;
use Commerz\Catalog\Routes\Exceptions\DuplicateSlug;
use Commerz\Catalog\Routes\Exceptions\RouteNotFound;
use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\Uuid7;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Stores\Data\ChannelId;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseUrlRoutes implements UrlRoutes
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private Clock $clock,
    ) {}

    public function assignCanonical(AssignCanonicalSlug $command): UrlRouteView
    {
        return $this->transactions->run(function () use ($command): UrlRouteView {
            $db = $this->db();
            $table = $this->tables->table('url_routes');
            $now = PgTimestamp::now($this->clock);

            // Lock all existing routes for this entity in (channel, locale) so INV-CAT-002 holds under concurrency.
            $db->table($table)
                ->where('channel_id', $command->channel->value)
                ->where('locale', $command->locale)
                ->where('entity_type', $command->entityType)
                ->where('entity_id', $command->entityId)
                ->orderBy('id')
                ->lock('for update')
                ->get();

            $existingBySlug = $db->table($table)
                ->where('channel_id', $command->channel->value)
                ->where('locale', $command->locale)
                ->where('slug', $command->slug)
                ->lock('for update')
                ->first();

            if ($existingBySlug !== null) {
                $sameEntity = Rows::string($existingBySlug, 'entity_type') === $command->entityType
                    && Rows::string($existingBySlug, 'entity_id') === $command->entityId;

                if (! $sameEntity) {
                    throw new DuplicateSlug($command->channel->value, $command->locale, $command->slug);
                }

                $id = Rows::string($existingBySlug, 'id');

                if (! Rows::bool($existingBySlug, 'is_canonical')) {
                    $db->table($table)
                        ->where('channel_id', $command->channel->value)
                        ->where('locale', $command->locale)
                        ->where('entity_type', $command->entityType)
                        ->where('entity_id', $command->entityId)
                        ->where('is_canonical', true)
                        ->update([
                            'is_canonical' => false,
                            'updated_at' => $now,
                        ]);
                }

                $updates = [
                    'is_canonical' => true,
                    'updated_at' => $now,
                ];

                if ($command->seoMeta instanceof SeoMeta) {
                    $updates['seo_meta'] = Documents::encode($command->seoMeta->toArray());
                }

                $db->table($table)->where('id', $id)->update($updates);

                return $this->findById($db, $id);
            }

            // Retire the previous canonical slug (if any) so it becomes a 301 RedirectRoute.
            $db->table($table)
                ->where('channel_id', $command->channel->value)
                ->where('locale', $command->locale)
                ->where('entity_type', $command->entityType)
                ->where('entity_id', $command->entityId)
                ->where('is_canonical', true)
                ->update([
                    'is_canonical' => false,
                    'updated_at' => $now,
                ]);

            $id = Uuid7::generate();
            $seoMeta = $command->seoMeta instanceof SeoMeta ? $command->seoMeta->toArray() : [];

            try {
                $db->transaction(fn () => $db->table($table)->insert([
                    'id' => $id,
                    'channel_id' => $command->channel->value,
                    'locale' => $command->locale,
                    'slug' => $command->slug,
                    'entity_type' => $command->entityType,
                    'entity_id' => $command->entityId,
                    'is_canonical' => true,
                    'seo_meta' => Documents::encode($seoMeta),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateSlug($command->channel->value, $command->locale, $command->slug);
            }

            return $this->findById($db, $id);
        });
    }

    public function resolve(ResolveRoute $query): CanonicalRoute|RedirectRoute
    {
        $db = $this->db();
        $row = $db->table($this->tables->table('url_routes'))
            ->where('channel_id', $query->channel->value)
            ->where('locale', $query->locale)
            ->where('slug', $query->slug)
            ->first() ?? throw new RouteNotFound("{$query->channel->value}:{$query->locale}:{$query->slug}");

        $matched = self::view($row);

        if ($matched->isCanonical) {
            return new CanonicalRoute($matched);
        }

        $canonical = $this->findCanonical($matched->channel, $matched->locale, $matched->entityType, $matched->entityId);

        return new RedirectRoute(
            matchedRoute: $matched,
            canonicalRoute: $canonical,
            targetSlug: $canonical->slug,
            statusCode: 301,
        );
    }

    public function findCanonical(ChannelId $channel, string $locale, string $entityType, string $entityId): UrlRouteView
    {
        $row = $this->db()->table($this->tables->table('url_routes'))
            ->where('channel_id', $channel->value)
            ->where('locale', $locale)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('is_canonical', true)
            ->first() ?? throw new RouteNotFound("{$channel->value}:{$locale}:{$entityType}:{$entityId}");

        return self::view($row);
    }

    public function forEntity(string $entityType, string $entityId, ?ChannelId $channel = null, ?string $locale = null): array
    {
        $builder = $this->db()->table($this->tables->table('url_routes'))
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderByDesc('is_canonical')
            ->orderBy('locale')
            ->orderBy('slug');

        if ($channel instanceof ChannelId) {
            $builder->where('channel_id', $channel->value);
        }

        if ($locale !== null) {
            $builder->where('locale', $locale);
        }

        return array_values(array_map(self::view(...), $builder->get()->all()));
    }

    private function findById(ConnectionInterface $db, string $id): UrlRouteView
    {
        $row = $db->table($this->tables->table('url_routes'))
            ->where('id', $id)
            ->first() ?? throw new RouteNotFound($id);

        return self::view($row);
    }

    private static function view(object $row): UrlRouteView
    {
        $seoMetaRaw = Documents::decode(Rows::string($row, 'seo_meta'));

        return new UrlRouteView(
            id: new UrlRouteId(Rows::string($row, 'id')),
            channel: new ChannelId(Rows::string($row, 'channel_id')),
            locale: Rows::string($row, 'locale'),
            slug: Rows::string($row, 'slug'),
            entityType: Rows::string($row, 'entity_type'),
            entityId: Rows::string($row, 'entity_id'),
            isCanonical: Rows::bool($row, 'is_canonical'),
            seoMeta: SeoMeta::fromArray($seoMetaRaw),
            createdAt: Rows::date($row, 'created_at'),
            updatedAt: Rows::date($row, 'updated_at'),
        );
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->tables->connection() ?? $this->config->connection);
    }
}
