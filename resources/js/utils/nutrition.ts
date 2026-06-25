import type { DetectedDish, DishPick } from '@/types/food'

/** Calo của 1 món theo số lượng đã chọn (làm tròn) */
export function dishCalories(d: DetectedDish, qty: number): number {
  return Math.round(d.calories * qty)
}

/** Giá trị macro của 1 món theo số lượng (làm tròn) */
export function dishMacro(d: DetectedDish, key: 'protein' | 'carbs' | 'fat' | 'sodium', qty: number): number {
  return Math.round(d[key] * qty)
}

/** Bước nhảy của stepper theo loại đơn vị */
export function stepFor(unitType: DetectedDish['unit_type']): number {
  return unitType === 'countable' ? 1 : 0.5
}

/** Giá trị nhỏ nhất của stepper theo loại đơn vị */
export function minFor(unitType: DetectedDish['unit_type']): number {
  return unitType === 'countable' ? 1 : 0.5
}

/** Tổng calo các món đã chọn */
export function totalCalories(items: DishPick[]): number {
  return items.reduce((sum, d) => sum + (d.selected ? dishCalories(d, d.quantity) : 0), 0)
}

export function totalMacro(items: DishPick[], key: 'protein' | 'carbs' | 'fat' | 'sodium'): number {
  return items.reduce((sum, d) => sum + (d.selected ? dishMacro(d, key, d.quantity) : 0), 0)
}

export function selectedCount(items: DishPick[]): number {
  return items.filter(d => d.selected).length
}

/** Hiển thị số lượng gọn: 1.5 → "1.5", 2 → "2" */
export function formatQty(qty: number): string {
  return Number.isInteger(qty) ? String(qty) : qty.toFixed(1)
}
