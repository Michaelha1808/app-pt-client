<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router'

// Backend OAuth callback redirect về đây kèm ?provider=&status=.
// Chuyển tiếp sang /activities để hiển thị toast + refresh dữ liệu.
const route = useRoute()
const router = useRouter()

onMounted(() => {
  const status = (route.query.status as string) || 'error'
  router.replace({ path: '/activities', query: { status } })
})
</script>

<template>
  <div class="flex flex-col items-center justify-center min-h-[60vh] gap-3">
    <div class="w-8 h-8 rounded-full border-2 border-calor-green border-t-transparent animate-spin"/>
    <p class="text-[14px] text-ios-gray">Đang hoàn tất kết nối...</p>
  </div>
</template>
