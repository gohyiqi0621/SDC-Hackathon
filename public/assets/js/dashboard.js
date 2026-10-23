
/* ============================================================
   SMART SME — AI EMAIL DASHBOARD
   Complete dashboard.js replacement

   Features:
   - Real Gmail Inbox
   - Real Gmail Sent
   - Full Gmail conversations
   - AI summaries and AI reply generation
   - Gmail reply sending
   - Attachments and signatures
   - Smart notifications
   - Follow-up approval and activity history
   - Search, sorting, filtering and stars
   - Customizable drag-and-drop dashboard
============================================================ */

document.addEventListener("DOMContentLoaded", () => {
    "use strict";

    /* ========================================================
       1. ELEMENTS AND CONFIGURATION
    ======================================================== */

    const root = document.getElementById("dashboardRoot");
    if (!root) return;

    const $ = id => document.getElementById(id);

    const emailList = $("emailList");
    const emailBody = $("emailBody");
    const replyDraft = $("replyDraft");

    const gmailInboxUrl = root.dataset.gmailInbox || "";
    const gmailMessageUrl = root.dataset.gmailMessage || "";
    const gmailSentUrl = root.dataset.gmailSent || "";
    const gmailThreadUrl = root.dataset.gmailThread || "";
    const gmailSendUrl = root.dataset.gmailSend || "";
    const gmailActionUrl = root.dataset.gmailAction || "";
    const gmailSpamUrl = root.dataset.gmailSpam || "";
    const gmailTrashUrl = root.dataset.gmailTrash || "";        

    const aiSummarizeUrl = root.dataset.aiSummarize || "";
    const aiRegenerateUrl = root.dataset.aiRegenerate || "";

    const csrfToken =
        root.dataset.csrfToken ||
        document.querySelector('meta[name="csrf-token"]')?.content ||
        "";

    const userId = root.dataset.userId || "guest";

    const notify = (message, type = "info") => {
        if (window.App && typeof window.App.toast === "function") {
            window.App.toast(message, type);
        } else {
            console.log(`[${type}] ${message}`);
        }
    };

    const escapeHTML = value =>
        String(value ?? "").replace(/[&<>"']/g, char => ({
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#39;"
        })[char]);

    const initials = name =>
        String(name || "?")
            .trim()
            .split(/\s+/)
            .map(word => word[0] || "")
            .join("")
            .slice(0, 2)
            .toUpperCase();

    const senderName = from =>
        String(from || "")
            .replace(/<[^>]*>/g, "")
            .replace(/"/g, "")
            .trim() || "Unknown";

    const formatTime = unix => {
        if (!unix) return "";

        const date = new Date(Number(unix) * 1000);
        if (Number.isNaN(date.getTime())) return "";

        const now = new Date();

        if (date.toDateString() === now.toDateString()) {
            return date.toLocaleTimeString([], {
                hour: "numeric",
                minute: "2-digit"
            });
        }

        const yesterday = new Date(now);
        yesterday.setDate(now.getDate() - 1);

        if (date.toDateString() === yesterday.toDateString()) {
            return "Yesterday";
        }

        return date.toLocaleDateString([], {
            month: "short",
            day: "numeric"
        });
    };

    async function requestJSON(url, options = {}) {
        if (!url) {
            throw new Error("API URL is missing.");
        }

        const response = await fetch(url, {
            credentials: "same-origin",
            ...options,
            headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
                ...(options.headers || {})
            }
        });

        const result = await response.json().catch(() => null);

        if (!response.ok || !result || !result.success) {
            throw new Error(
                result?.message ||
                `Request failed (HTTP ${response.status}).`
            );
        }

        return result.data || {};
    }

    /* ========================================================
       3. APPLICATION STATE
    ======================================================== */

    let emails = [];
    let sentEmails = [];
    let spamEmails = [];
    let trashEmails = [];

    let selectedEmailId = null;
    let activeFilter = "all";
    let searchQuery = "";
    let newestFirst = true;
    let selectionVersion = 0;

    let replyAttachments = [];
    let draftSaveTimer = null;

    const selectedEmail = () =>
    [...emails, ...sentEmails, ...spamEmails, ...trashEmails].find(
        email => email.id === selectedEmailId
    ) || null;

    const draftKeyPrefix =
        "smartSME_reply_draft_" + userId + "_";

    const signatureKey =
        "smartSME_reply_signature_" + userId;

    const workflowKey =
        "smartSMEEmailWorkflow:" + userId;

    const layoutKey =
        "smartSME_dashboard_layout_v1_" + userId;

    // Gmail labels are authoritative; never override them with localStorage.
    const gmailMutationBusy = new Set();

    async function gmailAction(action, email, extra = {}) {
        if (!gmailActionUrl) {
            throw new Error("Gmail actions are not configured.");
        }

        if (!email || !email.id) {
            throw new Error("Please select an email.");
        }

        if (gmailMutationBusy.has(email.id)) return false;

        gmailMutationBusy.add(email.id);

        try {
            const data = await requestJSON(gmailActionUrl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded"
                },
                body: new URLSearchParams({
                    csrf_token: csrfToken,
                    messageId: email.id,
                    action,
                    ...extra
                }).toString()
            });

            return data;
        } finally {
            gmailMutationBusy.delete(email.id);
        }
    }
    
    /* ========================================================
       4. GMAIL EMAIL ACTIONS
    ======================================================== */

    async function toggleEmailStar(email) {
        if (!email) return;

        const desired = !email.starred;

        try {
            await gmailAction(
                desired ? "star" : "unstar",
                email
            );

            email.starred = desired;

            updateStats();
            renderEmails();
            renderNotifications();
            updateSelectedStar();

            notify(
                desired
                    ? "Starred in Gmail."
                    : "Star removed in Gmail.",
                "success"
            );
        } catch (error) {
            notify(error.message, "error");
        }
    }

    function updateSelectedStar() {
        const email = selectedEmail();
        const icon = document.querySelector("#starEmailBtn i");

        if (!icon || !email) return;

        icon.className = email.starred
            ? "fa-solid fa-star"
            : "fa-regular fa-star";

        icon.style.color = email.starred
            ? "#f4b42e"
            : "";
    }

    async function markEmailAsRead(email) {
        if (!email || email.sent || !email.unread) return;

        try {
            await gmailAction("read", email);

            email.unread = false;

            updateStats();
            renderEmails();
        } catch (error) {
            console.warn(
                "Could not mark email as read:",
                error
            );

            notify(
                "Unable to mark as read: " + error.message,
                "error"
            );
        }
    }

    /* ========================================================
       5. FOLLOW-UP WORKFLOW STORAGE
    ======================================================== */

    function loadWorkflow() {
        try {
            const saved = JSON.parse(
                localStorage.getItem(workflowKey) || "null"
            );

            return {
                tasks: Array.isArray(saved?.tasks)
                    ? saved.tasks
                    : [],
                activityLog: Array.isArray(saved?.activityLog)
                    ? saved.activityLog
                    : []
            };
        } catch {
            return {
                tasks: [],
                activityLog: []
            };
        }
    }

    const workflow = loadWorkflow();

    function saveWorkflow() {
        localStorage.setItem(
            workflowKey,
            JSON.stringify(workflow)
        );
    }

    /* ========================================================
       6. GMAIL DATA MAPPING
    ======================================================== */

    function categorise(text, isSpam = false) {
        if (isSpam) return "Spam";

        if (
            /invoice|payment|bill|receipt|quotation|refund|pay\b/i
                .test(text)
        ) {
            return "Finance";
        }

        if (
            /meeting|schedule|appointment|call|zoom|teams/i
                .test(text)
        ) {
            return "Meeting";
        }

        if (
            /order|delivery|shipping|tracking/i
                .test(text)
        ) {
            return "Orders";
        }

        if (
            /partnership|collaborat|proposal/i
                .test(text)
        ) {
            return "Partnership";
        }

        return "General";
    }

    function fromGmail(mail) {
        const text =
            `${mail.subject || ""} ${mail.preview || ""}`;

        const category = categorise(
            text,
            mail.isSpam
        );

        const sender = senderName(
            mail.sender || mail.from || "Unknown"
        );

        return {
            id: String(mail.id || ""),
            threadId: mail.threadId || "",

            sender,

            senderEmail:
                mail.senderEmail ||
                (mail.sender || "").match(/<([^>]+)>/)?.[1] ||
                mail.sender ||
                "",

            from: mail.from || "",
            to: mail.to || "",

            subject: mail.subject || "(No Subject)",
            preview: mail.preview || "",
            body: mail.preview || "",

            summary: [
                mail.preview || "No preview available."
            ],

            category,

            time: formatTime(mail.timestamp),
            timestamp: Number(mail.timestamp) || 0,

            unread: Boolean(mail.unread),
            important: Boolean(mail.important),

            isTrash: Boolean(mail.isTrash),
            isInbox: mail.isInbox !== false,

            starred: Boolean(mail.starred),
            isSpam: Boolean(mail.isSpam),

            needsReply:
                !mail.sent &&
                /\?/.test(text) &&
                !mail.isSpam,

            meeting: category === "Meeting",
            sent: Boolean(mail.sent),

            summaryLoaded: Boolean(mail.sent),
            fullBodyLoaded: false
        };
    }

    function fromGmailSent(mail) {
        return {
            ...fromGmail(mail),

            sender: senderName(
                mail.to || mail.sender || "Unknown"
            ),

            senderEmail:
                mail.to ||
                mail.senderEmail ||
                "",

            from: mail.from || "",
            to: mail.to || "",
            threadId: mail.threadId || "",

            sent: true,
            category: "Sent",
            unread: false,
            needsReply: false,
            meeting: false,
            summaryLoaded: true
        };
    }

    /* ========================================================
       7. DASHBOARD STATISTICS
    ======================================================== */

    function updateStats() {
        const counts = {
            all: emails.length,

            unread: emails.filter(
                email => email.unread
            ).length,

            important: emails.filter(
                email => email.important || email.starred
            ).length,

            sent: sentEmails.length,
            spam: spamEmails.length,
            trash: trashEmails.length
        };

        const newCount = $("newEmailCount");
        const replyCount = $("replyCount");
        const meetingCount = $("meetingCount");
        const importantCount = $("importantCount");

        if (newCount) {
            newCount.textContent = counts.unread;
        }

        if (replyCount) {
            replyCount.textContent =
                emails.filter(
                    email => email.needsReply
                ).length;
        }

        if (meetingCount) {
            meetingCount.textContent =
                emails.filter(
                    email => email.meeting
                ).length;
        }

        if (importantCount) {
            importantCount.textContent =
                counts.important;
        }

        const names = {
            all: "All",
            unread: "Unread",
            important: "Important",
            sent: "Sent",
            spam: "Spam",
            trash: "Trash"
        };

        document.querySelectorAll(".tab").forEach(tab => {
            const filter = tab.dataset.filter;

            if (
                Object.prototype.hasOwnProperty.call(
                    counts,
                    filter
                )
            ) {
                tab.textContent =
                    `${names[filter]} (${counts[filter]})`;
            }
        });
    }

    /* ========================================================
       8. EMAIL LIST RENDERING
    ======================================================== */

    function renderEmails() {
        if (!emailList) return;

        const source =
        activeFilter === "sent" ? sentEmails
        : activeFilter === "spam" ? spamEmails
        : activeFilter === "trash" ? trashEmails
        : emails;

        let filtered = source.filter(email => {
            const text =
                `${email.sender} ${email.subject} ${email.preview}`
                    .toLowerCase();

            if (!text.includes(searchQuery)) {
                return false;
            }

            if (activeFilter === "unread") {
                return email.unread;
            }

            if (activeFilter === "important") {
                return email.important || email.starred;
            }

            return true;
        });

        filtered = [...filtered];

        if (filtered.every(email => email.timestamp)) {
            filtered.sort((a, b) =>
                newestFirst
                    ? b.timestamp - a.timestamp
                    : a.timestamp - b.timestamp
            );
        } else if (!newestFirst) {
            filtered.reverse();
        }

        if (filtered.length === 0) {
            emailList.innerHTML = `
                <p style="padding:25px;color:#888">
                    No emails found.
                </p>
            `;
            return;
        }

        emailList.innerHTML = filtered.map(email => `
            <div class="email-item
                ${email.id === selectedEmailId ? "selected" : ""}"
                data-id="${escapeHTML(email.id)}">

                <span class="email-dot"
                    style="${email.unread ? "" : "visibility:hidden"}">
                </span>

                <div class="email-avatar">
                    ${escapeHTML(initials(email.sender))}
                </div>

                <div class="email-item-content">
                    <div class="email-item-top">
                        <span class="email-sender">
                            ${escapeHTML(
                                email.sent
                                    ? "To: " + email.sender
                                    : email.sender
                            )}
                        </span>

                        <span class="email-date">
                            ${escapeHTML(email.time)}
                        </span>
                    </div>

                    <div class="email-subject">
                        ${escapeHTML(email.subject)}
                    </div>

                    <div class="email-preview">
                        ${escapeHTML(email.preview)}
                    </div>

                    <div class="email-item-middle">
                        <span class="category-badge">
                            ${escapeHTML(email.category)}
                        </span>

                        <button
                            type="button"
                            class="email-star
                                ${email.starred ? "starred" : ""}"
                            data-star-id="${escapeHTML(email.id)}"
                            aria-label="Toggle star">

                            <i class="${
                                email.starred
                                    ? "fa-solid"
                                    : "fa-regular"
                            } fa-star"></i>
                        </button>
                    </div>
                </div>
            </div>
        `).join("");
    }

    /* ========================================================
       9. AI SUMMARY
    ======================================================== */

    function renderSummary(email) {
        const box = $("aiSummary");
        if (!box || !email) return;

        const points = Array.isArray(email.summary)
            ? email.summary
            : [];

        box.innerHTML = `
            <strong>Key Points</strong>
            <ul>
                ${points.map(point =>
                    `<li>${escapeHTML(point)}</li>`
                ).join("")}
            </ul>

            ${
                email.summaryMode === "placeholder"
                    ? '<small>Rule-based preview — AI provider not connected yet.</small>'
                    : ""
            }
        `;
    }

    async function loadGmailDetails(email, version) {
        const summaryBox = $("aiSummary");

        try {
            if (!email.fullBodyLoaded) {
                if (!gmailMessageUrl) {
                    throw new Error(
                        "Gmail message URL is missing."
                    );
                }

                const url = new URL(
                    gmailMessageUrl,
                    window.location.href
                );

                url.searchParams.set("id", email.id);

                const data = await requestJSON(
                    url.toString()
                );

                email.body =
                    typeof data.body === "string"
                        ? data.body
                        : email.preview;

                email.fullBodyLoaded = true;
            }

            if (
                version !== selectionVersion ||
                selectedEmailId !== email.id
            ) {
                return;
            }

            if (emailBody) {
                emailBody.textContent = email.body;
            }

            if (email.summaryLoaded) {
                renderSummary(email);
                renderWorkflow();
                return;
            }

            if (!aiSummarizeUrl) {
                throw new Error(
                    "Summary endpoint is not configured."
                );
            }

            if (summaryBox) {
                summaryBox.textContent =
                    "Preparing summary…";
            }

            const data = await requestJSON(
                aiSummarizeUrl,
                {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        "X-CSRF-Token": csrfToken
                    },
                    body: JSON.stringify({
                        subject: email.subject,
                        body: email.body
                    })
                }
            );

            if (
                version !== selectionVersion ||
                selectedEmailId !== email.id
            ) {
                return;
            }

            email.summary = Array.isArray(data.summary)
                ? data.summary
                : [];

            email.category =
                typeof data.category === "string"
                    ? data.category
                    : email.category;

            email.suggestedAction =
                typeof data.suggestedAction === "string"
                    ? data.suggestedAction
                    : "";

            email.needsReply =
                Boolean(data.needsReply);

            email.meeting =
                email.category === "Meeting";

            email.summaryMode =
                data.mode || "placeholder";

            email.summaryLoaded = true;

            if ($("emailCategory")) {
                $("emailCategory").textContent =
                    email.category;
            }

            renderSummary(email);
            updateStats();
            renderEmails();
            renderWorkflow();
            renderNotifications();

        } catch (error) {
            if (
                version !== selectionVersion ||
                selectedEmailId !== email.id
            ) {
                return;
            }

            console.error(
                "Email details / summary failed:",
                error
            );

            if (summaryBox) {
                summaryBox.textContent =
                    "Unable to generate summary: " +
                    error.message;
            }

            notify(error.message, "error");
        }
    }

    /* ========================================================
       10. FULL GMAIL CONVERSATION
    ======================================================== */

    async function loadGmailThread(email, version) {
        if (!emailBody) return;

        if (!gmailThreadUrl || !email.threadId) {
            emailBody.textContent =
                email.body || "Conversation unavailable.";
            return;
        }

        try {
            const url = new URL(
                gmailThreadUrl,
                window.location.href
            );

            url.searchParams.set(
                "id",
                email.threadId
            );

            const data = await requestJSON(
                url.toString()
            );

            if (
                version !== selectionVersion ||
                selectedEmailId !== email.id
            ) {
                return;
            }

            const messages = Array.isArray(data.messages)
                ? [...data.messages]
                : [];

            messages.sort(
                (a, b) =>
                    Number(a.timestamp || 0) -
                    Number(b.timestamp || 0)
            );

            if (messages.length === 0) {
                emailBody.textContent =
                    "No messages in this conversation.";
                return;
            }

            emailBody.style.whiteSpace = "normal";

            emailBody.innerHTML = `
                <div class="conversation-thread"
                    style="
                        display:flex;
                        flex-direction:column;
                        width:100%;
                        gap:0;
                    ">

                    ${messages.map((message, index) => `
                        ${
                            index > 0
                                ? `<div class="conversation-divider"
                                    style="
                                        height:1px;
                                        background:#e5e7eb;
                                        margin:14px 0;
                                    ">
                                   </div>`
                                : ""
                        }

                        <div class="conversation-message"
                            style="
                                display:flex;
                                flex-direction:column;
                                gap:10px;
                                padding:12px 0;
                                margin:0;
                                height:auto;
                                min-height:0;
                            ">

                            <div class="conversation-message-header"
                                style="
                                    display:flex;
                                    align-items:center;
                                    justify-content:space-between;
                                    flex-wrap:wrap;
                                    gap:8px;
                                    margin:0;
                                ">

                                <strong>
                                    ${escapeHTML(
                                        message.sent
                                            ? `Me (${message.from || ""})`
                                            : message.from || "Unknown"
                                    )}
                                </strong>

                                <span>
                                    to ${escapeHTML(message.to || "")}
                                </span>
                            </div>

                            <div class="conversation-message-body"
                                style="
                                    white-space:pre-wrap;
                                    overflow-wrap:anywhere;
                                    line-height:1.6;
                                    margin:0;
                                    padding:4px 0;
                                    min-height:0;
                                    height:auto;
                                ">${escapeHTML(message.body || "")}</div>

                            ${
                                message.sent
                                    ? `<div class="conversation-sent-status"
                                        style="
                                            font-size:12px;
                                            margin-top:4px;
                                        ">
                                        <i class="fa-solid fa-check-double"></i>
                                        Sent
                                       </div>`
                                    : ""
                            }
                        </div>
                    `).join("")}
                </div>
            `;

        } catch (error) {
            if (
                version !== selectionVersion ||
                selectedEmailId !== email.id
            ) {
                return;
            }

            console.error(
                "Gmail conversation failed:",
                error
            );

            emailBody.textContent =
                "Unable to load conversation: " +
                error.message;

            notify(error.message, "error");
        }
    }

    /* ========================================================
       11. SHOW SELECTED EMAIL
    ======================================================== */

    function showEmail(id) {
        const email = [...emails, ...sentEmails, ...spamEmails, ...trashEmails].find(
            item => item.id === id
        );

        if (!email) return;

        const version = ++selectionVersion;

        selectedEmailId = id;


        document.dispatchEvent(
            new CustomEvent("sme:mascot", {
                detail: {
                    state: email.sent ? "sent" : "reading"
                }
            })
        );


        if (emailBody) {
            emailBody.classList.add("email-body-collapsed");
        }

        const sentView = Boolean(email.sent);

        const aiReplyCard =
            document.querySelector(".ai-reply-card");

        const aiSummaryCard =
            document.querySelector(".ai-summary-card");

        const followupCard =
            document.querySelector(".compact-followup");

        const todoAction =
            document.querySelector(".wp-email-task-action");

        [
            aiReplyCard,
            aiSummaryCard,
            followupCard,
            todoAction
        ].forEach(card => {
            if (!card) return;

            card.hidden = sentView;
            card.style.display =
                sentView ? "none" : "";
        });

        // Update Gmail unread status through the backend.
        // Errors are handled by markEmailAsRead().
        if (!sentView && email.unread) {
            void markEmailAsRead(email);
        }

        if ($("emailSubject")) {
            $("emailSubject").textContent =
                email.subject;
        }

        if ($("emailCategory")) {
            $("emailCategory").textContent =
                email.category;
        }

        if ($("senderAvatar")) {
            $("senderAvatar").textContent =
                initials(email.sender);
        }

        if ($("senderName")) {
            $("senderName").textContent =
                sentView ? "Me" : email.sender;
        }

        if ($("senderEmail")) {
            $("senderEmail").textContent =
                sentView
                    ? `To: ${email.senderEmail}`
                    : `<${email.senderEmail}>`;
        }

        if ($("emailTime")) {
            $("emailTime").textContent =
                email.time;
        }

        if (emailBody) {
            emailBody.style.whiteSpace = "pre-wrap";

            if (sentView) {
                emailBody.textContent =
                    "Loading conversation...";

                loadGmailThread(email, version);
            } else {
                emailBody.textContent =
                    email.body || "";
            }
        }

        const isRealGmail =
            Boolean(gmailMessageUrl) &&
            !email.id.startsWith("demo-") &&
            !sentView;

        if (isRealGmail && !email.summaryLoaded) {
            if ($("aiSummary")) {
                $("aiSummary").textContent =
                    "Loading email and preparing summary…";
            }
        } else if (!sentView) {
            renderSummary(email);
        }

        updateSelectedStar();

        if ($("replyRecipient")) {
            $("replyRecipient").textContent =
                email.senderEmail || email.sender;
        }

        if (replyDraft && !sentView) {
            replyDraft.value =
                localStorage.getItem(
                    draftKeyPrefix + email.id
                ) || "";
        }

        updateStats();
        renderEmails();
        renderWorkflow();

        if (isRealGmail && !email.summaryLoaded) {
            loadGmailDetails(email, version);
        }
    }

    /* ========================================================
       10. INBOX AND SENT SYNC
    ======================================================== */

    function showList(list) {
        ++selectionVersion;

        emails = Array.isArray(list) ? list : [];

        activeFilter = "all";

        document.querySelectorAll(".tab").forEach(tab => {
            tab.classList.toggle(
                "active",
                tab.dataset.filter === "all"
            );
        });

        if (emails.length > 0) {
            showEmail(emails[0].id);
        } else {
            selectedEmailId = null;

            if ($("emailSubject")) {
                $("emailSubject").textContent =
                    "No email selected";
            }

            if ($("emailCategory")) {
                $("emailCategory").textContent =
                    "Inbox";
            }

            if ($("senderAvatar")) {
                $("senderAvatar").textContent = "";
            }

            if ($("senderName")) {
                $("senderName").textContent = "";
            }

            if ($("senderEmail")) {
                $("senderEmail").textContent = "";
            }

            if ($("emailTime")) {
                $("emailTime").textContent = "";
            }

            if (emailBody) {
                emailBody.textContent =
                    "Your inbox is empty.";
            }

            if ($("aiSummary")) {
                $("aiSummary").textContent = "";
            }

            if (replyDraft) {
                replyDraft.value = "";
            }

            updateStats();
            renderEmails();
            renderWorkflow();
        }

        renderNotifications();
    }

    async function loadSentEmails() {
        if (!gmailSentUrl) {
            console.warn(
                "Gmail Sent URL is missing. Check data-gmail-sent."
            );
            return;
        }

        try {
            const data = await requestJSON(
                gmailSentUrl
            );

            sentEmails = (data.emails || [])
                .map(fromGmailSent)
                .sort(
                    (a, b) =>
                        b.timestamp - a.timestamp
                );

            console.log(
                "Gmail Sent loaded:",
                sentEmails.length
            );

            updateStats();

            if (activeFilter === "sent") {
                renderEmails();
            }

        } catch (error) {
            console.error(
                "Unable to load Gmail Sent:",
                error
            );

            notify(
                "Unable to load Sent: " +
                error.message,
                "error"
            );
        }
    }


