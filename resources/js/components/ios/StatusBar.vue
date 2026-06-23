<script setup lang="ts">
defineProps<{ dark?: boolean }>()
</script>

<template>
  <div
    class="flex-none h-12 flex items-end justify-between px-6 pb-1 select-none"
    :class="dark ? 'bg-black text-white' : 'bg-[#F2F2F7] text-black'"
  >
    <span class="text-[15px] font-semibold">9:41</span>
    <div class="flex items-center gap-1.5">
      <!-- Signal bars -->
      <svg width="17" height="12" viewBox="0 0 17 12" :fill="dark ? 'white' : 'black'">
        <rect x="0"    y="4"   width="3" height="8"   rx="1" opacity="0.35"/>
        <rect x="4.5"  y="2.5" width="3" height="9.5" rx="1" opacity="0.6"/>
        <rect x="9"    y="0.5" width="3" height="11.5" rx="1"/>
        <rect x="13.5" y="0.5" width="3" height="11.5" rx="1"/>
      </svg>
      <!-- WiFi -->
      <svg width="16" height="11" viewBox="0 0 16 11" :fill="dark ? 'white' : 'black'">
        <path d="M8 8.5a1.5 1.5 0 100 3 1.5 1.5 0 000-3z"/>
        <path d="M8 5.5A5.5 5.5 0 002.79 7.66L4.21 9.08A3.5 3.5 0 018 7.5a3.5 3.5 0 013.79 1.58l1.42-1.42A5.5 5.5 0 008 5.5z"/>
        <path d="M8 2A9.5 9.5 0 00.17 5.33L1.59 6.75A7.5 7.5 0 018 4a7.5 7.5 0 016.41 2.75l1.42-1.42A9.5 9.5 0 008 2z"/>
      </svg>
      <!-- Battery -->
      <div class="flex items-center">
        <div
          class="w-[25px] h-[12px] border rounded-[3px] relative flex items-center px-[1.5px]"
          :class="dark ? 'border-white/35' : 'border-black/35'"
        >
          <div
            class="h-full rounded-[2px]"
            :class="dark ? 'bg-white' : 'bg-black'"
            style="width: 78%"
          />
        </div>
        <div
          class="w-[1.5px] h-[5px] rounded-full ml-px"
          :class="dark ? 'bg-white/35' : 'bg-black/35'"
        />
      </div>
    </div>
  </div>
</template>
