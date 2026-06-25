<script setup lang="ts">
import CaloeyeCharacter from '@/components/caloeye/Character.vue'

const props = withDefaults(defineProps<{
  open: boolean
  feature?: string
}>(), {
  feature: 'tính năng này',
})

const emit = defineEmits<{
  'update:open': [boolean]
  dismiss: []
}>()

const router = useRouter()

function login() {
  emit('update:open', false)
  router.push('/auth/login')
}

function dismiss() {
  emit('update:open', false)
  emit('dismiss')
}
</script>

<template>
  <Teleport to="body">
    <Transition name="gate-fade">
      <div v-if="props.open" class="fixed inset-0 z-[60] flex items-end justify-center">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-[2px]" @click="dismiss" />

        <div class="relative w-full max-w-md mx-3 mb-3 bg-white rounded-[24px] p-6 shadow-2xl animate-fadeInUp">
          <div class="flex flex-col items-center text-center">
            <CaloeyeCharacter mood="reminder" :size="80" />
            <h3 class="text-[18px] font-semibold text-black mt-3">Đã hết lượt miễn phí hôm nay</h3>
            <p class="text-[14px] text-ios-gray mt-1.5 leading-relaxed">
              Bạn đã dùng hết lượt {{ props.feature }} miễn phí cho hôm nay. Đăng nhập để tiếp tục sử dụng không giới hạn và nhận tư vấn cá nhân hóa theo dữ liệu của bạn.
            </p>

            <button
              class="w-full mt-5 h-12 rounded-[14px] bg-ios-blue text-white text-[16px] font-semibold ios-press"
              @click="login"
            >
              Đăng nhập / Đăng ký
            </button>
            <button
              class="w-full mt-2 h-11 text-[15px] text-ios-gray font-medium ios-press"
              @click="dismiss"
            >
              Để sau
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.gate-fade-enter-active,
.gate-fade-leave-active {
  transition: opacity 0.2s ease;
}
.gate-fade-enter-from,
.gate-fade-leave-to {
  opacity: 0;
}
</style>
