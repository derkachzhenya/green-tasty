<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Utensils } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { figmaAtmosphere } from '@/lib/figma-demo';
import { homepageLocations } from '@/lib/homepage';
import { create } from '@/routes/restaurants/bookings';
import type { RestaurantDetails } from '@/types/restaurant';

const props = defineProps<{ restaurant: RestaurantDetails }>();
const location = computed(() => homepageLocations([props.restaurant])[0]);
const rustaveli = computed(
    () => location.value.referenceAddress === '48 Rustaveli Avenue',
);
const photo = computed(() =>
    rustaveli.value
        ? '/images/green-tasty/locations/rustaveli-profile.jpg'
        : location.value.image,
);
</script>

<template>
    <section aria-labelledby="restaurant-heading" class="profile-hero">
        <div class="profile-frame">
            <img
                v-if="photo"
                :src="photo"
                :alt="`Dining area at ${location.address} (Figma reference)`"
                width="997"
                height="587"
                class="profile-photo"
                fetchpriority="high"
            />
            <div
                v-else
                class="profile-photo grid place-items-center text-primary"
                aria-hidden="true"
            >
                <Utensils class="size-20" :stroke-width="1" />
            </div>
            <div class="profile-information space-y-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h1
                        id="restaurant-heading"
                        class="flex min-w-0 items-start gap-2 text-[26px] leading-10 font-medium wrap-anywhere sm:text-[32px]"
                    >
                        <img
                            src="/images/green-tasty/icons/location-pin.png"
                            alt=""
                            width="32"
                            height="32"
                            class="mt-1 size-8 shrink-0"
                        />{{ location.address }}
                    </h1>
                    <p
                        v-if="rustaveli"
                        aria-label="Rating: 4.73 out of 5"
                        class="flex items-center gap-1 text-xs leading-4 whitespace-nowrap"
                    >
                        4.73 / 5
                        <img
                            src="/images/green-tasty/icons/star-01.png"
                            alt=""
                            width="20"
                            height="20"
                        />
                    </p>
                </div>
                <div v-if="rustaveli" class="space-y-3 text-sm leading-6">
                    <h2 class="font-medium">Atmosphere</h2>
                    <p v-for="paragraph in figmaAtmosphere" :key="paragraph">
                        {{ paragraph }}
                    </p>
                </div>
                <div v-else class="space-y-3 text-sm leading-6">
                    <p>{{ restaurant.name }}</p>
                    <p>
                        {{ restaurant.table_count }}
                        {{ restaurant.table_count === 1 ? 'table' : 'tables' }}
                        available at this location.
                    </p>
                </div>
                <div class="flex flex-wrap gap-4">
                    <Button as-child class="profile-button grow"
                        ><Link :href="create(restaurant.slug)"
                            >Book a table</Link
                        ></Button
                    >
                    <Button
                        as-child
                        variant="outline"
                        class="profile-button text-primary"
                        ><a href="#menu">View menu</a></Button
                    >
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.profile-hero {
    background: #d6e5d5;
}
.profile-frame {
    position: relative;
    max-width: 1440px;
    margin-inline: auto;
    padding-bottom: 32px;
}
.profile-photo {
    width: 100%;
    height: 280px;
    object-fit: cover;
}
.profile-information {
    position: relative;
    margin: -32px 16px 0;
    padding: 24px;
    border-radius: 12px;
    background: linear-gradient(135deg, #fff, #f7f7ec);
    box-shadow: 0 0 10px 4px rgb(218 218 218 / 70%);
}
.profile-button {
    min-height: 56px;
    height: auto;
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 16px;
    line-height: 24px;
    font-weight: 600;
}
@media (min-width: 768px) {
    .profile-photo {
        height: 440px;
    }
    .profile-information {
        margin-inline: 32px;
    }
}
@media (min-width: 1280px) {
    .profile-frame {
        height: 587px;
        padding: 0;
    }
    .profile-photo {
        width: 997px;
        height: 587px;
    }
    .profile-information {
        position: absolute;
        top: 115px;
        right: 40px;
        width: 580px;
        margin: 0;
    }
}
</style>
