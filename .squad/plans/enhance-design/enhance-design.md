# Deskflow — Modern UI/UX Design System & Implementation Blueprint

This document contains the complete specification, component architecture, styling guidelines, and step-by-step implementation guide to apply or reproduce the modern SaaS design system across the **Deskflow** support management application.

---

## 1. Design System Foundations

### 1.1 Typography & Font
- **Primary Font**: [Inter](https://fonts.google.com/specimen/Inter) (`'Inter', ui-sans-serif, system-ui, sans-serif`)
- **Weights Used**: `400` (Regular), `500` (Medium), `600` (SemiBold), `700` (Bold), `800` (ExtraBold)
- **Monospace Font**: System monospace (`font-mono`) for ticket references (`#REF-1001`), slugs, and diagnostic values.
- **Font Smoothing**: `-webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;`

### 1.2 Color Palette
- **Neutrals (Slate)**:
  - Background: `#f8fafc` (`slate-50`) with radial gradient `radial-gradient(circle at 50% 0%, rgba(241, 245, 249, 0.8) 0%, rgba(248, 250, 252, 1) 100%)`
  - Cards & Panels: `#ffffff` (`bg-white`) with border `border-slate-200/80`
  - Text Primary: `#0f172a` (`slate-900`)
  - Text Secondary: `#475569` (`slate-600`) / `#64748b` (`slate-500`)
  - Subdued / Labels: `#94a3b8` (`slate-400`)
- **Primary Brand (Indigo & Violet)**:
  - Gradient: `from-indigo-600 to-violet-600`
  - Hover: `hover:bg-indigo-700`
  - Soft Tints: `bg-indigo-50 text-indigo-700`
  - Glow / Shadows: `shadow-indigo-500/20`
- **Status & Priority Tones**:
  - Success / Active: Emerald (`bg-emerald-50 text-emerald-700`, dot: `bg-emerald-500`)
  - Warning / In-Progress: Amber (`bg-amber-50 text-amber-800`, dot: `bg-amber-500`)
  - Critical / Error: Rose (`bg-rose-50 text-rose-700`, dot: `bg-rose-500`)
  - Informational / Queue: Blue / Sky (`bg-sky-50 text-sky-700`, dot: `bg-sky-500`)

### 1.3 Elevation & Shadows
- **Card Surface**: `rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm`
- **Elevated Interactive Card**: `hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-md hover:shadow-slate-200/50 transition-all duration-200`
- **Modal Dialogs**: `fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-xs` + `w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl`
- **Header Topbar**: `sticky top-0 z-40 border-b border-slate-200/80 bg-white/90 backdrop-blur-md`

---

## 2. Core Setup & Global Assets

### 2.1 `index.html` Font Preconnect
Include Google Fonts Inter in the `<head>` of `frontend/index.html`:
```html
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
```

### 2.2 `src/style.css` Global Rules
```css
@import 'tailwindcss';

:root {
  font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
  color: #0f172a;
  background-color: #f8fafc;
  font-synthesis: none;
  text-rendering: optimizeLegibility;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

body {
  margin: 0;
  min-width: 320px;
  background: radial-gradient(circle at 50% 0%, rgba(241, 245, 249, 0.8) 0%, rgba(248, 250, 252, 1) 100%);
  color: #0f172a;
}

button, input, textarea, select {
  font: inherit;
}

#app {
  min-height: 100svh;
  display: flex;
  flex-direction: column;
}

/* Custom sleek scrollbars */
::-webkit-scrollbar {
  width: 6px;
  height: 6px;
}
::-webkit-scrollbar-track {
  background: transparent;
}
::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 9999px;
}
::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}
```

---

## 3. Shared Components Specifications

### 3.1 `ColorBadge.vue`
Pill badge with colored indicator dot and contrast-calculated text:
```vue
<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold tracking-wide shadow-xs transition-colors"
    data-testid="color-badge"
    :style="{ backgroundColor: color, color: textColor }"
  >
    <span
      class="h-1.5 w-1.5 rounded-full opacity-80"
      :style="{ backgroundColor: textColor }"
    />
    {{ name }}
  </span>
</template>
```

### 3.2 `CategoryBadge.vue`
Category tag badge with opacity modifier for deactivated categories:
```vue
<template>
  <span
    class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium tracking-tight shadow-xs transition-opacity"
    :class="{ 'opacity-55 saturate-50': !category.is_active }"
    data-testid="category-badge"
    :style="{ backgroundColor: category.color, color: textColor }"
    :data-inactive="category.is_active ? undefined : 'true'"
  >
    {{ category.name }}
  </span>
</template>
```

### 3.3 `StatCard.vue`
Interactive dashboard metric card with vector icon badge and hover lift:
```vue
<template>
  <RouterLink
    :to="to"
    :data-testid="testid"
    class="group relative flex flex-col justify-between overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-md hover:shadow-slate-200/50"
  >
    <div class="flex items-start justify-between gap-4">
      <div>
        <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">
          {{ label }}
        </span>
        <strong
          :data-testid="testid + '-count'"
          class="mt-2 block text-3xl font-extrabold tracking-tight text-slate-900 group-hover:text-indigo-600 transition-colors"
        >
          {{ count }}
        </strong>
      </div>
      <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-50 text-slate-400 group-hover:bg-indigo-50 group-hover:text-indigo-600 transition-colors">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
        </svg>
      </div>
    </div>
    <div class="mt-4 flex items-center gap-1 text-xs font-medium text-slate-400 group-hover:text-indigo-600 transition-colors">
      <span>View tickets</span>
      <svg class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
      </svg>
    </div>
  </RouterLink>
</template>
```

### 3.4 `TicketActionToolbar.vue`
Cohesive action buttons with status disabled states and danger button for deletion:
- Includes icons for Edit, Assign, Change Status, Escalate, and Delete.

---

## 4. Layout & Views Architecture

### 4.1 Global Application Shell (`App.vue`)
- **Sticky Glassmorphic Header**: `backdrop-blur-md bg-white/90` with Deskflow gradient brand icon.
- **Active Navigation States**: Active routes styled with `active-class="bg-indigo-50 text-indigo-700 font-semibold"`.
- **Dynamic Counters**: "My tickets" displays badge counter for open tickets (`data-testid="nav-my-tickets-count"`).
- **User Profile Pill**: Avatar circle with initials, user name, role, Password link, and Sign out button.
- **Mobile Menu Drawer**: Slide-down navigation on smaller screens with hamburger toggle.

### 4.2 Dashboard (`DashboardView.vue`)
- **Welcome Banner**: Personalized agent greeting and "+ New ticket" primary action.
- **3-Card Metric Grid**: "My open tickets", "Unassigned", "Escalated tickets".
- **2-Column Breakdown Matrix**:
  - "Queue by status" / "My tickets by status": Interactive rows with progress distribution bars and count tags.
  - "Tickets by priority": Interactive rows with urgency bars and count tags.

### 4.3 Ticket List & Filter Ribbon (`TicketListView.vue` & `TicketFilterBar.vue`)
- **Filter Toolbar**:
  - Search input with leading SVG magnifier.
  - Multi-selects for Status, Priority, Category in styled containers.
  - Dropdowns for Assignee, Escalation, and Sort order.
  - "Clear" button with active filters badge counter.
- **Data Table**:
  - Sticky table header with sort indicator arrows (`sort-priority`, `sort-created_at`).
  - Monospace reference pills (`#REF-1001`) with hover link styling.
  - Requesters and Assignees with avatar initials.
  - Relative age with tooltip timestamp.
  - Friendly empty state when no tickets match.
  - Pagination footer with page size selector and previous/next page buttons.

### 4.4 Ticket Detail (`TicketDetailView.vue`)
- **Header Card**: Back link, reference badge, high-impact subject header, action toolbar.
- **Escalation Alert Banner**: High-visibility amber banner when ticket is escalated.
- **2-Column Responsive Layout**:
  - *Main Column*: Description card with `whitespace-pre-wrap` typography; Lifecycle milestones card (First responded, Resolved, Closed).
  - *Sidebar Column*: Requester card (Avatar, name, email, phone, company); Assignment & Audit card (Assignee, Creator, Created at, Updated at).

### 4.5 New Ticket Filing (`NewTicketView.vue`)
- **2-Section Form Card**:
  1. *Requester Information*: 2-column grid for Name, Email, Phone, Company.
  2. *Ticket Details*: Subject input, Category & Priority dropdowns, Description textarea with char counter (`X / 16,000`).
- Inline error messages and submit button with loading spinner.

### 4.6 Admin Panels (`AdminCategoriesView.vue`, `AdminUsersView.vue`, `AdminWorkloadView.vue`)
- **Categories Panel**: Filter by active/inactive, "+ New category" button, sort order inputs, Edit/Deactivate/Delete actions.
- **Users Panel**: Search with debounce, role pills (Admin in violet, Agent in blue), active indicators, and user creation/editing modal.
- **Workload Monitor**: Average load banner (`Average: X ± Y`), capacity load badges (High/Low/Balanced), and priority distribution matrix table.
- **Modals (`CategoryFormDialog`, `CategoryDeleteDialog`, `UserFormDialog`)**: Fixed backdrop overlays with smooth animations and keyboard/cancel controls.

### 4.7 Auth & Diagnostics (`LoginView.vue`, `AccountPasswordView.vue`, `ForbiddenView.vue`, `HealthView.vue`)
- **Login**: Radial glow background, brand card, styled inputs with focus rings.
- **Password**: Clean security settings card with password validation feedback.
- **403 Forbidden**: Security shield illustration card with return link.
- **API Health**: Live status pulse indicator (green/red), system diagnostics metrics grid, and recheck action.

---

## 5. Implementation Verification Checklist

When applying this modern design, always verify the following:

```bash
# 1. Run Vitest test suite (must pass 100%)
npm test

# 2. Run TypeScript typecheck
npm run typecheck

# 3. Run production build
npm run build

# 4. Check ESLint and Prettier formatting
npm run lint
npm run format:check
```

All `data-testid` attributes, router links, and store bindings are preserved for full backward compatibility and automated testing.
