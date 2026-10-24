
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

    /* --------------------------------------------------------
       Email text -> tidy, safe HTML.
       Gmail's plain-text bodies (calendar invites, newsletters)
       carry long raw links and hard line wraps. Here:
         - "Label" followed by a link on the next line becomes a
           button ("Join with Google Meet", "Add to calendar");
         - any other link is clickable with a short label;
         - wrapped lines are joined, extra blank lines dropped;
         - the footer (unsubscribe / notification text) is muted.
       Everything is escaped; only http(s) links are made.
    -------------------------------------------------------- */
    const EMAIL_URL_ONLY = /^<?(https?:\/\/[^\s<>"]+)>?$/i;
    const EMAIL_URL_ANY = /<?(https?:\/\/[^\s<>"]+)>?/gi;
    const EMAIL_HAS_URL = /https?:\/\//i;
    const EMAIL_FOOTER = /^(~~\/\/~~|--\s*|_{5,}|-{5,}|You are receiving this|You received this|To unsubscribe|Unsubscribe|This email was sent to|Invitation from Google Calendar)/i;

    function shortEmailUrl(url) {
        try {
            const u = new URL(url);
            const path = u.pathname === "/" ? "" : u.pathname;
            const text = u.hostname.replace(/^www\./, "") + path;
            return text.length > 42 ? text.slice(0, 40) + "…" : text;
        } catch (error) {
            return url.length > 42 ? url.slice(0, 40) + "…" : url;
        }
    }

    function emailLink(url, label, cls) {
        return `<a class="${cls}" href="${escapeHTML(url)}" target="_blank" rel="noopener noreferrer" title="${escapeHTML(url)}">${escapeHTML(label)}</a>`;
    }

    function emailInline(line) {
        let html = "";
        let last = 0;
        line.replace(EMAIL_URL_ANY, (match, url, offset) => {
            html += escapeHTML(line.slice(last, offset));
            const clean = url.replace(/[).,;:!?]+$/, "");
            html += emailLink(clean, shortEmailUrl(clean), "mail-link") + escapeHTML(url.slice(clean.length));
            last = offset + match.length;
            return match;
        });
        return html + escapeHTML(line.slice(last));
    }

    function formatEmailBody(raw) {
        const source = String(raw ?? "").replace(/\r\n?/g, "\n").split("\n");

        // Join hard-wrapped lines ("... want to  \nautomatically add ...").
        const lines = [];
        source.forEach(line => {
            const prev = lines.length ? lines[lines.length - 1] : null;
            if (prev !== null && /\S\s{2,}$/.test(prev) && line.trim() !== ""
                && !EMAIL_URL_ONLY.test(line.trim()) && !EMAIL_URL_ONLY.test(prev.trim())) {
                lines[lines.length - 1] = prev.trimEnd() + " " + line.trimStart();
            } else {
                lines.push(line);
            }
        });

        const main = [];
        const footer = [];
        let target = main;
        let blank = 0;

        for (let i = 0; i < lines.length; i++) {
            const line = lines[i].replace(/\s+$/, "").replace(/[ \t]{2,}/g, " ");
            const text = line.trim();

            if (target === main && main.length && EMAIL_FOOTER.test(text)) {
                target = footer;
                blank = 0;
                if (/^(~~\/\/~~|--|_{5,}|-{5,})$/.test(text)) continue;
            }

            if (text === "") {
                blank++;
                if (blank === 1 && target.length) target.push("");
                continue;
            }
            blank = 0;

            const next = (lines[i + 1] || "").trim();
            const onlyUrl = text.match(EMAIL_URL_ONLY);
            const nextUrl = next.match(EMAIL_URL_ONLY);

            if (!onlyUrl && nextUrl && text.length <= 90 && !EMAIL_HAS_URL.test(text)) {
                // "Join with Google Meet" + link  ->  a button.
                target.push(emailLink(nextUrl[1], text, "mail-action"));
                i++;
            } else if (onlyUrl) {
                target.push(emailLink(onlyUrl[1], shortEmailUrl(onlyUrl[1]), "mail-link"));
            } else {
                // "Learn more https://..."  ->  "Learn more" as the link.
                const tail = text.match(/^(.{2,60}?)\s+<?(https?:\/\/[^\s<>"]+)>?$/i);
                target.push(tail && !EMAIL_HAS_URL.test(tail[1])
                    ? escapeHTML(tail[1]) + " " + emailLink(tail[2], "↗", "mail-link")
                    : emailInline(text));
            }
        }

        while (main.length && main[main.length - 1] === "") main.pop();
        while (footer.length && footer[footer.length - 1] === "") footer.pop();

        let html = main.join("<br>");
        if (footer.length) {
            html += `<span class="mail-footer"><br><br>${footer.join("<br>")}</span>`;
        }
        return html || escapeHTML(String(raw ?? ""));
    }

    function setEmailBody(raw) {
        if (!emailBody) return;
        emailBody.innerHTML = formatEmailBody(raw);
    }

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

    let meetingOnly = false;
    let selectedCategory = "all";
    const meetingKeywords = /\b(?:meeting invitation|meeting request|schedule a meeting)\b/i;
    const isImportant = email => Boolean(email.starred);
    const receivedToday = email => email.timestamp > 0 &&
        new Date(email.timestamp * 1000).toDateString() === new Date().toDateString();

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

            meeting: Boolean(mail.meeting) || meetingKeywords.test(text),
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
                email => isImportant(email)
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
            newCount.textContent = emails.filter(receivedToday).length;
        }

        if (replyCount) {
            replyCount.textContent =
                emails.filter(
                    email => email.unread
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

            if (meetingOnly && !email.meeting) return false;
            if (selectedCategory !== "all" && !(selectedCategory === "Meeting"
                ? email.meeting || email.category === "Meeting"
                : email.category === selectedCategory)) return false;

            if (activeFilter === "unread") {
                return email.unread;
            }

            if (activeFilter === "important") {
                return isImportant(email);
            }

            return true;
        });

        filtered = [...filtered];

        filtered.sort((a, b) => newestFirst
            ? (b.timestamp || 0) - (a.timestamp || 0)
            : (a.timestamp || 0) - (b.timestamp || 0));

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
                setEmailBody(email.body);
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

            // Keep deterministic meeting detection independent of the AI category.

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
                                ">${formatEmailBody(message.body || "")}</div>

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

    function showEmail(id, markRead = true) {
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
        if (markRead && !sentView && email.unread) {
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
                setEmailBody(email.body || "");
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

        // Preserve a shortcut/filter chosen while Gmail is loading.

        if (emails.length > 0) {
            showEmail(emails[0].id, false);
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
            const data = { emails: [] };
            let pageToken = "";
            const seenTokens = new Set();
            do {
                const pageUrl = new URL(gmailInboxUrl, window.location.href);
                if (pageToken) pageUrl.searchParams.set("pageToken", pageToken);
                const page = await requestJSON(pageUrl.href);
                data.emails.push(...(page.emails || []));
                pageToken = page.nextPageToken || "";
                if (pageToken && seenTokens.has(pageToken)) throw new Error("Gmail pagination repeated. Please sync again.");
                seenTokens.add(pageToken);
            } while (pageToken);
            data.emails = [...new Map(data.emails.map(mail => [mail.id, mail])).values()];
            data.emails.sort((a, b) => Number(b.timestamp || 0) - Number(a.timestamp || 0));

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

    function activateFilter(filter, meetings = false) {
        activeFilter = filter;
        if (meetingOnly && !meetings) selectedCategory = "all";
        if (meetings) selectedCategory = "Meeting";
        meetingOnly = meetings;
        if ($("filterBtn")) $("filterBtn").value = selectedCategory;

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

    document.querySelectorAll("[data-inbox-shortcut]").forEach(card => {
        card.addEventListener("click", event => {
            event.preventDefault();
            const filter = card.dataset.inboxShortcut;
            searchQuery = "";
            if ($("searchInput")) $("searchInput").value = "";
            newestFirst = true;
            selectedCategory = "all";
            if ($("sortBtn")) $("sortBtn").value = "newest";
            activateFilter(filter === "meeting" ? "all" : filter, filter === "meeting");
            $("inbox")?.scrollIntoView({ behavior: "smooth", block: "start" });
            $("inbox")?.focus({ preventScroll: true });
        });
    });

    $("sortBtn")?.addEventListener("change", event => {
        newestFirst = event.target.value === "newest";
        renderEmails();
    });

    $("filterBtn")?.addEventListener("change", event => {
        selectedCategory = event.target.value;
        meetingOnly = false;
        renderEmails();
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
                <p>Drag a card by its handle in any direction to rearrange your dashboard.</p>
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
            handle.draggable = false;

            handle.innerHTML = `
                <i class="fa-solid fa-grip-vertical"></i>
                Drag to move
            `;

            handle.setAttribute(
                "aria-label",
                "Drag to move " + config.title + " (or use the arrow keys)"
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

        /* ----------------------------------------------------------
           Pointer drag (mouse, touch and pen)

           The card is lifted out of the grid and follows the pointer
           freely - up, down, left and right - kept inside the grid.
           A placeholder holds its slot; the other cards slide out of
           the way (FLIP animation) as the placeholder moves. On drop
           the card glides into the placeholder's slot.

           Only the "Drag to move" handle starts a drag, so buttons,
           inputs and links inside the cards keep working.
        ---------------------------------------------------------- */

        const DRAG_THRESHOLD = 4;      // px before a press becomes a drag
        // Auto-scroll: dragging toward the bottom of the screen scrolls the
        // page down, toward the top scrolls it up. The closer to the edge,
        // the faster it scrolls.
        const EDGE_SCROLL_RATIO = 0.22;   // top / bottom share of the viewport that scrolls
        const EDGE_SCROLL_MIN_ZONE = 90;  // px, at least this tall on small screens
        const EDGE_SCROLL_MAX = 26;       // px per frame at the very edge
        const prefersReducedMotion = window.matchMedia &&
            window.matchMedia("(prefers-reduced-motion: reduce)").matches;

        let drag = null;

        grid.style.position = "relative";

        /** Slide cards from their old positions to their new ones. */
        function animateReflow(before) {
            if (prefersReducedMotion) return;

            before.forEach((rect, card) => {
                const after = card.getBoundingClientRect();
                const dx = rect.left - after.left;
                const dy = rect.top - after.top;

                if (Math.abs(dx) < 1 && Math.abs(dy) < 1) return;

                card.animate(
                    [
                        { transform: `translate(${dx}px, ${dy}px)` },
                        { transform: "translate(0, 0)" }
                    ],
                    { duration: 220, easing: "cubic-bezier(.2, .8, .2, 1)" }
                );
            });
        }

        /** Move the placeholder (and slide the other cards) without jumps. */
        function movePlaceholder(insert) {
            const others = getCards().filter(card => card !== drag.card);
            const before = new Map(
                others.map(card => [card, card.getBoundingClientRect()])
            );

            insert();
            animateReflow(before);
        }

        function startDrag(event, handle, pressX, pressY) {
            const card = handle.closest(".dashboard-draggable-card");
            if (!card) return;

            const rect = card.getBoundingClientRect();

            const placeholder = document.createElement("div");
            placeholder.className = "dashboard-drag-placeholder";
            if (card.classList.contains("full-width")) {
                placeholder.classList.add("full-width");
            }
            placeholder.style.height = rect.height + "px";

            drag = {
                card,
                handle,
                placeholder,
                pointerId: event.pointerId,
                // Keep the exact point that was pressed under the pointer.
                offsetX: pressX - rect.left,
                offsetY: pressY - rect.top,
                width: rect.width,
                height: rect.height,
                x: event.clientX,
                y: event.clientY,
                lockTarget: null,
                frame: 0
            };

            card.before(placeholder);

            card.style.width = rect.width + "px";
            card.style.height = rect.height + "px";
            card.classList.add("is-lifted");
            grid.classList.add("is-sorting");
            document.body.classList.add("dashboard-is-dragging");
            // Stop the browser scrolling the page by itself when cards reflow.
            document.documentElement.style.overflowAnchor = "none";

            positionCard();
            drag.frame = requestAnimationFrame(tick);
        }

        /** Put the card under the pointer, clamped to the grid. */
        function positionCard() {
            const gridRect = grid.getBoundingClientRect();

            let left = drag.x - drag.offsetX - gridRect.left;
            let top = drag.y - drag.offsetY - gridRect.top;

            const maxLeft = Math.max(0, grid.clientWidth - drag.width);
            const maxTop = Math.max(0, grid.scrollHeight - drag.height);

            left = Math.min(Math.max(left, 0), maxLeft);
            top = Math.min(Math.max(top, 0), maxTop);

            drag.card.style.transform =
                `translate3d(${left}px, ${top}px, 0)`;
        }

        /** Which card (if any) the pointer is over, and where the placeholder should go. */
        function updateOrder() {
            const others = getCards().filter(card => card !== drag.card);
            const x = drag.x;
            const y = drag.y;

            const target = others.find(card => {
                const r = card.getBoundingClientRect();
                return x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
            });

            // After a swap, wait until the pointer leaves that card before
            // swapping with it again (stops cards of different sizes flickering).
            if (drag.lockTarget && drag.lockTarget !== target) {
                drag.lockTarget = null;
            }

            if (target && target !== drag.lockTarget) {
                const slots = Array.from(grid.children).filter(
                    el => el !== drag.card
                );
                const placeholderIndex = slots.indexOf(drag.placeholder);
                const targetIndex = slots.indexOf(target);

                movePlaceholder(() => {
                    if (placeholderIndex < targetIndex) {
                        target.after(drag.placeholder);
                    } else {
                        target.before(drag.placeholder);
                    }
                });

                drag.lockTarget = target;
                return;
            }

            // Below every card: the end of the grid.
            if (!target && others.length) {
                const last = others[others.length - 1];
                const lastRect = last.getBoundingClientRect();

                if (
                    y > lastRect.bottom &&
                    grid.lastElementChild !== drag.placeholder &&
                    !(grid.lastElementChild === drag.card &&
                      drag.card.previousElementSibling === drag.placeholder)
                ) {
                    movePlaceholder(() => grid.appendChild(drag.placeholder));
                }
            }
        }

        /** Scroll the page while the pointer is in the top or bottom part of the screen. */
        function edgeScroll() {
            const h = window.innerHeight;
            const zone = Math.max(EDGE_SCROLL_MIN_ZONE, h * EDGE_SCROLL_RATIO);
            let speed = 0;

            if (drag.y < zone) {
                speed = -EDGE_SCROLL_MAX * Math.min(1, (zone - drag.y) / zone);
            } else if (drag.y > h - zone) {
                speed = EDGE_SCROLL_MAX * Math.min(1, (drag.y - (h - zone)) / zone);
            }

            // Ease in so the scroll starts gently instead of lurching.
            speed = Math.sign(speed) * Math.pow(Math.abs(speed) / EDGE_SCROLL_MAX, 1.5) * EDGE_SCROLL_MAX;

            if (Math.abs(speed) >= 0.5) {
                const before = window.scrollY;
                window.scrollBy(0, speed);
                // The card is positioned from the pointer every frame, so it
                // stays under the finger/cursor while the page moves.
                return window.scrollY !== before;
            }

            return false;
        }

        function tick() {
            if (!drag) return;

            edgeScroll();
            positionCard();
            updateOrder();

            drag.frame = requestAnimationFrame(tick);
        }

        function endDrag(cancelled) {
            if (!drag) return;

            const { card, placeholder, handle, pointerId } = drag;
            cancelAnimationFrame(drag.frame);
            drag = null;

            if (handle.hasPointerCapture && handle.hasPointerCapture(pointerId)) {
                handle.releasePointerCapture(pointerId);
            }

            const finish = () => {
                placeholder.replaceWith(card);
                card.classList.remove("is-lifted", "is-settling");
                card.style.transform = "";
                card.style.width = "";
                card.style.height = "";
                grid.classList.remove("is-sorting");
                document.body.classList.remove("dashboard-is-dragging");
                document.documentElement.style.overflowAnchor = "";

                if (!cancelled) saveLayout();
            };

            if (prefersReducedMotion) {
                finish();
                return;
            }

            // Glide into the slot, then drop back into the normal flow.
            const gridRect = grid.getBoundingClientRect();
            const slot = placeholder.getBoundingClientRect();
            const left = slot.left - gridRect.left;
            const top = slot.top - gridRect.top;

            card.classList.add("is-settling");
            card.style.transform = `translate3d(${left}px, ${top}px, 0)`;

            let done = false;
            const once = () => {
                if (done) return;
                done = true;
                finish();
            };

            card.addEventListener("transitionend", once, { once: true });
            setTimeout(once, 260);
        }

        let pending = null;

        grid.addEventListener("pointerdown", event => {
            const handle = event.target.closest(".dashboard-drag-handle");
            if (!handle || !grid.contains(handle) || drag) return;
            if (event.pointerType === "mouse" && event.button !== 0) return;

            pending = {
                handle,
                pointerId: event.pointerId,
                x: event.clientX,
                y: event.clientY
            };

            handle.setPointerCapture(event.pointerId);
            event.preventDefault();   // no text selection / native image drag
        });

        grid.addEventListener("pointermove", event => {
            if (drag && event.pointerId === drag.pointerId) {
                drag.x = event.clientX;
                drag.y = event.clientY;
                event.preventDefault();
                return;
            }

            if (!pending || event.pointerId !== pending.pointerId) return;

            const moved = Math.hypot(
                event.clientX - pending.x,
                event.clientY - pending.y
            );

            if (moved >= DRAG_THRESHOLD) {
                const { handle, x, y } = pending;
                pending = null;
                startDrag(event, handle, x, y);
                drag.x = event.clientX;
                drag.y = event.clientY;
            }
        });

        const stop = event => {
            if (pending && event.pointerId === pending.pointerId) {
                pending = null;
            }
            if (drag && event.pointerId === drag.pointerId) {
                endDrag(event.type === "pointercancel");
            }
        };

        grid.addEventListener("pointerup", stop);
        grid.addEventListener("pointercancel", stop);
        grid.addEventListener("lostpointercapture", stop);

        // Escape puts the card back where the drag started.
        document.addEventListener("keydown", event => {
            if (event.key === "Escape" && drag) {
                drag.lockTarget = null;
                movePlaceholder(() => drag.card.before(drag.placeholder));
                endDrag(true);
            }
        });

        // Keyboard: focus the handle and use the arrow keys to move the card.
        grid.addEventListener("keydown", event => {
            const handle = event.target.closest(".dashboard-drag-handle");
            if (!handle || drag) return;

            const card = handle.closest(".dashboard-draggable-card");
            const list = getCards();
            const index = list.indexOf(card);
            let target = null;

            if (event.key === "ArrowUp" || event.key === "ArrowLeft") {
                target = list[index - 1];
                if (target) {
                    event.preventDefault();
                    const before = new Map(list.map(c => [c, c.getBoundingClientRect()]));
                    target.before(card);
                    animateReflow(before);
                }
            } else if (event.key === "ArrowDown" || event.key === "ArrowRight") {
                target = list[index + 1];
                if (target) {
                    event.preventDefault();
                    const before = new Map(list.map(c => [c, c.getBoundingClientRect()]));
                    target.after(card);
                    animateReflow(before);
                }
            }

            if (target) {
                handle.focus();
                saveLayout();
            }
        });

        // The old HTML5 drag events are not used any more.
        grid.addEventListener("dragstart", event => {
            if (event.target.closest(".dashboard-drag-handle")) {
                event.preventDefault();
            }
        });

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
        matchInboxHeight();
    }

    /* ----------------------------------------------------------
       Inbox height = Email Details & AI Assistant height
       The details card keeps its natural height; on desktop the
       Inbox card is given the same height (its list scrolls
       inside). Updates whenever the details card changes size.
    ---------------------------------------------------------- */

    function matchInboxHeight() {
        const inboxCard = document.querySelector(
            '#customDashboardGrid > [data-card-id="inbox"]'
        );
        const detailCard = document.querySelector(
            '#customDashboardGrid > [data-card-id="email-details"]'
        );

        if (!inboxCard || !detailCard) return;

        const desktop = window.matchMedia("(min-width: 901px)");

        function sync() {
            if (!desktop.matches) {
                inboxCard.classList.remove("is-height-matched");
                inboxCard.style.removeProperty("--inbox-match-height");
                return;
            }

            const height = detailCard.getBoundingClientRect().height;

            if (height > 0) {
                inboxCard.style.setProperty(
                    "--inbox-match-height",
                    height + "px"
                );
                inboxCard.classList.add("is-height-matched");
            }
        }

        if ("ResizeObserver" in window) {
            new ResizeObserver(sync).observe(detailCard);
        }

        if (desktop.addEventListener) {
            desktop.addEventListener("change", sync);
        } else if (desktop.addListener) {
            desktop.addListener(sync);
        }

        window.addEventListener("resize", sync);
        sync();
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
