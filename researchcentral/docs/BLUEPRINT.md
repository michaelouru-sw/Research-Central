# Thesis Management System for Moodle 5.0 — Blueprint

**Strategy:** use Moodle's core activities as the workflow engine; add one thin local plugin (`local_researchcentral`) as the tracking, reminder and analytics layer. Upgrade-safe, no core hacks.

## 1. Architecture

| Layer | Component | Role |
|---|---|---|
| Workflow | Core activities | Submission, feedback, review, meetings, gating |
| Tracking | `local_researchcentral` | Stage template, thesis record, milestones, status sync |
| Notification | Scheduled task + Message API | Due-soon and overdue reminders to student and supervisor |
| Insight | Analytics page (Chart API) | Bottlenecks, supervisor load, turnaround |

**Unit of deployment:** Moodle activities live inside courses, so a thesis programme needs a container course (e.g. "MEd Thesis", "PhD Research", one per school). Research Central is **opt-in per course**: a site admin designates thesis courses under *Site administration, Plugins, Local plugins, Thesis courses*. All other courses are untouched: no menu item, no reminders, no document checks, no event processing. Each thesis course holds its own stage template.

**Creating a thesis course:** on the course creation form, tick *This is a thesis course* and choose the programme level (Undergraduate, Masters, Doctorate) (needs `createcourse` capability; managers and course creators by default). The plugin enables the tracker, creates nine sections (concept note, proposal, defence, ethics, chapters 1-3, chapters 4-5, final, viva, corrections), one completion-tracked Assignment per document stage (optional, setting *Create Assignments*), and the linked stages with default due days.

## 2. Stage → Activity map (course template)

| Stage | Activity | Completion rule |
|---|---|---|
| Topic & concept note | Assignment | Receive grade / pass grade |
| Proposal | Assignment (+ similarity plugin) | Pass grade = approved |
| Proposal defence | BigBlueButton / Scheduler | Manual (supervisor confirms) |
| Ethics / research permit | Assignment or Feedback form | Pass grade |
| Chapters 1–3 … n | One Assignment per chapter, multiple attempts, feedback files | Pass grade |
| Panel / peer review | Workshop | Receive grade |
| Fortnightly progress log | Feedback or Database | Submit |
| Supervisor channel | Forum, separate groups (one group per supervisor) | n/a |
| Final submission | Assignment (+ similarity check) | Pass grade |
| Viva | BigBlueButton / Scheduler | Manual |
| Corrections | Assignment | Pass grade |

Gating: *Restrict access* chains chapters (Chapter 2 opens after Chapter 1 passes). Optional badges per milestone.

**Rule:** a stage linked to an activity closes automatically on completion; stages with no activity (defences) are confirmed by the supervisor.

## 3. Data model

- `local_researchcentral_stage`: course, name, linked cmid, level (all/ug/masters/phd), sort order, default days after start
- `local_researchcentral_thesis`: course, student, supervisor, level, title, start date (unique per course + student)
- `local_researchcentral_ms`: thesis, stage, due date, status (pending / complete / overdue), time completed
- `local_researchcentral_log`: reminder send log (prevents duplicates)

Assigning a student instantiates milestones from the stage template: due = start date + default days.

## 4. Status sync

- **Real time:** observer on `course_module_completion_updated`.
- **Daily:** scheduled task re-evaluates all milestones (flags overdue).
- **On view:** dashboard and analytics refresh the course first.

## 5. Reminders

- Daily task at 06:00.
- Due-soon: sent on configured days before due (default 7, 3, 1); one send per day-mark per milestone.
- Overdue: repeated every N days (default 7) until closed.
- Recipients: student + supervisor (supervisor toggle in settings).
- Channel: Moodle messaging (popup, email, mobile app per user preferences).

## 6. Roles and capabilities

| Capability | Default roles |
|---|---|
| `manage` (stages, assign students) | Teacher (editing), Manager |
| `supervise` (see own supervisees) | Teacher, Teacher (editing), Manager |
| `viewown` | Student |
| `viewanalytics` | Teacher (editing), Manager |

