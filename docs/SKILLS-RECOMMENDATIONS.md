# FlexiQueue — Recommended Cursor Skills

**Created:** 2026-02-11  
**Bead:** flexiqueue-7h2  
**Source:** Used `find-skills` skill + `npx skills find` and skills.sh leaderboard

---

## Project Context

FlexiQueue uses **Laravel 12**, **Svelte 5 + Inertia.js**, **TailwindCSS 4 + DaisyUI 5**, MariaDB, WebSocket (Reverb), and PWA. No Laravel, PHP, Svelte, or DaisyUI-specific skills exist in the ecosystem yet; recommendations focus on Tailwind, frontend, testing, API design, and workflow.

---

## High Priority (install first)

| Skill | Install Command | Why |
|-------|-----------------|-----|
| **tailwind-design-system** | `npx skills add wshobson/agents@tailwind-design-system -g -y` | DaisyUI sits on Tailwind; design-system patterns map well. |
| **web-design-guidelines** | `npx skills add vercel-labs/agent-skills@web-design-guidelines -g -y` | 91K+ installs; frontend UX standards. |
| **frontend-design** | `npx skills add anthropics/skills@frontend-design -g -y` | 61K+ installs; component and layout guidance. |
| **webapp-testing** | `npx skills add anthropics/skills@webapp-testing -g -y` | Phase 1 quality gates; web app testing patterns. |
| **api-design-principles** | `npx skills add wshobson/agents@api-design-principles -g -y` | Matches 08-API-SPEC-PHASE1.md and API design contracts. |

---

## Medium Priority (nice to have)

| Skill | Install Command | Why |
|-------|-----------------|-----|
| **tailwind-css** | `npx skills add bobmatnyc/claude-mpm-skills@tailwind-css -g -y` | Tailwind-specific knowledge for utilities and classes. |
| **e2e-testing-patterns** | `npx skills add wshobson/agents@e2e-testing-patterns -g -y` | E2E flows for triage, station flow, informant display. |
| **javascript-testing-patterns** | `npx skills add wshobson/agents@javascript-testing-patterns -g -y` | Unit/integration tests for Svelte. |
| **accessibility-compliance** | `npx skills add wshobson/agents@accessibility-compliance -g -y` | WCAG AA per 07-UI-UX-SPECS. |
| **responsive-design** | `npx skills add wshobson/agents@responsive-design -g -y` | Mobile-first staff and admin UIs. |
| **requesting-code-review** | `npx skills add obra/superpowers@requesting-code-review -g -y` | Aligns with agentic workflow code review triggers. |

---

## Lower Priority (optional)

| Skill | Install Command | Why |
|-------|-----------------|-----|
| **error-handling-patterns** | `npx skills add wshobson/agents@error-handling-patterns -g -y` | Matches 06-ERROR-HANDLING.md. |
| **web-component-design** | `npx skills add wshobson/agents@web-component-design -g -y` | General component design. |
| **frontend** | `npx skills add miles990/claude-software-skills@frontend -g -y` | Broad frontend guidance. |

---

## Gaps (no matching skills)

- **Laravel** — No Laravel/PHP skills found.
- **Svelte** — No Svelte-specific skills (Vue skills exist).
- **DaisyUI** — No DaisyUI-specific skills; Tailwind skills are closest.

Consider creating a project-specific skill for DaisyUI + FlexiQueue patterns (see `create-skill` in `.cursor/skills-cursor`).

---

## Already Installed (Cursor skills)

- `find-skills` — `~\.agents\skills\find-skills` (discover & install skills)
- `create-rule` — `.cursor/skills-cursor/create-rule`
- `create-skill` — `.cursor/skills-cursor/create-skill`
- `update-cursor-settings` — `.cursor/skills-cursor/update-cursor-settings`

---

## Quick Install (top 5)

```bash
npx skills add wshobson/agents@tailwind-design-system -g -y
npx skills add vercel-labs/agent-skills@web-design-guidelines -g -y
npx skills add anthropics/skills@frontend-design -g -y
npx skills add anthropics/skills@webapp-testing -g -y
npx skills add wshobson/agents@api-design-principles -g -y
```
