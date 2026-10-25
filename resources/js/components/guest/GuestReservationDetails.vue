<script setup lang="ts">
import { bookingDate } from '@/lib/booking';
import type { GuestContext } from '@/types/guest-selection';
defineProps<{ context: GuestContext; timezone: string }>();
</script>
<template>
    <dl
        class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-6 gap-y-2 text-sm leading-6"
    >
        <dt>Restaurant</dt>
        <dd class="wrap-anywhere">{{ context.restaurant }}</dd>
        <dt>Location</dt>
        <dd class="wrap-anywhere">{{ context.address }}</dd>
        <dt>Date</dt>
        <dd>{{ bookingDate(context.date) }}</dd>
        <dt>Time</dt>
        <dd>
            {{ context.time }} – {{ context.end_time
            }}<span v-if="context.end_date !== context.date">
                ({{ bookingDate(context.end_date) }})</span
            >
        </dd>
        <dt>Guests</dt>
        <dd>
            {{ context.guests }}
            {{ context.guests === 1 ? 'person' : 'people' }}
        </dd>
        <dt class="sr-only">Timezone</dt>
        <dd class="col-span-2 text-xs text-muted-foreground">
            Times in {{ timezone }}
        </dd>
    </dl>
</template>
