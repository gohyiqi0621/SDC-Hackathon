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
| Tools for Flowise | `src/Controllers/ToolController.php` (check_product, buy_product, check_availability, make_booking), token: `src/Support/ToolToken.php` |
| Bookings (Google Calendar) | `src/Services/BookingService.php`, `src/Controllers/BookingController.php`, `database/migrations/013_create_bookings.sql` |
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
Look up this shop's products and services: name, category, description, price and availability. Use it whenever the customer asks about a product, a price, a promotion or what is available, and before placing an order. Only quote prices and products that this tool returns; never invent any.
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

### Custom tool 3: `check_availability`

**Tool Name**
```
check_availability
```

**Tool Description**
```
Check if a date and time can be booked for a number of people, using the business's Google Calendar and booking rules (opening hours, slot length, how many customers fit in one slot). Use it before every booking. Give the date as YYYY-MM-DD and the time in 12-hour format with AM/PM (e.g. 10:00 AM, 2:30 PM; noon is 12:00 PM, midnight is 12:00 AM) in the business's timezone. It returns available true/false; when false it gives the reason and up to 3 other free times to offer. If the customer has no preferred time or asks what times are free, leave time empty: it returns the real free times. Never offer a time this tool did not return. It does NOT book anything.
```

**Input Schema**

| Property | Type | Description | Required |
|---|---|---|---|
| `date` | string | Date in YYYY-MM-DD, e.g. 2026-10-22. | yes |
| `time` | string | Start time in 12-hour format with AM/PM, e.g. 10:00 AM, 2:30 PM, 12:00 PM (noon). Leave empty to list the free times. | no |
| `pax` | number | Number of people. Use 1 if the customer did not say. | yes |

**JavaScript Function**
```javascript
const httpFetch = typeof globalThis.fetch === 'function' ? globalThis.fetch : require('node-fetch');
const res = await httpFetch($vars.tool_base_url + '/check_availability', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer ' + $vars.tool_token,
    'ngrok-skip-browser-warning': 'true'
  },
  body: JSON.stringify({
    date: typeof $date !== 'undefined' ? String($date) : '',
    time: typeof $time !== 'undefined' ? String($time) : '',
    pax: typeof $pax !== 'undefined' && $pax ? Number($pax) : 1
  })
});
return await res.text();
```

### Custom tool 4: `make_booking`

**Tool Name**
```
make_booking
```

**Tool Description**
```
Book a time slot for the customer in this chat on the business's Google Calendar. Call it ONCE, only after check_availability said the time is available AND the customer clearly confirmed the date, time, number of people and name. Never call it again after it succeeded. It checks the slot again and returns the booking number, or success=false with the reason and other free times. Never say the booking is confirmed unless it returned success=true.
```

**Input Schema**

| Property | Type | Description | Required |
|---|---|---|---|
| `date` | string | Date in YYYY-MM-DD. | yes |
| `time` | string | Start time in 12-hour format with AM/PM, e.g. 7:30 PM. | yes |
| `pax` | number | Number of people. | yes |
| `customer_name` | string | Name for the booking. | yes |
| `phone` | string | Customer's phone number, if given. | no |
| `note` | string | Special requests (birthday, baby chair, topic of the meeting...). | no |

**JavaScript Function**
```javascript
const httpFetch = typeof globalThis.fetch === 'function' ? globalThis.fetch : require('node-fetch');
const v = (x) => (typeof x !== 'undefined' && x !== null ? String(x) : '');
const res = await httpFetch($vars.tool_base_url + '/make_booking', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer ' + $vars.tool_token,
    'ngrok-skip-browser-warning': 'true'
  },
  body: JSON.stringify({
    date: v(typeof $date !== 'undefined' ? $date : ''),
    time: v(typeof $time !== 'undefined' ? $time : ''),
    pax: typeof $pax !== 'undefined' && $pax ? Number($pax) : 1,
    customer_name: v(typeof $customer_name !== 'undefined' ? $customer_name : '') || $vars.customer_name || '',
    phone: v(typeof $phone !== 'undefined' ? $phone : ''),
    note: v(typeof $note !== 'undefined' ? $note : ''),
    customer_username: $vars.customer_username || ''
  })
});
return await res.text();
```