async function loadGmailFolder(folder) {
    const url = folder === "spam"
        ? gmailSpamUrl
        : gmailTrashUrl;

    if (!url) {
        notify(`Gmail ${folder} URL is missing.`, "error");
        return;
    }

    try {
        const data = await requestJSON(url);
        const items = (data.emails || []).map(fromGmail);

        if (folder === "spam") {
            spamEmails = items;
        } else {
            trashEmails = items;
        }

        updateStats();

        if (activeFilter === folder) {
            renderEmails();

            if (items.length) {
                showEmail(items[0].id);
            } else {
                clearSelectedEmail();
            }
        }
    } catch (error) {
        notify(
            `Unable to load ${folder}: ${error.message}`,
            "error"
        );
    }
}

function clearSelectedEmail() {
    ++selectionVersion;
    selectedEmailId = null;

    if ($("emailSubject")) {
        $("emailSubject").textContent = "No email selected";
    }

    if (emailBody) {
        emailBody.textContent = "Select an email to view details.";
    }

    if ($("aiSummary")) {
        $("aiSummary").textContent = "";
    }

    renderEmails();
    renderWorkflow();
}


    async function syncGmailEmails() {
        const syncButton = $("gmailSyncBtn");

        if (!gmailInboxUrl) {
            showList([]);
            notify("Please connect your Gmail account.", "info");
            return;
        }

        if (syncButton) {
            syncButton.disabled = true;
            syncButton.classList.add("is-loading");
        }

        if (emailList) {
            emailList.innerHTML = `
                <p class="inbox-loading">
                    <i class="fa-solid fa-spinner fa-spin"></i>
                    Loading your Gmail…
                </p>
            `;
        }

        try {
            const data = await requestJSON(
                gmailInboxUrl
            );

            const incomingIds = (data.emails || [])
                .map(mail => String(mail.id || ""))
                .filter(Boolean);

            const previousIds = window.smartMascotInboxIds;

            if (
                previousIds instanceof Set &&
                incomingIds.some(id => !previousIds.has(id))
            ) {
                document.dispatchEvent(
                    new CustomEvent("sme:mascot", {
                        detail: { state: "new-email" }
                    })
                );
            }

            window.smartMascotInboxIds = new Set(incomingIds);


            showList(
                (data.emails || []).map(fromGmail)
            );

            notify(
                `Gmail synced: ${emails.length} emails.`,
                "success"
            );

        } catch (error) {
            console.error(
                "Gmail sync failed:",
                error
            );

            notify(
                "Unable to load Gmail: " + error.message,
                "error"
            );

            showList([]);

        } finally {
            if (syncButton) {
                syncButton.disabled = false;
                syncButton.classList.remove(
                    "is-loading"
                );
            }
        }
    }

    /* ========================================================
       11. SEARCH, FILTER AND SORT
    ======================================================== */

    emailList?.addEventListener("click", event => {
        const star = event.target.closest(
            "[data-star-id]"
        );

        if (star) {
            const id = star.dataset.starId;

            const email = [...emails, ...sentEmails].find(
                item => item.id === id
            );

            if (email) {
                toggleEmailStar(email);
            }

            return;
        }

        const item = event.target.closest(
            "[data-id]"
        );

        if (item) {
            showEmail(item.dataset.id);
        }
    });

    $("searchInput")?.addEventListener(
        "input",
        event => {
            searchQuery =
                event.target.value
                    .trim()
                    .toLowerCase();

            renderEmails();
        }
    );

    function activateFilter(filter) {
        activeFilter = filter;

        document.querySelectorAll(".tab").forEach(tab => {
            tab.classList.toggle(
                "active",
                tab.dataset.filter === filter
            );
        });

        renderEmails();

        if (filter === "sent") {
            void loadSentEmails();
        } else if (filter === "spam" || filter === "trash") {
            void loadGmailFolder(filter);
        }
    }

    document.querySelectorAll(".tab").forEach(tab => {
        tab.addEventListener("click", () => {
            activateFilter(tab.dataset.filter);
        });
    });

    $("sortBtn")?.addEventListener("click", () => {
        newestFirst = !newestFirst;
        renderEmails();
    });

    $("filterBtn")?.addEventListener("click", () => {
        const options = [
            "all",
            "unread",
            "important",
            "sent",
            "spam",
            "trash"
        ];

        const index = options.indexOf(
            activeFilter
        );

        const next =
            options[(index + 1) % options.length];

        activateFilter(next);
    });

    $("gmailSyncBtn")?.addEventListener(
        "click",
        async () => {
            await syncGmailEmails();
            await loadSentEmails();
        }
    );

    /* ========================================================
       12. EMAIL ACTIONS
    ======================================================== */

    $("starEmailBtn")?.addEventListener(
        "click",
        () => {
            const email = selectedEmail();
            if (!email) return;

            toggleEmailStar(email);
        }
    );

    
