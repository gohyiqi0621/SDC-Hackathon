<?php
/**
 * ToolController.php - endpoints that Flowise custom tools call.
 *
 *   POST /tools/check_product
 *     Authorization: Bearer <tool_token from overrideConfig.vars>
 *     JSON or form body: { "query": "nasi lemak", "category": "Food", "limit": 10 }
 *
 * The token says which business is asking (Src\Support\ToolToken), so a tool
 * can only ever read that business's own products. No login and no CSRF token
 * (Flowise cannot send either); the signed token is the authentication.
 *
 * buy_product and the booking tools are not built yet (no orders table, no
 * "create booking" in GoogleCalendarService); they are documented in
 * docs/FLOWISE.md as the next step.
 */
declare(strict_types=1);

namespace Src\Controllers;

use Src\Models\Product;
use Src\Support\Database;
use Src\Support\ToolToken;

final class ToolController
{
    /** POST /tools/check_product */
    public function checkProduct(): void
    {
        $userId = $this->authenticate();
        $input  = $this->input();

        $query    = mb_substr(trim((string) ($input['query'] ?? $input['product'] ?? $input['name'] ?? '')), 0, 100);
        $category = mb_substr(trim((string) ($input['category'] ?? '')), 0, 100);
        $limit    = max(1, min(20, (int) ($input['limit'] ?? 10)));

        $sql    = "SELECT p.name, p.sku, c.name AS category, p.description, p.characteristics,
                          p.price, p.promotion_price, p.currency
                     FROM products p
                     JOIN product_categories c ON c.id = p.category_id AND c.user_id = p.user_id
                    WHERE p.user_id = ? AND p.status = 'active'";
        $params = [$userId];

        if ($query !== '') {
            $like = '%' . addcslashes(mb_strtolower($query), '%_\\') . '%';
            $sql .= ' AND (LOWER(p.name) LIKE ? OR LOWER(COALESCE(p.sku, \'\')) LIKE ? OR LOWER(COALESCE(p.description, \'\')) LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        if ($category !== '') {
            $sql .= ' AND LOWER(c.name) = LOWER(?)';
            $params[] = $category;
        }

        $stmt = Database::connection()->prepare($sql . ' ORDER BY LOWER(p.name) LIMIT ' . $limit);
        $stmt->execute($params);

        $products = array_map(static function (array $row): array {
            $onPromo = $row['promotion_price'] !== null;

            return [
                'name'            => $row['name'],
                'sku'             => $row['sku'],
                'category'        => $row['category'],
                'description'     => $row['description'],
                'characteristics' => $row['characteristics'],
                'price'           => format_money($row['price'], $row['currency']),
                'promotion_price' => $onPromo ? format_money($row['promotion_price'], $row['currency']) : null,
                'price_to_pay'    => format_money($onPromo ? $row['promotion_price'] : $row['price'], $row['currency']),
                // There is no stock column yet; every active product counts as available.
                'stock_status'    => 'available',
            ];
        }, $stmt->fetchAll());

        $this->json(200, [
            'success'  => true,
            'count'    => count($products),
            'products' => $products,
            'note'     => $products === [] ? 'No matching active products. Do not invent any; tell the customer and offer alternatives.' : null,
        ]);
    }

    /* ---------------------------------------------------------- private ---- */

    private function authenticate(): int
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $token  = preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : (string) ($_SERVER['HTTP_X_TOOL_TOKEN'] ?? '');
        $userId = ToolToken::verify($token);

        if ($userId === null) {
            $this->json(401, ['success' => false, 'error' => 'Invalid or expired tool token.']);
        }

        return $userId;
    }

    private function input(): array
    {
        $raw  = (string) file_get_contents('php://input');
        $json = json_decode($raw, true);

        return is_array($json) ? $json : $_POST;
    }

    private function json(int $status, array $data): never
    {
        set_status($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
