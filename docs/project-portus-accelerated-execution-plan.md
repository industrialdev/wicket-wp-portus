# Project Portus Accelerated Execution Plan

## Purpose

This document extracts the accelerated lane into an execution-ready plan for three developers:

- Esteban (core orchestration and integration lead)
- Alex (settings adapters and safe import behavior)
- Marlon (content adapters and reporting quality)

The goal is to ship a demoable export -> validate/dry-run -> import flow by end of Wednesday, with Thursday reserved for hardening and demo prep.

## Execution Window

- Coding days: Monday, Tuesday, Wednesday
- Polish and presentation: Thursday
- Friday: out of office

## Non-Negotiable Ship Scope (By End of Wednesday)

- Standalone `wicket-wp-portus` plugin boots and exposes wp-admin flow.
- Manifest v1 contract is locked and unchanged after day 1 checkpoint.
- Export works for must-ship option-backed modules.
- Validate/dry-run works and shows actionable diffs.
- Import works for must-ship option-backed modules.
- Sensitive-data warnings shown on export and import.
- Plugin inventory export + missing-plugin/version mismatch warnings.
- Unsupported surfaces explicitly reported (never silent skip).

## Must-Ship Modules (Option-Backed)

- `wicket_settings`
- `wicket_membership_plugin_options`
- Wicket Gravity Forms plugin options
- Account Centre Carbon Fields options
- Theme ACF option values

## Stretch Scope (Only If Must-Ship Is Stable)

Priority order:

1. Structural pages export + dry-run + slug-based import (if stable)
2. Membership config/tier CPT export + dry-run (import only if stable)
3. Richer module-level reporting polish

## Architecture and Ownership

### Esteban (Core + Orchestration)

- Owns dependency integration and thin transfer-engine bridge.
- Owns wp-admin actions (`export`, `validate/dry-run`, `import`).
- Owns cross-module import ordering and final de-scope calls.
- Owns release-readiness decision at each daily checkpoint.

### Alex (Settings + Safe Imports)

- Owns option-backed adapters and validation logic.
- Owns import mode decisions (`merge` vs `replace`) per option surface.
- Owns settings-path safeguards and warning UX content.

### Marlon (Content + Report Quality)

- Owns structural page adapter (`page`, `my-account`) for stretch scope.
- Owns membership config/tier CPT adapter for stretch scope.
- Owns module-level report readability: changed/created/skipped summaries.

## Implementation Plan

## Day 1 (Monday): Contract Lock + Dependency Integration

### Shared Contract Lock (Required Before Coding Continues)

- [ ] Lock manifest v1 keys and schema version.
- [ ] Lock module IDs and ownership boundaries.
- [ ] Lock import matching rule: slug-first, ID reference-only.
- [ ] Lock de-scope order and checkpoint criteria.

### Esteban Tasks

- [x] Integrate accelerated transfer dependency and pin version.
- [x] Build transfer bridge service with `export`, `diff`, and `import` orchestration.
- [x] Add wp-admin action shells for export/validate/import.
- [x] Wire result envelopes with warnings + per-module status fields.

### Alex Tasks

- [x] Scaffold option adapter layer for must-ship surfaces.
- [x] Implement one complete option module end-to-end (`wicket_settings`) as proof.
- [x] Add module-level payload validation contract.

### Marlon Tasks

- [ ] Scaffold content adapter base for structural pages/CPTs (no hard import work yet).
- [ ] Implement first reporting formatter for module diff summaries.
- [ ] Review manifest ergonomics and flag schema readability gaps before day-end.

### Day 1 Checkpoint (Stop/Go)

- [x] Dependency integrated and bootstraps in plugin runtime.
- [x] One module can export, diff, and import from wp-admin flow.
- [x] Manifest contract still owned by Portus (engine-independent shape preserved).

If checkpoint fails:

- [ ] Cut all content stretch work immediately.
- [ ] Assign both Alex and Marlon to option-backed reliability for day 2.

## Day 2 (Tuesday): Complete Must-Ship Option Modules

### Esteban Tasks

