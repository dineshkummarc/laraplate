---
status: closed
created_on: 2026-10-19
---
# MES clock skew on agent resends. Implementation Plan

> **For agentic workers:** Steps use checkbox (`- [ ]`) syntax. Tick each step when it is done, in the same work block.

**Status:** Closed (2026-10-19). Delivered as planned.

**Goal:** a late resend after a network outage no longer opens a false `clock_skew` incident, while a sender clock that is really ahead or behind is still reported.

**Why:** the edge agent resends its sealed batch byte-identical after a temporary error (stack spec `docs/superpowers/specs/2026-10-12-laraplate-edge-agent-design.md`, 6.3), so `sent_at` is the seal time. After any outage longer than `mes.machine.clock_skew_seconds`, the first message received is that stale resend and `MachineMessageInbox::checkClock()` opens `clock_skew`, which points operators at NTP although the clock is fine. Found by the final review of the laraplate-edge agent core plan.

## Decisions

- **K1.** `sent_at` ahead of the server by more than the threshold opens the incident at once: a resend can only be late, never early.
- **K2.** `sent_at` behind by more than the threshold opens the incident only when the next new message of the same source is behind too. The first one is remembered in the cache for one day (`mes:machine:clock-behind:{source_id}`). A message within the threshold forgets it and resolves the incident, as today.
- **K3.** Duplicates are not checked (unchanged: they return before the checks).

## Tasks

### Task 1: Inbox rule, tests, docs

**Files:** modify `Modules/MES/app/Machine/MachineMessageInbox.php`, `Modules/MES/tests/Feature/Machine/MachineMessageInboxTest.php`, `Modules/MES/docs/MACHINE_CONNECTIVITY.md`.

- [x] **Step 1: Failing tests.** One message behind by 10 minutes then one on time: no incident. Two consecutive messages behind: one incident. Ahead: incident at the first message (existing test). A message on time between two behind ones: no incident.
- [x] **Step 2: Implementation** (K1, K2).
- [x] **Step 3: Docs** (`clock_skew` paragraph in MACHINE_CONNECTIVITY.md).
- [x] **Step 4: Run the MES machine tests, Pint, commit; close the plan.**

## Delivery status (2026-10-19)

Delivered as planned: `MachineMessageInbox::checkClock()` reports a clock ahead at once (K1) and a clock behind only on two consecutive late messages, remembering the first in the cache for one day (K2); duplicates are not checked (K3). Tests in `MachineMessageInboxTest` (one late message then on time, two consecutive late, a duplicate late; the existing ahead and open-while-it-lasts tests unchanged). No divergence.

**Documented in:** `Modules/MES/docs/MACHINE_CONNECTIVITY.md` (envelope notes, `clock_skew`).
