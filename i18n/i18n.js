/**
 * LSJbanana Frontend I18n
 */

(function(window) {
    class I18n {
        constructor() {
            this.locale = window.LSJ_LANG || 'zh-CN';
            this.translations = {};
            this.loaded = false;
        }

        async init() {
            try {
                // Determine the correct path to the language file
                // If we are in a subdirectory (like /admin/), we need to go up one level
                const scriptPath = document.currentScript ? document.currentScript.src : '';
                let baseUrl = '';
                
                // Try to determine base URL from window.location or existing global vars
                // This is a simplified approach, might need adjustment based on deployment
                if (window.ADMIN_API_ENDPOINT) {
                    // Admin panel
                    baseUrl = '../';
                }

                const langFile = `${baseUrl}i18n/lang/js/${this.locale}.json`;
                const response = await fetch(langFile);
                if (response.ok) {
                    this.translations = await response.json();
                    this.loaded = true;
                } else {
                    console.error('Failed to load translations:', response.status);
                }
            } catch (error) {
                console.error('I18n init error:', error);
            }
        }

        t(key, params = {}) {
            if (!this.loaded) return key;

            const keys = key.split('.');
            let value = this.translations;

            for (const k of keys) {
                if (value && typeof value === 'object' && k in value) {
                    value = value[k];
                } else {
                    return key;
                }
            }

            if (typeof value !== 'string') {
                return key;
            }

            // Replace parameters
            Object.keys(params).forEach(k => {
                value = value.replace(new RegExp(`{${k}}`, 'g'), params[k]);
            });

            return value;
        }
    }

    window.i18n = new I18n();
    
    // Initialize immediately
    window.addEventListener('DOMContentLoaded', () => {
        window.i18n.init().then(() => {
            // Dispatch event when i18n is ready
            window.dispatchEvent(new CustomEvent('i18nReady'));
        });
    });

})(window);