- [x] Integrate all option modules into unified export/validate/import pipeline.
- [x] Implement plugin inventory export with:
- [x] plugin slug
- [x] version
- [x] active/inactive status
- [x] destination missing/version warnings
- [x] Add operator-visible sensitive-data warnings to export/import views.
- [ ] Add standardized module result codes (`ok`, `warn`, `skip`, `error`).

### Alex Tasks

- [x] Complete adapters for:
- [x] `wicket_membership_plugin_options`
- [x] Wicket GF plugin option set
- [x] ACC Carbon Fields options
- [x] theme ACF option values
- [ ] Finalize import-mode policy per module (`merge` default unless proven safe).
- [x] Add strict validation + actionable failure messages.
- [x] Add regression checks for option round-trip behavior.

### Marlon Tasks

- [ ] Pair on option-surface edge cases if blockers appear.
- [ ] Improve dry-run output readability:
- [ ] per-module changed/unchanged/skipped counts
- [ ] warning buckets (missing dependency, unsupported surface, mismatched plugin)
- [ ] Implement structural-page export + dry-run path behind feature flag (no import unless stable).

### Day 2 Checkpoint (Stop/Go)

- [ ] All must-ship option-backed modules round-trip through export/dry-run/import.
- [x] Missing-plugin warnings render correctly.
- [x] Sensitive-data warning appears in both export and import flows.

If checkpoint fails:

- [ ] Freeze all stretch work.
- [ ] Day 3 becomes bug-fix and stabilization only.

## Day 3 (Wednesday): Hardening + Stretch Delivery

### Esteban Tasks

- [ ] Finalize import ordering and failure isolation between modules.
- [ ] Ensure unsupported modules are explicit export-only in output.
- [ ] Run full end-to-end scenario in disposable/staging-like environment.
- [ ] Make midday cut calls for any unstable stretch item.

### Alex Tasks

- [ ] Harden validation and import error handling for all option modules.
- [ ] Fix race/order/idempotency defects in settings imports.
- [ ] Confirm no module depends on numeric IDs as source of truth.

### Marlon Tasks

- [ ] Stabilize structural-page export + dry-run.
- [ ] Add slug-based structural-page import only if reliability is proven.
- [ ] Add membership CPT export + dry-run (import only if stable and non-risky).
- [ ] Finalize report formatting for demo readability.

### Day 3 Ship Checklist

- [ ] Export reliable for all must-ship option-backed modules.
- [ ] Validate/dry-run reliable and understandable.
- [ ] Import reliable for must-ship option-backed modules.
- [ ] Plugin inventory warnings reliable.
- [ ] Sensitive-data warnings present and unmissable.
- [ ] Stretch items either stable and shipped, or clearly cut and documented.

## Thursday: Polish and Demo Readiness

- [ ] Fix defects from Wednesday test run.
- [ ] Improve operator-facing messaging and warnings.
- [ ] Run acceptance pass on at least one real export bundle.
- [ ] Finalize demo script: export -> validate -> import -> report.
- [ ] Capture explicit phase-2 backlog items.

No new module starts on Thursday unless all Wednesday ship criteria are already green.

## De-Scope Rules (Enforced)

Cut in this order when risk rises:

1. Content-backed imports (keep export + dry-run)
2. Membership CPT import before structural-page import
3. Rich report formatting

Never cut:

- Must-ship option-backed import reliability
- Validate/dry-run capability
- Sensitive-data warnings
- Explicit unsupported-surface reporting

## Handoffs and Working Agreements

- No overlapping write scopes unless explicitly marked integration work.
- Shared contracts are frozen after day 1 checkpoint unless all three agree.
- Any blocker older than 90 minutes triggers immediate reassignment.
- Unknown schema version must fail closed.
- No silent fallback behavior; every skip needs a warning/reason.

## Deliverables at End of Week

- Demoable Portus plugin with accelerated transfer-core integration.
- Stable option-backed export/dry-run/import flow.
- Operator-readable reports and warning model.
- Clear list of export-only and deferred surfaces.
- Phase-2 backlog for broader content/WooCommerce/CLI scope.