## 7. Analytics (v0.1)

- Milestones by status (pie)
- Progress by stage, stacked complete / pending / overdue (bottleneck view)
- Supervisor workload: theses, completion ratio, overdue count
- Average days from start to completion per stage
- Theses by level

## 7b. Document checker (v0.2)

**Principle:** advisory quality gate. Checks structure and format; does not judge writing quality or source authenticity.

- **Trigger:** student saves a file in a linked Assignment (queued 2 minutes later), or presses *Check document*; supervisors and coordinators can run it too.
- **Rules per stage** (*Manage → Rules*): word min/max, page min/max, required sections, font, size, line spacing, minimum margin, referencing style (APA/Harvard), minimum references, share of recent references. Each rule can warn or fail.
- **Reliability:**

| Check | Word (.docx) | PDF |
|---|---|---|
| Word count, sections, referencing structure | Yes | Yes (needs `pdftotext`) |
| Font, size, spacing, margins | Yes | Skipped, reported as "not checked" |
| Page count | From Word's saved value | Exact |

- **Referencing checks:** reference list found; entry count; recent-source share; every in-text citation has a list entry and the reverse; citation punctuation consistent with the chosen style.
- **Report:** per-rule pass / warning / fail with the reason; visible to the student, their supervisor and coordinators; notification sent on automatic checks.
- **Analytics:** pass / warning / fail by stage and the most common issues.
- **Out of scope:** plagiarism (use Moodle similarity plugins on the same Assignment), source authenticity, argument quality. Optional AI feedback layer deferred pending a data-protection decision.

## 8. Rollout

1. Build the thesis course template (Section 2) and enable completion on each activity.
2. Install the plugin, set reminder days in *Site administration → Plugins → Local plugins → Research Central*.
3. Define stages in the course (*Research Central → Manage stages and students*).
4. Enrol students and supervisors; assign each student a supervisor and start date.
5. Pilot with one cohort; review analytics after one month.

## 9. Known gaps / next iterations

- Privacy API: export and delete handlers still to implement.
- No PHPUnit or Behat tests yet. The document engine was tested standalone on generated Word files; the Moodle integration is untested on a live site.
- Document checker: tables are excluded from word count; reference matching uses first author word plus year, so corporate authors may need manual review; scanned PDFs are not supported.
- Analytics: CSV export, cohort comparison, Report builder custom report source.
- Per-student due-date extensions; examiner/panel role; auto-sync with Moodle groups.
- Supervisor email digest (weekly) in addition to per-milestone reminders.
- Not yet run against a live Moodle 5.0 instance: install on a staging site first.


## 10. Central tracker (v1.0): site-level workspace

Replaces the course-based setup as the primary experience. Modelled on journal submission systems (Editorial Manager, Springer Nature): a stage pipeline, versioned submissions, reviewer decisions, one central dashboard. No course needed.

- **Programmes** (coordinator): name, school, level; stage template with due days, upload required or supervisor-confirmed, and document-check rules per stage.
- **Projects** (coordinator assigns): student + supervisor + programme + title + start date; stages and due dates are generated.
- **Workflow per stage:** Not started, Under review (student uploads a version), then supervisor Approves or Requests revision with feedback; a revision means a new version. Overdue is computed from the due date.
- **Views by role (automatic):** Student sees their supervisor, pipeline, submissions and bookings. Supervisor sees only students assigned to them, whatever the school. Coordinator sees all, with school, level and status filters, assigns students, manages programmes, analytics.
- **Appointments:** supervisor publishes slots (repeat weekly); student books with a topic, or cancels (slot reopens); notices both ways and a reminder the day before.
- **Document checker:** runs on each uploaded version when the stage has rules.
- **Notifications:** assigned, submitted, decision, booking, cancellation, due-soon, overdue, document-check report.
- **Access control:** students see only their own project; supervisors only their supervisees; files are served only to those two and coordinators. Coordinator = site admin or role with *Coordinate all thesis projects* (system level).
- The earlier course-based mode remains as opt-in legacy and is not shown in the menu unless used.

