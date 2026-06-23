<script setup lang="ts">
import { resizeImage } from '@/utils/image'

const props = defineProps<{
  currentUrl: string | null
  uploading?: boolean
}>()

const emit = defineEmits<{
  upload: [file: File]
  delete: []
}>()

const show = ref(false)
const confirmDelete = ref(false)

// Separate hidden inputs for camera vs library
const cameraInput = ref<HTMLInputElement>()
const libraryInput = ref<HTMLInputElement>()

function open() {
  confirmDelete.value = false
  show.value = true
}

function close() {
  show.value = false
  confirmDelete.value = false
}

function pickCamera() {
  close()
  // Slight delay so sheet animates out before system dialog opens
  setTimeout(() => cameraInput.value?.click(), 200)
}

function pickLibrary() {
  close()
  setTimeout(() => libraryInput.value?.click(), 200)
}

function askDelete() {
  confirmDelete.value = true
}

function cancelDelete() {
  confirmDelete.value = false
}

function confirmDeleteAction() {
  emit('delete')
  close()
}

async function onFileSelected(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  ;(e.target as HTMLInputElement).value = ''
  const resized = await resizeImage(file, 512)
  emit('upload', resized)
}

// Expose open() so parent can trigger it
defineExpose({ open })
</script>

<template>
  <!-- Hidden file inputs -->
  <input
    ref="cameraInput"
    type="file"
    accept="image/*"
    capture="environment"
    class="hidden"
    @change="onFileSelected"
  />
  <input
    ref="libraryInput"
    type="file"
    accept="image/jpeg,image/png,image/webp"
    class="hidden"
    @change="onFileSelected"
  />

  <!-- Backdrop -->
  <Teleport to="body">
    <Transition
      enter-active-class="transition-opacity duration-200"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition-opacity duration-200"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="show"
        class="fixed inset-0 bg-black/40 z-50"
        @click="close"
      />
    </Transition>

    <!-- Sheet -->
    <Transition
      enter-active-class="transition-transform duration-300 ease-out"
      enter-from-class="translate-y-full"
      enter-to-class="translate-y-0"
      leave-active-class="transition-transform duration-250 ease-in"
      leave-from-class="translate-y-0"
      leave-to-class="translate-y-full"
    >
      <div
        v-if="show"
        class="fixed bottom-0 left-0 right-0 z-50 px-4 pb-8 pt-2"
        style="max-width: 430px; margin: 0 auto;"
      >
        <!-- Confirm delete state -->
        <template v-if="confirmDelete">
          <div class="bg-white rounded-[16px] overflow-hidden shadow-xl mb-3">
            <div class="px-4 pt-4 pb-3 text-center border-b border-ios-gray5">
              <p class="text-[13px] text-ios-gray leading-snug">Xoá ảnh đại diện? Ảnh mặc định sẽ được sử dụng thay thế.</p>
            </div>
            <button
              class="w-full py-3.5 text-ios-red font-semibold text-[17px] ios-press"
              @click="confirmDeleteAction"
            >Xoá ảnh</button>
          </div>
          <div class="bg-white rounded-[16px] overflow-hidden shadow-xl">
            <button
              class="w-full py-3.5 text-ios-blue font-semibold text-[17px] ios-press"
              @click="cancelDelete"
            >Huỷ</button>
          </div>
        </template>

        <!-- Normal state -->
        <template v-else>
          <div class="bg-white rounded-[16px] overflow-hidden shadow-xl mb-3">
            <!-- Title -->
            <div class="px-4 pt-3 pb-2 text-center border-b border-ios-gray5">
              <p class="text-[13px] font-semibold text-black">Ảnh đại diện</p>
              <p class="text-[12px] text-ios-gray mt-0.5">Chọn ảnh từ thư viện hoặc chụp mới</p>
            </div>

            <!-- Camera -->
            <button
              class="w-full flex items-center gap-4 px-5 py-4 ios-press"
              @click="pickCamera"
            >
              <div class="w-10 h-10 rounded-full bg-ios-blue/10 flex items-center justify-center flex-shrink-0">
                <svg viewBox="0 0 24 24" class="w-5 h-5" fill="#007AFF">
                  <path d="M12 15.2A3.2 3.2 0 0 1 8.8 12 3.2 3.2 0 0 1 12 8.8 3.2 3.2 0 0 1 15.2 12 3.2 3.2 0 0 1 12 15.2M9 2L7.17 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2h-3.17L15 2H9Z"/>
                </svg>
              </div>
              <span class="text-[16px] text-black">Chụp ảnh</span>
            </button>

            <div class="ios-separator mx-5"/>

            <!-- Library -->
            <button
              class="w-full flex items-center gap-4 px-5 py-4 ios-press"
              @click="pickLibrary"
            >
              <div class="w-10 h-10 rounded-full bg-ios-purple/10 flex items-center justify-center flex-shrink-0">
                <svg viewBox="0 0 24 24" class="w-5 h-5" fill="#AF52DE">
                  <path d="M22 16V4c0-1.1-.9-2-2-2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2zm-11-4l2.03 2.71L16 11l4 5H8l3-4zM2 6v14c0 1.1.9 2 2 2h14v-2H4V6H2z"/>
                </svg>
              </div>
              <span class="text-[16px] text-black">Chọn từ thư viện</span>
            </button>

            <!-- Delete (only when avatar exists) -->
            <template v-if="currentUrl">
              <div class="ios-separator mx-5"/>
              <button
                class="w-full flex items-center gap-4 px-5 py-4 ios-press"
                @click="askDelete"
              >
                <div class="w-10 h-10 rounded-full bg-ios-red/10 flex items-center justify-center flex-shrink-0">
                  <svg viewBox="0 0 24 24" class="w-5 h-5" fill="#FF3B30">
                    <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/>
                  </svg>
                </div>
                <span class="text-[16px] text-ios-red">Xoá ảnh đại diện</span>
              </button>
            </template>
          </div>

          <!-- Cancel -->
          <div class="bg-white rounded-[16px] overflow-hidden shadow-xl">
            <button
              class="w-full py-3.5 text-ios-blue font-semibold text-[17px] ios-press"
              @click="close"
            >Huỷ</button>
          </div>
        </template>
      </div>
    </Transition>
  </Teleport>
</template>
