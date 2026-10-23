<?php
/**
 * dashboard/index.php - the home screen: the SME Smart AI email dashboard.
 *
 * The inbox shows the user's real Gmail when connected (loaded as JSON from
 * /gmail/inbox by assets/js/dashboard.js), otherwise demo emails. The AI
 * summary, reply draft and suggested follow-up are generated in the browser.
 */
$firstName = trim(explode(' ', trim((string) $user['name']))[0] ?? '') ?: (string) $user['name'];
$hour      = (int) date('G');
[$greeting, $greetingIcon] = $hour < 12 ? ['Good morning', '☀️'] : ($hour < 18 ? ['Good afternoon', '🌤️'] : ['Good evening', '🌙']);

view('layouts/header', [
    'title'  => 'Dashboard',
    'search' => false,
    'styles' => ['assets/css/dashboard.css'],
]);
?>


<!-- ========== WELCOME BANNER ========== -->

<!-- ========== PREMIUM WELCOME BANNER ========== -->
<section class="welcome-banner" id="welcomeBanner">

    <div class="welcome-text">
        <h1>
            <?= e($greeting) ?>,
            <?= e($firstName) ?>!
            <?= $greetingIcon ?>
        </h1>

        <p>Here's what matters in your emails today.</p>

        <!-- EMAIL STATISTICS -->
        <section class="stats-grid" aria-label="Email statistics">

            <a class="stat-card purlpe" href="#inbox" data-inbox-shortcut="all">
                <div class="stat-icon">
                    <i class="fa-solid fa-envelope"></i>
                </div>
                <div class="stat-details">
                    <h2 id="newEmailCount">—</h2>
                    <p>New emails</p>
                </div>
                <span class="stat-trend positive">↑ 28%</span>
            </a>

            <a class="stat-card blue" href="#inbox" data-inbox-shortcut="unread">
                <div class="stat-icon">
                    <i class="fa-solid fa-star"></i>
                </div>
                <div class="stat-details">
                    <h2 id="replyCount">—</h2>
                    <p>Need your reply</p>
                </div>
                <span class="stat-trend negative">↑ 2</span>
            </a>

            <a class="stat-card teal" href="#inbox" data-inbox-shortcut="meeting">
                <div class="stat-icon">
                    <i class="fa-solid fa-calendar-days"></i>
                </div>
                <div class="stat-details">
                    <h2 id="meetingCount">—</h2>
                    <p>Meeting requests</p>
                </div>
                <span class="stat-trend positive">↑ 1</span>
            </a>

            <a class="stat-card yellow" href="#inbox" data-inbox-shortcut="important">
                <div class="stat-icon">
                    <i class="fa-solid fa-flag"></i>
                </div>
                <div class="stat-details">
                    <h2 id="importantCount">—</h2>
                    <p>Important updates</p>
                </div>
                <span class="stat-trend warning">↑ 3</span>
            </a>

        </section>
    </div>

    <!-- 3D ROBOT POSITION -->
    <div class="ai-bot-space" id="aiBotSpace"></div>

    <!-- ANIMATED AI ELEMENTS -->
    <div class="wb-float wb-mail" aria-hidden="true">
        <i class="fa-solid fa-envelope"></i>
        <div>
            <strong>New Email</strong>
            <small></small>
        </div>
    </div>

    <div class="wb-float wb-summary" aria-hidden="true">
        <i class="fa-solid fa-wand-magic-sparkles"></i>
        <div>
            <strong>AI Summary</strong>
            <small></small>
        </div>
    </div>

    <div class="wb-float wb-reply" aria-hidden="true">
        <i class="fa-solid fa-paper-plane"></i>
        <div>
            <strong>Smart Reply</strong>
            <small></small>
        </div>
    </div>

    <span class="wb-spark one" aria-hidden="true"></span>
    <span class="wb-spark two" aria-hidden="true"></span>

</section>


<!-- ========== 3D AI BOT ========== -->
<div
    class="ai-bot-3d"
    id="aiBot3D"
>
    <div id="aiBotCanvas"></div>

</div>

<div class="page-body" id="dashboardRoot"
     data-user-id="<?= (int) $user['id'] ?>"
     data-csrf-token="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"
     data-gmail-send="<?= htmlspecialchars(url('gmail', ['action' => 'send']), ENT_QUOTES, 'UTF-8') ?>"     
     data-gmail-inbox="<?= $gmailEmail ? e(url('gmail', ['action' => 'inbox'])) : '' ?>"
     data-gmail-message="<?= $gmailEmail ? e(url('gmail', ['action' => 'message'])) : '' ?>"
     data-ai-summarize="<?= htmlspecialchars(url('ai', ['action' => 'summarize']), ENT_QUOTES, 'UTF-8') ?>"
     data-ai-regenerate="<?= htmlspecialchars(url('ai', ['action' => 'regenerate']), ENT_QUOTES, 'UTF-8') ?>"
    data-gmail-sent="<?= $gmailEmail ? e(url('gmail', ['action' => 'sent'])) : '' ?>"
    data-gmail-action="<?= $gmailEmail ? e(url('gmail', ['action' => 'action'])) : '' ?>"
    data-gmail-thread="<?= $gmailEmail ? e(url('gmail', ['action' => 'thread'])) : '' ?>"

    data-gmail-action="<?= e(url('gmail', ['action' => 'action'])) ?>"
    data-gmail-spam="<?= e(url('gmail', ['action' => 'spam'])) ?>"
    data-gmail-trash="<?= e(url('gmail', ['action' => 'trash'])) ?>"

     >
    
    <!-- ========== GMAIL CONNECTION ========== -->
    <section class="brevo-strip gmail-strip" aria-label="Gmail connection">
        <div class="brevo-strip__info">
            <strong>
                <i class="fa-brands fa-google"></i>
                Gmail inbox
                <?php if ($gmailEmail): ?>
                    <span class="badge badge--success"><i class="fa-solid fa-circle-check"></i> Connected</span>
                <?php else: ?>
                    <span class="badge badge--muted">Not Connected</span>
                <?php endif; ?>
            </strong>
            <span id="gmailStatus">
                <?php if ($gmailEmail): ?>
                    Showing the latest emails from <?= e($gmailEmail) ?>.
                <?php elseif ($gmailReady): ?>
                    Connect your Gmail account to view and manage your emails.
                <?php elseif (!($gmailTable ?? true)): ?>
                    The Gmail table is not in the database yet. Run
                    <code>php bin/migrate.php</code> (or migration <code>003</code> in Adminer).
                <?php else: ?>
                    Gmail is not set up yet. Add <code>GMAIL_CLIENT_ID</code> and
                    <code>GMAIL_CLIENT_SECRET</code> to the app's <code>.env</code>.
                <?php endif; ?>
            </span>
        </div>

        <div class="strip-actions">
            <?php if ($gmailEmail): ?>
                <button class="secondary-btn" type="button" id="gmailSyncBtn">
                    <i class="fa-solid fa-rotate"></i> Sync
                </button>
                <form method="post" action="<?= e(url('gmail', ['action' => 'disconnect'])) ?>"
                      data-confirm="Disconnect <?= e($gmailEmail) ?>? The dashboard will show demo emails again."
                      data-confirm-title="Disconnect Gmail?" data-confirm-ok="Disconnect">
                    <?= csrf_field() ?>
                    <button class="secondary-btn" type="submit"><i class="fa-solid fa-link-slash"></i> Disconnect</button>
                </form>
            <?php elseif ($gmailReady): ?>
                <a class="primary-btn" href="<?= e(url('gmail', ['action' => 'connect'])) ?>">
                    <i class="fa-brands fa-google"></i> Connect Gmail
                </a>
            <?php endif; ?>
        </div>
    </section>


