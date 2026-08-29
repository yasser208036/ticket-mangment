<template>
  <main
    class="mx-auto flex min-h-[70vh] max-w-lg items-center justify-center px-4 py-12"
  >
    <div
      class="w-full rounded-3xl border border-slate-200/80 bg-white p-8 text-center shadow-lg sm:p-10 space-y-5"
    >
      <div
        class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-amber-50 text-amber-600 shadow-xs"
      >
        <svg
          class="h-8 w-8"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
          stroke-width="2"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
          />
        </svg>
      </div>

      <div class="space-y-2">
        <span class="text-xs font-bold uppercase tracking-wider text-amber-600"
          >Error 403</span
        >
        <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">
          Not authorised
        </h1>
        <p
          data-testid="forbidden-message"
          class="text-sm leading-relaxed text-slate-600"
        >
          Your account does not have access to that screen. Ask an administrator
          to check your role.
        </p>
      </div>

      <div class="pt-2">
        <RouterLink
          to="/"
          data-testid="forbidden-home"
          class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-md shadow-indigo-500/20 transition-all hover:bg-indigo-700 active:scale-95"
        >
          <svg
            class="h-4 w-4"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M10 19l-7-7m0 0l7-7m-7 7h18"
            />
          </svg>
          <span>Back to the app</span>
        </RouterLink>
      </div>
    </div>
  </main>
</template>
