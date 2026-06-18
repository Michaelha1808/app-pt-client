<script setup lang="ts">
const props = withDefaults(defineProps<{
  mood?: 'normal' | 'happy' | 'celebrate' | 'thinking' | 'warning' | 'wave' | 'excited' | 'idle'
  size?: number
  message?: string
  bubbleDir?: 'right' | 'left' | 'top'
}>(), {
  mood: 'normal',
  size: 96,
  bubbleDir: 'right',
})

const moodClass = computed(() => ({
  normal:    'char-float',
  idle:      'char-idle',
  happy:     'char-bounce',
  celebrate: 'char-celebrate',
  thinking:  'char-think',
  warning:   'char-warn',
  wave:      'char-wave',
  excited:   'char-excited',
})[props.mood])
</script>

<template>
  <div class="relative inline-flex items-end" style="gap: 8px">

    <!-- Speech bubble -->
    <Transition name="bubble">
      <div
        v-if="message"
        class="speech-bubble"
        :class="[
          bubbleDir === 'left' ? 'order-first bubble-right' : '',
          bubbleDir === 'top'  ? 'absolute bottom-full left-1/2 -translate-x-1/2 mb-2 bubble-bottom' : '',
        ]"
      >
        <p class="text-[13px] leading-snug text-[#0C4D3D] font-medium">{{ message }}</p>
      </div>
    </Transition>

    <!-- Character -->
    <div :class="moodClass" class="relative flex-shrink-0" :style="`width:${size}px;height:${size}px`">
      <img
        src="/logo/AVO-mascot-nobg.png"
        :width="size"
        :height="size"
        alt="AVO"
        class="w-full h-full object-contain select-none"
        draggable="false"
      />

      <!-- Celebrate: sparkles -->
      <template v-if="mood === 'celebrate' || mood === 'excited'">
        <div class="absolute -top-2 -right-1 text-[16px] sparkle-1">✨</div>
        <div class="absolute top-0 -left-2 text-[12px] sparkle-2">⭐</div>
        <div class="absolute -top-3 left-1/2 text-[10px] sparkle-3">💫</div>
      </template>

      <!-- Warning badge -->
      <template v-if="mood === 'warning'">
        <div
          class="absolute -top-1 -right-1 rounded-full bg-ios-orange flex items-center justify-center shadow-md"
          :style="`width:${Math.round(size*0.26)}px;height:${Math.round(size*0.26)}px`"
        >
          <span class="text-white font-black leading-none" :style="`font-size:${Math.round(size*0.15)}px`">!</span>
        </div>
      </template>

      <!-- Wave: small hand wave indicator -->
      <template v-if="mood === 'wave'">
        <div class="absolute -top-1 -right-1 text-[14px] wave-emoji">👋</div>
      </template>

      <!-- Thinking dots above head -->
      <template v-if="mood === 'thinking'">
        <div class="absolute -top-3 left-1/2 -translate-x-1/2 flex gap-1">
          <div class="w-1.5 h-1.5 rounded-full bg-calor-green typing-dot-1"/>
          <div class="w-1.5 h-1.5 rounded-full bg-calor-green typing-dot-2"/>
          <div class="w-1.5 h-1.5 rounded-full bg-calor-green typing-dot-3"/>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.speech-bubble {
  position: relative;
  background: white;
  border: 1.5px solid #C8F0E2;
  border-radius: 16px;
  padding: 10px 14px;
  max-width: 200px;
  box-shadow: 0 4px 16px rgba(15, 110, 86, 0.12);
  align-self: flex-end;
  margin-bottom: 12px;
}
.speech-bubble::after {
  content: '';
  position: absolute;
  bottom: 18px; right: -9px;
  width: 0; height: 0;
  border-top: 8px solid transparent;
  border-bottom: 4px solid transparent;
  border-left: 9px solid white;
  filter: drop-shadow(1px 0 0 #C8F0E2);
}
.speech-bubble::before {
  content: '';
  position: absolute;
  bottom: 17px; right: -11px;
  width: 0; height: 0;
  border-top: 9px solid transparent;
  border-bottom: 5px solid transparent;
  border-left: 10px solid #C8F0E2;
}
.bubble-right::after { right: auto; left: -9px; border-left: none; border-right: 9px solid white; }
.bubble-right::before { right: auto; left: -11px; border-left: none; border-right: 10px solid #C8F0E2; }
.bubble-bottom::after {
  right: auto; bottom: auto;
  left: 50%; top: 100%;
  transform: translateX(-50%);
  border-left: 7px solid transparent; border-right: 7px solid transparent;
  border-top: 9px solid white; border-bottom: none;
}
.bubble-bottom::before {
  right: auto; bottom: auto;
  left: 50%; top: 100%;
  transform: translateX(-50%) translateY(-1px);
  border-left: 8px solid transparent; border-right: 8px solid transparent;
  border-top: 10px solid #C8F0E2; border-bottom: none;
}
.bubble-enter-active { transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); }
.bubble-leave-active { transition: all 0.2s ease; }
.bubble-enter-from   { opacity: 0; transform: scale(0.85) translateY(4px); }
.bubble-leave-to     { opacity: 0; transform: scale(0.9); }

@keyframes sparklePop {
  0%, 100% { transform: scale(1) rotate(0deg); opacity: 1; }
  50%       { transform: scale(1.45) rotate(25deg); opacity: 0.65; }
}
@keyframes waveEmoji {
  0%, 100% { transform: rotate(0deg); }
  30%       { transform: rotate(-20deg); }
  60%       { transform: rotate(15deg); }
}
.sparkle-1 { animation: sparklePop 1.1s 0.0s ease-in-out infinite; }
.sparkle-2 { animation: sparklePop 1.1s 0.4s ease-in-out infinite; }
.sparkle-3 { animation: sparklePop 1.1s 0.7s ease-in-out infinite; }
.wave-emoji { animation: waveEmoji 0.8s ease-in-out infinite; }
</style>