<!-- ========== PREMIUM TEAM WORKSPACE PLANNER ========== -->
<section class="workspace-planner" id="workspacePlanner" aria-label="Team tasks and calendar">

    <div class="wp-heading">
        <div class="wp-heading-info">
            <div class="wp-heading-title">
                <span class="wp-heading-icon">
                    <i class="fa-solid fa-briefcase"></i>
                </span>
                <h2>Team Workspace Planner</h2>
            </div>
            <p>Assign tasks, track deadlines and organize your team's schedule.</p>
        </div>

        <span class="wp-label">
            <i class="fa-solid fa-users"></i>
            Workspace
        </span>
    </div>

    <!-- SUMMARY CARDS -->
    <div class="wp-summary">

        <div class="wp-summary-card wp-summary-total">
            <span class="wp-summary-icon">
                <i class="fa-solid fa-chart-pie"></i>
            </span>
            <div class="wp-summary-info">
                <span>Total tasks</span>
                <strong id="wpTotal">0</strong>
            </div>
            <div class="wp-summary-extra">
                <div class="wp-progress-track">
                    <div class="wp-progress-fill" id="wpProgressFill"></div>
                </div>
                <small id="wpProgressText">0% completed</small>
            </div>
        </div>

        <div class="wp-summary-card wp-summary-pending">
            <span class="wp-summary-icon">
                <i class="fa-regular fa-clock"></i>
            </span>
            <div class="wp-summary-info">
                <span>Pending</span>
                <strong id="wpPending">0</strong>
            </div>
            <div class="wp-summary-extra wp-summary-status">
                <i class="fa-solid fa-circle"></i>
                <span id="wpPendingStatus">No pending tasks</span>
            </div>
        </div>

        <div class="wp-summary-card wp-summary-completed">
            <span class="wp-summary-icon">
                <i class="fa-regular fa-circle-check"></i>
            </span>
            <div class="wp-summary-info">
                <span>Completed</span>
                <strong id="wpCompleted">0</strong>
            </div>
            <div class="wp-summary-extra wp-summary-status">
                <span class="wp-complete-line"></span>
                <span id="wpCompletedStatus">All caught up!</span>
            </div>
        </div>

    </div>

    <!-- MAIN WORKSPACE -->
    <div class="wp-columns">

        <!-- TO-DO LIST -->
        <section class="wp-card wp-todo-panel" aria-label="Team to-do list">

            <div class="wp-card-heading">
                <h3>
                    <i class="fa-solid fa-list-check"></i>
                    Team To-Do List
                </h3>

                <button class="wp-primary" type="button" id="wpAddTask">
                    <i class="fa-solid fa-plus"></i>
                    Add Task
                </button>
            </div>

            <div class="wp-filters">
                <button type="button" class="wp-filter is-active" data-wp-filter="all">
                    All <span id="wpAllFilterCount"></span>
                </button>
                <button type="button" class="wp-filter" data-wp-filter="pending">
                    Pending <span id="wpPendingFilterCount"></span>
                </button>
                <button type="button" class="wp-filter" data-wp-filter="done">
                    Completed <span id="wpDoneFilterCount"></span>
                </button>
            </div>

            <div id="wpTaskList" class="wp-task-list" aria-live="polite"></div>

        </section>

        <!-- CALENDAR + EVENTS -->
        <div class="wp-calendar-area">

            <section class="wp-card wp-calendar-panel" aria-label="Team calendar">

                <div class="wp-card-heading">
                    <h3>
                        <i class="fa-regular fa-calendar-days"></i>
                        Team Calendar
                    </h3>
                </div>

                <div class="wp-monthbar">
                    <button type="button" id="wpPrevMonth" aria-label="Previous month">
                        <i class="fa-solid fa-chevron-left"></i>
                    </button>

                    <strong id="wpMonthTitle"></strong>

                    <button type="button" id="wpNextMonth" aria-label="Next month">
                        <i class="fa-solid fa-chevron-right"></i>
                    </button>
                </div>

                <div class="wp-calendar" id="wpCalendar" aria-label="Calendar days"></div>

                <div class="wp-agenda">
                    <h4 id="wpAgendaTitle">Selected date</h4>
                    <div id="wpAgendaItems"></div>
                </div>

            </section>

            <section class="wp-card wp-events-panel" aria-label="Upcoming events">

                <div class="wp-card-heading">
                    <h3>
                        <i class="fa-regular fa-calendar-check"></i>
                        Upcoming Events
                    </h3>

                    <button class="wp-primary" type="button" id="wpAddEvent">
                        <i class="fa-solid fa-plus"></i>
                        Event
                    </button>
                </div>

                <div class="wp-upcoming-list" id="wpUpcomingEvents">
                    <div class="wp-empty">No upcoming events.</div>
                </div>

            </section>

        </div>
    </div>

    <p class="wp-disclaimer">
        <i class="fa-solid fa-circle-info"></i>
        Tasks and events are saved in this browser for now.
    </p>

