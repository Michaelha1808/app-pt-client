<template>
  <div class="flex flex-col items-center gap-8">
    <div class="flex flex-col items-center gap-1">
      <span class="text-xs font-semibold tracking-[0.25em] text-blue-400 uppercase">
        PT Client
      </span>
      <h1 class="text-xl font-semibold text-slate-200">
        Realtime Clock
      </h1>
    </div>

    <Clock />

    <ApiStatus />
  </div>
</template>