### 10.1 v1.1 additions
- **Marks:** each stage can be marked out of N with an optional weight; the supervisor enters the mark when approving (required on approve), students see marks per stage and a weighted overall mark.
- **Discussion thread** per project (replaces the core message link): stored, notifies the other party, visible to student, supervisor and coordinators.
- **Deadline extensions:** student requests with a reason; supervisor or coordinator approves or declines; due date updates automatically.
- **Late flag** on versions uploaded after the due date.
- **Audit trail** per project: creation, submissions, decisions and marks, messages, extensions, supervisor changes.
- **Supervisor handover:** coordinator reassigns a project; future appointments with the old supervisor are cancelled; all three people are notified.
- **CSV export** of progress and overall marks (coordinators).
- **Upload limits:** file types .docx/.pdf, size limit from settings.

### 10.2 v1.2: schools, allocations, analytics
- **Schools:** created and managed only by the site administrator (no free text). Programmes pick a school from the list; earlier typed schools are converted on upgrade.
- **Bulk allocations (coordinator):** export current allocations as CSV, download a blank template, import with a preview (new project / supervisor change / no change / error per row) and apply only when confirmed. Students and supervisors matched by email or username; programme by exact name.
- **Analytics (learning-analytics view):** filters for school, level, programme, supervisor, status and period apply to every chart. Charts: status, stage bottlenecks (stacked), supervisor workload, school by level, average mark per stage, overall mark distribution, review turnaround per stage, submissions per month on time vs late. KPIs: average mark, review turnaround, late rate, completion rate. A watch-list flags students with 2+ overdue stages or 30+ days without a submission.

### 10.3 v1.3: school coordinators
- The site administrator assigns one or more coordinators per school (Schools page). Site-wide coordinators (admin or system role with the coordinate capability) still see everything.
- A school coordinator sees and manages only their school(s): projects, programmes and stages, assigning students, supervisor changes, CSV import and export, analytics, extension decisions. Programmes outside their schools are rejected on import and in forms; opening another school's project or file is refused.
- Schools themselves stay administrator-only.

### 10.4 v1.4: bulk import hub
CSV import (with preview and confirm) and export for schools and school coordinators (site administrator), programmes and programme stages (coordinators, within their schools), plus the existing allocations import. Users are created with Moodle's own Upload users and matched by email or username. Imports never delete records; existing stages with the same name are updated.

### 10.5 v1.5 - Data, communication and forums
- **Download data** (`dataset.php`): three tidy CSVs scoped to the coordinator's schools and the analytics filters. *Students* (one row per thesis: state, progress, marks, timeliness, engagement, days since activity), *Stages* (one row per student-stage), *Events* (audit trail). Optional anonymised export (hashed ids, no names/emails/titles) and a data dictionary. Every download is logged.
- **Communicate** (`broadcast.php`): coordinators write to students/supervisors in their schools; the site admin can also write to school coordinators and everyone. Filter by school, level, programme, supervisor, status, inactivity; preview and untick recipients; merge fields (`{firstname}`, `{stage}`, `{overdue}`...). Channels: direct email (guaranteed) or Moodle notification. Up to 40 recipients send immediately, more are queued to cron in batches of 200. History is kept. Scope is re-checked server-side on send.
- **Forums** (`forums.php`, `forum.php`, `thread.php`): auto-created from the data, nobody is enrolled by hand: *My supervision group*, *Programme*, *School*, *Level*, *Everyone*, plus a staff-only room. Q&A style: ask, reply, mark accepted answer, pin, lock, search, unanswered filter, unread badge. Supervisors and coordinators post alongside students and are labelled by role. Moderation: global coordinators everywhere, school coordinators in their school/programme, a supervisor in their own group. Access follows allocations automatically (handover moves the student to the new group).


