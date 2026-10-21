# Telegram → Website → Flowise → Telegram

How a customer's Telegram message becomes an AI reply, and what Flowise must
provide. The website side is built; Flowise is plugged in through `.env`.

```
Customer ──Telegram──► POST /telegram/webhook?bot=<id>            (TelegramWebhookController)
                         │  1. bot id → telegram_integrations → user_id (the business)
                         │  2. check X-Telegram-Bot-Api-Secret-Token
                         │  3. skip repeats (telegram_updates), answer Telegram 200 at once
                         ▼
                       AssistantReplyService
                         │  4. AssistantConfigService: company_profiles + ai_assistant_settings
                         │  5. SystemMessageBuilder: src/Prompts/system_message.md filled in
                         ▼
                       FlowiseService ──► POST {FLOWISE_API_URL}/api/v1/prediction/{chatflow}
                         │                    (model, temperature, key, system message, vars)
                         │              ◄── {"text": "..."}      ▲
                         │                                        │ custom tool calls
                         │                    POST /tools/check_product (ToolController)
                         ▼
Customer ◄─Telegram── TelegramService::sendText   (+ chat_messages log)
```

The website **Chat Interface** (AI Chatbot → Customer → Chat Interface) uses the
same pipeline, so it can be tested without Telegram. Its "View system message"
button shows the exact system message and Flowise request (secrets masked).

## Files

| Part | File |
|---|---|
| Webhook (Telegram → app) | `src/Controllers/TelegramWebhookController.php` |
| Register / remove webhook | `src/Services/TelegramWebhookService.php`, called on Connect/Disconnect in AI Settings, and `bin/telegram-webhook.php` |
| Load configuration | `src/Services/AssistantConfigService.php` |
| System message template | `src/Prompts/system_message.md` (edit wording here) |
| Fill the template | `src/Services/SystemMessageBuilder.php` |
| Call Flowise | `src/Services/FlowiseService.php` (`payload()` and `parseReply()`) |
| One message → one reply | `src/Services/AssistantReplyService.php` |
| Tools for Flowise | `src/Controllers/ToolController.php` (check_product, buy_product), token: `src/Support/ToolToken.php` |
| Orders, receipts, approval | `src/Services/OrderService.php`, `src/Controllers/OrderController.php`, `database/migrations/009_create_orders.sql` |
| Tables | `database/migrations/007_create_telegram_messaging.sql` |

## Where each template value comes from

| Placeholder | Source |
|---|---|
| `{{assistant_name}}` | `ai_assistant_settings.assistant_name` |
| `{{personality}}` | `ai_assistant_settings.personality` |
| `{{tone}}` | `ai_assistant_settings.tone_description`, or a default for the personality |
| `{{company_name}}`, `{{business_category}}` | `company_profiles` |
| `{{company_background}}` | `company_profiles.company_background` + `company_description` |
| `{{customer_service}}`, `{{booking_sop}}`, `{{salesman_sop}}` | `ai_assistant_settings.system_instructions`, split by its `### Customer Service SOP` / `### Booking SOP` / `### Salesman SOP` headings |

An empty SOP is replaced by a short "no SOP provided" line. Text outside those
headings is added as "6. Additional Instructions".

## The request Flowise receives

```json
POST {FLOWISE_API_URL}/api/v1/prediction/{FLOWISE_CHATFLOW_ID}
Authorization: Bearer {FLOWISE_API_KEY}

{
  "question": "How much is nasi lemak?",
  "overrideConfig": {
    "sessionId": "telegram:<user id>:<telegram chat id>",
    "systemMessage": "# AI Assistant System Message ...",
    "modelName": "claude-haiku-5-5",
    "temperature": 0.4,
    "vars": {
      "company_id": "1",
      "company_name": "Kedai Makan Yiqi",
      "assistant_name": "Mak Cik Bot",
      "provider": "Anthropic",
      "model": "claude-haiku-5-5",
      "provider_api_key": "sk-ant-...",
      "tool_base_url": "https://<PUBLIC_APP_URL>/tools",
      "tool_token": "<signed, valid 30 minutes>",
      "channel": "telegram",
      "customer_chat_id": "777",
      "customer_name": "Ali",
      "customer_username": "ali88",
      "attachment_file_id": "...",  "attachment_name": "receipt.pdf",  "attachment_type": "application/pdf"
    }
  }
}
```

`sessionId` is one per customer conversation, so Flowise memory keeps the
chat history. The `attachment_*` vars are only sent when the customer sent a file.
The reply is read from `text` (or `answer` / `output` / `json`).

## Setting up the chatflow (when Flowise is ready)

1. Build a chatflow with a **Tool Agent** (or Conversational Agent), a chat
   model node, a **Buffer Memory** node, and a **Custom Tool** `check_product`.
2. In the chatflow's **Configuration → Security → Override Configuration**,
   enable overriding: `systemMessage` (agent), `modelName` and `temperature`
   (chat model), `sessionId`, and the `vars` above.
3. **Provider key.** `provider_api_key` is sent in `vars`. Check how your chat
   model node can use it. Chat model nodes usually read keys from a saved
   Flowise credential. If that can't be overridden per request, store each
   business's key as a Flowise credential and set `FLOWISE_SEND_PROVIDER_KEY=false`.
