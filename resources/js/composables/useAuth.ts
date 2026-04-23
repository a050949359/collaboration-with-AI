import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function useAuth() {
    const page = usePage();

    const user = computed(() => page.props.auth?.user ?? null);
    const isLoggedIn = computed(() => !!user.value);
    const isAdmin = computed(() => !!page.props.auth?.is_admin);

    return { user, isLoggedIn, isAdmin };
}
