<?php
/**
 * OrderService.php - the payment side of an order.
 *
 *   sendPaymentQr()  the payment QR image goes to the customer right after
 *                    buy_product creates the order.
 *   attachReceipt()  a customer with an order awaiting payment sends a photo
 *                    or PDF in Telegram: it is stored privately
 *                    (storage/uploads/receipts/<user>/), the order becomes
 *                    "payment_submitted", the customer is thanked and the
 *                    staff chat gets the receipt with the order details.
 *   approve()        staff approved on the Orders page: "paid", and the
 *                    customer gets "Payment verified" on Telegram.
 *   reject()         staff rejected: the customer is asked for a new receipt.
 *   cancel()         staff cancelled the order; the customer is told.
 */
declare(strict_types=1);

namespace Src\Services;

use RuntimeException;
use Src\Models\ChatMessage;
use Src\Models\Order;
use Src\Models\TelegramIntegration;
use Src\Support\Crypto;
use Src\Support\FlowLog;

final class OrderService
{
    /**
     * @param array  $integration telegram_integrations row of the business
     * @param array  $message     the Telegram message (has a photo or document)
     * @return bool  true when the file was taken as a payment receipt
     */
    public function attachReceipt(array $integration, string $token, array $message): bool
    {
        $userId = (int) $integration['user_id'];
        $chatId = (string) $message['chat']['id'];
        $orders = new Order();

        try {
            $order = $orders->latestAwaitingReceipt($userId, 'telegram', $chatId);
        } catch (\PDOException $e) {
            FlowLog::write('order.receipt_skipped', ['reason' => 'orders table missing (run migration 009)']);
            return false;
        }
        if ($order === null) {
            return false; // no order waiting: let the AI answer as usual
        }

        $file = $this->fileOf($message);
        $telegram = new TelegramService();

        if ($file === null) {
            return false;
        }

        $download = $telegram->downloadFile($token, $file['id']);
        $stored   = $download !== null
            ? (new PrivateFiles('receipts'))->storeBytes($userId, $download['bytes'], $file['name'] ?: $download['name'])
            : null;

        if ($stored === null) {
            FlowLog::write('order.receipt_failed', ['order_no' => $order['order_no'], 'file' => $file]);
            $telegram->sendText($token, $chatId, 'Sorry, I could not read that file. Please send the payment receipt as a photo, or as a PDF / JPG / PNG file (10 MB max).');
            return true;
        }

        // A new receipt replaces an earlier rejected one.
        if (!empty($order['receipt_path'])) {
            (new PrivateFiles('receipts'))->delete($userId, (string) $order['receipt_path']);
        }

        $order = $orders->update($userId, (int) $order['id'], [
            'status'              => 'payment_submitted',
            'receipt_path'        => $stored['file_path'],
            'receipt_mime'        => $stored['file_mime'],
            'receipt_name'        => $stored['file_name'],
            'receipt_uploaded_at' => date('c'),
            'reviewed_at'         => null,
            'review_note'         => null,
        ]) ?? $order;

        FlowLog::write('order.receipt_received', ['order_no' => $order['order_no'], 'mime' => $stored['file_mime'], 'size' => $stored['file_size']]);

        $total = format_money($order['total'], $order['currency']);
        $thanks = "🧾 Thank you! We received your payment receipt for order {$order['order_no']} ({$total}).\n"
            . 'Our team will verify it and let you know here shortly.';
        $telegram->sendText($token, $chatId, $thanks);
        $this->remember($userId, $order, 'customer', '📎 [Payment receipt sent for ' . $order['order_no'] . ']');
        $this->remember($userId, $order, 'assistant', $thanks);

        // Staff alert with the approve link: never into the customer's own chat
        // (when you test with the same Telegram account that is linked as staff).
        $staff = (string) ($integration['staff_telegram_chat_id'] ?? '');
        if ($staff !== '' && $staff !== $chatId) {
            $caption = "🧾 Payment receipt to review\n"
                . "Order: {$order['order_no']}\n"
                . 'Customer: ' . $this->customerLabel($order) . "\n"
                . "Total: {$total}\n"
                . $this->itemSummary((int) $order['id']) . "\n"
                . 'Approve or reject: ' . PUBLIC_APP_URL . '/orders?status=payment_submitted';
            if (!$telegram->copyMessage($token, $staff, $chatId, (int) $message['message_id'], $caption)) {
                $telegram->sendText($token, $staff, $caption);
            }
        }

        return true;
    }

    /**
     * Send the business's payment QR code (Company Information) to the
     * customer's Telegram chat, captioned with the order number and total.
     * Returns true when it was sent.
     */
    public function sendPaymentQr(int $userId, array $order, array $company): bool
    {
        $relative = (string) ($company['payment_qr_url'] ?? '');
        $path     = $relative !== '' && !str_contains($relative, '..') ? PUBLIC_ROOT . '/' . $relative : '';
        if ($order['channel'] !== 'telegram' || $path === '' || !is_file($path)) {
            return false;
        }

        $integration = (new TelegramIntegration())->find($userId);
        if ($integration === null || empty($integration['bot_token_encrypted'])) {
            return false;
        }
        try {
            $token = Crypto::decrypt((string) $integration['bot_token_encrypted']);
        } catch (RuntimeException $e) {
            return false;
        }

        $total   = format_money($order['total'], $order['currency']);
        $caption = "Scan to pay {$total} for order {$order['order_no']}.\nThen send the payment receipt (photo or PDF) in this chat.";
        $sent    = (new TelegramService())->sendPhoto($token, (string) $order['customer_chat_id'], $path, $caption);
        if ($sent) {
            $this->remember($userId, $order, 'assistant', '🖼 [Payment QR code] ' . $caption);
        }
        return $sent;
    }