**Remove the Flowise "Google Calendar" tool node** from the chatflow when you
add these two: the website now talks to Google Calendar itself (with the
account connected in AI Settings → Google Calendar), so the AI must not create
events on its own.

### Custom tool 5: `send_official_receipt`
Description: *Send the customer their official receipt (PDF) again in this chat. Use when the customer asks for their
receipt / official receipt / invoice for an order that is already paid. Only works after staff verified the payment.*

| Property | Type | Description | Required |
|---|---|---|---|
| `order_no` | string | The order number, e.g. ORD-261021-25NJ (empty: the customer's latest paid order) | no |

```javascript
const httpFetch = typeof globalThis.fetch === 'function' ? globalThis.fetch : require('node-fetch');
const res = await httpFetch($vars.tool_base_url + '/send_official_receipt', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + $vars.tool_token, 'ngrok-skip-browser-warning': 'true' },
  body: JSON.stringify({ order_no: String(typeof $order_no !== 'undefined' && $order_no !== null ? $order_no : '') })
});
return await res.text();
```

The official receipt is also sent automatically when staff approve the payment, and staff can resend it with
**Send receipt** on the Orders page.

### How a slot is counted (AI Settings → Google Calendar → Booking rules)

| Setting | Effect |
|---|---|
| One customer per slot | Any timed event in the calendar at that time makes it unavailable (office, clinic, salon). |
| Many customers per slot | The slot is full at *Slot is full at*; counted by **people** (pax of each booking) or **bookings** (each = 1). Bookings made here carry their pax; events you add by hand count as 1, or the number in the title ("Ali 4 pax"). |
| Each booking lasts | Slot length; the event's end time. |
| Opens / Closes / Open days | Times outside them are refused; the last start is closing time minus the slot length. |
| All-day event "Closed", "Tutup", "Cuti", "Holiday", "Full" | Closes that day. Other all-day events are ignored. |
| Event marked "Free" in Google Calendar | Ignored. |

`make_booking` checks again inside a database lock, so two customers asking
for the last place at the same moment cannot both get it. Bookings are listed
under **AI Chatbot → Customer → Bookings**; cancelling one there deletes the
Google event and tells the customer on Telegram.

## Staff assistant (Staff > Chat Interface)

Staff chat with their own assistant about the business's records. Flow:
`Staff > Chat Interface -> POST staff/chat_send -> StaffAssistantService -> Flowise (staff chatflow) -> reply`.

- **Settings:** Staff > Setting & Integration (`staff_ai_assistant_settings`): name, personality, tone, AI model,
  temperature, key, and the **Functions** ticked (`check_email`, `check_invoice`, `check_claims`, `check_orders`).
- **Chatflow:** `FLOWISE_STAFF_CHATFLOW_ID` (Secret / .env). Not set: `FLOWISE_CHATFLOW_ID` is used.
- **Chat sessions:** "New chat" starts a new session; earlier chats stay under **Chats** and can be reopened.
  Each session has its own `chat_id` (`staff-<user>` or `staff-<user>-<session>`) and Flowise memory.
- **overrideConfig** sent: `sessionId` (`staff:<user>:<chat_id>`), `systemMessage` (src/Prompts/staff_system_message.md,
  lists only the ticked tools; src/Prompts/staff_email_rules.md is added when Check email is ticked), `modelName`, `temperature`, and `vars`: `tool_base_url`, `tool_token` (a **staff** token),
  `channel` = `staff`, `staff_name`, `functions`, `company_name`, `assistant_name`, `provider`, `model`, `provider_api_key`.
- **Tools:** they refuse customer tokens (403) and functions that are not ticked (success=false). Invoices, claims and
  orders are read-only; the only tool that changes anything is `send_email_reply` (see below).
- **History:** `chat_messages` with channel `staff` (migration 017); never shown in Customer > Chat Interface.

### Staff tool: `check_invoice`
Description: *Look up the business's invoices. Use for any question about invoices, amounts owed, unpaid, partially paid,
paid or overdue invoices. Returns totals (count, total amount, outstanding, overdue count) and the newest matching invoices.*

| Property | Type | Description | Required |
|---|---|---|---|
| `status` | string | unpaid, partial, paid, overdue, outstanding (not paid) or all | no |
| `period` | string | today, yesterday, this_week, last_week, last_7_days, last_30_days, this_month, last_month, this_year (by issue date) | no |
| `date_from` | string | YYYY-MM-DD (issue date from) | no |
| `date_to` | string | YYYY-MM-DD (issue date to) | no |
| `query` | string | Invoice number or party name | no |

### Staff tool: `check_claims`
Description: *Look up staff expense claims. Use for questions about claims, who claimed what, pending approval, approved,
rejected or reimbursed claims. Returns totals by status and the newest matching claims.*

| Property | Type | Description | Required |
|---|---|---|---|
| `status` | string | submitted, pending (= submitted), approved, rejected, reimbursed or all | no |
| `category` | string | travel, meals, office or other | no |
| `claimant` | string | Name of the person, or words in the claim title | no |
| `period` | string | Same values as check_invoice (by expense date) | no |
| `date_from` / `date_to` | string | YYYY-MM-DD | no |

### Staff tool: `check_orders`
Description: *Look up customer orders placed through the AI chatbot. Use for questions about orders, sales, receipts to
review, orders to ship, or orders on a date or in a period. Returns the count, total, counts per status, totals per day
and the newest matching orders.*

| Property | Type | Description | Required |
|---|---|---|---|
| `status` | string | pending_payment, payment_submitted (to_review), paid (to_ship), shipped, payment_rejected, cancelled or all | no |
| `period` | string | today, yesterday, this_week, last_week, last_7_days, last_30_days, this_month, last_month, this_year | no |
| `date` | string | One day, YYYY-MM-DD | no |
| `date_from` / `date_to` | string | YYYY-MM-DD | no |
| `query` | string | Order number, customer name or phone | no |
| `product` | string | Product name | no |

**JavaScript Function** (same for all three; change the path at the end of the URL):
```javascript
const httpFetch = typeof globalThis.fetch === 'function' ? globalThis.fetch : require('node-fetch');
const body = {};
for (const [k, v] of Object.entries({
  status: typeof $status !== 'undefined' ? $status : '',
  period: typeof $period !== 'undefined' ? $period : '',
  date: typeof $date !== 'undefined' ? $date : '',
  date_from: typeof $date_from !== 'undefined' ? $date_from : '',
  date_to: typeof $date_to !== 'undefined' ? $date_to : '',
  query: typeof $query !== 'undefined' ? $query : '',
  category: typeof $category !== 'undefined' ? $category : '',
  claimant: typeof $claimant !== 'undefined' ? $claimant : '',
  product: typeof $product !== 'undefined' ? $product : ''
})) { if (v !== null && v !== undefined && String(v) !== '') body[k] = String(v); }

const res = await httpFetch($vars.tool_base_url + '/check_orders', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + $vars.tool_token, 'ngrok-skip-browser-warning': 'true' },
  body: JSON.stringify(body)
});
return await res.text();
```

### Staff tool for payment receipts (Function: Check orders)
`review_receipt` shows the receipt picture the customer sent and the order on a card in the staff chat. The staff
member looks at the picture and clicks **Approve payment** or **Reject** on the card (POST staff/order_decide, same
as the Orders page; the customer is told on Telegram). The AI does not read, judge, approve or reject receipts.

**`review_receipt`** - Description: *Show the payment receipt picture of an order in the chat so the staff member can
check it and approve or reject it. Use when staff ask to see / check a receipt or a payment waiting for review.*

| Property | Type | Description | Required |
|---|---|---|---|
| `order_no` | string | Order number, e.g. ORD-261021-25NJ (the last part is enough). Empty: the oldest receipt waiting | no |

### Staff tools for email (Function: Check email)
They use the Gmail account connected on the **Dashboard** (Connect Gmail). Add all four tools to the staff chatflow.

**`check_email`** - Description: *List emails in the connected Gmail. Use when staff ask to see, find or categorise
emails (important, unread, sent, spam, promotions...). Returns sender, subject, date, snippet and an id for each email.*

| Property | Type | Description | Required |
|---|---|---|---|
| `folder` | string | inbox, important, unread, starred, sent, spam, trash, primary, social, promotions, updates or all (default inbox) | no |
| `query` | string | Words or Gmail search, e.g. `from:ali`, `invoice`, `has:attachment` | no |
| `period` | string | today, yesterday, this_week, last_week, last_7_days, last_30_days, this_month, last_month, this_year | no |
| `date_from` / `date_to` | string | YYYY-MM-DD | no |
| `limit` | number | How many to list, 1-25 (default 10) | no |

**`read_email`** - Description: *Open one email by its id (from check_email) to read the full text. Use when staff
want to see, summarise or reply to a specific email.*

| Property | Type | Description | Required |
|---|---|---|---|
| `id` | string | The email id from check_email | yes |

**`send_email_reply`** - Description: *Send a reply to an email, in the same thread, from the connected Gmail. Use ONLY
after the staff member has seen the exact final draft and clearly said to send it.*

| Property | Type | Description | Required |
|---|---|---|---|
| `id` | string | The email id being replied to | yes |
| `body` | string | The final reply text, exactly as shown to the staff member (without "> ") | yes |

**`send_email`** - Description: *Send a NEW email (not a reply) from the connected Gmail. Use when staff want to write
to someone. Use ONLY after the staff member has seen the draft with the To address and subject and clearly said to send it.*

| Property | Type | Description | Required |
|---|---|---|---|
| `to` | string | Recipient email address (several: separate with commas) | yes |
| `subject` | string | Email subject | yes |
| `body` | string | The message exactly as shown to the staff member (without "> ") | yes |
| `cc` | string | CC addresses, separated by commas | no |

The address must also appear in the chat (shown in the draft or typed by staff), or it is not sent.

The website also checks before sending: the staff member's latest message must say to send (send / yes / ok / hantar /
发送...) and not "wait / don't", and the text must be the draft the assistant just showed (or the text the staff member
typed with **Edit**). Otherwise it answers `NOT SENT ...` and nothing is sent.

