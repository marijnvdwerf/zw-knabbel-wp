/**
 * Knabbel WP Admin JavaScript
 *
 * Handles API testing and weekday toggles.
 */

(() => {
    /**
     * API Test Button Handler
     */
    function initApiTest() {
        const button = document.getElementById('test-babbel-api');
        const result = document.getElementById('api-test-result');

        if (!button || !result) return;

        button.addEventListener('click', async () => {
            button.disabled = true;
            button.textContent = knabbel_admin.testing_text;
            result.className = 'knabbel-test-result';
            result.textContent = '';

            const formData = new FormData();
            formData.append('action', 'knabbel_test_api');
            formData.append('_ajax_nonce', knabbel_admin.nonce);

            try {
                const response = await fetch(ajaxurl, {
                    method: 'POST',
                    body: formData,
                });
                const data = await response.json();
                const statusClass = data.success ? 'success' : 'error';
                const icon = data.success
                    ? '<svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"/></svg>'
                    : '<svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"/></svg>';
                result.className = `knabbel-test-result ${statusClass}`;
                // The message can contain an upstream response body; never parse it as HTML.
                result.innerHTML = icon;
                result.append(` ${data.data}`);
            } catch (error) {
                console.error('Knabbel API test failed:', error);
                result.className = 'knabbel-test-result error';
                result.textContent = knabbel_admin.error_text;
            } finally {
                button.disabled = false;
                button.textContent = knabbel_admin.button_text;
            }
        });
    }

    /**
     * Weekday Toggle Handler
     */
    function initWeekdayToggles() {
        const weekdays = document.querySelectorAll('.knabbel-weekday');

        weekdays.forEach((day) => {
            const checkbox = day.querySelector('input[type="checkbox"]');
            if (!checkbox) return;

            // Sync initial state
            day.classList.toggle('checked', checkbox.checked);

            // Handle clicks on the label (not directly on checkbox)
            day.addEventListener('click', (e) => {
                if (e.target.tagName === 'INPUT') return;

                // Prevent default label behavior (would toggle checkbox again)
                e.preventDefault();

                checkbox.checked = !checkbox.checked;
                day.classList.toggle('checked', checkbox.checked);
            });

            // Handle direct checkbox clicks (sync the visual state)
            checkbox.addEventListener('change', () => {
                day.classList.toggle('checked', checkbox.checked);
            });
        });
    }

    /**
     * Initialize on DOM ready
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        initApiTest();
        initWeekdayToggles();
    }
})();