    /** Staff approved the receipt. */
    public function approve(int $userId, array $order): array
    {
        if (!in_array($order['status'], ['payment_submitted', 'payment_rejected', 'pending_payment'], true)) {
            throw new RuntimeException('Only orders awaiting payment can be approved.');
        }

        $order = (new Order())->update($userId, (int) $order['id'], [
            'status' => 'paid', 'reviewed_at' => date('c'), 'review_note' => null,
        ]) ?? $order;

        $total = format_money($order['total'], $order['currency']);
        $text  = "✅ Payment verified!\nWe have received {$total} for order {$order['order_no']}. Thank you for your order.";
        $sent  = $this->notifyCustomer($userId, $order, $text);
        $this->remember($userId, $order, 'assistant', $text);

        FlowLog::write('order.approved', ['order_no' => $order['order_no'], 'customer_notified' => $sent]);

        return ['order' => $order, 'notified' => $sent];
    }

    /** Staff rejected the receipt: the customer can send a new one. */
    public function reject(int $userId, array $order, string $reason): array
    {
        if (!in_array($order['status'], ['payment_submitted', 'pending_payment'], true)) {
            throw new RuntimeException('Only orders with a receipt to review can be rejected.');
        }

        $reason = trim($reason);
        $order  = (new Order())->update($userId, (int) $order['id'], [
            'status' => 'payment_rejected', 'reviewed_at' => date('c'),
            'review_note' => $reason !== '' ? mb_substr($reason, 0, 300) : null,
        ]) ?? $order;

        $text = "⚠️ We could not verify the payment for order {$order['order_no']}."
            . ($reason !== '' ? "\nReason: {$reason}" : '')
            . "\nPlease send a clear photo or PDF of the payment receipt here, or reply if you need help.";
        $sent = $this->notifyCustomer($userId, $order, $text);
        $this->remember($userId, $order, 'assistant', $text);

        FlowLog::write('order.rejected', ['order_no' => $order['order_no'], 'reason' => $reason, 'customer_notified' => $sent]);

        return ['order' => $order, 'notified' => $sent];
    }

    /** Staff cancelled the order. */
    public function cancel(int $userId, array $order): array
    {
        if (in_array($order['status'], ['paid', 'cancelled'], true)) {
            throw new RuntimeException('Paid or cancelled orders cannot be cancelled.');
        }

        $order = (new Order())->update($userId, (int) $order['id'], ['status' => 'cancelled', 'reviewed_at' => date('c')]) ?? $order;
        $text  = "Order {$order['order_no']} has been cancelled. Reply here if you have any questions.";
        $sent  = $this->notifyCustomer($userId, $order, $text);
        $this->remember($userId, $order, 'assistant', $text);

        FlowLog::write('order.cancelled', ['order_no' => $order['order_no'], 'customer_notified' => $sent]);

        return ['order' => $order, 'notified' => $sent];
    }

    /* ---------------------------------------------------------- private ---- */

    /** Keep order events in the conversation history (Chat Interface > Customer conversations). */
    private function remember(int $userId, array $order, string $role, string $text): void
    {
        try {
            (new ChatMessage())->add($userId, (string) $order['channel'], (string) $order['customer_chat_id'], $role, $text, 'ok');
        } catch (\Throwable $e) {
            // history only; never stop the order flow
        }
    }

    /** Message the customer in the chat the order came from (Telegram only). */
    private function notifyCustomer(int $userId, array $order, string $text): bool
    {
        if ($order['channel'] !== 'telegram') {
            return false;
        }

        $integration = (new TelegramIntegration())->find($userId);
        if ($integration === null || empty($integration['bot_token_encrypted'])) {
            FlowLog::write('order.notify_skipped', ['order_no' => $order['order_no'], 'reason' => 'no Telegram bot saved']);
            return false;
        }

        try {
            $token = Crypto::decrypt((string) $integration['bot_token_encrypted']);
        } catch (RuntimeException $e) {
            return false;
        }

        return (new TelegramService())->sendText($token, (string) $order['customer_chat_id'], $text);
    }

    /** @return array{id: string, name: string}|null the receipt file in a message */
    private function fileOf(array $message): ?array
    {
        if (!empty($message['document']['file_id'])) {
            return ['id' => (string) $message['document']['file_id'], 'name' => (string) ($message['document']['file_name'] ?? 'receipt')];
        }
        if (!empty($message['photo']) && is_array($message['photo'])) {
            $photo = end($message['photo']); // largest size
            return ['id' => (string) ($photo['file_id'] ?? ''), 'name' => 'receipt.jpg'];
        }
        return null;
    }

    private function customerLabel(array $order): string
    {
        $name = trim((string) ($order['customer_name'] ?? ''));
        $user = trim((string) ($order['customer_username'] ?? ''));
        $label = $name !== '' ? $name : 'Chat ' . $order['customer_chat_id'];
        return $user !== '' ? $label . ' (@' . $user . ')' : $label;
    }

    private function itemSummary(int $orderId): string
    {
        $lines = [];
        foreach ((new Order())->items($orderId) as $item) {
            $lines[] = '• ' . $item['quantity'] . ' × ' . $item['product_name'];
        }
        return implode("\n", $lines);
    }
}
