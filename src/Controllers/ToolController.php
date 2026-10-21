<?php
/**
 * ToolController.php - endpoints that Flowise custom tools call.
 *
 *   POST /tools/check_product
 *     Authorization: Bearer <tool_token from overrideConfig.vars>
 *     JSON or form body: { "query": "nasi lemak", "category": "Food", "limit": 10 }
 *
 *   POST /tools/buy_product
 *     Authorization: Bearer <tool_token>
 *     JSON body: { "items": "Nasi Lemak x2, Teh Tarik x1",      (or a list of
 *                  {"product": "...", "quantity": 2})
 *                  "phone": "0123456789", "note": "Pickup 6pm" } (optional)
 *     Creates an order "awaiting payment" for the customer in this chat and
 *     returns the order number, total and how to pay. The customer then sends
 *     the payment receipt in Telegram (TelegramWebhookController attaches it)
 *     and staff approve it on the Orders page.
 *
 * The token says which business is asking (Src\Support\ToolToken), so a tool
 * can only ever read that business's own products; for buy_product it also
 * says which customer chat is ordering. No login and no CSRF token (Flowise
 * cannot send either); the signed token is the authentication.
 *
 * Business problems (unknown product, missing quantity) answer HTTP 200 with
 * success=false and a message, so the AI can explain and ask again.
 */
declare(strict_types=1);

namespace Src\Controllers;

