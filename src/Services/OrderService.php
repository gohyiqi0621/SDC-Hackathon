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
 *                    customer gets "Payment verified" and the official
 *                    receipt (PDF, OfficialReceiptPdf) on Telegram.
 *   officialReceipt() the official receipt PDF of a paid / shipped order.
 *
 * Wording: what the customer sends is the "bank reference" (transfer slip /
 * payment proof); the "official receipt" is the PDF the business issues.
 *   reject()         staff rejected: the customer is asked for a new receipt.
 *   cancel()         staff cancelled the order; the customer is told.
 */
declare(strict_types=1);

namespace Src\Services;

use RuntimeException;
use Src\Models\ChatMessage;
use Src\Models\CompanyProfile;
use Src\Models\Order;
use Src\Models\TelegramIntegration;
use Src\Support\Crypto;
use Src\Support\FlowLog;

final class OrderService
{
    /**
     * @param array  $integration telegram_integrations row of the business
     * @param array  $message     the Telegram message (has a photo or document)
     * @return bool  true when the file was taken as a bank reference
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
            $telegram->sendText($token, $chatId, 'Sorry, I could not read that file. Please send your bank reference (transfer slip) as a photo, or as a PDF / JPG / PNG file (10 MB max).');
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
        $thanks = "🧾 Thank you! We received your bank reference for order {$order['order_no']} ({$total}).\n"
            . 'Our team will verify it and let you know here shortly.';
        // Several orders waiting for payment: say which one this slip was matched to.
        try {
            $others = array_values(array_filter(
                $orders->recentForChat($userId, 'telegram', $chatId, 10),
                static fn (array $o): bool => (int) $o['id'] !== (int) $order['id']
                    && in_array($o['status'], ['pending_payment', 'payment_rejected'], true)
            ));
        } catch (\Throwable $e) {
            $others = [];
        }
        if ($others !== []) {
            $thanks .= "\nStill waiting for payment: "
                . implode(', ', array_map(static fn (array $o): string => $o['order_no'] . ' (' . format_money($o['total'], $o['currency']) . ')', $others))
                . ". If this slip was for one of those, tell us the order number.";
        }
        $telegram->sendText($token, $chatId, $thanks);
        $this->remember($userId, $order, 'customer', '📎 [Bank reference sent for ' . $order['order_no'] . ']');
        $this->remember($userId, $order, 'assistant', $thanks);

        // Staff alert: "verify this payment". When the staff Telegram is the same
        // account as the customer (testing with one phone), it still goes out,
        // clearly marked as a staff alert.
        $staff = (string) ($integration['staff_telegram_chat_id'] ?? '');
        if ($staff === '') {
            FlowLog::write('staff.alert_skipped', ['order_no' => $order['order_no'],
                'reason' => 'no staff Telegram linked (Staff Assistant > Connections > Telegram staff alerts)']);
        } else {
            $caption = "👩‍💼 STAFF ALERT · Payment to verify\n"
                . "Order: {$order['order_no']}\n"
                . 'Customer: ' . $this->customerLabel($order) . "\n"
                . "Total: {$total}\n"
                . $this->itemSummary((int) $order['id']) . "\n"
                . 'Approve or reject: ' . PUBLIC_APP_URL . '/?page=orders&status=payment_submitted';
            $sent = $staff !== $chatId
                && $telegram->copyMessage($token, $staff, $chatId, (int) $message['message_id'], $caption);
            if (!$sent) {
                $sent = $telegram->sendText($token, $staff, $caption);
            }
            FlowLog::write('staff.payment_alert', ['order_no' => $order['order_no'], 'sent' => $sent,
                'same_chat_as_customer' => $staff === $chatId]);
        }

        return true;
    }

    /**
     * Tell the staff chat that a customer placed a new order (buy_product).
     * Never sent into the customer's own chat. Returns true when sent.
     */
    public function alertStaffNewOrder(int $userId, array $order): bool
    {
        if ($order['channel'] !== 'telegram') {
            return false;
        }
        $integration = (new TelegramIntegration())->find($userId);
        $staff       = (string) ($integration['staff_telegram_chat_id'] ?? '');
        if ($integration === null || empty($integration['bot_token_encrypted']) || $staff === ''
            || $staff === (string) $order['customer_chat_id']) {
            FlowLog::write('staff.alert_skipped', ['order_no' => $order['order_no'],
                'reason' => $staff === '' ? 'no staff chat linked'
                    : ($staff === (string) $order['customer_chat_id'] ? 'staff chat is the same Telegram account as the customer' : 'no bot token')]);
            return false;
        }
        try {
            $token = Crypto::decrypt((string) $integration['bot_token_encrypted']);
        } catch (RuntimeException $e) {
            return false;
        }

        $text = "🛒 New order {$order['order_no']}\n"
            . 'Customer: ' . $this->customerLabel($order) . "\n"
            . 'Total: ' . format_money($order['total'], $order['currency']) . "\n"
            . $this->itemSummary((int) $order['id'])
            . (!empty($order['delivery_note']) ? "\nNote: {$order['delivery_note']}" : '') . "\n"
            . "Waiting for the customer's bank reference. You will get another message when it arrives.\n"
            . 'Orders: ' . PUBLIC_APP_URL . '/?page=orders';
        $sent = (new TelegramService())->sendText($token, $staff, $text);
        FlowLog::write('staff.new_order_alert', ['order_no' => $order['order_no'], 'sent' => $sent]);

        return $sent;
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
        if ($path !== '') {
            \Src\Support\FileBackup::restore('public', $relative);   // missing after a redeploy
        }
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
        $caption = "Scan to pay {$total} for order {$order['order_no']}.\nThen send your bank reference (transfer slip, photo or PDF) in this chat.";
        $sent    = (new TelegramService())->sendPhoto($token, (string) $order['customer_chat_id'], $path, $caption);
        if ($sent) {
            $this->remember($userId, $order, 'assistant', '🖼 [Payment QR code] ' . $caption);
        }
        return $sent;
    }