### 10.6 v1.6 - Journal-style review, supervision teams, meetings, grades
- Supervision team: lead + co-supervisors (Masters/PhD, max set in settings); lead listed first.
- Review workflow: every supervisor submits a review (recommendation, comments, confidential note, attachments); decision when all agree, coordinator decides on conflict; rounds, decision letters and all reviews retained (Feedback archive, dataset kind `reviews`).
- Response times: first response and decision times vs SLA (setting), per supervisor analytics.
- Extensions: system limits (per thesis, per stage), second eye (supervisor endorses, different coordinator confirms).
- Meetings: slot generator (date range, weekdays, 3 windows), allocation to a student, agenda/private note/minutes, Zoom/Meet/Teams links (saved links, course activity links), join button with evidence, Moodle calendar + .ics + Google Calendar, reminders (24h, 1h), dual attendance verification.
- Diploma level (6-stage template). Grades export (Excel sheet per programme / CSV) per school, programme, supervisor.
- UI: full-width responsive layout (`report` page layout + styles.css).
- Not included: Zoom/Meet API auto-creation, gradebook sync, examiner roles, rubrics, PHPUnit/Behat.

### 10.7 v1.7 - Receipts and privacy
- Receipt table (`local_researchcentral_receipt`): message seen, submission downloaded, feedback file opened. Ticks on messages (sent / seen by some / seen by all, names and times on hover), unread badges in the portal, download flags per supervisor on every submission version, "student opened feedback" flag, staff downloads of student work in the audit trail.
- Privacy API: export and deletion (discussion text erased, receipts deleted; academic records retained for QA).

### 10.8 v1.8 - Researcher workspace
- `workspace.php` tabs: What next (per-role action list), Feedback responses (issue-by-issue response-to-reviewers with supervisor verification), Ethics and permits (expiry reminders at 30/7 days), Publications, monthly Check-ins (support flag, supervisor reply), Viva and corrections (schedule, examiner reports, outcome, corrections deadline, sign-off).
- `compare.php`: paragraph and word-level diff between versions (docx and text PDF). Similarity percentage per version with a threshold flag.
- Early warning (`risk.php`): weighted signals (overdue, inactivity, stale revision, review waiting, no or missed meetings, missed check-ins, support asked, permit expired, similarity); portal column and tile; weekly Monday digest to supervisors and coordinators; dataset columns.
- `seminars.php` (events, registration, attendance), `comments.php` (comment bank inserted in the review form).
- Daily task `research_reminders`; settings for inactivity days, similarity threshold, check-ins. Review decision is now transactional and idempotent. Mobile push (airnotifier) enabled by default for all providers.
- Not built: reference-manager sync (needs Zotero/Mendeley keys), peer writing groups, AI pre-submission check, examiner login accounts (panel is recorded by staff).

### 10.9 Read and comment (v1.9.0)
- `read.php?ver=` shows a submitted .docx, text PDF or .txt as numbered paragraphs. Staff add comments under any paragraph (optional quoted text, optional supervisors-only flag). The student replies and marks a thread addressed; staff resolve or reopen.
- Table `local_researchcentral_ann` (verid, pmsid, paraidx, parahash, quote, parentid, status open/addressed/resolved, private). Open threads follow an unchanged paragraph (same text hash) into later versions; threads on edited text are listed separately.
- Read receipts reuse `local_researchcentral_receipt` with kind `ann`. One notification per author per version per 15 minutes. Privacy: export, and body text erased on deletion.
- `download.php?ver=&fmt=docx|pdf` (v1.9.1): the .docx gets each thread as a Word comment on its paragraph (threads on edited text go to a closing section); PDF report for any file type. Access follows `portal::require_view`; supervisors-only comments are included only for staff.

### 10.10 Scale notes (v1.9.3)
Load test, 3,000 projects / 27k stages / 18k messages / 36k activity rows (14 MB of tables): coordinator pages about 1 s, project and reader pages 0.1-0.2 s, role check 3-7 indexed queries per page (memoised per request). Daily reminder job sends one digest per student and per supervisor and does no per-row queries: steady state 10 queries, first run on a backlog of 3,400 overdue stages 25 s. Storage is dominated by uploaded files, not tables.