4. **Two providers** (Anthropic, DeepSeek) usually need different chat model
   nodes: make one chatflow per provider and set `FLOWISE_CHATFLOW_ID_ANTHROPIC`
   and `FLOWISE_CHATFLOW_ID_DEEPSEEK`.
5. Put the URL, chatflow ID and API key in `.env`, then open the Chat
   Interface page and send a test message.

### Custom tool 1: `check_product`

In Flowise: **Tools → Create → Custom Tool**, then add it to the agent node.
The Tool Name must be exactly `check_product` / `buy_product` (no spaces).

**Tool Name**
```
check_product
```

**Tool Description**
```
Look up this shop's products and services: name, category, description, normal price, promotion price, the final price to pay and availability. Use it whenever the customer asks about a product, a price, a promotion or what is available, and before placing an order. Only quote prices and products that this tool returns; never invent any.
```

**Input Schema**

| Property | Type | Description | Required |
|---|---|---|---|
| `query` | string | Product name, SKU or keyword the customer mentioned. Leave empty to list all products. | no |
| `category` | string | Category name to filter by, e.g. "Food". | no |

**JavaScript Function**
```javascript
// Flowise runs tools in a sandbox without a global fetch: use node-fetch.
const httpFetch = typeof globalThis.fetch === 'function' ? globalThis.fetch : require('node-fetch');
const query = typeof $query !== 'undefined' && $query ? String($query) : '';
const category = typeof $category !== 'undefined' && $category ? String($category) : '';

const res = await httpFetch($vars.tool_base_url + '/check_product', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer ' + $vars.tool_token,
    'ngrok-skip-browser-warning': 'true'
  },
  body: JSON.stringify({ query, category })
});
return await res.text();
```

### Custom tool 2: `buy_product`

**Tool Name**
```
buy_product
```

**Tool Description**
```
Place an order for the customer in this chat. Call it ONCE per order, and only after the customer has clearly confirmed the exact products and quantities (check them with check_product first). Never call it again after it succeeded. Include only the items of this order, not items from earlier paid orders. It creates an order that is awaiting payment and returns the order number, the items, the total and the payment instructions. Tell the customer all of these and ask them to send a photo or PDF of the payment receipt in this chat. Never say the order is paid or confirmed: staff verify the receipt first. If it returns success=false, explain the message to the customer and ask again.
```

**Input Schema**

| Property | Type | Description | Required |
|---|---|---|---|
| `items` | string | The products and quantities, using the exact product names or SKUs from check_product, e.g. "Nasi Lemak Special x2, Teh Tarik x1". | yes |
| `phone` | string | Customer's phone number, if they gave one. | no |
| `note` | string | Pickup or delivery details, or other notes for the order. | no |

**JavaScript Function**
```javascript
// Flowise runs tools in a sandbox without a global fetch: use node-fetch.
const httpFetch = typeof globalThis.fetch === 'function' ? globalThis.fetch : require('node-fetch');
const items = typeof $items !== 'undefined' && $items ? String($items) : '';
const phone = typeof $phone !== 'undefined' && $phone ? String($phone) : '';
const note = typeof $note !== 'undefined' && $note ? String($note) : '';

const res = await httpFetch($vars.tool_base_url + '/buy_product', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer ' + $vars.tool_token,
    'ngrok-skip-browser-warning': 'true'
  },
  body: JSON.stringify({
    items,
    phone,
    note,
    customer_name: $vars.customer_name || '',
    customer_username: $vars.customer_username || ''
  })
});
return await res.text();
```

The tool token is signed for this business **and this customer chat**, so an
order is always created for the customer who is chatting (the AI cannot be
talked into ordering for someone else). It expires after 30 minutes; every new
message gets a fresh one.

### After `buy_product`: payment receipt and approval

1. The customer sends the receipt (photo or PDF) in Telegram. The website does
   not pass it to the AI: it attaches it to the customer's latest order that
   is awaiting payment (`OrderService::attachReceipt`), thanks the customer and
   forwards the receipt with the order details to the staff chat.
2. Staff open **AI Chatbot → Customer → Orders**, click **View receipt**, then
   **Approve** or **Reject** (with a reason).
3. Approve: the order becomes *Paid* and the customer gets
   "✅ Payment verified!" on Telegram. Reject: the customer is asked for a new
   receipt (sending one puts the order back to *Receipt to review*).

Payment details given to the customer come from **Company Information → How
customers pay**.

## Telegram needs a public https address

Telegram can't call `http://localhost`. For XAMPP:

1. Run a tunnel, e.g. `ngrok http 80`. It gives `https://abc123.ngrok-free.app`.
2. In `.env`: `PUBLIC_APP_URL=https://abc123.ngrok-free.app/SDC-Hackathon/public`
3. Connect (or reconnect) the bot in AI Settings, or run
   `C:\xampp\php\php.exe bin\telegram-webhook.php`. Run it again whenever the
   tunnel address changes. `bin\telegram-webhook.php status` shows what
   Telegram has, including its last error.

## Not built yet

- Booking tools: `GoogleCalendarService::freeSlots()` exists; creating the
  calendar event does not.
- Stock: `products` has no stock column, so `check_product` reports active
  products as `available` and orders do not reduce stock.