    /** Staff approved the bank reference. */
    public function approve(int $userId, array $order): array
    {
        // Only after the customer sent a receipt.
        if ($order['status'] !== 'payment_submitted' || empty($order['receipt_path'])) {
            throw new RuntimeException('The customer has not sent a bank reference for this order yet.');
        }

        $order = (new Order())->update($userId, (int) $order['id'], [
            'status' => 'paid', 'reviewed_at' => date('c'), 'review_note' => null,
        ]) ?? $order;

        // The order's invoice (Financial > Invoice) becomes Paid.
        $invoice = (new OrderInvoiceService())->markPaid($userId, $order);

        $total = format_money($order['total'], $order['currency']);
        $text  = "✅ Payment verified!\nWe have received {$total} for order {$order['order_no']}. Thank you for your order.\n"
            . 'Your official receipt (' . OfficialReceiptPdf::receiptNo($order) . ') is attached below.';
        $sent  = $this->notifyCustomer($userId, $order, $text);
        $this->remember($userId, $order, 'assistant', $text);
        $receiptSent = $sent && $this->sendOfficialReceipt($userId, $order);
        if ($receiptSent) {
            $order = $this->markReceiptSent($userId, $order);
        }

        FlowLog::write('order.approved', ['order_no' => $order['order_no'], 'customer_notified' => $sent, 'official_receipt_sent' => $receiptSent]);

        return ['order' => $order, 'notified' => $sent, 'receipt_sent' => $receiptSent,
                'invoice_paid' => $invoice !== null && ($invoice['status'] ?? '') === 'paid'];
    }

    /**
     * The official receipt PDF (bytes) of a paid / shipped order, with the
     * business details from Company Profile.
     */
    public function officialReceipt(int $userId, array $order): string
    {
        try {
            $company = (new CompanyProfile())->find($userId) ?? [];
        } catch (\Throwable $e) {
            $company = [];
        }
        return (new OfficialReceiptPdf())->render($order, (new Order())->items((int) $order['id']), $company);
    }

    /**
     * Send the official receipt PDF to the customer on Telegram again (paid /
     * shipped orders only): "Send receipt" on the Orders page, or the customer
     * asks for it in the chat (tools/send_official_receipt).
     */
    public function resendOfficialReceipt(int $userId, array $order): bool
    {
        if (!in_array((string) $order['status'], OfficialReceiptPdf::STATUSES, true)) {
            throw new RuntimeException('The official receipt is issued after the payment is approved.');
        }
        if ($order['channel'] !== 'telegram') {
            throw new RuntimeException('This customer did not order on Telegram. Download the receipt and send it yourself.');
        }
        if (!$this->sendOfficialReceipt($userId, $order)) {
            return false;
        }
        $this->markReceiptSent($userId, $order);
        FlowLog::write('order.official_receipt_resent', ['order_no' => $order['order_no']]);
        return true;
    }

    private function markReceiptSent(int $userId, array $order): array
    {
        try {
            Order::ensureReceiptSentColumn();
            return (new Order())->update($userId, (int) $order['id'], ['official_receipt_sent_at' => date('c')]) ?? $order;
        } catch (\Throwable $e) {
            return $order;
        }
    }

    /** Send the official receipt PDF to the customer on Telegram. */
    private function sendOfficialReceipt(int $userId, array $order): bool
    {
        if ($order['channel'] !== 'telegram') {
            return false;
        }
        $token = $this->botToken($userId);
        if ($token === null) {
            return false;
        }
        try {
            $pdf = $this->officialReceipt($userId, $order);
        } catch (\Throwable $e) {
            FlowLog::write('order.official_receipt_failed', ['order_no' => $order['order_no'], 'error' => $e->getMessage()]);
            return false;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'or');
        if ($tmp === false || file_put_contents($tmp, $pdf) === false) {
            return false;
        }
        $ok = (new TelegramService())->sendDocument($token, (string) $order['customer_chat_id'], $tmp,
            OfficialReceiptPdf::fileName($order), '🧾 Official receipt ' . OfficialReceiptPdf::receiptNo($order) . ' · ' . $order['order_no']);
        @unlink($tmp);
        if ($ok) {
            $this->remember($userId, $order, 'assistant', '📄 [Official receipt ' . OfficialReceiptPdf::receiptNo($order) . ' sent as PDF]');
        }
        return $ok;
    }

