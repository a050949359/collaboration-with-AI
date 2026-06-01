import { createI18n } from 'vue-i18n';
import en from './locales/en';
import zhTw from './locales/zh-tw';

const savedLocale =
    typeof window !== 'undefined'
        ? (localStorage.getItem('locale') ?? 'zh-tw')
        : 'zh-tw';

const i18n = createI18n({
    legacy: false,
    locale: savedLocale,
    fallbackLocale: 'en',
    messages: {
        'zh-tw': zhTw,
        en,
    },
});

export default i18n;

export function setLocale(locale: 'zh-tw' | 'en') {
    i18n.global.locale.value = locale;
    localStorage.setItem('locale', locale);
}

export function getLocale(): string {
    return i18n.global.locale.value;
}
