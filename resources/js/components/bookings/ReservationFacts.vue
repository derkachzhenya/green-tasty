<script setup lang="ts">
import { bookingDate } from '@/lib/booking';
withDefaults(
    defineProps<{
        address: string;
        date: string;
        time: string;
        endTime: string;
        endDate: string;
        guests: number;
        table: string;
        timezone: string;
        showTable?: boolean;
        showTimezone?: boolean;
    }>(),
    { showTable: true, showTimezone: true },
);
</script>
<template>
    <dl
        class="my-4 grid grid-cols-[auto_minmax(0,1fr)] gap-x-6 gap-y-2 text-sm leading-6"
    >
        <dt>Location</dt>
        <dd class="wrap-anywhere">{{ address }}</dd>
        <template v-if="showTable">
            <dt>Table</dt>
            <dd class="wrap-anywhere">{{ table }}</dd>
        </template>
        <dt>Date</dt>
        <dd>{{ bookingDate(date) }}</dd>
        <dt>Time</dt>
        <dd>
            {{ time }} – {{ endTime
            }}<span v-if="endDate !== date"> ({{ bookingDate(endDate) }})</span>
        </dd>
        <dt>Guests</dt>
        <dd>{{ guests }} {{ guests === 1 ? 'person' : 'people' }}</dd>
        <template v-if="showTimezone">
            <dt class="sr-only">Timezone</dt>
            <dd class="col-span-2 text-xs text-muted-foreground">
                Times in {{ timezone }}
            </dd>
        </template>
    </dl>
</template>
