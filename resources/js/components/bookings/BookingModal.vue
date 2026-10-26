<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
const props = defineProps<{
    open: boolean;
    title: string;
    description: string;
    busy?: boolean;
    returnFocusTarget?: HTMLElement | null;
}>();
const emit = defineEmits<{ 'update:open': [boolean] }>();
function closeOnEscape(): void {
    if (props.open && !props.busy) emit('update:open', false);
}
let returnFocus: HTMLElement | null = null;
function rememberFocus(): void {
    returnFocus =
        document.activeElement instanceof HTMLElement
            ? document.activeElement
            : null;
}
function restoreFocus(event: Event): void {
    const target = props.returnFocusTarget ?? returnFocus;
    if (target?.isConnected) {
        event.preventDefault();
        target.focus();
    }
}
</script>
<template>
    <Dialog :open="open" @update:open="!busy && emit('update:open', $event)">
        <DialogContent
            class="green-tasty booking-dialog max-h-[calc(100dvh-32px)] w-[520px] max-w-[calc(100%-32px)] overflow-y-auto rounded-3xl bg-card p-6 font-[Poppins,sans-serif] sm:max-w-[520px] sm:p-10"
            :show-close-button="!busy"
            @open-auto-focus="rememberFocus"
            @close-auto-focus="restoreFocus"
            @escape-key-down.prevent="closeOnEscape"
            @keydown.esc.stop.prevent="closeOnEscape"
            @interact-outside="$event.preventDefault()"
        >
            <DialogTitle class="pr-6 text-[26px] leading-10 font-medium">{{
                title
            }}</DialogTitle>
            <DialogDescription class="sr-only">{{
                description
            }}</DialogDescription>
            <slot />
        </DialogContent>
    </Dialog>
</template>
