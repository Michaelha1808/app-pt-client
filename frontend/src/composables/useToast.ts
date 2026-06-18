interface Toast {
  id: number
  message: string
  type: 'success' | 'error' | 'info'
}

let _nextId = 0

export function useToast() {
  const toasts = useState<Toast[]>('ui.toasts', () => [])

  function show(message: string, type: Toast['type'] = 'info', duration = 3000) {
    const id = ++_nextId
    toasts.value = [...toasts.value, { id, message, type }]
    setTimeout(() => {
      toasts.value = toasts.value.filter(t => t.id !== id)
    }, duration)
  }

  return {
    toasts,
    success: (msg: string, duration?: number) => show(msg, 'success', duration),
    error: (msg: string, duration?: number) => show(msg, 'error', duration),
    info: (msg: string, duration?: number) => show(msg, 'info', duration),
  }
}
