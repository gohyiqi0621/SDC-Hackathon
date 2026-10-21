/* =====================================
   SMART SME - DASHBOARD (AI EMAIL INBOX)
   dashboard.js - loaded only on the dashboard page.

   - Inbox: the user's real Gmail when connected (JSON from /gmail/inbox),
     otherwise demo emails.
   - AI summary, reply draft and suggested follow-up are generated in the
     browser for now.
   - Approved / dismissed follow-ups and the activity log are kept in this
     browser (localStorage), separately for each signed-in user.
===================================== */

document.addEventListener("DOMContentLoaded", () => {

    const root = document.getElementById("dashboardRoot");
    if (!root) return;

    // Messages use the app's toast (app.js); fall back to notify().
    
    const notify = (message, type) => {
        if (window.App && typeof window.App.toast === "function") {
            window.App.toast(message, type || "info");
        } else {
            console.log(`[${type || "info"}] ${message}`);
        }
    };

    // 1. DEMO EMAILS (shown until Gmail is connected)
    const DEMO_EMAILS = [
        {
            id: "demo-1",
            sender: "Klook",
            senderEmail: "partnership@klook.com",
            subject: "Partnership Proposal",
            preview: "We would like to explore a partnership...",
            body: `Hi Sze Yen,

We are Klook, a travel and experience platform in Asia.

We would like to explore a potential partnership with your brand for upcoming campaigns in Malaysia.

Could we schedule a short call next week to discuss the details?

Best regards,
Amanda
Partnerships Team
Klook`,
            summary: [
                "Klook is interested in a partnership for upcoming campaigns in Malaysia.",
                "They would like to schedule a short call next week.",
                "Action required: Reply with your availability."
            ],
            category: "Partnership",
            time: "10:24 AM",
            unread: true,
            important: true,
            starred: false,
            needsReply: true,
            meeting: true
        },
        {
            id: "demo-2",
            sender: "ABC Supplier",
            senderEmail: "accounts@abcsupplier.com",
            subject: "Urgent Payment Reminder",
            preview: "Your invoice payment is due tomorrow...",
            body: `Dear Team,

This is a reminder that invoice INV-2026-104 has a payment deadline tomorrow.

Please confirm the payment status at your earliest convenience.

Thank you,
ABC Supplier`,
            summary: [
                "Invoice INV-2026-104 is due tomorrow.",
                "Supplier requests payment confirmation.",
                "Action required: Check payment status."
            ],
            category: "Finance",
            time: "9:45 AM",
            unread: true,
            important: true,
            starred: true,
            needsReply: true,
            meeting: false
        },
        {
            id: "demo-3",
            sender: "HR Department",
            senderEmail: "hr@company.com",
            subject: "Staff Training Schedule",
            preview: "Please review the updated training schedule...",
            body: `Hello Team,

The updated staff training schedule is now available.

Please review your assigned sessions and confirm your attendance by Friday.

Thank you,
HR Department`,
            summary: [
                "Updated staff training schedule is available.",
                "Staff must confirm attendance by Friday."
            ],
            category: "HR",
            time: "Yesterday",
            unread: false,
            important: false,
            starred: false,
            needsReply: false,
            meeting: false
        },
        {
            id: "demo-4",
            sender: "Tech Solutions",
            senderEmail: "support@techsolutions.com",
            subject: "Project Meeting Request",
            preview: "Can we arrange a meeting next Monday?",
            body: `Hi Team,

We would like to arrange a project discussion next Monday.

Please let us know your preferred meeting time.

Best regards,
Tech Solutions`,
            summary: [
                "A project meeting is requested for next Monday.",
                "Action required: Suggest a suitable meeting time."
            ],
            category: "Meeting",
            time: "Yesterday",
            unread: true,
            important: false,
            starred: false,
            needsReply: true,
            meeting: true
        },
        {
            id: "demo-5",
            sender: "Shopee Business",
            senderEmail: "business@shopee.com",
            subject: "October Sales Report",
            preview: "Your monthly sales report is ready...",
            body: `Dear Seller,

Your October sales report is ready for review.

Please log in to your seller dashboard for more information.

Best regards,
Shopee Business`,
            summary: [
                "October sales report is available.",
                "No immediate reply is required."
            ],
            category: "Sales",
            time: "Oct 18",
            unread: false,
            important: false,
            starred: false,
            needsReply: false,
            meeting: false
        }
    ];


let emails = [];
    // 2. APPLICATION STATE
    let selectedEmailId = null;
    let activeFilter = "all";
    let searchQuery = "";
    let newestFirst = true;

    // DOMAIN 2 - WORKFLOW STATE

    const STORAGE_KEY = "smartSMEEmailWorkflow:" + (root.dataset.userId || "guest");

    function loadWorkflow() {
        try {
            return JSON.parse(localStorage.getItem(STORAGE_KEY)) || {
                tasks: [],
                activityLog: []
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
            STORAGE_KEY,
            JSON.stringify(workflow)
        );
    }

    function addActivity(emailId, action, details) {
        workflow.activityLog.unshift({
            id: Date.now(),
            emailId: emailId,
            action: action,
            details: details,
            timestamp: new Date().toLocaleString()
        });

        saveWorkflow();
    }

    function approveEmailTask(emailId) {
        const email = emails.find(e => e.id === emailId);
        if (!email) return;

        // Prevent duplicate tasks
        const existingTask = workflow.tasks.find(
            task => task.emailId === emailId
        );

        if (existingTask) {
            notify("A task already exists for this email.", "error");
            return;
        }

        const task = {
            id: Date.now(),
            emailId: email.id,
            title: `Follow up: ${email.subject}`,
            assignedTo: email.category === "Finance"
                ? "Finance Team"
                : "Operations Team",
            status: "Pending",
            createdAt: new Date().toLocaleString()
        };

        workflow.tasks.push(task);

        addActivity(
            email.id,
            "APPROVED",
            `Task created: ${task.title}`
        );

        notify("Approved! Follow-up task created.", "success");
    }

    function rejectEmailTask(emailId) {
        const email = emails.find(e => e.id === emailId);
        if (!email) return;

        addActivity(
            email.id,
            "DISMISSED",
            `Suggested task dismissed: ${email.subject}`
        );

        notify("Suggested task dismissed. No task created.");
    }

    const emailList = document.getElementById("emailList");
    const emailBody = document.getElementById("emailBody");
    const replyDraft = document.getElementById("replyDraft");

    const escapeHTML = (value) => {
        return String(value).replace(/[&<>"']/g, char => ({
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#39;"
        })[char]);
    };

    const initials = (name) =>
        name.split(" ").map(word => word[0]).join("")
            .slice(0, 2).toUpperCase();

    const selectedEmail = () =>
        emails.find(email => email.id === selectedEmailId);

    // 3. UPDATE STATISTICS
    function updateStats() {
        document.getElementById("newEmailCount").textContent =
            emails.filter(e => e.unread).length;

        document.getElementById("replyCount").textContent =
            emails.filter(e => e.needsReply).length;

        document.getElementById("meetingCount").textContent =
            emails.filter(e => e.meeting).length;

        document.getElementById("importantCount").textContent =
            emails.filter(e => e.important).length;

        const counts = {
            all: emails.length,
            unread: emails.filter(e => e.unread).length,
            important: emails.filter(e => e.important).length,
            mentions: emails.filter(e => e.meeting).length
        };

        document.querySelectorAll(".tab").forEach(tab => {
            const filter = tab.dataset.filter;
            const names = {
                all: "All",
                unread: "Unread",
                important: "Important",
                mentions: "Mentions"
            };
            tab.textContent = `${names[filter]} (${counts[filter]})`;
        });
    }

    // 4. RENDER EMAIL LIST
    function renderEmails() {
        let filtered = emails.filter(email => {
            const matchesSearch =
                `${email.sender} ${email.subject} ${email.preview}`
                    .toLowerCase()
                    .includes(searchQuery);

            let matchesFilter = true;

            if (activeFilter === "unread") {
                matchesFilter = email.unread;
            } else if (activeFilter === "important") {
                matchesFilter = email.important;
            } else if (activeFilter === "mentions") {
                matchesFilter = email.meeting;
            }

            return matchesSearch && matchesFilter;
        });

        if (!newestFirst) filtered.reverse();

        if (filtered.length === 0) {
            emailList.innerHTML =
                `<p style="padding:25px;color:#888">
                    No emails found.
                 </p>`;
            return;
        }

        emailList.innerHTML = filtered.map(email => `
            <div class="email-item
                ${email.id === selectedEmailId ? "selected" : ""}"
                data-id="${email.id}">

                <span class="email-dot"
                    style="${email.unread ? "" : "visibility:hidden"}">
                </span>

                <div class="email-avatar">
                    ${escapeHTML(initials(email.sender))}
                </div>

                <div class="email-item-content">
                    <div class="email-item-top">
                        <span class="email-sender">
                            ${escapeHTML(email.sender)}
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

                        <button class="email-star
                            ${email.starred ? "starred" : ""}"
                            data-star-id="${email.id}"
                            aria-label="Toggle star">
                            <i class="${email.starred
                                ? "fa-solid"
                                : "fa-regular"} fa-star"></i>
                        </button>
                    </div>
                </div>
            </div>
        `).join("");
    }

    // 5. SHOW SELECTED EMAIL
    
// 5. SHOW SELECTED EMAIL + GMAIL BODY + SUMMARY API

const gmailMessageUrl = root.dataset.gmailMessage || "";
const aiSummarizeUrl = root.dataset.aiSummarize || "";

const csrfToken = document.querySelector(
    'meta[name="csrf-token"]'
)?.content || "";

let selectionVersion = 0;

// Display the summary in the existing dashboard card.
function renderSummary(email) {
    const points = Array.isArray(email.summary)
        ? email.summary
        : [];

    document.getElementById("aiSummary").innerHTML = `
        <strong>Key Points</strong>
        <ul>
            ${points.map(point =>
                `<li>${escapeHTML(point)}</li>`
            ).join("")}
        </ul>
        ${email.summaryMode === "placeholder"
            ? '<small>Rule-based preview — AI provider not connected yet.</small>'
            : ""}
    `;
}

// Reusable JSON API request.
async function requestJSON(url, options = {}) {
    const response = await fetch(url, {
        credentials: "same-origin",
        ...options,
        headers: {
            "Accept": "application/json",
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

// Load the real Gmail body and generate a temporary summary.
async function loadGmailDetails(email, version) {
    const summaryBox = document.getElementById("aiSummary");

    try {
        // Step 1: Fetch full email body from Gmail.
        if (!email.fullBodyLoaded) {
            const url = new URL(
                gmailMessageUrl,
                window.location.href
            );

            url.searchParams.set("id", email.id);

            const data = await requestJSON(url.toString());

            email.body = typeof data.body === "string"
                ? data.body
                : email.preview;

            email.fullBodyLoaded = true;
        }

        // Avoid displaying results for a previously selected email.
        if (
            version !== selectionVersion ||
            selectedEmailId !== email.id
        ) {
            return;
        }

        emailBody.textContent = email.body;

        // Reuse an already generated summary.
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

        // Step 2: Request summary from PHP backend.
        summaryBox.textContent = "Preparing summary…";

        const data = await requestJSON(aiSummarizeUrl, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": csrfToken
            },
            body: JSON.stringify({
                subject: email.subject,
                body: email.body
            })
        });

        if (
            version !== selectionVersion ||
            selectedEmailId !== email.id
        ) {
            return;
        }

        // Step 3: Save the analysis result.
        email.summary = Array.isArray(data.summary)
            ? data.summary
            : [];

        email.category = typeof data.category === "string"
            ? data.category
            : email.category;

        email.suggestedAction =
            typeof data.suggestedAction === "string"
                ? data.suggestedAction
                : "";

        email.needsReply = Boolean(data.needsReply);
        email.meeting = email.category === "Meeting";
        email.summaryMode = data.mode || "placeholder";
        email.summaryLoaded = true;

        // Step 4: Refresh dashboard components.
        document.getElementById("emailCategory").textContent =
            email.category;

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

        summaryBox.textContent =
            `Unable to generate summary: ${error.message}`;

        notify(error.message, "error");
    }
}

// Display the selected email.
function showEmail(id) {
    const email = emails.find(e => e.id === id);
    if (!email) return;

    const version = ++selectionVersion;

    selectedEmailId = id;
    email.unread = false;

    document.getElementById("emailSubject").textContent =
        email.subject;

    document.getElementById("emailCategory").textContent =
        email.category;

    document.getElementById("senderAvatar").textContent =
        initials(email.sender);

    document.getElementById("senderName").textContent =
        email.sender;

    document.getElementById("senderEmail").textContent =
        `<${email.senderEmail}>`;

    document.getElementById("emailTime").textContent =
        email.time;

    emailBody.style.whiteSpace = "pre-line";
    emailBody.textContent = email.body;

    const isRealGmail =
        Boolean(gmailMessageUrl) &&
        !email.id.startsWith("demo-");

    if (isRealGmail && !email.summaryLoaded) {
        document.getElementById("aiSummary").textContent =
            "Loading email and preparing summary…";
    } else {
        renderSummary(email);
    }

    const starIcon = document.querySelector(
        "#starEmailBtn i"
    );

    starIcon.className = email.starred
        ? "fa-solid fa-star"
        : "fa-regular fa-star";

    starIcon.style.color = email.starred
        ? "#f4b42e"
        : "";

    generateReply();
    updateStats();
    renderEmails();
    renderWorkflow();

    if (isRealGmail && !email.summaryLoaded) {
        loadGmailDetails(email, version);
    }
}



    // 6. SIMULATED AI REPLY
    function generateReply() {
        const email = selectedEmail();
        if (!email) return;

        const tone =
            document.getElementById("replyTone").value;

        const templates = {
            formal:
`Dear ${email.sender} Team,

Thank you for your email regarding "${email.subject}".

We have received your message and will review the details. We will get back to you with an update shortly.

Best regards,
SME Smart Team`,

            friendly:
`Hi ${email.sender} Team,

Thanks so much for reaching out!

We've received your message about "${email.subject}". We'll take a look and get back to you soon.

Best,
SME Smart Team`,

            concise:
`Hi,

Thank you for your email about "${email.subject}".

We will review it and respond shortly.

Regards,
SME Smart Team`
        };

        replyDraft.value = templates[tone];
    }

    // 7. EMAIL LIST INTERACTIONS
    emailList.addEventListener("click", event => {
        const star = event.target.closest("[data-star-id]");

        if (star) {
            const id = star.dataset.starId;
            const email = emails.find(e => e.id === id);

            if (email) {
                email.starred = !email.starred;
                renderEmails();

                if (id === selectedEmailId) {
                    showEmail(id);
                }
            }
            return;
        }

        const item = event.target.closest("[data-id]");

        if (item) {
            showEmail(item.dataset.id);
        }
    });

    // 8. SEARCH
    document.getElementById("searchInput")
        .addEventListener("input", event => {
            searchQuery = event.target.value
                .trim().toLowerCase();
            renderEmails();
        });

    // 9. FILTER TABS
    document.querySelectorAll(".tab").forEach(tab => {
        tab.addEventListener("click", () => {
            document.querySelectorAll(".tab")
                .forEach(t => t.classList.remove("active"));

            tab.classList.add("active");
            activeFilter = tab.dataset.filter;
            renderEmails();
        });
    });

    // 10. SORT
    document.getElementById("sortBtn")
        .addEventListener("click", () => {
            newestFirst = !newestFirst;
            renderEmails();
        });

    // 11. FILTER BUTTON
    document.getElementById("filterBtn")
        .addEventListener("click", () => {
            const options = [
                "all", "unread", "important", "mentions"
            ];

            const index = options.indexOf(activeFilter);
            activeFilter =
                options[(index + 1) % options.length];

            document.querySelectorAll(".tab").forEach(tab => {
                tab.classList.toggle(
                    "active",
                    tab.dataset.filter === activeFilter
                );
            });

            renderEmails();
        });

    // 12. STAR SELECTED EMAIL
    document.getElementById("starEmailBtn")
        .addEventListener("click", () => {
            const email = selectedEmail();
            if (!email) return;

            email.starred = !email.starred;
            showEmail(email.id);
        });

    // 13. DELETE EMAIL (DEMO)
    document.getElementById("deleteEmailBtn")
        .addEventListener("click", () => {
            const email = selectedEmail();
            if (!email) return;

            if (!confirm(`Delete "${email.subject}"?`)) {
                return;
            }

            const index = emails.findIndex(
                e => e.id === email.id
            );

            emails.splice(index, 1);

            if (emails.length > 0) {
                showEmail(emails[0].id);
            } else {
                selectedEmailId = null;
                document.getElementById("emailSubject")
                    .textContent = "No email selected";
                emailBody.textContent = "";
                document.getElementById("aiSummary")
                    .textContent = "";
                replyDraft.value = "";
                updateStats();
                renderEmails();
            }
        });

    // 14. ARCHIVE EMAIL (DEMO)
    document.getElementById("archiveEmailBtn")
        .addEventListener("click", () => {
            notify("Archive feature will be connected to the backend later.");
        });

    // 15. MORE OPTIONS
    document.getElementById("moreEmailBtn")
        .addEventListener("click", () => {
            notify("More email actions coming soon.");
        });

    // 16. REPLY TONE
    document.getElementById("replyTone")
        .addEventListener("change", generateReply);

    // 17. REGENERATE REPLY
    document.getElementById("regenerateReplyBtn")
        .addEventListener("click", () => {
            generateReply();
            notify("Demo reply regenerated!");
        });

    // 18. ADJUST REPLY
    document.getElementById("adjustReplyBtn")
        .addEventListener("click", () => {
            replyDraft.focus();
            notify("You can edit the draft before sending.");
        });

    // 19. SEND REPLY (DEMO ONLY)
    document.getElementById("sendReplyBtn")
        .addEventListener("click", () => {
            notify(
                "Demo mode: Email not sent. " +
                "Connect a real email API later."
            );
        });

    // 20. COPY SUMMARY
    document.getElementById("copySummaryBtn")
        .addEventListener("click", async () => {
            const summary =
                document.getElementById("aiSummary").innerText;

            try {
                await navigator.clipboard.writeText(summary);
                notify("AI Summary copied!", "success");
            } catch {
                notify("Unable to copy summary.", "error");
            }
        });

    // 21. SMART NOTIFICATIONS
    const notificationPanel =
        document.getElementById("notificationPanel");

    function renderNotifications() {
        const importantEmails =
            emails.filter(e => e.important || e.needsReply);

        const container =
            document.getElementById("notificationList");

        container.innerHTML = importantEmails.map(email => `
            <div class="notification-item">
                <strong>${escapeHTML(email.subject)}</strong>
                <p>${escapeHTML(email.summary[0] || "")}</p>
            </div>
        `).join("");
    }

    document.getElementById("notificationBtn")
        .addEventListener("click", () => {
            renderNotifications();
            notificationPanel.hidden =
                !notificationPanel.hidden;
        });

    document.getElementById("closeNotificationBtn")
        .addEventListener("click", () => {
            notificationPanel.hidden = true;
        });

    // DOMAIN 2 - WORKFLOW UI

    function renderHistory() {
        const tasksContainer =
            document.getElementById("createdTasks");

        tasksContainer.innerHTML = workflow.tasks.length
            ? workflow.tasks.map(task => `
                <div class="task-item">
                    <strong>${escapeHTML(task.title)}</strong>
                    <p>Assigned to: ${escapeHTML(task.assignedTo)}</p>
                    <p>Status: ${escapeHTML(task.status)}</p>
                </div>
            `).join("")
            : "No tasks created yet.";

        const logContainer =
            document.getElementById("activityLog");

        logContainer.innerHTML = workflow.activityLog.length
            ? workflow.activityLog.map(log => `
                <div class="log-item">
                    <strong>${escapeHTML(log.action)}</strong>
                    <p>${escapeHTML(log.details)}</p>
                    <small>${escapeHTML(log.timestamp)}</small>
                </div>
            `).join("")
            : "No activity recorded yet.";
    }

    function renderWorkflow() {
        const email = selectedEmail();
        renderHistory();

        const approveBtn = document.getElementById("approveTaskBtn");
        const rejectBtn = document.getElementById("rejectTaskBtn");

        if (!email) {
            document.getElementById("suggestedAction").textContent =
                "Select an email to view the suggested action.";
            document.getElementById("approvalStatus").textContent = "—";
            approveBtn.disabled = true;
            rejectBtn.disabled = true;
            return;
        }

        rejectBtn.disabled = false;

        const existingTask = workflow.tasks.find(
            task => task.emailId === email.id
        );

        const latestRejection = workflow.activityLog.find(
            log => log.emailId === email.id &&
                log.action === "DISMISSED"
        );

        const suggestedAction =
            document.getElementById("suggestedAction");

        
        if (email.suggestedAction) {
            suggestedAction.textContent = email.suggestedAction;
        } else if (email.category === "Finance") {
            suggestedAction.textContent =
                "Verify invoice payment status and create a follow-up task for the Finance Team.";
        } else if (email.meeting) {
            suggestedAction.textContent =
                "Review the meeting request and create a follow-up task.";
        } else if (email.needsReply) {
            suggestedAction.textContent =
                "Review the email and create a follow-up task.";
        } else {
            suggestedAction.textContent =
                "No immediate action required.";
        }

        if (email.category === "Finance") {
            suggestedAction.textContent =
                "Verify invoice payment status and create a follow-up task for the Finance Team.";
        } else if (email.meeting) {
            suggestedAction.textContent =
                "Review the meeting request and create a follow-up task.";
        } else if (email.needsReply) {
            suggestedAction.textContent =
                "Review the email and create a follow-up task.";
        } else {
            suggestedAction.textContent =
                "No immediate action required.";
        }

        const status = document.getElementById("approvalStatus");

        if (existingTask) {
            status.textContent = "Approved";
        } else if (latestRejection) {
            status.textContent = "Dismissed";
        } else {
            status.textContent = "Pending Approval";
        }

        approveBtn.disabled = Boolean(existingTask);


    }

        // APPROVE BUTTON
        document.getElementById("approveTaskBtn")
        .addEventListener("click", () => {
            approveEmailTask(selectedEmailId);
            renderWorkflow();
        });

        // REJECT BUTTON
        document.getElementById("rejectTaskBtn")
        .addEventListener("click", () => {
            rejectEmailTask(selectedEmailId);
            renderWorkflow();
        });

        // HISTORY POPUP

    const historyModal =
        document.getElementById("historyModal");

    document.getElementById("historyBtn")
        .addEventListener("click", () => {
            renderWorkflow();
            historyModal.hidden = false;
        });

    document.getElementById("closeHistoryBtn")
        .addEventListener("click", () => {
            historyModal.hidden = true;
        });

    historyModal.addEventListener("click", event => {
        if (event.target === historyModal) {
            historyModal.hidden = true;
        }
    });

    // 22. INITIALIZE DASHBOARD
    // 22. GMAIL INBOX

    const gmailInboxUrl = root.dataset.gmailInbox || "";
    const syncButton = document.getElementById("gmailSyncBtn");

    const senderName = (from) =>
        (from.replace(/<[^>]*>/, "").replace(/"/g, "").trim()) || from;

    const formatTime = (unix) => {
        if (!unix) return "";
        const date = new Date(unix * 1000);
        const now = new Date();
        if (date.toDateString() === now.toDateString()) {
            return date.toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });
        }
        const yesterday = new Date(now);
        yesterday.setDate(now.getDate() - 1);
        if (date.toDateString() === yesterday.toDateString()) return "Yesterday";
        return date.toLocaleDateString([], { month: "short", day: "numeric" });
    };

    // Simple keyword rules so Gmail emails get a category and a suggestion.
    const categorise = (text, isSpam) => {
        if (isSpam) return "Spam";
        if (/invoice|payment|bill|receipt|quotation|refund|pay\b/i.test(text)) return "Finance";
        if (/meeting|schedule|appointment|call|zoom|teams/i.test(text)) return "Meeting";
        if (/order|delivery|shipping|tracking/i.test(text)) return "Orders";
        if (/partnership|collaborat|proposal/i.test(text)) return "Partnership";
        return "General";
    };

    function fromGmail(mail) {
        const text = `${mail.subject} ${mail.preview}`;
        const category = categorise(text, mail.isSpam);
        const sender = senderName(mail.sender || "Unknown sender");

        return {
            id: mail.id,
            sender: sender,
            senderEmail: (mail.sender || "").match(/<([^>]+)>/)?.[1] || mail.sender || "",
            subject: mail.subject || "(No Subject)",
            preview: mail.preview || "",
            body: mail.preview || "",
            summary: [mail.preview || "No preview available."],
            category: category,
            time: formatTime(mail.timestamp),
            unread: Boolean(mail.unread),
            important: Boolean(mail.important),
            starred: Boolean(mail.starred),
            needsReply: /\?/.test(text) && !mail.isSpam,
            meeting: category === "Meeting",
            isSpam: Boolean(mail.isSpam)
        };
    }

    function showList(list) {
    ++selectionVersion;
    emails = list;
        activeFilter = "all";
        document.querySelectorAll(".tab").forEach(tab =>
            tab.classList.toggle("active", tab.dataset.filter === "all"));

        if (emails.length > 0) {
            showEmail(emails[0].id);
        } else {
            selectedEmailId = null;
            document.getElementById("emailSubject").textContent = "No email selected";
            document.getElementById("emailCategory").textContent = "Inbox";
            document.getElementById("senderAvatar").textContent = "";
            document.getElementById("senderName").textContent = "";
            document.getElementById("senderEmail").textContent = "";
            document.getElementById("emailTime").textContent = "";
            emailBody.textContent = "Your inbox is empty.";
            document.getElementById("aiSummary").textContent = "";
            replyDraft.value = "";
            updateStats();
            renderEmails();
            renderWorkflow();
        }

        renderNotifications();
    }

    async function syncGmailEmails() {
        if (syncButton) {
            syncButton.disabled = true;
            syncButton.classList.add("is-loading");
        }
        emailList.innerHTML = '<p class="inbox-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading your Gmail…</p>';

        try {
            const response = await fetch(gmailInboxUrl, {
                credentials: "same-origin",
                headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" }
            });
            const data = await response.json().catch(() => null);

            if (!data || !data.success) {
                throw new Error((data && data.message) || "Gmail sync failed.");
            }

            showList((data.data.emails || []).map(fromGmail));
            notify(`Gmail synced: ${emails.length} emails.`, "success");
        } catch (error) {
            console.error("Gmail sync failed:", error);
            notify(error.message + " Showing demo emails.", "error");
            showList(DEMO_EMAILS.map(email => ({ ...email })));
        } finally {
            if (syncButton) {
                syncButton.disabled = false;
                syncButton.classList.remove("is-loading");
            }
        }
    }

    if (syncButton) {
        syncButton.addEventListener("click", syncGmailEmails);
    }

    // 23. INITIALIZE DASHBOARD
    if (gmailInboxUrl) {
        syncGmailEmails();
    } else {
        showList(DEMO_EMAILS.map(email => ({ ...email })));
    }

    const userId = root.dataset.userId || "guest";
    const storageKey = "smartSME_dashboard_layout_v1_" + userId;

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

    // Insert the new layout before the existing planner.
    const planner = document.getElementById("workspacePlanner");
    if (!planner) return;

    planner.before(toolbar, grid);

    // Move the EXISTING elements, not copies.
    // This preserves their IDs, state and event listeners.
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

    function getCardElements() {
        return Array.from(
            grid.querySelectorAll(
                ":scope > .dashboard-draggable-card"
            )
        );
    }

    function saveLayout() {
        const order = getCardElements().map(
            card => card.dataset.cardId
        );

        try {
            localStorage.setItem(
                storageKey,
                JSON.stringify(order)
            );
        } catch (error) {
            console.warn("Could not save dashboard layout:", error);
        }
    }

    function restoreLayout() {
        let savedOrder;

        try {
            savedOrder = JSON.parse(
                localStorage.getItem(storageKey) || "null"
            );
        } catch {
            savedOrder = null;
        }

        if (!Array.isArray(savedOrder)) return;

        savedOrder.forEach(id => {
            const card = getCardElements().find(
                item => item.dataset.cardId === id
            );

            if (card) {
                grid.appendChild(card);
            }
        });
    }


    // ==========================================
    // DASHBOARD DRAG & DROP - FULL SIZE
    // ==========================================

    let draggedCard = null;

    grid.addEventListener("dragstart", event => {
        const handle = event.target.closest(
            ".dashboard-drag-handle"
        );

        if (!handle) {
            event.preventDefault();
            return;
        }

        const card = handle.closest(
            ".dashboard-draggable-card"
        );

        if (!card) return;

        draggedCard = card;

        event.dataTransfer.effectAllowed = "move";
        event.dataTransfer.setData(
            "text/plain",
            card.dataset.cardId
        );

        card.classList.add("is-dragging");
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

        getCardElements().forEach(card => {
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
            const elements = getCardElements();

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
        getCardElements().forEach(card => {
            card.classList.remove(
                "is-dragging",
                "drag-over"
            );
        });

        draggedCard = null;
    }

    grid.addEventListener("dragend", cleanupDrag);


    document.getElementById(
        "resetDashboardLayout"
    ).addEventListener("click", () => {
        try {
            localStorage.removeItem(storageKey);
        } catch (error) {
            console.warn(error);
        }

        cards.forEach(config => {
            const card = getCardElements().find(
                item => item.dataset.cardId === config.id
            );

            if (card) grid.appendChild(card);
        });
    });

    restoreLayout();
});