</section>

    <div class="wp-modal-backdrop" id="wpModal" hidden>
        <div class="wp-modal" role="dialog" aria-modal="true" aria-labelledby="wpModalTitle">
            <div class="wp-modal-heading"><h3 id="wpModalTitle">New task</h3><button type="button" id="wpCloseModal" aria-label="Close">&times;</button></div>
            <form id="wpForm">
                <label for="wpTitle">Title</label><input id="wpTitle" name="title" maxlength="150" required placeholder="e.g. Follow up invoice" />
                <div id="wpTaskFields">
                    <label for="wpAssignee">Assign to (worker name)</label><input id="wpAssignee" name="assignee" maxlength="100" placeholder="e.g. Sarah" />
                    <label for="wpPriority">Priority</label><select id="wpPriority" name="priority"><option>High</option><option selected>Medium</option><option>Low</option></select>
                </div>
                <label for="wpDate">Date / deadline</label><input id="wpDate" name="date" type="date" required />
                <p class="wp-date-warning" id="wpDateWarning" hidden><i class="fa-solid fa-triangle-exclamation"></i> This date has already passed. The task will be marked <strong>Expired</strong>.</p>
                <div class="wp-modal-actions"><button type="button" id="wpCancel" class="wp-cancel">Cancel</button><button type="submit" class="wp-primary">Save</button></div>
            </form>
        </div>
    </div>
    <style>
      .workspace-planner{margin:22px 0;padding:22px;border:1px solid #e9e5fb;border-radius:17px;background:#faf9ff;color:#24254d}
      .wp-heading,.wp-card-heading,.wp-monthbar,.wp-modal-heading{display:flex;align-items:center;justify-content:space-between;gap:12px}
      .wp-heading h2{font-size:19px;margin:0 0 6px;font-weight:750}.wp-heading h2 i,.wp-card-heading h3 i{color:#6c58ef;margin-right:7px}.wp-heading p{font-size:12px;color:#8185a0;margin:0}.wp-label{background:#ebe7ff;color:#6853dc;padding:6px 11px;border-radius:30px;font-size:11px;font-weight:700}
      .wp-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:17px 0}.wp-summary>div,.wp-card{background:#fff;border:1px solid #eae8f5;border-radius:13px;padding:17px}.wp-summary span{font-size:12px;color:#8589a0;display:block}.wp-summary strong{font-size:25px;display:block;margin-top:4px;color:#25264c}
      .wp-columns{display:grid;grid-template-columns:1fr 1fr;gap:14px}.wp-card-heading h3{margin:0;font-size:15px}.wp-primary{border:0;border-radius:9px;background:#6d59ef;color:#fff;padding:9px 12px;font-size:12px;font-weight:700;cursor:pointer}.wp-primary:hover{background:#5c49d5}
      .wp-filters{display:flex;gap:5px;margin:16px 0 9px}.wp-filter{background:transparent;border:0;padding:7px 11px;border-radius:8px;font-size:12px;color:#8589a0;cursor:pointer}.wp-filter.is-active{background:#eeebff;color:#6b55e8;font-weight:700}
      .wp-task-list{max-height:350px;overflow-y:auto}.wp-task{display:flex;align-items:flex-start;gap:10px;padding:13px 2px;border-bottom:1px solid #f0eef8}.wp-task input{margin-top:4px;accent-color:#6d59ef}.wp-task-content{flex:1;min-width:0}.wp-task-title{font-size:13px;font-weight:700;overflow-wrap:anywhere}.wp-task.is-done .wp-task-title{text-decoration:line-through;color:#9698a9}.wp-task-meta{font-size:11px;color:#8b8ea5;margin-top:5px;display:flex;flex-wrap:wrap;gap:7px}.wp-priority{border-radius:20px;padding:2px 7px;background:#f0edff;color:#6955dc}.wp-priority.high{background:#ffe9e9;color:#c54949}.wp-priority.low{background:#e6f7ed;color:#25845c}.wp-delete{border:0;background:transparent;color:#9a9ab0;cursor:pointer}.wp-task-actions{display:flex;align-items:center;gap:2px;flex:0 0 auto}.wp-edit{border:0;background:transparent;color:#9a9ab0;cursor:pointer}.wp-edit:hover{color:#6d59ef}.wp-expired{border-radius:20px;padding:2px 7px;background:#ffe9e9;color:#c54949;font-weight:700}.wp-task.is-expired .wp-task-title{color:#b54848}.wp-task.is-expired .wp-due{color:#c54949}.wp-date-warning{margin:8px 0 0;padding:8px 10px;border-radius:8px;background:#fff4e5;color:#9a5b00;font-size:12px;line-height:1.45}.wp-date-warning[hidden]{display:none}.wp-empty{padding:22px 4px;color:#9295ab;font-size:12px;text-align:center}
      .wp-monthbar{margin:18px 0 12px}.wp-monthbar strong{font-size:14px}.wp-monthbar button{background:#f5f2ff;border:0;border-radius:7px;padding:7px 10px;cursor:pointer;color:#6d59ef}.wp-calendar{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px}.wp-weekday{text-align:center;font-size:11px;color:#9a9cb0;padding:6px 0}.wp-day{border:0;background:#f9f8ff;min-height:36px;border-radius:7px;cursor:pointer;color:#414366;font-size:12px;position:relative}.wp-day:hover{background:#efebff}.wp-day.selected{background:#6d59ef;color:#fff}.wp-day.has-item:after{content:'';width:5px;height:5px;border-radius:50%;background:#f0a843;position:absolute;bottom:4px;left:calc(50% - 2.5px)}.wp-day.selected:after{background:#fff}.wp-calendar .wp-blank{min-height:36px}.wp-agenda{border-top:1px solid #eeeaf9;margin-top:16px;padding-top:12px}.wp-agenda h4{font-size:12px;margin:0 0 10px}.wp-agenda-item{padding:8px 10px;margin:6px 0;background:#f8f6ff;border-radius:8px;font-size:12px}.wp-agenda-item span{color:#878ba6;font-size:11px}.wp-disclaimer{font-size:11px;color:#9395ac;margin:13px 0 0}
      .wp-modal-backdrop[hidden]{display:none!important}.wp-modal-backdrop{position:fixed;inset:0;background:#1d1d3a99;z-index:10000;display:flex;justify-content:center;align-items:center;padding:15px}.wp-modal{background:white;border-radius:15px;padding:22px;width:min(420px,100%);box-shadow:0 12px 40px #15153530}.wp-modal-heading h3{font-size:18px;margin:0}.wp-modal-heading button{background:none;border:0;font-size:24px;cursor:pointer}.wp-modal label{display:block;font-size:12px;color:#656981;font-weight:650;margin:13px 0 5px}.wp-modal input,.wp-modal select{display:block;width:100%;height:42px;box-sizing:border-box;border:1px solid #e2e0ee;border-radius:8px;padding:0 12px;font:inherit;font-size:13px;color:#28294b;background:#fff}.wp-modal select{appearance:none;-webkit-appearance:none;padding-right:36px;cursor:pointer;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23656981' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") no-repeat right 12px center}.wp-modal input:focus,.wp-modal select:focus{outline:none;border-color:#9587f0;box-shadow:0 0 0 3px rgba(109,92,231,.1)}.wp-modal-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:19px}.wp-cancel{border:1px solid #e6e3f4;background:#fff;padding:9px 12px;border-radius:9px;cursor:pointer}
      @media(max-width:850px){.wp-columns{grid-template-columns:1fr}}@media(max-width:550px){.workspace-planner{padding:13px}.wp-summary>div,.wp-card{padding:11px}.wp-heading h2{font-size:16px}.wp-summary strong{font-size:21px}}
    </style>
    <script>
    (function () {
      'use strict';
      document.addEventListener('DOMContentLoaded', function () {
        const root = document.getElementById('workspacePlanner');
        if (!root) return;
        const el = id => document.getElementById(id);
        const userId = document.getElementById('dashboardRoot')?.dataset.userId || 'guest';
        const storageKey = 'smart_sme_planner_' + userId;
        const today = new Date();
        const localISO = date => [date.getFullYear(), String(date.getMonth()+1).padStart(2,'0'),String(date.getDate()).padStart(2,'0')].join('-');
        const escapeHTML = text => String(text).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        let data;
        try { const parsed = JSON.parse(localStorage.getItem(storageKey) || '{}'); data = {tasks:Array.isArray(parsed.tasks)?parsed.tasks:[],events:Array.isArray(parsed.events)?parsed.events:[]}; } catch (_) { data={tasks:[],events:[]}; }
        let filter='all', month=new Date(today.getFullYear(),today.getMonth(),1), selected=localISO(today), modalType='task';
        const save=()=>{try{localStorage.setItem(storageKey,JSON.stringify(data));}catch(e){console.warn('Planner local storage unavailable',e);}};
        // A task is Expired when it is not done and its date / deadline is before today.
        const isExpired=t=>!t.done&&!!t.date&&t.date<localISO(new Date());
        let editingId=null;
        function renderTasks(){
          el('wpTotal').textContent=data.tasks.length;el('wpPending').textContent=data.tasks.filter(t=>!t.done).length;el('wpCompleted').textContent=data.tasks.filter(t=>t.done).length;
          const shown=data.tasks.filter(t=>filter==='all'||(filter==='done'?t.done:!t.done));
          el('wpTaskList').innerHTML=shown.length?shown.map(t=>'<div class="wp-task '+(t.done?'is-done ':'')+(isExpired(t)?'is-expired':'')+'"><input type="checkbox" data-task-toggle="'+escapeHTML(t.id)+'" aria-label="Mark task complete" '+(t.done?'checked':'')+'><div class="wp-task-content"><div class="wp-task-title">'+escapeHTML(t.title)+'</div><div class="wp-task-meta"><span><i class="fa-regular fa-user"></i> '+escapeHTML(t.assignee||'Unassigned')+'</span><span class="wp-due"><i class="fa-regular fa-calendar"></i> '+escapeHTML(t.date)+'</span><span class="wp-priority '+escapeHTML(String(t.priority||'Medium').toLowerCase())+'">'+escapeHTML(t.priority||'Medium')+'</span>'+(isExpired(t)?'<span class="wp-expired"><i class="fa-regular fa-clock"></i> Expired</span>':'')+'</div></div><div class="wp-task-actions"><button class="wp-edit" type="button" data-task-edit="'+escapeHTML(t.id)+'" aria-label="Edit task" title="Edit task"><i class="fa-regular fa-pen-to-square"></i></button><button class="wp-delete" type="button" data-task-delete="'+escapeHTML(t.id)+'" aria-label="Delete task" title="Delete task"><i class="fa-solid fa-trash"></i></button></div></div>').join(''):'<div class="wp-empty">No tasks here yet. Click Add Task to get started.</div>';
          el('wpTaskList').querySelectorAll('[data-task-toggle]').forEach(box=>box.addEventListener('change',()=>{const t=data.tasks.find(t=>t.id===box.dataset.taskToggle);if(t){t.done=box.checked;save();render();}}));
          el('wpTaskList').querySelectorAll('[data-task-edit]').forEach(btn=>btn.addEventListener('click',()=>{const t=data.tasks.find(t=>t.id===btn.dataset.taskEdit);if(t)openModal('task','',t);}));
          el('wpTaskList').querySelectorAll('[data-task-delete]').forEach(btn=>btn.addEventListener('click',()=>{const t=data.tasks.find(t=>t.id===btn.dataset.taskDelete);if(!t)return;confirmDelete(t).then(ok=>{if(!ok)return;data.tasks=data.tasks.filter(x=>x.id!==t.id);save();render();});}));
        }
        function renderCalendar(){
          el('wpMonthTitle').textContent=month.toLocaleDateString('en-US',{month:'long',year:'numeric'});
          const year=month.getFullYear(),mon=month.getMonth(),offset=(new Date(year,mon,1).getDay()+6)%7,days=new Date(year,mon+1,0).getDate();
          let html=['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].map(d=>'<div class="wp-weekday">'+d+'</div>').join('');
          for(let i=0;i<offset;i++)html+='<div class="wp-blank"></div>';
          for(let day=1;day<=days;day++){const date=year+'-'+String(mon+1).padStart(2,'0')+'-'+String(day).padStart(2,'0');const has=data.events.some(e=>e.date===date)||data.tasks.some(t=>t.date===date&&!t.done);html+='<button type="button" class="wp-day '+(date===selected?'selected ':'')+(has?'has-item':'')+'" data-date="'+date+'" aria-label="'+date+'">'+day+'</button>';}
          el('wpCalendar').innerHTML=html;
          el('wpCalendar').querySelectorAll('[data-date]').forEach(b=>b.addEventListener('click',()=>{selected=b.dataset.date;renderCalendar();}));
          el('wpAgendaTitle').textContent=new Date(selected+'T12:00:00').toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric',year:'numeric'});
          const items=[...data.tasks.filter(t=>t.date===selected).map(t=>({title:t.title,kind:t.done?'Completed task':(isExpired(t)?'Expired task':'Task'),assignee:t.assignee})),...data.events.filter(e=>e.date===selected).map(e=>({title:e.title,kind:'Event',assignee:''}))];
          el('wpAgendaItems').innerHTML=items.length?items.map(i=>'<div class="wp-agenda-item"><strong>'+escapeHTML(i.title)+'</strong><br><span>'+escapeHTML(i.kind)+(i.assignee?' · '+escapeHTML(i.assignee):'')+'</span></div>').join(''):'<div class="wp-empty">Nothing scheduled for this date.</div>';
        }
        
function renderUpcomingEvents() {
    const container = el('wpUpcomingEvents');
    if (!container) return;

    const todayISO = localISO(new Date());

    const upcoming = data.events
        .filter(event => event.date >= todayISO)
        .sort((a, b) => a.date.localeCompare(b.date))
        .slice(0, 4);

    container.innerHTML = upcoming.length
        ? upcoming.map(event => {
            const dateText = new Date(
                event.date + 'T12:00:00'
            ).toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            });

            return `
                <div class="wp-upcoming-item">
                    <span class="wp-event-dot"></span>
                    <div class="wp-event-info">
                        <strong>${escapeHTML(event.title)}</strong>
                        <small>${escapeHTML(dateText)}</small>
                    </div>
                </div>
            `;
        }).join('')
        : '<div class="wp-empty">No upcoming events. Click + Event to add one.</div>';
}

function renderPlannerSummary() {
    const total = data.tasks.length;
    const completed = data.tasks.filter(task => task.done).length;
    const pending = total - completed;
    const percentage = total
        ? Math.round((completed / total) * 100)
        : 0;

    el('wpProgressFill').style.width = percentage + '%';
    el('wpProgressText').textContent = percentage + '% completed';

    el('wpPendingStatus').textContent =
        pending === 0
            ? 'No pending tasks'
            : pending + ' task' + (pending === 1 ? '' : 's') + ' remaining';

    el('wpCompletedStatus').textContent =
        total > 0 && pending === 0
            ? 'All caught up!'
            : completed + ' completed';

    el('wpAllFilterCount').textContent = '(' + total + ')';
    el('wpPendingFilterCount').textContent = '(' + pending + ')';
    el('wpDoneFilterCount').textContent = '(' + completed + ')';
}

function render() {
    renderTasks();
    renderCalendar();
    renderPlannerSummary();
    renderUpcomingEvents();
}

        function updateDateWarning(){const w=el('wpDateWarning');if(!w)return;const v=el('wpDate').value;w.hidden=!(modalType==='task'&&v&&v<localISO(new Date()));}
        function openModal(type,prefill='',task=null) {modalType=type;editingId=task?task.id:null;el('wpModalTitle').textContent=task?'Edit Team Task':(type==='task'?'Create Team Task':'Add Calendar Event');el('wpTaskFields').hidden=type==='event';el('wpTitle').value=task?task.title:prefill;el('wpAssignee').value=task?(task.assignee||''):'';el('wpPriority').value=task?(task.priority||'Medium'):'Medium';el('wpDate').value=task?task.date:selected;updateDateWarning();el('wpModal').hidden=false;el('wpTitle').focus();}
        function closeModal(){el('wpModal').hidden=true;editingId=null;}
        el('wpDate').addEventListener('input',updateDateWarning);el('wpDate').addEventListener('change',updateDateWarning);

        /* Delete needs a second confirmation in a popup (the app's confirm dialog). */
        function confirmDelete(task){
          const message='Delete "'+task.title+'"? This task will be removed permanently and cannot be undone.';
          const dialog=document.getElementById('confirmDialog');
          if(!dialog||typeof dialog.showModal!=='function')return Promise.resolve(window.confirm(message));
          dialog.querySelector('[data-confirm-title]').textContent='Delete this task?';
          dialog.querySelector('[data-confirm-message]').textContent=message;
          dialog.querySelector('[data-confirm-button]').textContent='Delete';
          dialog.returnValue='';
          dialog.showModal();
          return new Promise(resolve=>{dialog.addEventListener('close',function onClose(){dialog.removeEventListener('close',onClose);resolve(dialog.returnValue==='confirm');});});
        }
        el('wpAddTask').addEventListener('click',()=>openModal('task'));
        el('wpAddEvent').addEventListener('click',()=>openModal('event'));
        el('wpCloseModal').addEventListener('click',closeModal);el('wpCancel').addEventListener('click',closeModal);
        el('wpModal').addEventListener('click',e=>{if(e.target===el('wpModal'))closeModal();});
        document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!el('wpModal').hidden)closeModal();});
        el('wpForm').addEventListener('submit',e=>{e.preventDefault();const title=el('wpTitle').value.trim(),date=el('wpDate').value;if(!title||!date)return;const id='wp-'+Date.now()+'-'+Math.random().toString(36).slice(2,8);const editing=modalType==='task'&&editingId?data.tasks.find(t=>t.id===editingId):null;if(editing){editing.title=title;editing.date=date;editing.assignee=el('wpAssignee').value.trim();editing.priority=el('wpPriority').value;}else if(modalType==='task')data.tasks.push({id,title,date,assignee:el('wpAssignee').value.trim(),priority:el('wpPriority').value,done:false});else data.events.push({id,title,date});selected=date;const parts=date.split('-');month=new Date(Number(parts[0]),Number(parts[1])-1,1);save();closeModal();render();});
        root.querySelectorAll('[data-wp-filter]').forEach(b=>b.addEventListener('click',()=>{filter=b.dataset.wpFilter;root.querySelectorAll('[data-wp-filter]').forEach(x=>x.classList.toggle('is-active',x===b));renderTasks();}));
        el('wpPrevMonth').addEventListener('click',()=>{month=new Date(month.getFullYear(),month.getMonth()-1,1);renderCalendar();});
        el('wpNextMonth').addEventListener('click',()=>{month=new Date(month.getFullYear(),month.getMonth()+1,1);renderCalendar();});
        const fromEmail=el('wpCreateFromEmail');if(fromEmail)fromEmail.addEventListener('click',()=>{const suggested=el('suggestedAction')?.textContent?.trim()||'';const subject=el('emailSubject')?.textContent?.trim()||'';openModal('task',suggested&&suggested!=='Select an email to view the suggested action.'?suggested.slice(0,150):('Follow up: '+subject).slice(0,150));});
        render();
      });
    })();
    </script>




    <!-- ========== MAIN CONTENT ========== -->
    <div class="main-content">


        <!-- ========== LEFT: INBOX ========== -->
        <section class="inbox-panel" id="inbox" tabindex="-1">

            <div class="panel-header">

                <h2>Inbox</h2>

                <div class="inbox-actions">

                    <label class="inbox-select">
                        <span><i class="fa-solid fa-arrow-down-wide-short" aria-hidden="true"></i> Sort</span>
                        <select id="sortBtn" aria-label="Sort emails by received date">
                            <option value="newest">Newest to oldest</option>
                            <option value="oldest">Oldest to newest</option>
                        </select>
                    </label>

                    <label class="inbox-select">
                        <span><i class="fa-solid fa-filter" aria-hidden="true"></i> Filter</span>
                        <select id="filterBtn" aria-label="Filter emails by category">
                            <option value="all">All categories</option>
                            <option value="Finance">Finance</option>
                            <option value="Meeting">Meeting</option>
                            <option value="Orders">Orders</option>
                            <option value="Partnership">Partnership</option>
                            <option value="General">General</option>
                        </select>
                    </label>

                </div>

            </div>

            <!-- Inbox Search -->
            <div class="inbox-search-wrapper">
                <i class="fa-solid fa-magnifying-glass"></i>

                <input
                    type="search"
                    id="searchInput"
                    placeholder="Search emails by sender, subject..."
                    autocomplete="off"
                    aria-label="Search emails"
                >
            </div>


            <!-- Email Tabs -->
            
            <div class="email-tabs">
                <button type="button" class="tab active" data-filter="all">
                    All
                </button>

                <button type="button" class="tab" data-filter="unread">
                    Unread
                </button>

                <button type="button" class="tab" data-filter="important">
                    Important
                </button>

                <button type="button" class="tab" data-filter="sent">
                    Sent
                </button>

                <button type="button" class="tab" data-filter="spam">
                    Spam
                </button>

                <button type="button" class="tab" data-filter="trash">
                    Trash
                </button>
            </div>



            <!-- Email List -->
            <div class="email-list" id="emailList">

                <!-- Emails will be inserted using JavaScript -->

            </div>

        </section>



        <!-- ========== RIGHT: EMAIL DETAILS ========== -->
        <section class="email-detail-panel">

            <!-- Email Header -->
            <div class="email-detail-header">

                <div class="email-title-area">
                    <h2 id="emailSubject">
                        Partnership Proposal
                    </h2>

                    <span class="email-category"
                          id="emailCategory">
                        Inbox
                    </span>
                </div>

                <div class="email-tools">

                    <button id="starEmailBtn"
                            aria-label="Star email">
                        <i class="fa-regular fa-star"></i>
                    </button>

                    <button id="archiveEmailBtn"
                            aria-label="Archive email">
                        <i class="fa-solid fa-box-archive"></i>
                    </button>

                    <button id="deleteEmailBtn"
                            aria-label="Delete email">
                        <i class="fa-regular fa-trash-can"></i>
                    </button>

                    <button id="moreEmailBtn"
                            aria-label="More options">
                        <i class="fa-solid fa-ellipsis-vertical"></i>
                    </button>


                <div id="emailMoreMenu" class="email-more-menu" hidden>
                    <button type="button" data-gmail-action="read">
                        Mark as Read
                    </button>

                    <button type="button" data-gmail-action="unread">
                        Mark as Unread
                    </button>

                    <button type="button" data-gmail-action="important">
                        Mark as Important
                    </button>

                    <button type="button" data-gmail-action="not_important">
                        Remove Important
                    </button>

                    <button type="button" data-gmail-action="spam">
                        Report Spam
                    </button>

                    <button type="button" data-gmail-action="not_spam">
                        Not Spam
                    </button>

                    <button type="button" data-gmail-action="restore">
                        Restore Email
                    </button>
                </div>


                </div>

            </div>


            <!-- Sender -->
            <div class="sender-section">

                <div class="sender-avatar" id="senderAvatar">
                    KL
                </div>

                <div class="sender-info">

                    <strong id="senderName">Klook</strong>

                    <span id="senderEmail">
                        &lt;partnership@klook.com&gt;
                    </span>

                    <small>to me</small>

                </div>

                <span class="email-time" id="emailTime">
                    10:24 AM
                </span>

            </div>


            <!-- Email Body -->
            <div class="email-body" id="emailBody">

                <p>Hi Sze Yen,</p>

                <p>
                    We are Klook, a leading travel and
                    experience platform in Asia.
                </p>

                <p>
                    We would like to explore a potential
                    partnership with your brand for
                    upcoming campaigns in Malaysia.
                </p>

                <p>
                    Could we schedule a short call next
                    week to discuss the details?
                </p>

                <p>
                    Best regards,<br>
                    Amanda<br>
                    Partnerships Team<br>
                    Klook
                </p>

            </div>
        <button
            type="button"
            id="emailViewMoreBtn"
            class="email-view-more-btn"
            aria-expanded="false"
            hidden
        >
            <span>View More</span>
            <i class="fa-solid fa-chevron-down"></i>
        </button>

            <!-- ========== AI SUMMARY ========== -->
            <div class="ai-summary-card">

                <div class="ai-card-header">

                    <h3>
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                        AI Summary
                    </h3>

                    <button id="copySummaryBtn"
                            aria-label="Copy summary">
                        <i class="fa-regular fa-copy"></i>
                    </button>

                </div>

                <div class="ai-summary-content"
                     id="aiSummary">

                    <strong>Key points</strong>

                    <ul>
                        <li>
                            Klook is interested in a partnership
                            for upcoming campaigns in Malaysia.
                        </li>

                        <li>
                            They would like to schedule a short
                            call next week.
                        </li>

                        <li>
                            Email is from Amanda,
                            Partnerships Team.
                        </li>
                    </ul>

                </div>

            </div>


<!-- ========== AI REPLY ========== -->
<div class="ai-reply-card">

    <div class="ai-card-header">
        <h3>
            <i class="fa-solid fa-robot"></i>
            AI Reply (Draft)
        </h3>

        <select id="replyTone">
            <option value="formal">Formal</option>
            <option value="friendly">Friendly</option>
            <option value="concise">Concise</option>
        </select>
    </div>

    <div class="gmail-reply-editor">

        <div class="gmail-reply-to">
            <i class="fa-solid fa-reply"></i>
            <span>Reply to</span>
            <strong id="replyRecipient">Selected sender</strong>
        </div>

        <textarea
            id="replyDraft"
            rows="8"
            placeholder="Write your reply..."
        ></textarea>

        <div id="replyAttachmentList"
             class="reply-attachment-list"></div>

        <input
            type="file"
            id="replyFileInput"
            multiple
            hidden
        >

        <div class="gmail-reply-toolbar">

            <button type="button"
                    id="attachReplyBtn"
                    title="Attach files">
                <i class="fa-solid fa-paperclip"></i>
                <span>Attach</span>
            </button>

            <button type="button"
                    id="insertSignatureBtn"
                    title="Insert signature">
                <i class="fa-solid fa-signature"></i>
                <span>Signature</span>
            </button>

            <button type="button"
                    id="editSignatureBtn"
                    title="Edit signature">
                <i class="fa-solid fa-pen"></i>
                <span>Edit Signature</span>
            </button>

            <span id="replyDraftStatus">
                Draft not saved
            </span>

        </div>

    </div>

    <div class="reply-actions">
        <button type="button"
                class="primary-btn"
                id="sendReplyBtn">
            <i class="fa-regular fa-paper-plane"></i>
            Send Reply
        </button>

        <button type="button"
                class="secondary-btn"
                id="regenerateReplyBtn">
            <i class="fa-solid fa-rotate"></i>
            Regenerate
        </button>
    </div>

</div>


            <!-- Create a planner task from the selected email (requires user confirmation). -->
            <div class="wp-email-task-action" style="margin:12px 0;text-align:right">
                <button type="button" class="secondary-btn" id="wpCreateFromEmail"><i class="fa-solid fa-list-check"></i> Add Email to Team To-Do</button>
            </div>


        </section>

    </div>

    <!-- SEND REPLY CONFIRMATION MODAL -->
<div class="send-modal-backdrop" id="sendConfirmModal" hidden>
    <div class="send-modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="sendConfirmTitle">

        <div class="send-modal-icon">
            <i class="fa-regular fa-paper-plane"></i>
        </div>

        <h3 id="sendConfirmTitle">Send Email Reply?</h3>

        <p>
            Are you sure you want to send this reply?
            This action cannot be undone.
        </p>

        <div class="send-modal-actions">
            <button type="button"
                    class="secondary-btn"
                    id="cancelSendBtn">
                Cancel
            </button>

            <button type="button"
                    class="primary-btn"
                    id="confirmSendBtn">
                <i class="fa-regular fa-paper-plane"></i>
                Send Reply
            </button>
        </div>

    </div>
</div>


    <!-- ========== FOLLOW-UP HISTORY ========== -->
    <div id="historyModal" class="history-modal" hidden>
        <div class="history-dialog" role="dialog" aria-modal="true" aria-labelledby="historyTitle">
            <div class="history-header">
                <h3 id="historyTitle">Follow-up History</h3>
                <button id="closeHistoryBtn" type="button" aria-label="Close history">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <h4>Created Tasks</h4>
            <div id="createdTasks"></div>

            <h4>Activity Log</h4>
            <div id="activityLog"></div>
        </div>
    </div>


    <!-- ========== NOTIFICATION PANEL ========== -->
    <div class="notification-panel"
         id="notificationPanel"
         hidden>

        <div class="notification-header">
            <h3>Smart Notifications</h3>

            <button id="closeNotificationBtn"
                    aria-label="Close notifications">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div id="notificationList">
            <!-- Smart AI notifications -->
        </div>

    </div>

</div>


<!-- Three.js 3D Engine -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/0.152.2/three.min.js"></script>
<!-- PREMIUM GLB ROBOT LOADER -->
<script type="importmap">
{
  "imports": {
    "three": "https://cdn.jsdelivr.net/npm/three@0.152.2/build/three.module.js",
    "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.152.2/examples/jsm/"
  }
}
</script>

<script type="module">
import * as THREE from "three";
import { GLTFLoader } from "three/addons/loaders/GLTFLoader.js";

  
// asset(): the right address on XAMPP (/SDC-Hackathon/public/...) and on Replit (/assets/...).
const ROBOT_MODEL_URL = <?= json_encode(asset('assets/roboter_cute.glb'), JSON_UNESCAPED_SLASHES) ?>;



function initGLBRobot() {
  const space = document.getElementById("aiBotSpace");
  const bot = document.getElementById("aiBot3D");
  const host = document.getElementById("aiBotCanvas");

  if (!space || !bot || !host) return;

  space.appendChild(bot);

  Object.assign(bot.style, {
    position: "absolute",
    inset: "0",
    width: "100%",
    height: "100%",
    transform: "none",
    cursor: "pointer"
  });

  host.style.width = "100%";
  host.style.height = "100%";

  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(35, 1, 0.1, 100);
  camera.position.set(0, 0.3, 6);

  const renderer = new THREE.WebGLRenderer({
    antialias: true,
    alpha: true,
    powerPreference: "high-performance"
  });

  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.25;
  renderer.domElement.style.pointerEvents = "none";
  host.replaceChildren(renderer.domElement);

  // Soft studio lighting
  scene.add(new THREE.HemisphereLight(0xffffff, 0xb5a7ed, 2));

  const keyLight = new THREE.DirectionalLight(0xffffff, 3);
  keyLight.position.set(-3, 5, 6);
  scene.add(keyLight);

  const fillLight = new THREE.DirectionalLight(0xc6bcff, 1.8);
  fillLight.position.set(4, 1, 3);
  scene.add(fillLight);

  const rimLight = new THREE.DirectionalLight(0xece5ff, 2.5);
  rimLight.position.set(1, 3, -4);
  scene.add(rimLight);

  const robot = new THREE.Group();
  const movement = new THREE.Group();
  scene.add(robot);
  robot.add(movement);

  let loaded = false;
  let state = "idle";
  let mixer = null;
  let currentAction = null;
  let hover = false;

let mouseX = 0;
let mouseY = 0;

space.addEventListener("pointermove", (event) => {
  const rect = space.getBoundingClientRect();

  mouseX = ((event.clientX - rect.left) / rect.width) * 2 - 1;
  mouseY = ((event.clientY - rect.top) / rect.height) * 2 - 1;
});

space.addEventListener("pointerleave", () => {
  mouseX = 0;
  mouseY = 0;
});

  let reactionUntil = 0;
  let reactionStart = 0;

  const clock = new THREE.Clock();
  const reduceMotion = window.matchMedia(
    "(prefers-reduced-motion: reduce)"
  ).matches;

  new GLTFLoader().load(
    ROBOT_MODEL_URL,
    gltf => {
      const model = gltf.scene;

      const bounds = new THREE.Box3().setFromObject(model);
      const center = bounds.getCenter(new THREE.Vector3());
      const size = bounds.getSize(new THREE.Vector3());
      const maxSize = Math.max(size.x, size.y, size.z);

      if (maxSize <= 0) return;

      model.position.sub(center);
      movement.scale.setScalar(2.9 / maxSize);
      movement.add(model);

      model.traverse(child => {
        if (!child.isMesh) return;

        child.frustumCulled = false;

        if (child.material) {
          const materials = Array.isArray(child.material)
            ? child.material
            : [child.material];

          materials.forEach(mat => {
            if ("envMapIntensity" in mat) {
              mat.envMapIntensity = 1.1;
            }
          });
        }
      });

      // Use built-in GLB animation if available
      if (gltf.animations && gltf.animations.length) {
        mixer = new THREE.AnimationMixer(model);

        const preferred = gltf.animations.find(clip =>
          /idle|float|hover|breath/i.test(clip.name)
        ) || gltf.animations[0];

        currentAction = mixer.clipAction(preferred);
        currentAction.play();
      }

      loaded = true;
      console.log("Animated Robot ready");
    },
    undefined,
    error => console.error("Robot loading failed:", error)
  );

  function react(next) {
    state = next;
    bot.dataset.state = next;

    if (next === "success" || next === "happy") {
      reactionStart = clock.getElapsedTime();
      reactionUntil = reactionStart + 1.2;
    }
  }

  window.smartMascot = { react };

  document.addEventListener("sme:mascot", event => {
    if (event.detail?.state) react(event.detail.state);
  });

  bot.addEventListener("mouseenter", () => {
    hover = true;
  });

  bot.addEventListener("mouseleave", () => {
    hover = false;
  });


  function resize() {
    const width = Math.max(host.clientWidth, 1);
    const height = Math.max(host.clientHeight, 1);

    camera.aspect = width / height;
    camera.updateProjectionMatrix();
    renderer.setSize(width, height, false);
  }

  if (window.ResizeObserver) {
    new ResizeObserver(resize).observe(host);
  }

  window.addEventListener("resize", resize);
  resize();

  function animate() {
    requestAnimationFrame(animate);

    const dt = Math.min(clock.getDelta(), 0.05);
    const t = clock.getElapsedTime();

    if (loaded) {
      if (mixer && !reduceMotion) mixer.update(dt);

      if (!reduceMotion) {
        // Natural floating with secondary movement
        robot.position.y =
          0.45 +
          Math.sin(t * 1.15) * 0.085 +
          Math.sin(t * 0.43) * 0.025;

        // Gentle turning and tilting
        

// ===== CUTE MOUSE FOLLOW (FRONT VIEW ONLY) =====

// Very small left/right movement
const targetY =
  Math.sin(t * 0.52) * 0.035 + mouseX * 0.06;

// Slightly look up/down
const targetX = -mouseY * 0.035;

// Gentle head-like tilt
const targetZ =
  Math.sin(t * 0.85) * 0.018 - mouseX * 0.025;

robot.rotation.y = THREE.MathUtils.lerp(
  robot.rotation.y,
  targetY,
  0.045
);

robot.rotation.x = THREE.MathUtils.lerp(
  robot.rotation.x,
  targetX,
  0.045
);

robot.rotation.z = THREE.MathUtils.lerp(
  robot.rotation.z,
  targetZ,
  0.045
);
   

        // Subtle breathing effect
        const breathing = 1 + Math.sin(t * 1.7) * 0.012;
        robot.scale.setScalar(breathing);

        // Friendly hover response
        
if (hover) {
  // Slight happy lift without turning around
  robot.position.y += 0.045;
  robot.scale.multiplyScalar(1.025);
}


        // Thinking reaction
        if (state === "thinking") {
          robot.rotation.z += Math.sin(t * 2.2) * 0.045;
          robot.rotation.y += Math.sin(t * 1.8) * 0.06;
        }

        // One-time happy bounce
        if (t < reactionUntil) {
          const progress = Math.min(
            (t - reactionStart) / 1.2, 1
          );

          const bounce =
            Math.sin(progress * Math.PI) *
            Math.abs(Math.sin(progress * Math.PI * 3));

          robot.position.y += bounce * 0.2;
          robot.rotation.z += Math.sin(progress * Math.PI * 2) * 0.09;
        }
      } else {
        robot.position.y = 0.45;
        robot.rotation.set(0, 0, 0);
        robot.scale.setScalar(1);
      }
    }

    renderer.render(scene, camera);
  }

  animate();
}


if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initGLBRobot);
} else {
  initGLBRobot();
}
</script>

<?php view('layouts/footer', ['scripts' => ['assets/js/dashboard.js']]); ?>
