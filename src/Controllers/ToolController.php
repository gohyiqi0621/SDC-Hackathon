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
 *   POST /tools/check_availability
 *     JSON body: { "date": "2026-10-22", "time": "21:00", "pax": 4 }
 *     Is there room at that time on the business's Google Calendar? Answers
 *     available true/false, the reason, and up to 3 other free times.
 *
 *   POST /tools/make_booking
 *     JSON body: { "date": "2026-10-22", "time": "21:00", "pax": 4,
 *                  "customer_name": "Ali", "phone": "0123456789", "note": "Birthday" }
 *     Checks again, adds the event to Google Calendar and saves the booking
 *     (AI Chatbot > Customer > Bookings). See BookingService for the rules.
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
use Src\Services\BookingService;
use Src\Services\OrderService;
use Src\Support\Database;
use Src\Support\FlowLog;
use Src\Support\ToolToken;
use RuntimeException;
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
                          p.price, p.currency
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
            return [
                'name'            => $row['name'],
                'sku'             => $row['sku'],
                'category'        => $row['category'],
                'description'     => $row['description'],
                'characteristics' => $row['characteristics'],
                'price'           => format_money($row['price'], $row['currency']),
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
                'unit_price'   => (string) $product['price'],
                'quantity'     => $qty,
            ];
        }

        if ($problems !== []) {
            $this->fail('The order was NOT created. ' . implode(' ', $problems) . ' Ask the customer to confirm, then call buy_product again.');
        }

        $phone = mb_substr(trim((string) ($input['phone'] ?? $input['customer_phone'] ?? '')), 0, 30);
        $note  = mb_substr(trim((string) ($input['note'] ?? $input['delivery_note'] ?? '')), 0, 500);

        // An add-on after a finished order: the AI sometimes repeats the items
        // of the order that was already paid. Those items are taken out, so the
        // new order holds only what was added.
        $addOnOf = null;
        try {
            $locked = (new Order())->latestLocked($userId, $auth['channel'], $auth['chat_id']);
        } catch (Throwable $e) {
            $locked = null;
        }
        if ($locked !== null) {
            [$remaining, $contained, $sameItems] = $this->subtractItems($items, $locked['items']);
            $lockedLabel = $locked['order_no'] . (in_array($locked['status'], ['paid', 'shipped'], true) ? ' (already paid)' : ' (receipt sent, being checked)');

            if ($sameItems && strtotime((string) $locked['created_at']) > time() - 600) {
                $this->fail('The order was NOT created: these are exactly the items of order ' . $lockedLabel . '. '
                    . 'Do not order them again. If the customer really wants the same items again, ask them to confirm first, '
                    . 'then call buy_product with only the items they want now.');
            }
            if ($contained && $remaining !== []) {
                $addOnOf = $lockedLabel;
                $items    = $remaining;
            }
        }

        // One order at a time per customer chat: the lock makes two calls that
        // arrive together (the AI sometimes repeats a tool call) wait for each
        // other, so the second one finds the first order instead of making a copy.
        $orders    = new Order();
        $db        = Database::connection();
        $duplicate = false;
        $replaced  = [];

        try {
            $db->beginTransaction();
            $db->prepare('SELECT pg_advisory_xact_lock(?, ?)')
               ->execute([$userId, crc32($auth['channel'] . ':' . $auth['chat_id']) & 0x7fffffff]);

            $order = $orders->findSamePending($userId, $auth['channel'], $auth['chat_id'], $items);
            $duplicate = $order !== null;

            if (!$duplicate) {
                $order = $orders->create($userId, [
                'channel'           => $auth['channel'],
                'customer_chat_id'  => $auth['chat_id'],
                'customer_name'     => mb_substr(trim((string) ($input['customer_name'] ?? '')), 0, 150),
                'customer_username' => mb_substr(ltrim(trim((string) ($input['customer_username'] ?? '')), '@'), 0, 64),
                'customer_phone'    => $phone,
                'delivery_note'     => $note,
                'currency'          => $currency ?? 'MYR',
            ], $items);

                // The new order replaces this customer's other unpaid orders.
                $replaced = $orders->cancelOtherUnpaid($userId, $auth['channel'], $auth['chat_id'], (int) $order['id'],
                    'Replaced by ' . $order['order_no']);
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[tools] buy_product: ' . $e->getMessage());
            FlowLog::write('tool.buy_product_error', ['error' => $e->getMessage()]);
            $this->json(500, ['success' => false, 'error' => 'The order could not be saved. Run the database migrations (009) and try again.']);
        }

        $company      = (new CompanyProfile())->find($userId) ?? [];
        $instructions = trim((string) ($company['payment_instructions'] ?? ''));
        // The QR goes out once, with the new order (not again for a repeated call).
        $qrSent       = !$duplicate && (new OrderService())->sendPaymentQr($userId, $order, $company);
        $hasQr        = !empty($company['payment_qr_url']);

        if ($duplicate) {
            $items = $orders->items((int) $order['id']);
        }

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
            'payment_qr'           => $qrSent || ($duplicate && $hasQr && $order['channel'] === 'telegram')
                ? 'The payment QR code image has already been sent to the customer in this chat. Tell them to scan it to pay.'
                : ($hasQr ? 'payment_qr_url: ' . asset((string) $company['payment_qr_url']) : 'none'),
            'next_step'            => 'Tell the customer the order number, the items and the total, give the payment instructions, '
                                    . 'and ask them to send a photo or PDF of the payment receipt in this chat. '
                                    . 'Do not say the order is paid or confirmed: staff verify the receipt first. '
                                    . 'The order is done: do NOT call buy_product again for it.',
        ];
        if ($duplicate) {
            $result['note'] = 'This exact order already exists (created a moment ago), so no new order was made. '
                            . 'Do not call buy_product again; just answer the customer with this order.';
        }
        if ($addOnOf !== null) {
            $result['add_on_of'] = $addOnOf;
            $result['note'] = 'Order ' . $addOnOf . ' is finished, so its items were NOT added again. '
                            . 'This is a separate new order with ONLY the add-on items listed here. '
                            . 'Tell the customer it is a new order for the add-on and they pay only this total.';
        }
        if ($replaced !== []) {
            $result['replaced_orders'] = $replaced;
            $result['note'] = trim(($result['note'] ?? '') . ' This order replaces the customer\'s earlier unpaid order(s) '
                            . implode(', ', $replaced) . ', which were cancelled. Tell the customer to pay only this order.');
        }
        FlowLog::write($duplicate ? 'tool.buy_product_same' : 'tool.buy_product_ok',
            ['order_no' => $order['order_no'], 'total' => $order['total'], 'replaced' => $replaced]);

        $this->json(200, $result);
    }

    /** POST /tools/check_availability */
    public function checkAvailability(): void
    {
        $auth  = $this->token();
        $input = $this->input();
        FlowLog::write('tool.check_availability', ['user_id' => $auth['user_id'], 'input' => $input]);

        // No time given ("any time is fine", "what times do you have?"): list real free times.
        if (trim((string) ($input['time'] ?? '')) === '') {
            $this->freeTimesAnswer($auth['user_id'], $input);
        }

        [$service, $s, $start, $pax] = $this->bookingRequest($auth['user_id'], $input);

        try {
            $check = $service->check($auth['user_id'], $s, $start, $pax);
        } catch (Throwable $e) {
            $this->bookingError($e);
        }

        $result = [
            'success'   => true,
            'available' => $check['available'],
            'requested' => BookingService::label($check['start'], $check['end']),
            'pax'       => $pax,
            'rules'     => BookingService::rulesText($s),
        ];
        if ($s['booking_mode'] === 'multi' && ($check['available'] || $check['used'] > 0)) {
            $result['places_left'] = max(0, $s['slot_capacity'] - $check['used']) . ' of ' . $s['slot_capacity']
                . ($s['capacity_unit'] === 'pax' ? ' seats' : ' bookings');
        }
        if ($check['available']) {
            $result['next_step'] = 'The time is free. Summarise date, time, pax and name for the customer and ask them to confirm. '
                                 . 'Only after they say yes, call make_booking with the same date and time.';
        } else {
            $result['reason']       = $check['reason'];
            $result['alternatives'] = $check['alternatives'];
            $result['next_step']    = 'Do not book this time. Tell the customer the reason and offer the alternatives (if any).';
        }
        FlowLog::write('tool.check_availability_result', ['available' => $check['available'], 'reason' => $check['reason']]);
        $this->json(200, $result);
    }

    /** POST /tools/make_booking */
    public function makeBooking(): void
    {
        $auth  = $this->token();
        $input = $this->input();
        FlowLog::write('tool.make_booking', ['user_id' => $auth['user_id'], 'chat' => $auth['channel'] . ':' . $auth['chat_id'], 'input' => $input]);

        if ($auth['chat_id'] === null || !in_array($auth['channel'], ['telegram', 'web'], true)) {
            $this->json(400, ['success' => false, 'error' => 'This tool token is not linked to a customer chat.']);
        }

        [$service, $s, $start, $pax] = $this->bookingRequest($auth['user_id'], $input);

        $name = mb_substr(trim((string) ($input['customer_name'] ?? $input['name'] ?? '')), 0, 150);
        [$knownName, $knownUser] = $this->chatCustomer($auth['user_id'], (string) $auth['channel'], (string) $auth['chat_id']);
        if ($name === '') {
            $name = $knownName;
        }
        if ($name === '') {
            $this->fail('The booking was NOT made: the customer\'s name is missing. Ask for it, then call make_booking again.');
        }

        try {
            $result = $service->book($auth['user_id'], $start, $pax, [
                'channel'           => (string) $auth['channel'],
                'customer_chat_id'  => (string) $auth['chat_id'],
                'customer_name'     => $name,
                'customer_username' => mb_substr(ltrim(trim((string) ($input['customer_username'] ?? $knownUser)), '@'), 0, 64),
                'customer_phone'    => mb_substr(trim((string) ($input['phone'] ?? $input['customer_phone'] ?? '')), 0, 30),
                'note'              => mb_substr(trim((string) ($input['note'] ?? '')), 0, 500),
            ]);
        } catch (Throwable $e) {
            $this->bookingError($e);
        }

        if (!$result['ok']) {
            FlowLog::write('tool.make_booking_refused', ['message' => $result['message']]);
            $this->json(200, [
                'success'      => false,
                'message'      => 'The booking was NOT made: ' . $result['message'],
                'alternatives' => $result['alternatives'] ?? [],
                'next_step'    => 'Tell the customer and offer the alternatives. Do not say it is booked.',
            ]);
        }

        $b    = $result['booking'];
        $zone = $s['zone'];
        $out  = [
            'success'    => true,
            'booking_no' => $b['booking_no'],
            'status'     => 'confirmed',
            'when'       => BookingService::label((new \DateTimeImmutable((string) $b['starts_at']))->setTimezone($zone),
                                                  (new \DateTimeImmutable((string) $b['ends_at']))->setTimezone($zone)),
            'pax'        => (int) $b['pax'],
            'name'       => $b['customer_name'],
            'next_step'  => 'Tell the customer the booking is confirmed with the booking number, date, time and pax. '
                          . 'The booking is done: do NOT call make_booking again for it.',
        ];
        if (!empty($result['duplicate'])) {
            $out['note'] = 'This booking already existed, so no new booking was made. Do not call make_booking again.';
        }
        FlowLog::write('tool.make_booking_ok', ['booking_no' => $b['booking_no'], 'duplicate' => !empty($result['duplicate'])]);
        $this->json(200, $out);
    }

    /* ---------------------------------------------------------- private ---- */

    /** check_availability without a time: the next free start times from that date (or tomorrow). */
    private function freeTimesAnswer(int $userId, array $input): never
    {
        $service = new BookingService();
        try {
            $s = $service->settings($userId);
        } catch (Throwable $e) {
            $this->bookingError($e);
        }

        $date = trim((string) ($input['date'] ?? ''));
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            ? (\DateTimeImmutable::createFromFormat('!Y-m-d', $date, $s['zone']) ?: new \DateTimeImmutable('today', $s['zone']))
            : new \DateTimeImmutable('today', $s['zone']);
        $pax  = max(1, min(1000, (int) ($input['pax'] ?? 1)));

        try {
            $times = $service->freeTimes($userId, $s, $from, $pax);
        } catch (Throwable $e) {
            $this->bookingError($e);
        }

        FlowLog::write('tool.check_availability_list', ['from' => $from->format('Y-m-d'), 'count' => count($times)]);
        $this->json(200, [
            'success'    => true,
            'free_times' => $times,
            'rules'      => BookingService::rulesText($s),
            'next_step'  => $times === []
                ? 'There are no free times in the next 2 weeks. Tell the customer and offer to pass the request to staff.'
                : 'Offer the customer ONLY these free times (do not add other times). When they choose one, confirm it, then call make_booking.',
        ]);
    }

    /** @return array{0: BookingService, 1: array, 2: \DateTimeImmutable, 3: int} */
    private function bookingRequest(int $userId, array $input): array
    {
        $service = new BookingService();
        try {
            $s = $service->settings($userId);
        } catch (Throwable $e) {
            $this->bookingError($e);
        }

        $date  = trim((string) ($input['date'] ?? $input['start'] ?? $input['datetime'] ?? ''));
        $time  = trim((string) ($input['time'] ?? ''));
        $start = $service->parseStart($date, $time, $s['zone']);
        if ($start === null) {
            $this->fail('Give the date as YYYY-MM-DD and the time in 12-hour format with AM/PM, e.g. date "'
                . (new \DateTimeImmutable('tomorrow', $s['zone']))->format('Y-m-d') . '", time "7:30 PM". '
                . 'Today is ' . (new \DateTimeImmutable('now', $s['zone']))->format('l, Y-m-d g:i A') . ' (' . $s['zone']->getName() . ').');
        }

        $pax = (int) ($input['pax'] ?? $input['people'] ?? $input['guests'] ?? 1);
        if ($pax < 1 || $pax > 1000) {
            $this->fail('The number of people (pax) must be 1 or more.');
        }

        return [$service, $s, $start, $pax];
    }

    /** Name and username Telegram gave for this chat (saved with the messages). */
    private function chatCustomer(int $userId, string $channel, string $chatId): array
    {
        try {
            $stmt = Database::connection()->prepare(
                "SELECT customer_name, customer_username FROM chat_messages
                  WHERE user_id = ? AND channel = ? AND chat_id = ? AND COALESCE(customer_name, '') <> ''
                  ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$userId, $channel, $chatId]);
            $row = $stmt->fetch();
            return [(string) ($row['customer_name'] ?? ''), (string) ($row['customer_username'] ?? '')];
        } catch (Throwable) {
            return ['', ''];
        }
    }

    private function bookingError(Throwable $e): never
    {
        if ($e instanceof RuntimeException && str_starts_with($e->getMessage(), 'Bookings are not available')) {
            FlowLog::write('tool.booking_unavailable', ['message' => $e->getMessage()]);
            $this->json(200, ['success' => false, 'message' => $e->getMessage()
                . ' Tell the customer bookings cannot be made in this chat right now and staff will contact them.']);
        }
        error_log('[tools] booking: ' . $e->getMessage());
        FlowLog::write('tool.booking_error', ['error' => $e->getMessage()]);
        $this->json(500, ['success' => false, 'error' => 'The booking system could not be reached. '
            . (str_contains($e->getMessage(), 'bookings') ? 'Run the database migrations (013). ' : '') . 'Please try again.']);
    }

    /**
     * Take the items of an earlier order out of a new list.
     *
     * @return array{0: list<array>, 1: bool, 2: bool} [items left, every earlier item was in the new list, the lists are identical]
     */
    private function subtractItems(array $new, array $earlier): array
    {
        $old = [];
        foreach ($earlier as $item) {
            $id = (int) ($item['product_id'] ?? 0);
            $old[$id] = ($old[$id] ?? 0) + (int) $item['quantity'];
        }
        if ($old === [] || isset($old[0])) {
            return [$new, false, false];
        }

        $wanted = [];
        foreach ($new as $item) {
            $wanted[(int) $item['product_id']] = ($wanted[(int) $item['product_id']] ?? 0) + (int) $item['quantity'];
        }

        foreach ($old as $id => $qty) {
            if (($wanted[$id] ?? 0) < $qty) {
                return [$new, false, false]; // not a copy of the earlier order: keep as asked
            }
        }
        if ($wanted == $old) {
            return [[], true, true];
        }

        $left = [];
        foreach ($new as $item) {
            $id   = (int) $item['product_id'];
            $take = min($old[$id] ?? 0, (int) $item['quantity']);
            $old[$id] = ($old[$id] ?? 0) - $take;
            if ((int) $item['quantity'] - $take > 0) {
                $item['quantity'] = (int) $item['quantity'] - $take;
                $left[] = $item;
            }
        }
        return [$left, true, false];
    }

    /**
     * The one active product whose name is clearly closest to $name (at most
     * ~20% of the letters different, and clearly better than the next one).
     */
    private function closestProduct(int $userId, string $name): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT id, name, sku, price, currency FROM products WHERE user_id = ? AND status = 'active'"
        );
        $stmt->execute([$userId]);

        $norm   = static fn (string $v): string => (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($v));
        $target = $norm($name);
        if (strlen($target) < 3) {
            return null;
        }

        $scored = [];
        foreach ($stmt->fetchAll() as $product) {
            $candidate = $norm((string) $product['name']);
            if ($candidate === '' || strlen($candidate) > 255 || strlen($target) > 255) {
                continue;
            }
            $distance = levenshtein($target, $candidate);
            $scored[] = [$distance / max(strlen($target), strlen($candidate)), $product];
        }
        usort($scored, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        if ($scored === [] || $scored[0][0] > 0.2 || (isset($scored[1]) && $scored[1][0] - $scored[0][0] < 0.1)) {
            return null;
        }
        return $scored[0][1];
    }

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
        FlowLog::write('tool.refused', ['path' => $_SERVER['REQUEST_URI'] ?? '', 'message' => $message]);
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
     * A SKU anywhere in the text wins ("Single Subject Tuition (PL-00002)"),
     * so products that share a name can still be told apart.
     *
     * @return array{0: ?array, 1: string} [product row or null, problem message]
     */
    private function matchProduct(int $userId, string $name): array
    {
        $db   = Database::connection();
        $base = "SELECT p.id, p.name, p.sku, p.price, p.currency, c.name AS category
                   FROM products p LEFT JOIN product_categories c ON c.id = p.category_id
                  WHERE p.user_id = ? AND p.status = 'active'";

        // 1. A SKU written anywhere in the item text.
        $stmt = $db->prepare($base . " AND COALESCE(p.sku, '') <> ''");
        $stmt->execute([$userId]);
        $bySku = [];
        foreach ($stmt->fetchAll() as $p) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote((string) $p['sku'], '/') . '(?![\p{L}\p{N}])/iu', $name)) {
                $bySku[(int) $p['id']] = $p;
            }
        }
        if (count($bySku) === 1) {
            return [reset($bySku), ''];
        }

        // 2. Exact name (or SKU on its own).
        $stmt = $db->prepare($base . ' AND (LOWER(p.sku) = LOWER(?) OR LOWER(p.name) = LOWER(?)) ORDER BY p.id LIMIT 6');
        $stmt->execute([$userId, $name, $name]);
        $exact = $stmt->fetchAll();
        if (count($exact) === 1) {
            return [$exact[0], ''];
        }
        if (count($exact) > 1) {
            return [null, $this->ambiguous($name, $exact)];
        }

        // 3. Part of a name, without anything in brackets ("Nasi Lemak (special)").
        $plain = trim(preg_replace('/\s*[\(\[].*?[\)\]]\s*/u', ' ', $name) ?? $name) ?: $name;
        $like  = '%' . addcslashes(mb_strtolower($plain), '%_\\') . '%';
        $stmt  = $db->prepare($base . ' AND LOWER(p.name) LIKE ? ORDER BY LOWER(p.name) LIMIT 6');
        $stmt->execute([$userId, $like]);
        $found = $stmt->fetchAll();

        if (count($found) === 1) {
            return [$found[0], ''];
        }
        if ($found === []) {
            // Small spelling differences ("Teh O Ais Limau" vs "TEH OH AIS LIMAU").
            $close = $this->closestProduct($userId, $plain);
            if ($close !== null) {
                return [$close, ''];
            }
            return [null, 'No active product matches "' . $name . '" (use check_product to see what is available).'];
        }

        return [null, $this->ambiguous($name, $found)];
    }

    /** Several products fit: list them with SKU, category and price so the AI can ask and then order by SKU. */
    private function ambiguous(string $name, array $products): string
    {
        $list = array_map(static function (array $p): string {
            $bits = array_filter([
                !empty($p['sku']) ? 'SKU ' . $p['sku'] : null,
                !empty($p['category']) ? $p['category'] : null,
                format_money($p['price'], $p['currency']),
            ]);
            return $p['name'] . ' (' . implode(', ', $bits) . ')';
        }, $products);

        $withSku = array_values(array_filter($products, static fn (array $p): bool => !empty($p['sku'])));
        $hint    = $withSku !== []
            ? ' Ask the customer which one, then put its SKU in items, e.g. "' . $withSku[0]['sku'] . ' x1".'
            : ' Ask the customer which one.';

        return '"' . $name . '" matches several products: ' . implode('; ', $list) . '.' . $hint;
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