### 10.11 v2.0.0 - Seven additions and an audit pass
- **Comment to review item.** A supervisor can queue a public inline comment ("Add to my review"). The review form opens with the queued comments appended as numbered points ("3. (paragraph 7) ..."). On save each comment is linked to its point (`ann.reviewid`, `ann.itemno`) so the student's issue-by-issue response tracker and the document view both show "Review point N". The student sees the link only after the round's decision.
- **Meeting log** (`Meetings` tab, class `meetings`). Agenda (student or staff), minutes (staff only), agreed actions with owner and due date. Open actions appear in the owner's "what next"; overdue actions add the `actions_overdue` risk signal; held meetings count towards "no meeting in 60 days". Read receipt (kind `meet`) shows staff whether the student opened the minutes. Students may edit only meetings they created.
- **External examiners** (`examiners.php` for staff, `exam.php` for the examiner). No Moodle account: a 192-bit random link per invitation, scoped to one stage of one thesis, expiring (default 30 days), revocable and extendable. The examiner reads the submissions, comments inline (stored with `userid = -examid`, supervisors-only until shared), downloads the files and sends one report (locked after sending; also written to the viva panel when a viva exists). The page sends `Referrer-Policy: no-referrer`, uses no session, and every query is scoped by the invitation. Invitation e-mail uses `email_to_user`; if it fails the link is still shown to staff.
- **Leadership dashboard** (`leadership.php`, class `leadership`). Aggregate SQL only: completed/started/in progress/overdue per school and level, median time to complete, first response and decision times, bottlenecks by stage, supervisor load (limit in settings), completions per month. Scoped to the schools the viewer coordinates. Cached for an hour (MUC definition `leadership`); a PDF for quarterly reports.
- **Generated documents** (`Documents` tab, `document.php`, `verify.php`). Viva invitation, progress report, supervision certificate, completion letter and ethics clearance letter as PDF with a QR code. Each has a 12-character code in `local_researchcentral_doc`; `verify.php?c=CODE` is public and shows only the document type, the person's name, programme and date. Coordinators can revoke a code.
- **Ethics workflow** (`Ethics` tab, `ethics.php`). Student application, committee queue (capability `local/researchcentral:ethicsreview` or school coordinators), decision approved / approved with conditions / not approved. Approval writes the permit tracker entry and enables the clearance letter.
- **Repository handoff** (`repository.php`, class `repo`). Completed theses with their final approved document, licence and embargo exported as a DSpace Simple Archive zip (`dublin_core.xml`, `metadata_thesis.xml`, `contents`, file) plus `index.csv`. Up to 40 per download.
- **Audit fixes in this release.** `read.php` and queueing used a stage record without `needsupload`, raising an "undefined property" for staff once a stage was no longer under review (found with developer debugging on). Privacy `get_users_in_context` aliasing. Duplicate language key `activity` split into `activity` and `linked_activity`. Date format key `strftimedatefull` replaced by `strftimedate` in generated documents.
- Not built (by request): the AI pre-submission check. Not built: peer groups, reference-manager sync, notes pinned on the original PDF.

### 10.12 v2.1.0: upload rules, figures, interaction layer
- Thesis text is accepted as `.docx` only (extension, ZIP structure and `word/document.xml` are checked). Size limit: project override, else stage setting, else site default, never above the administrator ceiling. Supervisors and coordinators set the project limit on the stage page; coordinators set the stage limit on the programme page.
- Figures and diagrams are uploaded in separate image slots (png, jpg, gif, webp; svg refused), each with caption, alt text and location. They belong to a version, carry forward to the next version, and each has its own comment thread in the reader (`ann.figid`).
- `js/tt.js` (loaded on plugin pages by a hook): drop zones, client-side file checks, upload progress, confirm dialogs, draft autosave, countdowns, sortable and filterable tables, reader keys (j/k), larger text. Everything degrades to plain forms.
- Home pages lead with one next step per role; breadcrumbs on project pages; one search box (`search.php`).
