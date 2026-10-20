
/* =====================================
   SMART SME - DASHBOARD (AI EMAIL INBOX)
   dashboard.js
   Frontend demo using mock data.
   Loaded only on the dashboard page.
===================================== */

document.addEventListener("DOMContentLoaded", () => {

    // Messages use the app's toast (app.js); fall back to alert().
    const notify = (message, type) =>
        window.App ? window.App.toast(message, type || "info") : alert(message);

    // 1. SAMPLE EMAIL DATA
    const emails = [
        {
            id: 1,
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
            id: 2,
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
            id: 3,
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
            id: 4,
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
            id: 5,
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

    // 2. APPLICATION STATE
    let selectedEmailId = 1;
    let activeFilter = "all";
    let searchQuery = "";
    let newestFirst = true;

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
    function showEmail(id) {
        const email = emails.find(e => e.id === id);
        if (!email) return;

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

        emailBody.textContent = email.body;
        emailBody.style.whiteSpace = "pre-line";

        document.getElementById("aiSummary").innerHTML = `
            <strong>Key Points</strong>
            <ul>
                ${email.summary.map(point =>
                    `<li>${escapeHTML(point)}</li>`
                ).join("")}
            </ul>
        `;

        const starIcon =
            document.querySelector("#starEmailBtn i");

        starIcon.className = email.starred
            ? "fa-solid fa-star"
            : "fa-regular fa-star";

        starIcon.style.color = email.starred
            ? "#f4b42e"
            : "";

        generateReply();
        updateStats();
        renderEmails();
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
Smart SME Team`,

            friendly:
`Hi ${email.sender} Team,

Thanks so much for reaching out!

We've received your message about "${email.subject}". We'll take a look and get back to you soon.

Best,
Smart SME Team`,

            concise:
`Hi,

Thank you for your email about "${email.subject}".

We will review it and respond shortly.

Regards,
Smart SME Team`
        };

        replyDraft.value = templates[tone];
    }

    // 7. EMAIL LIST INTERACTIONS
    emailList.addEventListener("click", event => {
        const star = event.target.closest("[data-star-id]");

        if (star) {
            const id = Number(star.dataset.starId);
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
            showEmail(Number(item.dataset.id));
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

    // 22. INITIALIZE DASHBOARD
    showEmail(1);
    renderNotifications();

});