function removeEmailFromView(email) {
    emails = emails.filter(item => item.id !== email.id);
    sentEmails = sentEmails.filter(item => item.id !== email.id);
    spamEmails = spamEmails.filter(item => item.id !== email.id);
    trashEmails = trashEmails.filter(item => item.id !== email.id);

    if (selectedEmailId === email.id) {
        const visible =
            activeFilter === "sent" ? sentEmails
            : activeFilter === "spam" ? spamEmails
            : activeFilter === "trash" ? trashEmails
            : emails;

        if (visible.length) {
            showEmail(visible[0].id);
        } else {
            clearSelectedEmail();
        }
    }

    updateStats();
    renderEmails();
    renderNotifications();
    renderWorkflow();
}

    async function performEmailAction(
        action,
        email = selectedEmail()
    ) {
        if (!email) {
            return notify(
                "Please select an email.",
                "info"
            );
        }

        const destructive =
            action === "trash" ||
            action === "spam";

        if (destructive && !confirm(
            action === "trash"
                ? `Move "${email.subject}" to Trash?`
                : `Report "${email.subject}" as spam?`
        )) {
            return;
        }

        try {
            await gmailAction(action, email);

            if (
                ["archive", "trash", "spam","restore","not_spam"]
                    .includes(action)
            ) {
                removeEmailFromView(email);
            } else if (action === "read") {
                email.unread = false;
            } else if (action === "unread") {
                email.unread = true;
            } else if (action === "important") {
                email.important = true;
            } else if (action === "not_important") {
                email.important = false;
            }

            updateStats();
            renderEmails();
            renderNotifications();

            notify(
                "Gmail action completed: " +
                action.replaceAll("_", " "),
                "success"
            );

            closeEmailMoreMenu();

        } catch (error) {
            notify(
                "Gmail action failed: " +
                error.message,
                "error"
            );
        }
    }

    $("deleteEmailBtn")?.addEventListener(
        "click",
        () => performEmailAction("trash")
    );

    $("archiveEmailBtn")?.addEventListener(
        "click",
        () => performEmailAction("archive")
    );

    
    /* Gmail More Menu — position outside draggable cards */
    const moreEmailBtn = $("moreEmailBtn");
    const emailMoreMenu = $("emailMoreMenu");

    // Move the menu outside cards with overflow: hidden.
    if (emailMoreMenu) {
        document.body.appendChild(emailMoreMenu);
        emailMoreMenu.hidden = true;
    }

    function closeEmailMoreMenu() {
        if (emailMoreMenu) {
            emailMoreMenu.hidden = true;
        }

        moreEmailBtn?.setAttribute("aria-expanded", "false");
    }

    function positionEmailMoreMenu() {
        if (!moreEmailBtn || !emailMoreMenu) return;

        const rect = moreEmailBtn.getBoundingClientRect();
        const menuWidth = emailMoreMenu.offsetWidth;
        const menuHeight = emailMoreMenu.offsetHeight;
        const gap = 8;

        // Align the right edge with the three-dot button.
        const left = Math.max(
            gap,
            Math.min(
                rect.right - menuWidth,
                window.innerWidth - menuWidth - gap
            )
        );

        // Open upwards when there is not enough room below.
        const top =
            rect.bottom + menuHeight + gap > window.innerHeight
                ? Math.max(gap, rect.top - menuHeight - gap)
                : rect.bottom + gap;

        emailMoreMenu.style.left = `${left}px`;
        emailMoreMenu.style.top = `${top}px`;
    }

    moreEmailBtn?.addEventListener("click", event => {
        event.preventDefault();
        event.stopPropagation();

        if (!emailMoreMenu) return;

        if (!emailMoreMenu.hidden) {
            closeEmailMoreMenu();
            return;
        }

        emailMoreMenu.hidden = false;
        positionEmailMoreMenu();

        moreEmailBtn.setAttribute("aria-expanded", "true");
    });

    emailMoreMenu?.addEventListener("click", event => {
        const button = event.target.closest(
            "[data-gmail-action]"
        );

        if (!button) return;

        event.stopPropagation();

        closeEmailMoreMenu();

        void performEmailAction(
            button.dataset.gmailAction
        );
    });

    document.addEventListener("click", event => {
        if (
            !event.target.closest(
                "#emailMoreMenu, #moreEmailBtn"
            )
        ) {
            closeEmailMoreMenu();
        }
    });

    document.addEventListener("keydown", event => {
        if (event.key === "Escape") {
            closeEmailMoreMenu();
        }
    });

    window.addEventListener("resize", () => {
        if (emailMoreMenu && !emailMoreMenu.hidden) {
            positionEmailMoreMenu();
        }
    });

    window.addEventListener(
        "scroll",
        closeEmailMoreMenu,
        true
    );


    /* ========================================================
       13. AI REPLY GENERATION
    ======================================================== */

    async function generateReply() {
        const email = selectedEmail();

        document.dispatchEvent(
            new CustomEvent("sme:mascot", {
                detail: { state: "thinking" }
            })
        );


        if (!email || email.sent) {
            notify(
                "Please select an Inbox email.",
                "error"
            );
            return;
        }

        if (!aiRegenerateUrl) {
            notify(
                "AI Regenerate endpoint is missing.",
                "error"
            );
            return;
        }

        const button = $("regenerateReplyBtn");
        const tone = $("replyTone")?.value || "";
        const draftStatus = $("replyDraftStatus");

        const emailId = email.id;

        if (button) {
            button.disabled = true;
        }

        if (replyDraft) {
            replyDraft.placeholder =
                "AI is generating...";
        }

        if (draftStatus) {
            draftStatus.textContent =
                "Generating AI reply...";
        }

        try {
            if (
                !email.id.startsWith("demo-") &&
                !email.fullBodyLoaded
            ) {
                if (!gmailMessageUrl) {
                    throw new Error(
                        "Gmail message URL is missing."
                    );
                }

                const url = new URL(
                    gmailMessageUrl,
                    window.location.href
                );

                url.searchParams.set(
                    "id",
                    email.id
                );

                const message = await requestJSON(
                    url.toString()
                );

                email.body =
                    typeof message.body === "string"
                        ? message.body
                        : email.preview;

                email.fullBodyLoaded = true;
            }

            const data = await requestJSON(
                aiRegenerateUrl,
                {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        "X-CSRF-Token": csrfToken
                    },
                    body: JSON.stringify({
                        subject: email.subject,
                        body: email.body,
                        tone
                    })
                }
            );

            if (selectedEmailId !== emailId) {
                return;
            }

            if (
                !data.reply ||
                typeof data.reply !== "string"
            ) {
                throw new Error(
                    "AI returned an empty reply."
                );
            }

            if (replyDraft) {
                replyDraft.value = data.reply;

                replyDraft.dispatchEvent(
                    new Event("input", {
                        bubbles: true
                    })
                );
            }

            if (draftStatus) {
                draftStatus.textContent =
                    "AI draft generated";
            }

            notify(
                "AI reply generated successfully!",
                "success"
            );

            document.dispatchEvent(
                new CustomEvent("sme:mascot", {
                    detail: { state: "draft" }
                })
            );


        } catch (error) {
            console.error("AI error:", error);

            if (draftStatus) {
                draftStatus.textContent =
                    "AI generation failed";
            }

            notify(error.message, "error");

        } finally {
            if (button) {
                button.disabled = false;
            }

            if (replyDraft) {
                replyDraft.placeholder =
                    "Write your reply...";
            }
        }
    }

    $("regenerateReplyBtn")?.addEventListener(
        "click",
        generateReply
    );

    $("adjustReplyBtn")?.addEventListener(
        "click",
        () => {
            replyDraft?.focus();
            notify(
                "You can edit the draft before sending."
            );
        }
    );

    $("replyTone")?.addEventListener(
        "change",
        () => {
            notify(
                "Tone changed. Click Regenerate to apply."
            );
        }
    );

    /* ========================================================
       14. SEND GMAIL REPLY
    ======================================================== */

    function confirmSend() {
        const modal = $("sendConfirmModal");
        const confirmButton = $("confirmSendBtn");
        const cancelButton = $("cancelSendBtn");

        if (
            !modal ||
            !confirmButton ||
            !cancelButton
        ) {
            return Promise.resolve(
                confirm("Send this reply?")
            );
        }

        modal.hidden = false;

        return new Promise(resolve => {
            function close(result) {
                modal.hidden = true;

                confirmButton.removeEventListener(
                    "click",
                    onConfirm
                );

                cancelButton.removeEventListener(
                    "click",
                    onCancel
                );

                modal.removeEventListener(
                    "click",
                    onBackdrop
                );

                document.removeEventListener(
                    "keydown",
                    onKeydown
                );

                resolve(result);
            }

            const onConfirm = () => close(true);
            const onCancel = () => close(false);

            const onBackdrop = event => {
                if (event.target === modal) {
                    close(false);
                }
            };

            const onKeydown = event => {
                if (event.key === "Escape") {
                    close(false);
                }
            };

            confirmButton.addEventListener(
                "click",
                onConfirm
            );

            cancelButton.addEventListener(
                "click",
                onCancel
            );

            modal.addEventListener(
                "click",
                onBackdrop
            );

            document.addEventListener(
                "keydown",
                onKeydown
            );

            cancelButton.focus();
        });
    }

    $("sendReplyBtn")?.addEventListener(
        "click",
        async () => {
            const email = selectedEmail();
            const body = replyDraft?.value.trim() || "";

            if (!body) {
                notify(
                    "Please write a reply first.",
                    "error"
                );
                return;
            }

            if (
                !email ||
                email.sent ||
                email.id.startsWith("demo-") ||
                !gmailSendUrl
            ) {
                notify(
                    "Please select a connected Gmail Inbox email.",
                    "error"
                );
                return;
            }

            const confirmed = await confirmSend();
            if (!confirmed) return;

            const button = $("sendReplyBtn");

            if (button) {
                button.disabled = true;
                button.innerHTML = `
                    <i class="fa-solid fa-spinner fa-spin"></i>
                    Sending...
                `;
            }

            let sentSuccessfully = false;

            try {
                const formData = new FormData();

                formData.append(
                    "messageId",
                    email.id
                );

                formData.append(
                    "body",
                    body
                );

                formData.append(
                    "csrf_token",
                    csrfToken
                );

                replyAttachments.forEach(file => {
                    formData.append(
                        "attachments[]",
                        file
                    );
                });

                const response = await fetch(
                    gmailSendUrl,
                    {
                        method: "POST",
                        body: formData,
                        credentials: "same-origin"
                    }
                );

                const result = await response
                    .json()
                    .catch(() => null);

                if (
                    !response.ok ||
                    !result?.success
                ) {
                    throw new Error(
                        result?.message ||
                        "Failed to send reply."
                    );
                }

                sentSuccessfully = true;


                document.dispatchEvent(
                    new CustomEvent("sme:mascot", {
                        detail: { state: "celebrate" }
                    })
                );


                notify(
                    "Reply sent successfully!",
                    "success"
                );

                email.needsReply = false;

                if (replyDraft) {
                    replyDraft.value = "";
                }

                localStorage.removeItem(
                    draftKeyPrefix + email.id
                );

                replyAttachments = [];
                renderReplyAttachments();

                updateStats();
                renderEmails();

            } catch (error) {
                console.error(
                    "Gmail send failed:",
                    error
                );

                notify(
                    error.message ||
                    "Unable to send reply.",
                    "error"
                );

            } finally {
                if (button) {
                    button.disabled = false;
                    button.innerHTML = `
                        <i class="fa-regular fa-paper-plane"></i>
                        Send Reply
                    `;
                }
            }

            // Refresh Sent only AFTER confirmed success.
            // A Sent refresh error must not be mistaken
            // for an email sending failure.
            if (sentSuccessfully) {
                await loadSentEmails();
            }
        }
    );

    /* ========================================================
       15. ATTACHMENTS AND SIGNATURES
    ======================================================== */

    function renderReplyAttachments() {
        const container =
            $("replyAttachmentList");

        if (!container) return;

        container.replaceChildren();

        replyAttachments.forEach(
            (file, index) => {
                const item =
                    document.createElement("div");

                item.className =
                    "reply-attachment-item";

                const name =
                    document.createElement("span");

                name.textContent =
                    "📎 " +
                    file.name +
                    " (" +
                    (file.size / 1024).toFixed(1) +
                    " KB)";

                const remove =
                    document.createElement("button");

                remove.type = "button";
                remove.textContent = "×";

                remove.setAttribute(
                    "aria-label",
                    "Remove " + file.name
                );

                remove.addEventListener(
                    "click",
                    () => {
                        replyAttachments.splice(
                            index,
                            1
                        );

                        renderReplyAttachments();
                    }
                );

                item.append(name, remove);
                container.appendChild(item);
            }
        );
    }

    $("attachReplyBtn")?.addEventListener(
        "click",
        () => {
            $("replyFileInput")?.click();
        }
    );

    $("replyFileInput")?.addEventListener(
        "change",
        event => {
            const files = Array.from(
                event.target.files || []
            );

            replyAttachments.push(...files);
            renderReplyAttachments();

            event.target.value = "";
        }
    );

    $("insertSignatureBtn")?.addEventListener(
        "click",
        () => {
            const signature =
                localStorage.getItem(signatureKey) || "";

            if (!signature) {
                notify(
                    "Please create a signature first.",
                    "info"
                );
                return;
            }

            if (!replyDraft) return;

            const separator =
                replyDraft.value.trim()
                    ? "\n\n"
                    : "";

            replyDraft.value =
                replyDraft.value.trimEnd() +
                separator +
                signature;

            replyDraft.dispatchEvent(
                new Event("input", {
                    bubbles: true
                })
            );
        }
    );

    $("editSignatureBtn")?.addEventListener(
        "click",
        () => {
            const current =
                localStorage.getItem(signatureKey) || "";

            const updated = prompt(
                "Enter your email signature:",
                current
            );

            if (updated === null) return;

            localStorage.setItem(
                signatureKey,
                updated
            );

            notify(
                "Signature saved.",
                "success"
            );
        }
    );

    replyDraft?.addEventListener(
        "input",
        () => {
            if ($("replyDraftStatus")) {
                $("replyDraftStatus").textContent =
                    "Saving...";
            }

            clearTimeout(draftSaveTimer);

            draftSaveTimer = setTimeout(() => {
                if (!selectedEmailId) return;

                localStorage.setItem(
                    draftKeyPrefix + selectedEmailId,
                    replyDraft.value
                );

                if ($("replyDraftStatus")) {
                    $("replyDraftStatus").textContent =
                        "Draft saved";
                }
            }, 500);
        }
    );


    /* ========================================================
       16. SMART NOTIFICATIONS
    ======================================================== */

    function renderNotifications() {
        const container =
            $("notificationList");

        if (!container) return;

        const importantEmails = emails.filter(
            email =>
                email.important ||
                email.starred ||
                email.needsReply
        );

        container.innerHTML = importantEmails.map(
            email => `
                <div class="notification-item">
                    <strong>
                        ${escapeHTML(email.subject)}
                    </strong>

                    <p>
                        ${escapeHTML(
                            email.summary?.[0] ||
                            email.preview ||
                            ""
                        )}
                    </p>
                </div>
            `
        ).join("");
    }

    $("notificationBtn")?.addEventListener(
        "click",
        () => {
            renderNotifications();

            const panel =
                $("notificationPanel");

            if (panel) {
                panel.hidden = !panel.hidden;
            }
        }
    );

    $("closeNotificationBtn")?.addEventListener(
        "click",
        () => {
            if ($("notificationPanel")) {
                $("notificationPanel").hidden = true;
            }
        }
    );


    /* ========================================================
       16. COPY AI SUMMARY
    ======================================================== */

    $("copySummaryBtn")?.addEventListener(
        "click",
        async () => {
            try {
                const summary =
                    $("aiSummary")?.innerText || "";

                await navigator.clipboard.writeText(
                    summary
                );

                notify(
                    "AI Summary copied!",
                    "success"
                );

            } catch {
                notify(
                    "Unable to copy summary.",
                    "error"
                );
            }
        }
    );

    /* ========================================================
       17. FOLLOW-UP WORKFLOW
    ======================================================== */

    function addActivity(
        emailId,
        action,
        details
    ) {
        workflow.activityLog.unshift({
            id: Date.now(),
            emailId,
            action,
            details,
            timestamp:
                new Date().toLocaleString()
        });

        saveWorkflow();
    }

    function approveEmailTask(emailId) {
        const email = emails.find(
            item => item.id === emailId
        );

        if (!email) return;

        const existingTask = workflow.tasks.find(
            task => task.emailId === emailId
        );

        if (existingTask) {
            notify(
                "A task already exists for this email.",
                "error"
            );
            return;
        }

        const task = {
            id: Date.now(),
            emailId: email.id,
            title: `Follow up: ${email.subject}`,
            assignedTo:
                email.category === "Finance"
                    ? "Finance Team"
                    : "Operations Team",
            status: "Pending",
            createdAt:
                new Date().toLocaleString()
        };

        workflow.tasks.push(task);

        addActivity(
            email.id,
            "APPROVED",
            `Task created: ${task.title}`
        );

        notify(
            "Approved! Follow-up task created.",
            "success"
        );
    }

    function rejectEmailTask(emailId) {
        const email = emails.find(
            item => item.id === emailId
        );

        if (!email) return;

        addActivity(
            email.id,
            "DISMISSED",
            `Suggested task dismissed: ${email.subject}`
        );

        notify(
            "Suggested task dismissed. No task created."
        );
    }

    function renderHistory() {
        const tasksContainer =
            $("createdTasks");

        if (tasksContainer) {
            tasksContainer.innerHTML =
                workflow.tasks.length
                    ? workflow.tasks.map(task => `
                        <div class="task-item">
                            <strong>
                                ${escapeHTML(task.title)}
                            </strong>
                            <p>
                                Assigned to:
                                ${escapeHTML(task.assignedTo)}
                            </p>
                            <p>
                                Status:
                                ${escapeHTML(task.status)}
                            </p>
                        </div>
                    `).join("")
                    : "No tasks created yet.";
        }

        const logContainer =
            $("activityLog");

        if (logContainer) {
            logContainer.innerHTML =
                workflow.activityLog.length
                    ? workflow.activityLog.map(log => `
                        <div class="log-item">
                            <strong>
                                ${escapeHTML(log.action)}
                            </strong>
                            <p>
                                ${escapeHTML(log.details)}
                            </p>
                            <small>
                                ${escapeHTML(log.timestamp)}
                            </small>
                        </div>
                    `).join("")
                    : "No activity recorded yet.";
        }
    }

    function renderWorkflow() {
        renderHistory();

        const email = selectedEmail();

        const approveButton =
            $("approveTaskBtn");

        const rejectButton =
            $("rejectTaskBtn");

        const suggestedAction =
            $("suggestedAction");

        const approvalStatus =
            $("approvalStatus");

        if (!email || email.sent) {
            if (suggestedAction) {
                suggestedAction.textContent =
                    "Select an Inbox email to view the suggested action.";
            }

            if (approvalStatus) {
                approvalStatus.textContent = "—";
            }

            if (approveButton) {
                approveButton.disabled = true;
            }

            if (rejectButton) {
                rejectButton.disabled = true;
            }

            return;
        }

        const existingTask = workflow.tasks.find(
            task => task.emailId === email.id
        );

        const latestRejection =
            workflow.activityLog.find(
                log =>
                    log.emailId === email.id &&
                    log.action === "DISMISSED"
            );

        let suggestion =
            email.suggestedAction?.trim() || "";

        if (!suggestion) {
            if (email.category === "Finance") {
                suggestion =
                    "Verify invoice payment status and create a follow-up task for the Finance Team.";
            } else if (email.meeting) {
                suggestion =
                    "Review the meeting request and create a follow-up task.";
            } else if (email.needsReply) {
                suggestion =
                    "Review the email and create a follow-up task.";
            } else {
                suggestion =
                    "No immediate action required.";
            }
        }

        if (suggestedAction) {
            suggestedAction.textContent =
                suggestion;
        }

        if (approvalStatus) {
            approvalStatus.textContent =
                existingTask
                    ? "Approved"
                    : latestRejection
                        ? "Dismissed"
                        : "Pending Approval";
        }

        if (approveButton) {
            approveButton.disabled =
                Boolean(existingTask);
        }

        if (rejectButton) {
            rejectButton.disabled = false;
        }
    }

    $("approveTaskBtn")?.addEventListener(
        "click",
        () => {
            approveEmailTask(selectedEmailId);
            renderWorkflow();
        }
    );

    $("rejectTaskBtn")?.addEventListener(
        "click",
        () => {
            rejectEmailTask(selectedEmailId);
            renderWorkflow();
        }
    );

    $("historyBtn")?.addEventListener(
        "click",
        () => {
            renderHistory();

            if ($("historyModal")) {
                $("historyModal").hidden = false;
            }
        }
    );

    $("closeHistoryBtn")?.addEventListener(
        "click",
        () => {
            if ($("historyModal")) {
                $("historyModal").hidden = true;
            }
        }
    );

    $("historyModal")?.addEventListener(
        "click",
        event => {
            if (
                event.target === $("historyModal")
            ) {
                $("historyModal").hidden = true;
            }
        }
    );

    /* ========================================================
       18. CUSTOMIZABLE DASHBOARD LAYOUT
    ======================================================== */


    function initializeDashboardLayout() {
        const planner = $("workspacePlanner");

        if (!planner) return;

        const cards = [
            {
                id: "planner",
                title: "Team Workspace Planner",
                selector: "#workspacePlanner",
                fullWidth: true
            },
            {
                id: "statistics",
                title: "Email Statistics",
                selector: "#dashboardRoot > .stats-grid",
                fullWidth: true
            },
            {
                id: "inbox",
                title: "Inbox",
                selector: "#dashboardRoot .inbox-panel",
                fullWidth: false
            },
            {
                id: "email-details",
                title: "Email Details & AI Assistant",
                selector: "#dashboardRoot .email-detail-panel",
                fullWidth: false
            }
        ];

        const toolbar = document.createElement("div");
        toolbar.className = "custom-dashboard-toolbar";

        toolbar.innerHTML = `
            <div>
                <h2>Customize Your Dashboard</h2>
                <p>Drag the handle to rearrange your dashboard cards.</p>
            </div>

            <button type="button" id="resetDashboardLayout">
                <i class="fa-solid fa-rotate-left"></i>
                Reset Layout
            </button>
        `;

        const grid = document.createElement("div");
        grid.className = "custom-dashboard-grid";
        grid.id = "customDashboardGrid";

        planner.before(toolbar, grid);

        cards.forEach(config => {
            const original = root.querySelector(config.selector);
            if (!original) return;

            const wrapper = document.createElement("section");
            wrapper.className = "dashboard-draggable-card";

            if (config.fullWidth) {
                wrapper.classList.add("full-width");
            }

            wrapper.dataset.cardId = config.id;

            const header = document.createElement("div");
            header.className = "dashboard-drag-header";

            const title = document.createElement("span");
            title.className = "dashboard-drag-title";
            title.textContent = config.title;

            const handle = document.createElement("button");
            handle.type = "button";
            handle.className = "dashboard-drag-handle";
            handle.draggable = true;
            handle.style.cursor = "grab";

            handle.innerHTML = `
                <i class="fa-solid fa-grip-vertical"></i>
                Drag to move
            `;

            handle.setAttribute(
                "aria-label",
                "Drag to move " + config.title
            );

            header.append(title, handle);
            wrapper.append(header, original);
            grid.appendChild(wrapper);
        });

        const getCards = () =>
            Array.from(
                grid.querySelectorAll(
                    ":scope > .dashboard-draggable-card"
                )
            );

        function saveLayout() {
            const order = getCards().map(
                card => card.dataset.cardId
            );

            try {
                localStorage.setItem(
                    layoutKey,
                    JSON.stringify(order)
                );
            } catch (error) {
                console.warn("Could not save layout:", error);
            }
        }

        function restoreLayout() {
            let savedOrder = null;

            try {
                savedOrder = JSON.parse(
                    localStorage.getItem(layoutKey) || "null"
                );
            } catch {
                savedOrder = null;
            }

            if (!Array.isArray(savedOrder)) return;

            savedOrder.forEach(id => {
                const card = getCards().find(
                    item => item.dataset.cardId === id
                );

                if (card) {
                    grid.appendChild(card);
                }
            });
        }

        let draggedCard = null;

        grid.addEventListener("dragstart", event => {
            const handle = event.target.closest(
                ".dashboard-drag-handle"
            );

            if (!handle) {
                event.preventDefault();
                return;
            }

            draggedCard = handle.closest(
                ".dashboard-draggable-card"
            );

            if (!draggedCard) return;

            event.dataTransfer.effectAllowed = "move";

            event.dataTransfer.setData(
                "text/plain",
                draggedCard.dataset.cardId
            );

            draggedCard.classList.add("is-dragging");
        });

        grid.addEventListener("dragover", event => {
            if (!draggedCard) return;

            event.preventDefault();

            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = "move";
            }

            const target = event.target.closest(
                ".dashboard-draggable-card"
            );

            getCards().forEach(card => {
                card.classList.toggle(
                    "drag-over",
                    card === target && card !== draggedCard
                );
            });
        });

        grid.addEventListener("drop", event => {
            if (!draggedCard) return;

            event.preventDefault();

            const target = event.target.closest(
                ".dashboard-draggable-card"
            );

            if (target && target !== draggedCard) {
                const elements = getCards();

                const fromIndex = elements.indexOf(draggedCard);
                const toIndex = elements.indexOf(target);

                if (fromIndex < toIndex) {
                    target.after(draggedCard);
                } else {
                    target.before(draggedCard);
                }

                saveLayout();
            }

            cleanupDrag();
        });

        function cleanupDrag() {
            getCards().forEach(card => {
                card.classList.remove(
                    "is-dragging",
                    "drag-over"
                );
            });

            draggedCard = null;
        }

        grid.addEventListener("dragend", cleanupDrag);

        $("resetDashboardLayout")?.addEventListener(
            "click",
            () => {
                localStorage.removeItem(layoutKey);

                cards.forEach(config => {
                    const card = getCards().find(
                        item =>
                            item.dataset.cardId === config.id
                    );

                    if (card) {
                        grid.appendChild(card);
                    }
                });
            }
        );

        restoreLayout();
    }

    /* ========================================================
       COLLAPSIBLE EMAIL DETAILS
    ======================================================== */

    function initializeEmailViewMore() {
        if (!emailBody) return;

        const button = $("emailViewMoreBtn");
        if (!button) return;

        const label = button.querySelector("span");
        const icon = button.querySelector("i");

        let expanded = false;
        let currentEmailId = null;

        function updateButton() {
            if (expanded) {
                button.hidden = false;
                button.setAttribute("aria-expanded", "true");

                if (label) label.textContent = "View Less";
                if (icon) {
                    icon.className = "fa-solid fa-chevron-up";
                }

                return;
            }

            emailBody.classList.add("email-body-collapsed");

            const needsExpansion =
                emailBody.scrollHeight >
                emailBody.clientHeight + 2;

            button.hidden = !needsExpansion;
            button.setAttribute("aria-expanded", "false");

            if (label) label.textContent = "View More";
            if (icon) {
                icon.className = "fa-solid fa-chevron-down";
            }
        }

        function resetExpansion() {
            expanded = false;
            emailBody.classList.add("email-body-collapsed");
            updateButton();
        }

        button.addEventListener("click", () => {
            expanded = !expanded;

            emailBody.classList.toggle(
                "email-body-collapsed",
                !expanded
            );

            updateButton();
        });

        const observer = new MutationObserver(() => {
            if (selectedEmailId !== currentEmailId) {
                currentEmailId = selectedEmailId;
                resetExpansion();
            } else {
                updateButton();
            }
        });

        observer.observe(emailBody, {
            childList: true,
            characterData: true,
            subtree: true
        });

        window.addEventListener(
            "resize",
            updateButton
        );

        currentEmailId = selectedEmailId;
        resetExpansion();
    }

    /* ========================================================
       19. INITIALIZE DASHBOARD
    ======================================================== */

    initializeDashboardLayout();
    initializeEmailViewMore();
    updateStats();
    renderEmails();
    renderWorkflow();
    renderNotifications();

    // Load Inbox and Sent independently.
    // If Inbox fails, Sent can still load.
    if (gmailInboxUrl) {
        syncGmailEmails();
    } else {
        showList([]);
    }

    if (gmailSentUrl) {
        loadSentEmails();
    } else {
        console.warn(
            "Gmail Sent is not configured. " +
            "Check data-gmail-sent in index.php."
        );
    }

    console.log(
        "Smart SME Dashboard initialized.",
        {
            inboxConfigured: Boolean(gmailInboxUrl),
            sentConfigured: Boolean(gmailSentUrl),
            threadConfigured: Boolean(gmailThreadUrl)
        }
    );
});