**JavaScript Function** for the three email tools (change the path for each tool):
```javascript
const httpFetch = typeof globalThis.fetch === 'function' ? globalThis.fetch : require('node-fetch');
const body = {};
for (const [k, v] of Object.entries({
  folder: typeof $folder !== 'undefined' ? $folder : '',
  query: typeof $query !== 'undefined' ? $query : '',
  period: typeof $period !== 'undefined' ? $period : '',
  date_from: typeof $date_from !== 'undefined' ? $date_from : '',
  date_to: typeof $date_to !== 'undefined' ? $date_to : '',
  limit: typeof $limit !== 'undefined' ? $limit : '',
  id: typeof $id !== 'undefined' ? $id : '',
  body: typeof $body !== 'undefined' ? $body : ''
})) { if (v !== null && v !== undefined && String(v) !== '') body[k] = String(v); }

const res = await httpFetch($vars.tool_base_url + '/check_email', {   // or /read_email, /send_email_reply
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + $vars.tool_token, 'ngrok-skip-browser-warning': 'true' },
  body: JSON.stringify(body)
});
return await res.text();
```

## Telegram needs a public https address

Telegram can't call `http://localhost`. For XAMPP:

1. Run a tunnel, e.g. `ngrok http 80`. It gives `https://abc123.ngrok-free.app`.
2. In `.env`: `PUBLIC_APP_URL=https://abc123.ngrok-free.app/SDC-Hackathon/public`
3. Connect (or reconnect) the bot in AI Settings, or run
   `C:\xampp\php\php.exe bin\telegram-webhook.php`. Run it again whenever the
   tunnel address changes. `bin\telegram-webhook.php status` shows what
   Telegram has, including its last error.

## Not built yet

- Stock: `products` has no stock column, so `check_product` reports active
  products as `available` and orders do not reduce stock.
