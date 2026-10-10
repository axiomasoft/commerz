<?php

declare(strict_types=1);

// ORD-01/ORD-02: an order is written only as the snapshot of a placement. Every insert into `orders` or `order_lines`
// goes through Common/Internal/OrderWriter, so no second path creates an order or a line.

/** Whether a statement of `$code` inserts into `orders` or `order_lines`, naming the table directly or through a variable. */
function owInsertsOrders(string $code): bool
{
    preg_match_all("/\\$(\\w+)\\s*=\\s*\\\$this->tables->table\\(\\s*'(?:orders|order_lines)'\\s*\\)/", $code, $variables);
    $names = ["'orders'", "'order_lines'", ...array_map(static fn (string $name): string => '$'.$name, $variables[1])];

    foreach (explode(';', $code) as $statement) {
        if (preg_match('/->insert\w*\(|insert\s+into/i', $statement) !== 1) {
            continue;
        }

        foreach ($names as $name) {
            if (preg_match('/table\(\s*'.preg_quote($name, '/').'\s*\)|insert\s+into\s+\S*order(s|_lines)\b/i', $statement) === 1) {
                return true;
            }
        }
    }

    return false;
}

it('leaves the inserts into orders and order lines to OrderWriter alone', function (): void {
    $src = dirname(__DIR__, 3).'/src';
    $writers = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && owInsertsOrders((string) file_get_contents($file->getPathname()))) {
            $writers[] = substr($file->getPathname(), strlen($src) + 1);
        }
    }

    expect($writers)->toBe(['Common/Internal/OrderWriter.php']);
});

it('recognises an insert however the table is named', function (string $code, bool $inserts): void {
    expect(owInsertsOrders($code))->toBe($inserts);
})->with([
    'literal' => ["\$db->table(\$this->tables->table('orders'))->insert(\$row);", true],
    'variable' => ["\$lines = \$this->tables->table('order_lines');\n\$db->table(\$lines)->insert(\$rows);", true],
    'raw sql' => ['$db->insert("insert into cmz_orders (id) values (?)", [$id]);', true],
    'read only' => ["\$db->table(\$this->tables->table('orders'))->where('id', \$id)->first();", false],
    'another table' => ["\$db->table(\$this->tables->table('order_events'))->insert(\$row);", false],
    'another insert' => ["\$this->tables->table('orders');\n\$this->carts->insert(\$context);", false],
]);