    /**
     * Send the order's invoice (PDF) to the customer on Telegram. The invoice
     * is created first if the order has none yet. Returns the invoice number.
     *
     * @throws RuntimeException with a message for staff
     */
    public function sendInvoice(int $userId, array $order): string
    {
        if ($order['channel'] !== 'telegram') {
            throw new RuntimeException('This order did not come from Telegram, so the invoice cannot be sent there.');
        }
        $invoice = (new OrderInvoiceService())->createFor($userId, $order);
        if ($invoice === null || empty($invoice['invoice_number'])) {
            throw new RuntimeException('The invoice for this order could not be created. Check Financial > Invoice.');
        }
        $token = $this->botToken($userId);
        if ($token === null) {
            throw new RuntimeException('Telegram is not connected (Customer Assistant > Connections).');
        }

        $items = (new \Src\Models\InvoiceItem())->forInvoice((int) $invoice['id']);
        $pdf   = (new InvoicePdfService())->render($invoice, $items, $userId);
        $tmp   = tempnam(sys_get_temp_dir(), 'inv');
        if ($tmp === false || file_put_contents($tmp, $pdf) === false) {
            throw new RuntimeException('The invoice PDF could not be prepared. Please try again.');
        }
        $number = (string) $invoice['invoice_number'];
        $ok = (new TelegramService())->sendDocument($token, (string) $order['customer_chat_id'], $tmp,
            'Invoice-' . $number . '.pdf', '🧾 Invoice ' . $number . ' · ' . $order['order_no']);
        @unlink($tmp);
        if (!$ok) {
            throw new RuntimeException('Telegram did not accept the invoice. Check the Telegram connection and try again.');
        }
        $this->remember($userId, $order, 'assistant', '📄 [Invoice ' . $number . ' sent as PDF]');
        FlowLog::write('order.invoice_sent', ['order_no' => $order['order_no'], 'invoice' => $number]);

        return $number;
    }

    /** The saved Telegram bot token of this business, or null. */
    private function botToken(int $userId): ?string
    {
        $integration = (new TelegramIntegration())->find($userId);
        if ($integration === null || empty($integration['bot_token_encrypted'])) {
            return null;
        }
        try {
            return Crypto::decrypt((string) $integration['bot_token_encrypted']);
        } catch (RuntimeException $e) {
            return null;
        }
    }

    /** Staff rejected the bank reference: the customer can send a new one. */
    public function reject(int $userId, array $order, string $reason): array
    {
        if ($order['status'] !== 'payment_submitted') {
            throw new RuntimeException('Only orders with a bank reference to review can be rejected.');
        }

        $reason = trim($reason);
        $order  = (new Order())->update($userId, (int) $order['id'], [
            'status' => 'payment_rejected', 'reviewed_at' => date('c'),
            'review_note' => $reason !== '' ? mb_substr($reason, 0, 300) : null,
        ]) ?? $order;

        $text = "⚠️ We could not verify the payment for order {$order['order_no']}."
            . ($reason !== '' ? "\nReason: {$reason}" : '')
            . "\nPlease send a clear photo or PDF of your bank reference (transfer slip) here, or reply if you need help.";
        $sent = $this->notifyCustomer($userId, $order, $text);
        $this->remember($userId, $order, 'assistant', $text);

        FlowLog::write('order.rejected', ['order_no' => $order['order_no'], 'reason' => $reason, 'customer_notified' => $sent]);

        return ['order' => $order, 'notified' => $sent];
    }

    /** Staff sent the order out: "Shipped", the customer is told on Telegram. */
    public function ship(int $userId, array $order): array
    {
        if ($order['status'] !== 'paid') {
            throw new RuntimeException('Only orders with a successful payment can be marked as shipped.');
        }

        $order = (new Order())->update($userId, (int) $order['id'], ['status' => 'shipped', 'shipped_at' => date('c')]) ?? $order;
        $text  = "📦 Your order {$order['order_no']} has been shipped!\nThank you for shopping with us. Reply here if you have any questions.";
        $sent  = $this->notifyCustomer($userId, $order, $text);
        $this->remember($userId, $order, 'assistant', $text);

        FlowLog::write('order.shipped', ['order_no' => $order['order_no'], 'customer_notified' => $sent]);

        return ['order' => $order, 'notified' => $sent];
    }

    /** Staff cancelled the order. */
    public function cancel(int $userId, array $order): array
    {
        if (in_array($order['status'], ['paid', 'shipped', 'cancelled'], true)) {
            throw new RuntimeException('Paid, shipped or cancelled orders cannot be cancelled.');
        }

        $order = (new Order())->update($userId, (int) $order['id'], ['status' => 'cancelled', 'reviewed_at' => date('c')]) ?? $order;
        (new OrderInvoiceService())->cancelForOrders($userId, [(string) $order['order_no']]);
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