use Src\Models\CompanyProfile;
use Src\Models\Order;
use Src\Services\OrderService;
use Src\Support\Database;
use Src\Support\FlowLog;
use Src\Support\ToolToken;
use Throwable;

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

    /** POST /tools/buy_product */
    public function buyProduct(): void
    {
        $auth  = $this->token();
        $input = $this->input();
        FlowLog::write('tool.buy_product', ['user_id' => $auth['user_id'], 'chat' => $auth['channel'] . ':' . $auth['chat_id'], 'input' => $input]);

        if ($auth['chat_id'] === null || !in_array($auth['channel'], ['telegram', 'web'], true)) {
            $this->json(400, ['success' => false, 'error' => 'This tool token is not linked to a customer chat.']);
        }

        $wanted = $this->parseItems($input['items'] ?? $input['products'] ?? $input['order'] ?? '');
        if ($wanted === []) {
            $this->fail('No items were given. Ask the customer which products and how many, then call buy_product again with items like "Nasi Lemak x2, Teh Tarik x1".');
        }
        if (count($wanted) > 20) {
            $this->fail('Too many different items in one order (20 at most).');
        }

        $userId   = $auth['user_id'];
        $items    = [];
        $problems = [];
        $currency = null;

        foreach ($wanted as [$name, $qty]) {
            if ($qty < 1 || $qty > 999) {
                $problems[] = '"' . $name . '": quantity must be 1 to 999.';
                continue;
            }
            [$product, $note] = $this->matchProduct($userId, $name);
            if ($product === null) {
                $problems[] = $note;
                continue;
            }
            if ($currency !== null && $product['currency'] !== $currency) {
                $problems[] = '"' . $product['name'] . '" is priced in ' . $product['currency'] . ', other items in ' . $currency . '. Order them separately.';
                continue;
            }
            $currency = $product['currency'];
            $items[]  = [
                'product_id'   => (int) $product['id'],
                'product_name' => (string) $product['name'],
                'sku'          => $product['sku'],
                'unit_price'   => (string) ($product['promotion_price'] ?? $product['price']),
                'quantity'     => $qty,
            ];
        }

        if ($problems !== []) {
            $this->fail('The order was NOT created. ' . implode(' ', $problems) . ' Ask the customer to confirm, then call buy_product again.');
        }

        $phone = mb_substr(trim((string) ($input['phone'] ?? $input['customer_phone'] ?? '')), 0, 30);
        $note  = mb_substr(trim((string) ($input['note'] ?? $input['delivery_note'] ?? '')), 0, 500);

        try {
            $order = (new Order())->create($userId, [
                'channel'           => $auth['channel'],
                'customer_chat_id'  => $auth['chat_id'],
                'customer_name'     => mb_substr(trim((string) ($input['customer_name'] ?? '')), 0, 150),
                'customer_username' => mb_substr(ltrim(trim((string) ($input['customer_username'] ?? '')), '@'), 0, 64),
                'customer_phone'    => $phone,
                'delivery_note'     => $note,
                'currency'          => $currency ?? 'MYR',
            ], $items);
        } catch (Throwable $e) {
            error_log('[tools] buy_product: ' . $e->getMessage());
            FlowLog::write('tool.buy_product_error', ['error' => $e->getMessage()]);
            $this->json(500, ['success' => false, 'error' => 'The order could not be saved. Run the database migrations (009) and try again.']);
        }

        $company      = (new CompanyProfile())->find($userId) ?? [];
        $instructions = trim((string) ($company['payment_instructions'] ?? ''));
        $qrSent       = (new OrderService())->sendPaymentQr($userId, $order, $company);
        $hasQr        = !empty($company['payment_qr_url']);

        $lines = array_map(static fn (array $i): array => [
            'name'       => $i['product_name'],
            'quantity'   => $i['quantity'],
            'unit_price' => format_money($i['unit_price'], $order['currency']),
            'line_total' => format_money(number_format(round((float) $i['unit_price'] * $i['quantity'], 2), 2, '.', ''), $order['currency']),
        ], $items);

        $result = [
            'success'              => true,
            'order_no'             => $order['order_no'],
            'status'               => 'awaiting_payment',
            'items'                => $lines,
            'total'                => format_money($order['total'], $order['currency']),
            'payment_instructions' => $instructions !== '' ? $instructions
                : ($hasQr ? 'Scan the payment QR code.' : 'No payment details are set up. Tell the customer our staff will send the payment details shortly.'),
            'payment_qr'           => $qrSent
                ? 'The payment QR code image has already been sent to the customer in this chat. Tell them to scan it to pay.'
                : ($hasQr ? 'payment_qr_url: ' . asset((string) $company['payment_qr_url']) : 'none'),
            'next_step'            => 'Tell the customer the order number, the items and the total, give the payment instructions, '
                                    . 'and ask them to send a photo or PDF of the payment receipt in this chat. '
                                    . 'Do not say the order is paid or confirmed: staff verify the receipt first.',
        ];
        FlowLog::write('tool.buy_product_ok', ['order_no' => $order['order_no'], 'total' => $order['total']]);

        $this->json(200, $result);
    }

    /* ---------------------------------------------------------- private ---- */

    private function authenticate(): int
    {
        return $this->token()['user_id'];
    }

    /** @return array{user_id: int, channel: ?string, chat_id: ?string} */
    private function token(): array
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $token  = preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : (string) ($_SERVER['HTTP_X_TOOL_TOKEN'] ?? '');
        $auth   = ToolToken::read($token);

        if ($auth === null) {
            FlowLog::write('tool.rejected', ['reason' => 'Invalid or expired tool token.', 'path' => $_SERVER['REQUEST_URI'] ?? '']);
            $this->json(401, ['success' => false, 'error' => 'Invalid or expired tool token.']);
        }

        return $auth;
    }

    /** A problem the AI should explain to the customer (HTTP 200 so the tool shows the text). */
    private function fail(string $message): never
    {
        FlowLog::write('tool.buy_product_refused', ['message' => $message]);
        $this->json(200, ['success' => false, 'message' => $message]);
    }

    /**
     * Items as [name, quantity] pairs. Accepts a JSON list
     * ([{"product": "Nasi Lemak", "quantity": 2}]) or text such as
     * "Nasi Lemak x2, 1 x Teh Tarik; Kuih (3)".
     *
     * @return list<array{0: string, 1: int}>
     */
    private function parseItems(mixed $items): array
    {
        if (is_string($items)) {
            $decoded = json_decode($items, true);
            if (is_array($decoded)) {
                $items = $decoded;
            }
        }

        $out = [];
        if (is_array($items)) {
            if (isset($items['product']) || isset($items['name'])) {
                $items = [$items];
            }
            foreach ($items as $item) {
                if (is_string($item)) {
                    $out = array_merge($out, $this->parseItems($item));
                    continue;
                }
                if (!is_array($item)) {
                    continue;
                }
                $name = trim((string) ($item['product'] ?? $item['name'] ?? $item['sku'] ?? ''));
                $qty  = (int) ($item['quantity'] ?? $item['qty'] ?? 1);
                if ($name !== '') {
                    $out[] = [mb_substr($name, 0, 200), $qty];
                }
            }
            return $out;
        }

        foreach (preg_split('/[,;\n]+/', (string) $items) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $qty = 1;
            if (preg_match('/^(.+?)\s*(?:[x×*]\s*(\d+)|\((\d+)\)|:\s*(\d+))$/iu', $part, $m)) {
                $part = $m[1];
                $qty  = (int) ($m[2] ?: ($m[3] ?: $m[4]));
            } elseif (preg_match('/^(\d+)\s*[x×*]?\s+(.+)$/iu', $part, $m)) {
                $qty  = (int) $m[1];
                $part = $m[2];
            }
            $part = trim($part, " \t-");
            if ($part !== '') {
                $out[] = [mb_substr($part, 0, 200), $qty];
            }
        }

        return $out;
    }

    /**
     * Find one active product by SKU, exact name, or a unique partial name.
     *
     * @return array{0: ?array, 1: string} [product row or null, problem message]
     */
    private function matchProduct(int $userId, string $name): array
    {
        $db   = Database::connection();
        $base = "SELECT id, name, sku, price, promotion_price, currency FROM products WHERE user_id = ? AND status = 'active'";

        $stmt = $db->prepare($base . ' AND (LOWER(sku) = LOWER(?) OR LOWER(name) = LOWER(?)) ORDER BY id LIMIT 2');
        $stmt->execute([$userId, $name, $name]);
        $exact = $stmt->fetchAll();
        if (count($exact) === 1) {
            return [$exact[0], ''];
        }

        $like = '%' . addcslashes(mb_strtolower($name), '%_\\') . '%';
        $stmt = $db->prepare($base . ' AND LOWER(name) LIKE ? ORDER BY LOWER(name) LIMIT 6');
        $stmt->execute([$userId, $like]);
        $found = $stmt->fetchAll();

        if (count($found) === 1) {
            return [$found[0], ''];
        }
        if ($found === []) {
            return [null, 'No active product matches "' . $name . '" (use check_product to see what is available).'];
        }

        return [null, '"' . $name . '" matches several products: '
            . implode(', ', array_map(static fn (array $p): string => $p['name'], $found)) . '. Ask which one.'];
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
