export type DisplayStatus = 'available' | 'occupied' | 'reserved' | 'maintenance' | 'inactive'
export type RentalType = 'daily' | 'weekly' | 'monthly' | 'custom'
export type ScheduleStatus = 'reserved' | 'confirmed' | 'cancelled' | 'maintenance'

export interface RentalTenant {
    id: number
    name: string
    business_name: string | null
    contact_number: string | null
    email: string | null
    notes: string | null
}

export interface RentalSchedule {
    id: number
    stall_id: number
    tenant_id: number
    rental_type: RentalType
    status: ScheduleStatus
    start_date: string
    end_date: string
    start_time: string | null
    end_time: string | null
    price: number
    notes: string | null
    tenant: Pick<RentalTenant, 'id' | 'name' | 'business_name' | 'contact_number' | 'email'> | null
    stall: { id: number; number: number; label: string } | null
}

export interface StallDay {
    id: number
    number: number
    label: string
    description: string | null
    is_active: boolean
    display_status: DisplayStatus
    schedule: RentalSchedule | null
}

export interface CalendarDay {
    date: string
    total_stalls: number
    occupied: number
    reserved: number
    maintenance: number
    available: number
}

export interface Stats {
    today: { occupied: number; reserved: number; maintenance: number; available: number }
    month_revenue: number
    total_tenants: number
    active_stalls: number
}
