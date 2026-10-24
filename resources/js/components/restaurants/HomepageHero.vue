<script setup lang="ts">
import SiteContainer from '@/components/site/SiteContainer.vue';
import { Button } from '@/components/ui/button';
</script>

<template>
    <section
        aria-labelledby="welcome-heading"
        class="homepage-hero overflow-hidden"
    >
        <div class="hero-frame">
            <SiteContainer class="hero-container relative">
                <div
                    class="hero-copy relative z-10 flex flex-col items-start gap-6"
                >
                    <div class="space-y-4">
                        <h1
                            id="welcome-heading"
                            class="hero-heading font-medium"
                        >
                            Green by nature,<br />Georgian by heart.
                        </h1>
                        <p
                            class="text-xs leading-5 text-primary uppercase sm:text-sm"
                        >
                            SEASONAL BOWLS • VEGAN FRIENDLY • TBILISI
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-4">
                        <Button as-child class="hero-button">
                            <a href="#locations">Book a table</a>
                        </Button>
                        <Button
                            as-child
                            variant="outline"
                            class="hero-button text-primary"
                        >
                            <a href="#locations">View menu</a>
                        </Button>
                    </div>
                </div>
            </SiteContainer>
            <div class="hero-art" aria-hidden="true">
                <div class="bowl-shadow bowl-shadow-back" />
                <img
                    src="/images/green-tasty/dishes/hero-avocado-bowl.png"
                    alt=""
                    width="1254"
                    height="1254"
                    class="hero-bowl hero-bowl-back"
                    fetchpriority="high"
                />
                <div class="bowl-shadow bowl-shadow-front" />
                <img
                    src="/images/green-tasty/dishes/salad1.png"
                    alt=""
                    width="532"
                    height="405"
                    class="hero-bowl hero-bowl-front"
                    fetchpriority="high"
                />
            </div>
        </div>
    </section>
</template>

<style scoped>
.homepage-hero {
    background: #d6e5d5;
}
.hero-frame {
    position: relative;
    max-width: 1440px;
    margin-inline: auto;
}
.hero-container {
    padding-block: 40px 0;
}
.hero-copy {
    padding-bottom: 24px;
}
.hero-heading {
    font-size: clamp(26px, 7.2vw, 36px);
    line-height: 1.2;
    letter-spacing: 0;
}
.hero-button {
    min-height: 56px;
    height: auto;
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 16px;
    line-height: 24px;
    font-weight: 600;
}
.hero-art {
    position: relative;
    width: calc(100% - 32px);
    max-width: 806.8px;
    aspect-ratio: 806.8 / 396;
    margin-inline: auto;
    overflow: hidden;
}
.hero-bowl {
    position: absolute;
    max-width: none;
    height: auto;
}
.hero-bowl-front {
    top: 0.8586%;
    left: 0;
    width: 65.9395%;
}
.hero-bowl-back {
    top: -16.1616%;
    right: 0;
    width: 61.9732%;
}
.bowl-shadow {
    position: absolute;
    border-radius: 50%;
    background: rgb(19 74 40 / 40%);
    filter: blur(clamp(12px, 3.47vw, 50px));
}
.bowl-shadow-front {
    top: 90.1515%;
    left: 5.082%;
    width: 55.776%;
    height: 4.2929%;
}
.bowl-shadow-back {
    top: 89.1414%;
    left: 40.5%;
    width: 55.776%;
    height: 4.798%;
}
@media (min-width: 640px) {
    .hero-container {
        padding-top: 56px;
    }
    .hero-heading {
        font-size: 48px;
        line-height: 52px;
    }
    .hero-art {
        width: calc(100% - 64px);
    }
}
@media (min-width: 1280px) {
    .hero-container {
        height: 396px;
        padding-block: 88px;
    }
    .hero-copy {
        width: 613px;
        padding-bottom: 0;
        padding-left: 4px;
    }
    .hero-art {
        position: absolute;
        top: 0;
        right: 0;
        width: min(806.8px, 56.03vw);
        margin: 0;
    }
}
</style>
