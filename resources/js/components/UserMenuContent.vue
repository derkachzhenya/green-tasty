<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    LayoutDashboard,
    LogOut,
    Settings,
    UtensilsCrossed,
} from '@lucide/vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { logout } from '@/routes';
import { dashboard } from '@/routes/account';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

const handleLogout = () => {
    // Discard the history decryption key as well as prefetched private pages.
    router.clearHistory();
};

defineProps<Props>();
const page = usePage();
const isStaff = computed(() => Boolean(page.props.auth?.isStaff));
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link
                class="block w-full cursor-pointer"
                :href="isStaff ? '/staff/enter' : dashboard()"
            >
                <LayoutDashboard class="mr-2 h-4 w-4" />
                {{ isStaff ? 'Staff workspace' : 'Dashboard' }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="mr-2 h-4 w-4" />
                Account settings
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem v-if="!isStaff" :as-child="true"
            ><Link class="block w-full cursor-pointer" href="/staff"
                ><UtensilsCrossed class="mr-2 h-4 w-4" />Staff area</Link
            ></DropdownMenuItem
        >
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            Log out
        </Link>
    </DropdownMenuItem>
</template>
