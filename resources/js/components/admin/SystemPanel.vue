<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useAdmin } from '@/composables/useAdmin'
import { useAuth } from '@/composables/useAuth'
import { useToast } from '@/composables/useToast'
import type { SystemInfo, SystemLogs, CacheTarget, FailedJobRow, Paginated } from '@/types/admin'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import {
  AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
  AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table'
import {
  Activity, CheckCircle2, XCircle, Info, HardDrive, DatabaseZap,
  Trash2, FileText, RefreshCw, Loader2, AlertCircle, RotateCcw,
  ChevronLeft, ChevronRight,
} from 'lucide-vue-next'

const { fetchSystemInfo, clearSystemCache, fetchSystemLogs, fetchFailedJobs, retryFailedJobs, deleteFailedJob } = useAdmin()
const { extractError } = useAuth()
const toast = useToast()

const info = ref<SystemInfo | null>(null)
const logs = ref<SystemLogs | null>(null)
const loadingInfo = ref(true)
const loadingLogs = ref(false)
const clearing = ref<Record<string, boolean>>({})
const logLevel = ref('all')

async function loadInfo() {
  loadingInfo.value = true
  try { info.value = await fetchSystemInfo() }
  catch (e) { toast.error(extractError(e) || 'Không tải được thông tin hệ thống') }
  finally { loadingInfo.value = false }
}

async function loadLogs() {
  loadingLogs.value = true
  try {
    logs.value = await fetchSystemLogs({
      lines: 100,
      level: logLevel.value === 'all' ? undefined : logLevel.value,
    })
  } catch (e) {
    toast.error(extractError(e) || 'Không tải được log')
  } finally {
    loadingLogs.value = false
  }
}

// ── Failed jobs ──
const failedJobs = ref<Paginated<FailedJobRow> | null>(null)
const loadingFailed = ref(false)
const retrying = ref<string | null>(null) // uuid đang retry, 'all' = retry tất cả
const deleteOpen = ref(false)
const deleteTarget = ref<FailedJobRow | null>(null)

async function loadFailedJobs(page = 1) {
  loadingFailed.value = true
  try {
    failedJobs.value = await fetchFailedJobs({ page, per_page: 10 })
  } catch (e) {
    toast.error(extractError(e) || 'Không tải được danh sách job lỗi')
  } finally {
    loadingFailed.value = false
  }
}

async function retryJobs(uuid?: string) {
  retrying.value = uuid ?? 'all'
  try {
    const res = await retryFailedJobs(uuid)
    res.ok ? toast.success(res.message) : toast.error(res.message)
    await loadFailedJobs()
  } catch (e) {
    toast.error(extractError(e))
  } finally {
    retrying.value = null
  }
}

function askDeleteJob(job: FailedJobRow) {
  deleteTarget.value = job
  deleteOpen.value = true
}

async function confirmDeleteJob() {
  const job = deleteTarget.value
  if (!job) return
  deleteTarget.value = null
  try {
    const res = await deleteFailedJob(job.uuid)
    res.ok ? toast.success(res.message) : toast.error(res.message)
    await loadFailedJobs()
  } catch (e) {
    toast.error(extractError(e))
  }
}

function fmtTime(s: string): string {
  return new Date(s).toLocaleString('vi-VN')
}

async function clearCache(target: CacheTarget) {
  clearing.value[target] = true
  try {
    const res = await clearSystemCache(target)
    res.ok ? toast.success(res.message) : toast.error(res.message)
  } catch (e) {
    toast.error(extractError(e))
  } finally {
    clearing.value[target] = false
  }
}

function fmtBytes(n: number | null | undefined): string {
  if (n == null) return '—'
  if (n < 1024) return `${n} B`
  const units = ['KB', 'MB', 'GB', 'TB']
  let v = n
  let u = -1
  do { v /= 1024; u++ } while (v >= 1024 && u < units.length - 1)
  return `${v.toFixed(1)} ${units[u]}`
}

const CACHE_ACTIONS: { target: CacheTarget; label: string; hint: string }[] = [
  { target: 'cache',  label: 'Cache ứng dụng', hint: 'Settings, thống kê, dữ liệu tạm' },
  { target: 'config', label: 'Config cache',   hint: 'Cấu hình đã compile' },
  { target: 'route',  label: 'Route cache',    hint: 'Bảng định tuyến đã compile' },
  { target: 'view',   label: 'View cache',     hint: 'Blade template đã compile' },
]

function levelClass(level: string): string {
  if (['emergency', 'alert', 'critical', 'error'].includes(level))
    return 'bg-destructive/10 text-destructive border-destructive/30'
  if (level === 'warning')
    return 'bg-amber-500/10 text-amber-600 border-amber-500/30'
  return 'bg-muted text-muted-foreground border-transparent'
}

onMounted(() => { loadInfo(); loadFailedJobs(); loadLogs() })
</script>

<template>
  <div class="space-y-4">
    <div v-if="loadingInfo" class="space-y-4">
      <Skeleton class="h-40 rounded-xl" />
      <Skeleton class="h-64 rounded-xl" />
    </div>

    <template v-else-if="info">
      <!-- Health checks -->
      <Card class="gap-4">
        <CardHeader class="flex-row items-start justify-between">
          <div class="space-y-1">
            <CardTitle class="text-base flex items-center gap-2"><Activity class="w-4 h-4 text-primary" /> Tình trạng dịch vụ</CardTitle>
            <CardDescription>Kiểm tra nhanh các thành phần hệ thống phụ thuộc.</CardDescription>
          </div>
          <Button variant="outline" size="sm" :disabled="loadingInfo" @click="loadInfo">
            <RefreshCw class="w-3.5 h-3.5" /> Làm mới
          </Button>
        </CardHeader>
        <CardContent>
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <div
              v-for="check in info.health" :key="check.name"
              class="flex items-start gap-2.5 rounded-lg border p-3"
            >
              <CheckCircle2 v-if="check.ok" class="w-4.5 h-4.5 text-calor-green flex-none mt-0.5" />
              <XCircle v-else class="w-4.5 h-4.5 text-destructive flex-none mt-0.5" />
              <div class="min-w-0">
                <p class="text-sm font-medium">{{ check.label }}</p>
                <p class="text-xs text-muted-foreground truncate" :title="check.detail || ''">{{ check.detail || '—' }}</p>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      <!-- Environment info -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <Card class="gap-4">
          <CardHeader>
            <CardTitle class="text-base flex items-center gap-2"><Info class="w-4 h-4 text-primary" /> Ứng dụng</CardTitle>
          </CardHeader>
          <CardContent>
            <dl class="text-sm divide-y">
              <div class="flex justify-between gap-4 py-2">
                <dt class="text-muted-foreground">Môi trường</dt>
                <dd class="flex items-center gap-2">
                  <Badge :variant="info.app.env === 'production' ? 'default' : 'secondary'">{{ info.app.env }}</Badge>
                  <Badge v-if="info.app.debug" variant="destructive">debug</Badge>
                </dd>
              </div>
              <div class="flex justify-between gap-4 py-2"><dt class="text-muted-foreground">Laravel</dt><dd class="font-mono">{{ info.app.laravel }}</dd></div>
              <div class="flex justify-between gap-4 py-2"><dt class="text-muted-foreground">PHP</dt><dd class="font-mono">{{ info.app.php }}</dd></div>
              <div class="flex justify-between gap-4 py-2"><dt class="text-muted-foreground">Timezone</dt><dd>{{ info.app.timezone }}</dd></div>
              <div class="flex justify-between gap-4 py-2"><dt class="text-muted-foreground">Giờ server</dt><dd class="font-mono">{{ info.server.server_time }}</dd></div>
              <div class="flex justify-between gap-4 py-2"><dt class="text-muted-foreground">OS</dt><dd class="truncate max-w-52" :title="info.server.os">{{ info.server.os }}</dd></div>
              <div class="flex justify-between gap-4 py-2"><dt class="text-muted-foreground">Memory limit</dt><dd class="font-mono">{{ info.server.memory_limit }}</dd></div>
              <div class="flex justify-between gap-4 py-2">
                <dt class="text-muted-foreground">Upload tối đa</dt>
                <dd class="font-mono">{{ info.server.upload_max_filesize }} / {{ info.server.post_max_size }}</dd>
              </div>
            </dl>
          </CardContent>
        </Card>

        <Card class="gap-4">
          <CardHeader>
            <CardTitle class="text-base flex items-center gap-2"><HardDrive class="w-4 h-4 text-primary" /> Dữ liệu &amp; Lưu trữ</CardTitle>
          </CardHeader>
          <CardContent>
            <dl class="text-sm divide-y">
              <div class="flex justify-between gap-4 py-2">
                <dt class="text-muted-foreground">Database</dt>
                <dd class="font-mono">{{ info.database.driver }}{{ info.database.version ? ` ${info.database.version}` : '' }}</dd>
              </div>
              <div class="flex justify-between gap-4 py-2"><dt class="text-muted-foreground">Kích thước DB</dt><dd>{{ fmtBytes(info.database.size) }}</dd></div>
              <div class="flex justify-between gap-4 py-2"><dt class="text-muted-foreground">Cache driver</dt><dd class="font-mono">{{ info.cache.driver }}</dd></div>
              <div class="flex justify-between gap-4 py-2"><dt class="text-muted-foreground">Queue driver</dt><dd class="font-mono">{{ info.queue.driver }}</dd></div>
              <div class="flex justify-between gap-4 py-2">
                <dt class="text-muted-foreground">Queue jobs</dt>
                <dd>
                  {{ info.queue.pending ?? '—' }} chờ
                  <span :class="info.queue.failed ? 'text-destructive font-medium' : ''"> · {{ info.queue.failed ?? '—' }} lỗi</span>
                </dd>
              </div>
              <div class="flex justify-between gap-4 py-2"><dt class="text-muted-foreground">Log files</dt><dd>{{ fmtBytes(info.storage.logs_size) }}</dd></div>
              <div class="flex justify-between gap-4 py-2">
                <dt class="text-muted-foreground">Ổ đĩa còn trống</dt>
                <dd>{{ fmtBytes(info.storage.disk_free) }} / {{ fmtBytes(info.storage.disk_total) }}</dd>
              </div>
            </dl>
          </CardContent>
        </Card>
      </div>

      <!-- Cache management -->
      <Card class="gap-4">
        <CardHeader>
          <CardTitle class="text-base flex items-center gap-2"><DatabaseZap class="w-4 h-4 text-primary" /> Quản lý cache</CardTitle>
          <CardDescription>Xoá cache khi thay đổi không có hiệu lực hoặc sau khi deploy.</CardDescription>
        </CardHeader>
        <CardContent>
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div v-for="a in CACHE_ACTIONS" :key="a.target" class="rounded-lg border p-3 flex flex-col gap-2">
              <div>
                <p class="text-sm font-medium">{{ a.label }}</p>
                <p class="text-xs text-muted-foreground">{{ a.hint }}</p>
              </div>
              <Button variant="outline" size="sm" class="mt-auto" :disabled="clearing[a.target]" @click="clearCache(a.target)">
                <Loader2 v-if="clearing[a.target]" class="w-3.5 h-3.5 animate-spin" />
                <Trash2 v-else class="w-3.5 h-3.5" />
                Xoá
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      <!-- Failed jobs -->
      <Card class="gap-4">
        <CardHeader class="flex-row items-start justify-between">
          <div class="space-y-1">
            <CardTitle class="text-base flex items-center gap-2">
              <AlertCircle class="w-4 h-4 text-primary" /> Failed jobs
              <Badge v-if="failedJobs" :variant="failedJobs.meta.total ? 'destructive' : 'secondary'">{{ failedJobs.meta.total }}</Badge>
            </CardTitle>
            <CardDescription>Job trong hàng đợi bị lỗi — thử lại hoặc xoá khỏi danh sách.</CardDescription>
          </div>
          <div class="flex items-center gap-2">
            <Button
              v-if="failedJobs?.meta.total"
              variant="outline" size="sm"
              :disabled="retrying !== null"
              @click="retryJobs()"
            >
              <Loader2 v-if="retrying === 'all'" class="w-3.5 h-3.5 animate-spin" />
              <RotateCcw v-else class="w-3.5 h-3.5" />
              Thử lại tất cả
            </Button>
            <Button variant="outline" size="sm" :disabled="loadingFailed" @click="loadFailedJobs(failedJobs?.meta.current_page ?? 1)">
              <Loader2 v-if="loadingFailed" class="w-3.5 h-3.5 animate-spin" />
              <RefreshCw v-else class="w-3.5 h-3.5" />
            </Button>
          </div>
        </CardHeader>
        <CardContent>
          <div v-if="loadingFailed && !failedJobs" class="space-y-2">
            <Skeleton v-for="i in 3" :key="i" class="h-8 rounded-lg" />
          </div>
          <div v-else-if="!failedJobs?.data.length" class="text-sm text-muted-foreground py-6 text-center">
            Không có job lỗi 🎉
          </div>
          <template v-else>
            <div class="overflow-x-auto rounded-lg border">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Job</TableHead>
                    <TableHead>Queue</TableHead>
                    <TableHead>Thời gian</TableHead>
                    <TableHead>Lỗi</TableHead>
                    <TableHead class="text-right">Thao tác</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  <TableRow v-for="job in failedJobs.data" :key="job.uuid">
                    <TableCell class="font-mono text-xs max-w-52 truncate" :title="job.job_name">{{ job.job_name }}</TableCell>
                    <TableCell class="text-muted-foreground text-xs">{{ job.connection }} / {{ job.queue }}</TableCell>
                    <TableCell class="text-muted-foreground text-xs whitespace-nowrap">{{ fmtTime(job.failed_at) }}</TableCell>
                    <TableCell class="text-xs text-destructive/90 max-w-72 truncate" :title="job.exception_excerpt">
                      {{ job.exception_excerpt || '—' }}
                    </TableCell>
                    <TableCell class="text-right whitespace-nowrap">
                      <Button
                        variant="ghost" size="sm" class="h-7 px-2"
                        :disabled="retrying !== null"
                        @click="retryJobs(job.uuid)"
                      >
                        <Loader2 v-if="retrying === job.uuid" class="w-3.5 h-3.5 animate-spin" />
                        <RotateCcw v-else class="w-3.5 h-3.5" />
                        Thử lại
                      </Button>
                      <Button
                        variant="ghost" size="sm" class="h-7 px-2 text-destructive hover:text-destructive"
                        @click="askDeleteJob(job)"
                      >
                        <Trash2 class="w-3.5 h-3.5" />
                      </Button>
                    </TableCell>
                  </TableRow>
                </TableBody>
              </Table>
            </div>
            <div v-if="failedJobs.meta.last_page > 1" class="flex items-center justify-end gap-1 mt-3 text-sm text-muted-foreground">
              <Button
                variant="ghost" size="icon" class="h-8 w-8"
                :disabled="failedJobs.meta.current_page <= 1"
                @click="loadFailedJobs(failedJobs.meta.current_page - 1)"
              ><ChevronLeft class="w-4 h-4" /></Button>
              <span class="px-2">Trang {{ failedJobs.meta.current_page }} / {{ failedJobs.meta.last_page }}</span>
              <Button
                variant="ghost" size="icon" class="h-8 w-8"
                :disabled="failedJobs.meta.current_page >= failedJobs.meta.last_page"
                @click="loadFailedJobs(failedJobs.meta.current_page + 1)"
              ><ChevronRight class="w-4 h-4" /></Button>
            </div>
          </template>
        </CardContent>
      </Card>

      <!-- Log viewer -->
      <Card class="gap-4">
        <CardHeader class="flex-row items-start justify-between">
          <div class="space-y-1">
            <CardTitle class="text-base flex items-center gap-2"><FileText class="w-4 h-4 text-primary" /> Log gần nhất</CardTitle>
            <CardDescription>
              {{ logs?.file ? `${logs.file} (${fmtBytes(logs.size)})` : 'Chưa có file log' }} — 100 entry mới nhất.
            </CardDescription>
          </div>
          <div class="flex items-center gap-2">
            <Select v-model="logLevel" @update:model-value="loadLogs">
              <SelectTrigger size="sm" class="w-32">
                <SelectValue placeholder="Mức log" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">Tất cả</SelectItem>
                <SelectItem value="error">Error</SelectItem>
                <SelectItem value="warning">Warning</SelectItem>
                <SelectItem value="info">Info</SelectItem>
                <SelectItem value="debug">Debug</SelectItem>
              </SelectContent>
            </Select>
            <Button variant="outline" size="sm" :disabled="loadingLogs" @click="loadLogs">
              <Loader2 v-if="loadingLogs" class="w-3.5 h-3.5 animate-spin" />
              <RefreshCw v-else class="w-3.5 h-3.5" />
            </Button>
          </div>
        </CardHeader>
        <CardContent>
          <div v-if="!logs?.entries.length" class="text-sm text-muted-foreground py-6 text-center">
            Không có log nào khớp bộ lọc.
          </div>
          <div v-else class="max-h-96 overflow-y-auto rounded-lg border divide-y">
            <div v-for="(entry, i) in logs.entries" :key="i" class="px-3 py-2">
              <div class="flex items-center gap-2 mb-1">
                <span class="text-[10px] font-semibold uppercase px-1.5 py-0.5 rounded border" :class="levelClass(entry.level)">
                  {{ entry.level }}
                </span>
                <span class="text-xs text-muted-foreground font-mono">{{ entry.timestamp }}</span>
              </div>
              <pre class="text-xs font-mono whitespace-pre-wrap break-all text-foreground/90">{{ entry.message }}</pre>
            </div>
          </div>
        </CardContent>
      </Card>
    </template>

    <!-- Xác nhận xoá failed job -->
    <AlertDialog v-model:open="deleteOpen">
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle class="flex items-center gap-2">
            <Trash2 class="w-5 h-5 text-destructive" /> Xoá job lỗi này?
          </AlertDialogTitle>
          <AlertDialogDescription>
            Job "{{ deleteTarget?.job_name }}" sẽ bị xoá khỏi danh sách và không thể thử lại được nữa.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel @click="deleteTarget = null">Huỷ</AlertDialogCancel>
          <AlertDialogAction class="bg-destructive text-white hover:bg-destructive/90" @click="confirmDeleteJob">
            Xoá
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  </div>
</template>
