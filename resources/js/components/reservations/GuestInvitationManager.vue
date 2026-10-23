<script setup lang="ts">
import { computed, shallowRef } from 'vue';
import InputError from '@/components/InputError.vue';
import ReservationParticipants from '@/components/reservations/ReservationParticipants.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useGuestInvitations } from '@/composables/useGuestInvitations';
import { formatInvitationExpiration } from '@/lib/invitationApi';
import type { GuestOrder, ReservationParticipant } from '@/types/reservation';

const props = defineProps<{
    reservationId: number;
    participants: ReservationParticipant[];
    selections: GuestOrder[];
    timezone: string;
    refreshing?: boolean;
}>();
const emit = defineEmits<{ changed: [] }>();
const {
    action,
    selected,
    issued,
    name,
    nameError,
    error,
    success,
    busy,
    uncertain,
    copying,
    copyMessage,
    open,
    clear,
    submit,
    copy,
} = useGuestInvitations(
    () => props.reservationId,
    () => emit('changed'),
);
const title = computed(() =>
    issued.value
        ? 'Guest invite link'
        : action.value === 'create'
          ? 'Create guest invitation'
          : action.value === 'regenerate'
            ? 'Regenerate invitation?'
            : action.value === 'revoke'
              ? 'Revoke invitation?'
              : 'Guest invitation',
);
const opener = shallowRef<HTMLElement | null>(null);
function begin(
    next: 'create' | 'regenerate' | 'revoke',
    guest: ReservationParticipant | null,
    event: MouseEvent,
): void {
    opener.value = event.currentTarget as HTMLElement;
    open(next, guest);
}
function restoreFocus(event: Event): void {
    event.preventDefault();
    if (opener.value?.isConnected) opener.value.focus();
    else document.getElementById('create-guest-invitation')?.focus();
}
function close(opened: boolean): void {
    if (!opened && !busy.value) clear();
}
</script>

