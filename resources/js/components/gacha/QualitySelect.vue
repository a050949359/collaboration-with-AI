<script setup lang="ts">
import { onClickOutside, useElementBounding } from '@vueuse/core';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { QUALITY_TIERS } from '@/composables/useGachaRoom';

const props = defineProps<{ modelValue: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const { t } = useI18n();
const open = ref(false);
const triggerRef = ref<HTMLElement>();
const dropdownRef = ref<HTMLElement>();

const { left, bottom, width } = useElementBounding(triggerRef);

onClickOutside(dropdownRef, (e) => {
    if (triggerRef.value?.contains(e.target as Node)) {
        return;
    }

    open.value = false;
});

const dropdownStyle = computed(() => ({
    position: 'fixed' as const,
    top: `${bottom.value + 4}px`,
    left: `${left.value}px`,
    width: `${width.value}px`,
    zIndex: 9999,
}));

const selectedTier = computed(() =>
    QUALITY_TIERS.find((q) => q.name === props.modelValue),
);
</script>

<template>
    <div ref="triggerRef">
        <button
            type="button"
            class="flex w-full items-center gap-2 rounded-lg border border-[var(--binary-outline-variant)] bg-[var(--binary-surface-lowest)] px-3 py-2 text-xs tracking-wider transition-colors hover:bg-[var(--binary-surface-dim)]"
            :class="modelValue === 'legendary' ? 'border-[#d4af3755]' : ''"
            @click="open = !open"
        >
            <span
                class="h-2 w-2 shrink-0 rounded-full"
                :class="modelValue === 'legendary' ? 'dot-legendary' : ''"
                :style="
                    modelValue !== 'legendary'
                        ? { background: selectedTier?.color }
                        : {}
                "
            />
            <span
                class="flex-1 text-left"
                :class="modelValue === 'legendary' ? 'gradient-text' : ''"
                :style="
                    modelValue !== 'legendary'
                        ? { color: selectedTier?.color }
                        : {}
                "
                >{{ t(`gacha.quality_${modelValue}`) }}</span
            >
            <span class="text-[10px] text-[var(--binary-primary)]/40">▾</span>
        </button>

        <Teleport to="body">
            <div
                v-if="open"
                ref="dropdownRef"
                :style="dropdownStyle"
                class="overflow-hidden rounded-lg border border-[var(--binary-outline-variant)] bg-[var(--binary-background)] shadow-xl"
            >
                <button
                    v-for="tier in QUALITY_TIERS"
                    :key="tier.name"
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-2 text-xs tracking-wider transition-colors hover:bg-[var(--binary-surface-high)]"
                    :class="[
                        modelValue === tier.name
                            ? 'bg-[var(--binary-surface-container)]'
                            : '',
                        tier.name === 'legendary'
                            ? 'border-b border-[#d4af3715] last:border-b-0'
                            : '',
                    ]"
                    @click="
                        emit('update:modelValue', tier.name);
                        open = false;
                    "
                >
                    <span
                        class="h-2 w-2 shrink-0 rounded-full"
                        :class="
                            tier.name === 'legendary' ? 'dot-legendary' : ''
                        "
                        :style="
                            tier.name !== 'legendary'
                                ? { background: tier.color }
                                : {}
                        "
                    />
                    <span
                        :class="
                            tier.name === 'legendary' ? 'gradient-text' : ''
                        "
                        :style="
                            tier.name !== 'legendary'
                                ? { color: tier.color }
                                : {}
                        "
                        >{{ t(`gacha.quality_${tier.name}`) }}</span
                    >
                </button>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
.gradient-text {
    background: linear-gradient(to bottom, #d4af37 0%, #b84a2a 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    font-weight: bold;
}
.dot-legendary {
    background: linear-gradient(to bottom, #d4af37 0%, #b84a2a 100%);
}
</style>