<template>
    <section aria-label="Guest invitation management" class="space-y-4">
        <p
            v-if="success && !issued"
            role="status"
            class="rounded-xl border border-border bg-accent p-4 text-sm"
        >
            {{ success }}
        </p>
        <ReservationParticipants
            title="Participants and invitations"
            :participants="participants"
            :selections="selections"
            :timezone="timezone"
        >
            <template #heading-actions
                ><Button
                    id="create-guest-invitation"
                    type="button"
                    class="min-h-11"
                    :disabled="busy || refreshing"
                    @click="begin('create', null, $event)"
                    >Create invitation</Button
                ></template
            >
            <template #introduction
                ><p class="text-sm leading-6 text-muted-foreground">
                    Create an individual link for each guest, then copy and
                    share it yourself.
                </p></template
            >
            <template #actions="{ participant }">
                <div
                    v-if="!participant.is_owner"
                    class="flex flex-wrap gap-2 border-t border-border pt-3"
                >
                    <Button
                        type="button"
                        variant="outline"
                        class="min-h-11 bg-card"
                        :disabled="busy || refreshing"
                        :aria-label="`${participant.invitation_status === 'not_invited' ? 'Generate' : 'Regenerate'} invitation for ${participant.name}`"
                        @click="begin('regenerate', participant, $event)"
                        >{{
                            participant.invitation_status === 'not_invited'
                                ? 'Generate link'
                                : 'Regenerate'
                        }}</Button
                    >
                    <Button
                        v-if="
                            !['not_invited', 'revoked'].includes(
                                participant.invitation_status,
                            )
                        "
                        type="button"
                        variant="outline"
                        class="min-h-11 bg-card text-destructive"
                        :disabled="busy || refreshing"
                        :aria-label="`Revoke invitation for ${participant.name}`"
                        @click="begin('revoke', participant, $event)"
                        >Revoke</Button
                    >
                </div>
            </template>
        </ReservationParticipants>
        <Dialog :open="action !== null" @update:open="close">
            <DialogContent
                class="green-tasty max-h-[calc(100dvh-2rem)] w-[calc(100%_-_2rem)] max-w-lg overflow-y-auto border-border bg-card font-[Poppins,sans-serif] text-foreground sm:max-w-lg"
                :show-close-button="!busy"
                @close-auto-focus="restoreFocus"
                @escape-key-down="busy && $event.preventDefault()"
                @interact-outside="busy && $event.preventDefault()"
            >
                <DialogHeader
                    ><DialogTitle>{{ title }}</DialogTitle
                    ><DialogDescription>
                        <template v-if="issued"
                            >Copy this individual link for {{ issued.name }}. It
                            will no longer be shown after you close this
                            dialog.</template
                        >
                        <template v-else-if="action === 'create'"
                            >Create a personal reservation and menu link. No
                            email is sent.</template
                        >
                        <template v-else-if="action === 'regenerate'"
                            >Regenerate the invitation for {{ selected?.name }}?
                            The previous link will stop working. Saved
                            selections will remain.</template
                        >
                        <template v-else-if="action === 'revoke'"
                            >Revoke the invitation for {{ selected?.name }}? The
                            current link will stop working. The guest and saved
                            selections will remain.</template
                        >
                    </DialogDescription></DialogHeader
                >
                <template v-if="issued">
                    <p
                        role="status"
                        class="rounded-xl bg-accent p-4 text-sm leading-6"
                    >
                        {{ success }}
                    </p>
                    <p class="text-sm leading-6">
                        Expires:
                        <time :datetime="issued.expires_at">{{
                            formatInvitationExpiration(
                                issued.expires_at,
                                timezone,
                            )
                        }}</time>
                        ({{ timezone }})
                    </p>
                    <div class="space-y-2">
                        <label
                            for="issued-invitation-link"
                            class="text-sm font-medium"
                            >New invitation link</label
                        ><input
                            id="issued-invitation-link"
                            :value="issued.invitation_url"
                            readonly
                            autocomplete="off"
                            spellcheck="false"
                            aria-describedby="link-help"
                            class="min-h-11 w-full min-w-0 rounded-md border border-primary/40 bg-accent px-3 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                            @focus="
                                ($event.target as HTMLInputElement).select()
                            "
                        />
                        <p
                            id="link-help"
                            class="text-sm leading-6 text-muted-foreground"
                        >
                            Anyone with this link can access this guest’s
                            invitation. Share it only with the intended guest.
                        </p>
                    </div>
                    <p
                        v-if="copyMessage"
                        role="status"
                        class="text-sm leading-6"
                    >
                        {{ copyMessage }}
                    </p>
                    <DialogFooter
                        ><Button
                            type="button"
                            variant="outline"
                            class="min-h-11 bg-card"
                            @click="clear"
                            >Done</Button
                        ><Button
                            type="button"
                            class="min-h-11"
                            :disabled="copying"
                            @click="copy"
                            >{{ copying ? 'Copying…' : 'Copy link' }}</Button
                        ></DialogFooter
                    >
                </template>
                <form
                    v-else-if="action"
                    class="space-y-5"
                    :aria-busy="busy"
                    @submit.prevent="submit"
                >
                    <div v-if="action === 'create'" class="space-y-2">
                        <label
                            for="invitation-guest-name"
                            class="text-sm font-medium"
                            >Guest display name</label
                        ><input
                            id="invitation-guest-name"
                            v-model="name"
                            type="text"
                            required
                            maxlength="255"
                            autocomplete="off"
                            :disabled="busy"
                            :aria-invalid="!!nameError"
                            aria-describedby="invitation-name-error"
                            class="min-h-11 w-full rounded-md border border-primary/40 bg-card px-3 focus-visible:outline-2 focus-visible:outline-ring"
                        /><InputError
                            id="invitation-name-error"
                            :message="nameError"
                            role="alert"
                        />
                    </div>
                    <p
                        v-if="error"
                        role="alert"
                        class="text-sm leading-6 text-destructive"
                    >
                        {{ error }}
                    </p>
                    <p v-if="busy" role="status" class="text-sm">
                        Updating invitation…
                    </p>
                    <DialogFooter
                        ><Button
                            type="button"
                            variant="outline"
                            class="min-h-11 bg-card"
                            :disabled="busy"
                            @click="clear"
                            >Cancel</Button
                        ><Button
                            type="submit"
                            :variant="
                                action === 'revoke' ? 'destructive' : 'default'
                            "
                            class="min-h-11"
                            :disabled="busy || uncertain"
                            >{{
                                busy
                                    ? 'Please wait…'
                                    : action === 'create'
                                      ? 'Create invitation'
                                      : action === 'regenerate'
                                        ? 'Regenerate link'
                                        : 'Revoke invitation'
                            }}</Button
                        ></DialogFooter
                    >
                </form>
            </DialogContent>
        </Dialog>
    </section>
</template>
